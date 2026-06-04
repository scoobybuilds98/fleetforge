<?php
declare(strict_types=1);

/**
 * api/v1/payments/bulk_delete.php
 *
 * Bulk soft-delete payments and reverse all their effects. Each ID is processed
 * independently inside its own transaction; a failure on one ID does not abort
 * the remaining IDs.
 *
 * For each payment the reversal mirrors payments/delete.php exactly:
 *   1. Soft-delete the payment row  (D5/D13 — NEVER hard-delete payments)
 *   2. For each allocation, reverse invoices.amount_paid and recompute balance_due
 *   3. Reverse leases.total_paid for each affected lease
 *   4. Reverse customers.outstanding_balance (non-terminal invoices only — Path B)
 *   5. Revert invoice status if it was driven to 'paid' or 'partially_paid'
 *   6. Audit log inside each transaction
 *   7. In-app notification (NOTIF-1, best-effort) after each successful transaction
 *   8. QBO sync enqueue (best-effort) for each successfully soft-deleted payment
 *
 * Requires Manager or higher permission. Accountants can record but not void.
 *
 * @method  POST
 * @body    JSON: { "ids": [int, ...], "reason": string }   (max 100 ids)
 * @auth    Session required; require_permission('payments','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *
 * Decisions: D5/D13 (soft-delete), D16 (bcmath), Trap 6 (counters in same txn),
 *            S-FIX-2 Path B (terminal invoice guard)
 * Session: S-BULK-DELETE-PAYMENTS
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'delete');

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

// ── Validate reason ───────────────────────────────────────────────────────────

$reason = clean_string($body['reason'] ?? null, 500);
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required when deleting payments.', 422);
}

// ── Shared context (read once, reused per iteration) ─────────────────────────

$now       = date('Y-m-d H:i:s');
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ── Process each ID independently ────────────────────────────────────────────

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($ids as $id) {
    // Load payment outside the transaction — if it doesn't exist we skip cleanly
    // without opening a needless DB transaction.
    $payment = db_row(
        "SELECT id, payment_number, customer_id, amount, currency, status
         FROM payments WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$payment) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Payment not found or already deleted.'];
        continue;
    }

    // Load all allocations for this payment (mirrors delete.php logic verbatim).
    // S-FIX-2 D-E: pull invoice.deleted_at so we can skip the OB re-INC and
    // invoice balance updates for invoices that were voided/written-off/deleted
    // after the payment was made (those events already accounted for the counters).
    $allocations = db_select(
        "SELECT pa.id, pa.invoice_id, pa.amount,
                i.lease_id, i.invoice_number, i.total_amount, i.credits_applied,
                i.amount_paid, i.balance_due, i.status AS invoice_status,
                i.customer_id, i.deleted_at AS invoice_deleted_at
         FROM payment_allocations pa
         JOIN invoices i ON i.id = pa.invoice_id
         WHERE pa.payment_id = ?",
        [$id]
    );

    // Capture reverted statuses per payment (used in audit log and QBO enqueue
    // decision; not surfaced in the bulk response to keep payload compact).
    $revertedStatuses = [];

    try {
        db_transaction(function () use (
            $id, $payment, $allocations, $reason, $now,
            $userId, $userName, $ipAddress, &$revertedStatuses
        ) {
            // ------------------------------------------------------------------
            // Step 1: Soft-delete the payment row (D5/D13)
            // ------------------------------------------------------------------
            db_update('payments', ['deleted_at' => $now], 'id = ?', [$id]);

            // ------------------------------------------------------------------
            // Step 2: Reverse each invoice allocation (Trap 6 — inside txn)
            // ------------------------------------------------------------------
            foreach ($allocations as $alloc) {
                // Use bcmath to avoid float drift (D16).
                $allocated  = (string) $alloc['amount'];
                $amountPaid = (string) $alloc['amount_paid'];
                $credits    = (string) $alloc['credits_applied'];
                $total      = (string) $alloc['total_amount'];

                // S-FIX-2 D-E: status guard. If the invoice is currently void /
                // written_off / soft-deleted, the void/writeoff/delete path already
                // reversed the counters. Skip the OB re-INC and invoice balance
                // updates entirely — touching them now would create a phantom balance.
                $invoiceTerminal = in_array($alloc['invoice_status'], ['void', 'written_off'], true)
                                || $alloc['invoice_deleted_at'] !== null;

                if ($invoiceTerminal) {
                    $revertedStatuses[] = [
                        'invoice_id'     => $alloc['invoice_id'],
                        'invoice_number' => $alloc['invoice_number'],
                        'old_status'     => $alloc['invoice_status'],
                        'new_status'     => $alloc['invoice_status'],
                        'note'           => 'skipped — invoice is void/written_off/deleted (Path B status guard)',
                    ];
                    // Still reverse leases.total_paid — that counter tracks payments,
                    // not OB, and the payment really is being reversed.
                    if ($alloc['lease_id']) {
                        db_execute(
                            "UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?",
                            [$allocated, $alloc['lease_id']]
                        );
                    }
                    continue;
                }

                $newAmountPaid = bcround(bcsub($amountPaid, $allocated, 6), 2);
                if (bccomp($newAmountPaid, '0', 2) < 0) {
                    $newAmountPaid = '0.00';
                }

                // Recompute balance_due = total - credits - new_amount_paid
                $newBalanceDue = bcround(bcsub(bcsub($total, $credits, 6), $newAmountPaid, 6), 2);
                if (bccomp($newBalanceDue, '0', 2) < 0) {
                    $newBalanceDue = '0.00';
                }

                // --------------------------------------------------------------
                // Step 2a: Determine reverted invoice status
                //   - balance_due = 0              → stays 'paid' (other payments cover it)
                //   - 0 < balance_due < total      → 'partially_paid'
                //   - balance_due = total (nothing paid) → 'sent'
                //   - void / written_off are handled above (terminal guard)
                // --------------------------------------------------------------
                $revertedStatus = $alloc['invoice_status']; // default: no change

                if (in_array($alloc['invoice_status'], ['paid', 'partially_paid'], true)) {
                    if (bccomp($newBalanceDue, '0', 2) === 0) {
                        $revertedStatus = 'paid';
                    } elseif (bccomp($newAmountPaid, '0', 2) > 0 || bccomp($credits, '0', 2) > 0) {
                        $revertedStatus = 'partially_paid';
                    } else {
                        // Nothing left applied — revert to 'sent'
                        $revertedStatus = 'sent';
                    }
                }

                $invoiceUpdates = [
                    'amount_paid' => $newAmountPaid,
                    'balance_due' => $newBalanceDue,
                    'status'      => $revertedStatus,
                    'updated_at'  => $now,
                ];
                // Clear paid_date if no longer fully paid
                if ($revertedStatus !== 'paid') {
                    $invoiceUpdates['paid_date'] = null;
                }

                db_update('invoices', $invoiceUpdates, 'id = ?', [$alloc['invoice_id']]);

                if ($revertedStatus !== $alloc['invoice_status']) {
                    $revertedStatuses[] = [
                        'invoice_id'     => $alloc['invoice_id'],
                        'invoice_number' => $alloc['invoice_number'],
                        'old_status'     => $alloc['invoice_status'],
                        'new_status'     => $revertedStatus,
                    ];
                }

                // --------------------------------------------------------------
                // Step 3: Reverse leases.total_paid (Trap 6)
                // --------------------------------------------------------------
                if ($alloc['lease_id']) {
                    db_execute(
                        "UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?",
                        [$allocated, $alloc['lease_id']]
                    );
                }

                // --------------------------------------------------------------
                // Step 4: Reverse customers.outstanding_balance (Trap 6)
                //         Balance goes back UP when we remove the payment.
                //         Path B: only re-INC on non-terminal invoice statuses
                //         (terminal case is handled by the guard above).
                //         leases.outstanding_balance is intentionally NOT touched —
                //         payments do not DEC it, so reversing payments must not
                //         INC it either (pre-existing asymmetry, Path B invariant).
                // --------------------------------------------------------------
                if ($alloc['customer_id']) {
                    db_execute(
                        "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
                        [$allocated, $alloc['customer_id']]
                    );
                }
            }

            // ------------------------------------------------------------------
            // Step 5: Audit log inside transaction
            // ------------------------------------------------------------------
            $invoiceList = implode(', ', array_column($allocations, 'invoice_number'));
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'payments',
                'entity_type'  => 'payment',
                'entity_id'    => $id,
                'entity_label' => $payment['payment_number'],
                'notes'        => "Payment {$payment['payment_number']} soft-deleted via bulk_delete. "
                                . "Reason: {$reason}. Reversed allocations for: {$invoiceList}.",
                'ip_address'   => $ipAddress,
            ]);
        });

        // ── In-app notification (NOTIF-1, best-effort outside txn) ───────────
        try {
            $companyName = '';
            if (!empty($payment['customer_id'])) {
                $cust = db_row("SELECT company_name FROM customers WHERE id = ?", [$payment['customer_id']]);
                $companyName = $cust['company_name'] ?? '';
            }
            \FleetForge\Notifications\NotificationService::notify(
                type:       'payment.reversed',
                title:      "Payment {$payment['payment_number']} reversed",
                message:    "Payment {$payment['payment_number']} reversed"
                            . ($companyName ? " for {$companyName}" : '')
                            . ". Reason: {$reason}",
                entityType: 'payment',
                entityId:   $id,
                url:        '/fleetforge/payments',
                severity:   'warning'
            );
        } catch (\Throwable $e) {
            // Notification failure must never abort the bulk run.
            error_log('[NOTIF payment.reversed bulk] id=' . $id . ' — ' . $e->getMessage());
        }

        // ── QBO sync enqueue (S-QBO-PUSHVOID-TRIO / F7, best-effort) ─────────
        // After the transaction commits (deleted_at is now set), enqueue a void
        // signal so QBO mirrors the reversal. Mirrors delete.php §6.9 contract.
        try {
            \FleetForge\QboPushers\PaymentEnqueuer::enqueue((int) $id, 'void');
        } catch (\Throwable $e) {
            error_log('[QBO enqueue payment void bulk] id=' . $id . ' — ' . $e->getMessage());
        }

        $actioned++;

    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to the caller.
        error_log('bulk_delete payments: id=' . $id . ' failed: ' . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Delete failed due to a server error.'];
    }
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
