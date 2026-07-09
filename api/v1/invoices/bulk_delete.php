<?php
declare(strict_types=1);

/**
 * api/v1/invoices/bulk_delete.php
 *
 * Bulk soft-delete draft invoices. Each ID is processed independently inside
 * its own transaction; a failure on one ID does not abort the remaining IDs.
 * Non-draft invoices are skipped (returned as errors) unless the caller is a
 * super_admin, matching the single-delete immutability rule (D12).
 *
 * Counter deltas replicate Path B semantics from invoices/delete.php (S-FIX-2):
 *   draft         — total_invoiced -= total_amount; OB unchanged
 *   sent/partial/
 *   overdue       — total_invoiced -= total_amount; OB -= balance_due   (super_admin only)
 *   paid          — total_invoiced -= total_amount; OB unchanged        (super_admin only)
 *   void          — both deltas 0                                       (super_admin only)
 *   written_off   — both deltas 0                                       (super_admin only)
 *
 * @method  POST
 * @body    { "ids": [1, 2, 3] }   (int[], max 100)
 * @auth    Session required; require_permission('invoices','delete')
 * @returns 200 { "success": true, "data": { "deleted": N, "skipped": N, "errors": [...] } }
 *
 * Decisions: D5 (soft-delete), D12 (immutability), D15 (gap-free numbers), S-FIX-2 (Path B)
 * Session: S-BULK-DELETE-INVOICES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'delete');

$body = json_body();

// ── Validate ids array ────────────────────────────────────────────────────────

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

// Coerce to clean integers and reject any non-positive values.
$ids = [];
foreach ($rawIds as $raw) {
    $int = clean_int($raw);
    if (!$int || $int <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $int;
}
$ids = array_values(array_unique($ids));

// ── Process each ID independently ────────────────────────────────────────────

$deleted = 0;
$skipped = 0;
$errors  = [];

$now        = date('Y-m-d H:i:s');
$superAdmin = is_super_admin();
$userId     = current_user_id();
$userName   = current_user()['name'] ?? 'System';
$ipAddress  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($ids as $id) {
    // Fetch outside the transaction so we can gate before opening one.
    $invoice = db_row(
        "SELECT id, status, invoice_number, total_amount, balance_due, customer_id, lease_id
         FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$invoice) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Invoice not found or already deleted.'];
        continue;
    }

    // Immutability gate — mirrors invoices/delete.php (D12).
    if ($invoice['status'] !== 'draft' && !$superAdmin) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Only draft invoices can be deleted. Use void for sent invoices.'];
        continue;
    }

    // S-ORPHAN-OVERFLOW-CN: an already-APPLIED overflow credit note blocks the
    // delete (mirrors invoices/delete.php); unapplied ones are auto-voided
    // inside the transaction below.
    $cnBlockers = \FleetForge\Billing\OverflowCreditNotes::findBlockers($id);
    if ($cnBlockers) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => \FleetForge\Billing\OverflowCreditNotes::blockerMessage($cnBlockers, 'delete')];
        continue;
    }

    // Each delete is its own transaction; failures are isolated.
    try {
        $voidedCns = [];
        db_transaction(function () use ($id, $invoice, $now, $userId, $userName, $ipAddress, &$voidedCns) {
            // Soft-delete the invoice row. I06: gate on deleted_at IS NULL so a
            // concurrent/overlapping bulk-delete cannot delete the same row twice
            // and double-reverse the Path-B counters below. 0 rows = already
            // deleted → abort this id's transaction.
            $affected = db_update('invoices', ['deleted_at' => $now], 'id = ? AND deleted_at IS NULL', [$id]);
            if ($affected === 0) {
                throw new \RuntimeException('Invoice already deleted (modified concurrently).');
            }

            // ── Path B counter logic (S-FIX-2) ──────────────────────────────
            // Mirrors the exact branching in invoices/delete.php; comments there
            // explain the WHY for each status bucket.
            $totalAmount = (string) $invoice['total_amount'];
            $balanceDue  = (string) $invoice['balance_due'];
            $status      = $invoice['status'];

            $decTotalInvoiced = $totalAmount;
            $decOb            = $balanceDue;

            if ($status === 'draft') {
                // Draft was never counted in OB; only total_invoiced is reversed.
                $decOb = '0.00';
            } elseif ($status === 'paid') {
                // OB was already decremented when payment was recorded.
                $decOb = '0.00';
            } elseif ($status === 'void') {
                // Void already reversed both counters; nothing left to undo.
                // Also protects pre-S-FIX-2 void rows with stale balance_due.
                $decTotalInvoiced = '0.00';
                $decOb            = '0.00';
            } elseif ($status === 'written_off') {
                // Write-off cleared OB and preserved total_invoiced as real revenue.
                $decTotalInvoiced = '0.00';
                $decOb            = '0.00';
            }
            // sent / partially_paid / overdue → both decrements stand (super_admin path).

            // total_revenue (customers + equipment_units) is booked ONLY at send,
            // never at draft creation — so it must NOT mirror $decTotalInvoiced
            // (which includes drafts) or deleting a draft drives revenue negative.
            // Reverse it only for statuses where revenue was net-booked and not
            // already reversed. Mirrors invoices/delete.php.
            $decRevenue = in_array($status, ['sent', 'partially_paid', 'overdue', 'paid'], true)
                ? $totalAmount
                : '0.00';

            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE leases
                     SET total_invoiced      = total_invoiced      - ?,
                         outstanding_balance = outstanding_balance - ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$decTotalInvoiced, $decOb, $invoice['lease_id']]
                );

                // Walk the billing-coverage anchor back to the latest STILL-LIVE
                // invoice (mirrors invoices/delete.php + invoices/void.php). Without
                // this, bulk-deleting the most-recent invoice leaves
                // leases.last_billed_date pointing PAST real coverage, so reports
                // and the lease-close partial_end derivation read a stale anchor.
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

            // Equipment total_revenue follows the same send-booked rule.
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
                'action'       => 'delete',
                'module'       => 'invoices',
                'entity_type'  => 'invoice',
                'entity_id'    => $id,
                'entity_label' => $invoice['invoice_number'],
                'notes'        => "Invoice {$invoice['invoice_number']} soft-deleted via bulk_delete (was {$status}). "
                                . "Counter delta: total_invoiced -= {$decTotalInvoiced}, outstanding_balance -= {$decOb} (Path B).",
                'old_values'   => json_encode(['status' => $status, 'balance_due' => $balanceDue]),
                'new_values'   => json_encode(['deleted_at' => $now]),
                'ip_address'   => $ipAddress,
            ]);

            // S-ORPHAN-OVERFLOW-CN: void this invoice's auto-created overflow
            // CNs in the SAME transaction (unapplied-only; blockers skipped above).
            $voidedCns = \FleetForge\Billing\OverflowCreditNotes::voidForInvoice(
                $id, $userId, $userName,
                "source invoice {$invoice['invoice_number']} deleted (bulk)"
            );
        });

        // Best-effort QBO void propagation AFTER this id's commit.
        foreach ($voidedCns as $cn) {
            \FleetForge\QboPushers\CreditMemoEnqueuer::enqueue((int) $cn['id'], 'void');
        }

        $deleted++;
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to the caller.
        error_log("bulk_delete invoices: id={$id} failed: " . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Delete failed due to a server error.'];
    }
}

if ($deleted > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'deleted' => $deleted,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
