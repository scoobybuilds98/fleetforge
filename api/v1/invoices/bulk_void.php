<?php
declare(strict_types=1);

/**
 * api/v1/invoices/bulk_void.php
 *
 * Bulk void up to 100 invoices in a single request. Each ID is processed
 * independently inside its own db_transaction; a failure on one ID never
 * aborts the remaining IDs.
 *
 * Voidable statuses: 'draft', 'sent' — for EVERYONE (matches the single-void
 * void.php). MEDIUM [02]: paid/partially_paid invoices are NOT bulk-voidable
 * (the counter reversal here only un-books total_revenue for prior-status
 * 'sent' and never reverses payment allocations) — reverse the payment first.
 *
 * Path B counter logic is replicated EXACTLY from void.php (S-FIX-2 D45):
 *   draft → void  : total_invoiced -= total_amount; outstanding_balance unchanged
 *   sent  → void  : total_invoiced -= total_amount; outstanding_balance -= balance_due
 *
 * AutoEntryBridge and audit_log run INSIDE the transaction (same as void.php).
 * InvoiceEnqueuer::enqueue() runs OUTSIDE the transaction, per void.php pattern.
 * Notifications are wrapped in try/catch (non-fatal).
 *
 * @method  POST
 * @body    { "ids": [1, 2, 3], "void_reason": "string" }   (ids: int[], max 100)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { "success": true, "data": { "actioned": N, "skipped": N, "errors": [...] } }
 *          422 VALIDATION_ERROR — ids or void_reason invalid
 *
 * Decisions: D12 (immutability), D45 (Path B counter semantics), S-FIX-2
 * Session: S-BULK-VOID-INVOICES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

// ── Validate ids array ────────────────────────────────────────────────────────

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

// Coerce to clean integers; reject any non-positive values.
$ids = [];
foreach ($rawIds as $raw) {
    $int = clean_int($raw);
    if (!$int || $int <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $int;
}
$ids = array_values(array_unique($ids));

// ── Validate void_reason ──────────────────────────────────────────────────────

$voidReason = clean_string($body['void_reason'] ?? null, 1000);
if (!$voidReason) {
    json_error('VALIDATION_ERROR', 'void_reason is required.', 422);
}

// ── Shared context ────────────────────────────────────────────────────────────

$userId     = current_user_id();
$userName   = current_user()['name'] ?? 'System';
$ipAddress  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Statuses that a normal user can void.
$voidable = ['draft', 'sent'];

$actioned = 0;
$skipped  = 0;
$errors   = [];

// ── Process each ID independently ────────────────────────────────────────────

foreach ($ids as $id) {
    // Fetch outside the transaction so we can gate before opening one.
    $invoice = db_row(
        "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
         FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$invoice) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Invoice not found.'];
        continue;
    }

    // State-machine gate — only draft/sent are voidable, for EVERYONE (matches
    // the single-void void.php). MEDIUM [02]: the old super_admin "any status"
    // escape hatch let a paid/partially_paid invoice be voided, but the counter
    // reversal below only un-books total_revenue when the prior status was
    // 'sent' (decRevenue) — voiding a 'paid' invoice left revenue overstated —
    // and it never reverses the payment allocations / amount_paid, orphaning
    // them. Voiding a paid invoice must go through payment reversal first, not
    // a bulk void.
    if (!in_array($invoice['status'], $voidable, true)) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Cannot void invoice with status {$invoice['status']} — only draft or sent invoices can be voided. Reverse the payment(s) first.",
        ];
        continue;
    }

    // (A 'void' status is already rejected by the $voidable gate above — no
    //  separate already-void branch is needed; it would be unreachable.)

    // S-ORPHAN-OVERFLOW-CN: an already-APPLIED overflow credit note blocks the
    // void (mirrors FinancialActions::voidInvoice); unapplied ones are
    // auto-voided inside the transaction below.
    $cnBlockers = \FleetForge\Billing\OverflowCreditNotes::findBlockers($id);
    if ($cnBlockers) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => \FleetForge\Billing\OverflowCreditNotes::blockerMessage($cnBlockers, 'void')];
        continue;
    }

    // ── Per-invoice transaction: mirrors void.php exactly ────────────────────
    try {
        $voidedCns = [];
        db_transaction(function () use ($id, $invoice, $voidReason, $userId, $userName, $ipAddress, &$voidedCns): void {
            // S-FIX-2 Path B canonical truth (D45):
            //   draft → void : OB unchanged (decOb = 0.00)
            //   sent  → void : OB -= balance_due
            // (Only draft/sent reach here per the $voidable gate above.)
            $preVoidStatus = $invoice['status'];
            $totalAmount   = (string) $invoice['total_amount'];
            $balanceDue    = (string) $invoice['balance_due'];

            // S-FIX-2 Phase 0.5 Bug B: zero balance_due on the void row to
            // prevent a subsequent super_admin delete from double-decrementing.
            $decOb = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;

            // I06: self-guarding flip — gate on the pre-void status so a concurrent
            // or overlapping bulk-void cannot pass the gate twice and double-reverse
            // the Path-B counters below. 0 rows = already transitioned → abort this
            // id's transaction (recorded as a skip by the outer catch).
            $affected = db_update('invoices', [
                'status'      => 'void',
                'balance_due' => '0.00',
                'voided_date' => date('Y-m-d'),
                'void_reason' => $voidReason,
                'voided_by'   => $userId,
                'updated_by'  => $userId,
            ], 'id = ? AND status = ?', [$id, $preVoidStatus]);
            if ($affected === 0) {
                throw new \RuntimeException("Invoice no longer '{$preVoidStatus}' (modified concurrently).");
            }

            // Reverse denormalized counters (Trap 6 / Path B).
            // total_revenue tracks sent invoice amounts — only reverse when was sent
            $decRevenue = ($preVoidStatus === 'sent') ? $totalAmount : '0.00';
            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE leases
                     SET total_invoiced      = total_invoiced      - ?,
                         outstanding_balance = outstanding_balance - ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$totalAmount, $decOb, $invoice['lease_id']]
                );

                // Walk the billing-coverage anchor back to the latest STILL-LIVE
                // invoice (mirrors single-item void.php). Without this, bulk-voiding
                // the most-recent invoice leaves leases.last_billed_date pointing
                // PAST real coverage. The just-voided row is status='void' above so
                // MAX excludes it; NULL when no live invoice remains.
                $cov = db_row(
                    "SELECT i2.billing_period_end AS max_end, i2.id AS inv_id
                       FROM invoices i2
                      WHERE i2.lease_id = ?
                        AND i2.deleted_at IS NULL
                        AND i2.status <> 'void'
                        AND i2.billing_period_end IS NOT NULL
                      ORDER BY i2.billing_period_end DESC, i2.id DESC
                      LIMIT 1",
                    [$invoice['lease_id']]
                );
                db_execute(
                    "UPDATE leases SET last_billed_date = ?, last_billed_invoice_id = ?, updated_at = NOW() WHERE id = ?",
                    [$cov['max_end'] ?? null, $cov['inv_id'] ?? null, $invoice['lease_id']]
                );
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers
                     SET outstanding_balance = outstanding_balance - ?,
                         total_revenue       = total_revenue       - ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$decOb, $decRevenue, $invoice['customer_id']]
                );
            }
            if ($decRevenue !== '0.00' && $invoice['lease_id']) {
                db_execute(
                    "UPDATE equipment_units eu
                       JOIN leases l ON l.id = ? AND l.equipment_unit_id = eu.id AND l.deleted_at IS NULL
                            SET eu.total_revenue = eu.total_revenue - ?, eu.updated_at = NOW()",
                    [$invoice['lease_id'], $decRevenue]
                );
            }

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $id,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "Invoice {$invoice['invoice_number']} voided via bulk_void (was {$preVoidStatus}): {$voidReason}. "
                                . "Counter delta: total_invoiced -= {$totalAmount}, outstanding_balance -= {$decOb} (Path B).",
                'old_values'   => json_encode(['status' => $preVoidStatus, 'balance_due' => $balanceDue]),
                'new_values'   => json_encode(['status' => 'void', 'balance_due' => '0.00']),
                'ip_address'   => $ipAddress,
            ]);

            // Auto-JE: reverse the original invoice JE (if one exists).
            // WHY inside transaction — reversal failure rolls back the void (A8, §16).
            \FleetForge\Accounting\AutoEntryBridge::onInvoiceVoided($id, $userId);

            // S-AUDIT-LIFECYCLE-1 (closes F33 at the bulk-void site): restore
            // any precharge drawdown this invoice consumed + re-open the D138
            // emit gate if it carried the mileage_precharge charge.
            ff_reverse_precharge_on_invoice_removal($id, $userId, $userName, 'voided (bulk)');

            // S-ORPHAN-OVERFLOW-CN: void this invoice's auto-created overflow
            // CNs in the SAME transaction (unapplied-only; blockers skipped above).
            $voidedCns = \FleetForge\Billing\OverflowCreditNotes::voidForInvoice(
                $id, $userId, $userName,
                "source invoice {$invoice['invoice_number']} voided (bulk)"
            );

            // ── In-app notification (NOTIF-1) — non-fatal ────────────────────
            try {
                \FleetForge\Notifications\NotificationService::notify(
                    type:       'invoice.voided',
                    title:      "Invoice {$invoice['invoice_number']} voided",
                    message:    "Invoice {$invoice['invoice_number']} voided: {$voidReason}",
                    entityType: 'invoice',
                    entityId:   $id,
                    url:        '/fleetforge/invoices/show?id=' . $id,
                    severity:   'warning'
                );
            } catch (\Throwable $e) {
                error_log('[NOTIF invoice.voided bulk] id=' . $id . ': ' . $e->getMessage());
            }
        });

        // ── S-QBO-12: enqueue void to QBO — OUTSIDE transaction ──────────────
        // Best-effort enqueue after the db_transaction commits so void state is
        // durably persisted before the QBO sync attempt fires. Matches void.php
        // pattern (D-ENQUEUER-GATE-0-ELIGIBILITY). status='void' is now on disk.
        \FleetForge\QboPushers\InvoiceEnqueuer::enqueue((int) $id, 'void');
        foreach ($voidedCns as $cn) {
            \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cn['id'], 'void');
        }

        $actioned++;
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to caller.
        error_log("bulk_void invoices: id={$id} failed: " . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Void failed due to a server error.'];
    }
}

// I06: invalidate the dashboard cache so AR tiles reflect the voids
// (bulk_delete already does this; bulk_void previously did not).
invalidate_dashboard_cache();

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
