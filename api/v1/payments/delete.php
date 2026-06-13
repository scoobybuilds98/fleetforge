<?php
declare(strict_types=1);

/**
 * api/v1/payments/delete.php
 *
 * Soft-delete a payment and reverse all its effects in a single transaction:
 *   1. Soft-delete the payment row  (D5/D13 — NEVER hard-delete payments)
 *   2. For each allocation, reverse invoices.amount_paid and recompute balance_due
 *   3. Reverse leases.total_paid for each affected lease
 *   4. Reverse customers.outstanding_balance
 *   5. Revert invoice status if it was driven to 'paid' or 'partially_paid'
 *      (paid → partially_paid → sent, depending on remaining allocations)
 *
 * Requires Manager or higher permission. Accountants can record but not void.
 *
 * @method  POST
 * @body    JSON: id (required), reason (required — for audit trail)
 * @auth    Session required; require_permission('payments','delete')
 * @returns 200 { id, deleted: true, invoice_statuses_reverted: [] }
 *
 * Decisions: D5/D13 (soft-delete), D16 (bcmath), Trap 6 (counters in same txn)
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'delete');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$reason = clean_string($body['reason'] ?? null, 500);
if (!$reason) {
    json_error('MISSING_REQUIRED', 'reason is required when deleting a payment.', 422);
}

// Load the payment
$payment = db_row(
    "SELECT id, payment_number, customer_id, amount, currency, status
     FROM payments WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$payment) {
    json_error('NOT_FOUND', 'Payment not found.', 404);
}

// Load all allocations for this payment (to know which invoices/leases to reverse).
// S-FIX-2 Path B / D-E: also pull invoice.deleted_at so we can skip OB re-INC and
// invoice updates for invoices that were voided / written-off / deleted after the
// payment was made (those events already accounted for the counter movement).
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

$revertedStatuses = [];

db_transaction(function () use ($id, $payment, $allocations, $reason, &$revertedStatuses) {
    // ------------------------------------------------------------------
    // Step 1: Soft-delete the payment (D5/D13)
    // ------------------------------------------------------------------
    db_update('payments', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

    // ------------------------------------------------------------------
    // Step 2: Reverse each invoice allocation (Trap 6 — inside transaction)
    // ------------------------------------------------------------------
    foreach ($allocations as $alloc) {
        // Use bcmath to avoid float drift (D16)
        $allocated    = (string) $alloc['amount'];
        $amountPaid   = (string) $alloc['amount_paid'];
        $credits      = (string) $alloc['credits_applied'];
        $total        = (string) $alloc['total_amount'];

        // S-FIX-2 D-E: status guard. If the invoice is currently void / written_off /
        // soft-deleted, the void/writeoff/delete path already reversed the counters.
        // Skip the OB re-INC and the invoice balance/amount_paid update entirely —
        // touching them now would create a phantom balance.
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
            // Still reverse leases.total_paid — that counter tracks payments, not OB,
            // and the payment really is being reversed.
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

        // ------------------------------------------------------------------
        // Step 2a: Determine reverted invoice status
        //   - If balance_due = 0: stays 'paid' (other payments cover it)
        //   - If 0 < balance_due < total: partially_paid
        //   - If balance_due = total (nothing paid): sent
        //   - Do NOT revert void or written_off invoices (handled above)
        // ------------------------------------------------------------------
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
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
        // Clear paid_date if no longer paid
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

        // ------------------------------------------------------------------
        // Step 3: Reverse leases.total_paid (Trap 6)
        // ------------------------------------------------------------------
        if ($alloc['lease_id']) {
            db_execute(
                "UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?",
                [$allocated, $alloc['lease_id']]
            );
        }

        // ------------------------------------------------------------------
        // Step 4: Reverse customers.outstanding_balance (Trap 6)
        //         Balance goes back UP when we remove the payment.
        //         Path B: only re-INC on non-terminal invoice statuses (handled above).
        //         leases.outstanding_balance is intentionally NOT touched here — it
        //         has a pre-existing asymmetry (payments do not DEC it) and the brief
        //         restricts Path B to the customer counter at payment-level moves.
        // ------------------------------------------------------------------
        if ($alloc['customer_id']) {
            db_execute(
                "UPDATE customers SET outstanding_balance = outstanding_balance + ?, updated_at = NOW() WHERE id = ?",
                [$allocated, $alloc['customer_id']]
            );
        }
    }

    // ------------------------------------------------------------------
    // Step 4b: Reverse the GL (Codex). onPaymentReceived / onOverpaymentReceived
    // posted a JE (source_type='payment', source_id=this payment) debiting Cash
    // / crediting AR. Reversing only the operational counters above left that JE
    // posted, so the ledger showed cash still received after the delete. Reverse
    // every still-posted payment JE (a reversing entry dated today, into the
    // open current period) so the subledger and GL stay in lock-step. Idempotent:
    // an already-reversed entry is skipped; any other failure (e.g. no open
    // period to reverse into) aborts the whole delete so we never commit a
    // counter reversal without the matching GL reversal.
    $paymentJes = \db_select(
        "SELECT id FROM acc_journal_entries
          WHERE source_type = 'payment' AND source_id = ? AND status = 'posted'",
        [$id]
    );
    foreach ($paymentJes as $je) {
        try {
            \FleetForge\Accounting\JournalEntryService::reverse((int) $je['id'], null, current_user_id());
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'already been reversed') !== false) {
                continue; // idempotent — nothing to do
            }
            throw $e;     // closed period / no period → roll the whole delete back
        }
    }

    // ------------------------------------------------------------------
    // Step 5: Audit log inside transaction
    // ------------------------------------------------------------------
    $invoiceList = implode(', ', array_column($allocations, 'invoice_number'));
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'delete',
        'module'       => 'payments',
        'entity_type'  => 'payment',
        'entity_id'    => $id,
        'entity_label' => $payment['payment_number'],
        'notes'        => "Payment {$payment['payment_number']} soft-deleted. Reason: {$reason}. Reversed allocations for: {$invoiceList}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        $companyName = '';
        if (!empty($payment['customer_id'])) {
            $cust = \db_row("SELECT company_name FROM customers WHERE id = ?", [$payment['customer_id']]);
            $companyName = $cust['company_name'] ?? '';
        }
        \FleetForge\Notifications\NotificationService::notify(
            type:       'payment.reversed',
            title:      "Payment {$payment['payment_number']} reversed",
            message:    "Payment {$payment['payment_number']} reversed" . ($companyName ? " for {$companyName}" : '') . ". Reason: {$reason}",
            entityType: 'payment',
            entityId:   $id,
            url:        '/fleetforge/payments',
            severity:   'warning'
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF payment.reversed] ' . $e->getMessage());
    }
});

// ── QBO sync enqueue (S-QBO-PUSHVOID-TRIO / F7) ─────────────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws. AFTER the
// db_transaction commits (payment now soft-deleted: deleted_at set), BEFORE
// json_success. Payments have no status='void' — the soft-delete IS the void
// signal (D-QBO-PUSHVOID-TRIO-1); the Enqueuer's gate-0 for op='void'
// requires deleted_at IS NOT NULL. Propagates the void to the mapped QBO
// Payment (PaymentPusher::pushVoid). origin/sync gates still apply.
if (!empty($id)) {
    \FleetForge\QboPushers\PaymentEnqueuer::enqueue((int) $id, 'void');
}

invalidate_dashboard_cache();

json_success([
    'id'                       => $id,
    'deleted'                  => true,
    'invoice_statuses_reverted' => $revertedStatuses,
]);
