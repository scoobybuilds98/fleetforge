<?php
declare(strict_types=1);

/**
 * api/v1/invoices/bulk_void.php
 *
 * Bulk void up to 100 invoices in a single request. Each ID is processed
 * independently inside its own db_transaction; a failure on one ID never
 * aborts the remaining IDs.
 *
 * Voidable statuses: 'draft', 'sent'. All others are skipped unless the
 * caller is a super_admin, in which case any status is accepted.
 *
 * Path B counter logic is replicated EXACTLY from void.php (S-FIX-2 D45):
 *   draft → void  : total_invoiced -= total_amount; outstanding_balance unchanged
 *   sent  → void  : total_invoiced -= total_amount; outstanding_balance -= balance_due
 *   (super_admin only: any other status uses the same $decOb calculation)
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

$superAdmin = is_super_admin();
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

    // State-machine gate — super_admin can void any status; others only draft/sent.
    if (!$superAdmin && !in_array($invoice['status'], $voidable, true)) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Cannot void invoice with status {$invoice['status']}",
        ];
        continue;
    }

    // Already void — skip gracefully.
    if ($invoice['status'] === 'void') {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Cannot void invoice with status void'];
        continue;
    }

    // ── Per-invoice transaction: mirrors void.php exactly ────────────────────
    try {
        db_transaction(function () use ($id, $invoice, $voidReason, $userId, $userName, $ipAddress): void {
            // S-FIX-2 Path B canonical truth (D45):
            //   draft → void : OB unchanged (decOb = 0.00)
            //   sent  → void : OB -= balance_due
            //   super_admin other statuses follow the same rule for symmetry.
            $preVoidStatus = $invoice['status'];
            $totalAmount   = (string) $invoice['total_amount'];
            $balanceDue    = (string) $invoice['balance_due'];

            // S-FIX-2 Phase 0.5 Bug B: zero balance_due on the void row to
            // prevent a subsequent super_admin delete from double-decrementing.
            $decOb = ($preVoidStatus === 'draft') ? '0.00' : $balanceDue;

            db_update('invoices', [
                'status'      => 'void',
                'balance_due' => '0.00',
                'voided_date' => date('Y-m-d'),
                'void_reason' => $voidReason,
                'voided_by'   => $userId,
                'updated_by'  => $userId,
            ], 'id = ?', [$id]);

            // Reverse denormalized counters (Trap 6 / Path B).
            if ($invoice['lease_id']) {
                db_execute(
                    "UPDATE leases
                     SET total_invoiced      = total_invoiced      - ?,
                         outstanding_balance = outstanding_balance - ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$totalAmount, $decOb, $invoice['lease_id']]
                );
            }
            if ($invoice['customer_id']) {
                db_execute(
                    "UPDATE customers
                     SET outstanding_balance = outstanding_balance - ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$decOb, $invoice['customer_id']]
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

        $actioned++;
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to caller.
        error_log("bulk_void invoices: id={$id} failed: " . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Void failed due to a server error.'];
    }
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
