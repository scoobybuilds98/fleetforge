<?php
declare(strict_types=1);

/**
 * api/v1/payments/reallocate.php
 *
 * Move all or part of a payment's allocation from one invoice to another
 * invoice of the same customer — SOP known issue I17 ("no screen to
 * re-allocate a customer payment to a different invoice").
 *
 * Ledger: none. Both invoices sit in the same Accounts Receivable account
 * for the same customer, so moving money between them changes no balance.
 * (USD invoices frozen at different rates WOULD move realized FX — refused
 * with a clear message rather than guessed at.) The payment's own cash entry
 * is untouched; this is NOT unallocate + allocate, which would post the cash
 * a second time.
 *
 * Counters kept in step, in one transaction:
 *   - payment_allocations: source reduced (deleted at zero), target added to
 *     (unique per payment + invoice) or inserted;
 *   - both invoices: amount_paid / balance_due / status / paid_date;
 *   - leases.total_paid moves when the invoices belong to different leases;
 *   - customers.outstanding_balance nets to zero (same customer) — untouched.
 * Then the dashboard cache is invalidated and the QuickBooks copy of the
 * payment re-pushed (its lines list the invoices it pays).
 *
 * @method  POST
 * @body    JSON: allocation_id (required), target_invoice_id (required),
 *                amount? (default: the whole allocation)
 * @auth    Session required; require_permission('payments','edit')
 * @returns 200 { payment_number, from_invoice, to_invoice, amount }
 *
 * @session S-SOP-KNOWN-ISSUES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'edit');

$body     = json_body();
$allocId  = clean_int($body['allocation_id'] ?? null);
$targetId = clean_int($body['target_invoice_id'] ?? null);
$amountIn = clean_decimal($body['amount'] ?? null);

$fields = [];
if (!$allocId)  $fields['allocation_id']     = 'Choose the allocation to move.';
if (!$targetId) $fields['target_invoice_id'] = 'Choose the invoice to move it to.';
if ($amountIn !== null && $amountIn !== '' && bccomp($amountIn, '0', 2) <= 0) {
    $fields['amount'] = 'The amount must be greater than zero.';
}
if ($fields) {
    json_validation_error($fields);
}

$result = null;

db_transaction(function () use ($allocId, $targetId, $amountIn, &$result) {
    $alloc = db_row("SELECT * FROM payment_allocations WHERE id = ? FOR UPDATE", [$allocId]);
    if (!$alloc) {
        json_error('NOT_FOUND', 'Allocation not found.', 404, ['fields' => ['allocation_id' => 'Allocation not found.']]);
    }
    $paymentId = (int) $alloc['payment_id'];

    $payment = db_row(
        "SELECT id, payment_number, customer_id, currency, status, origin
           FROM payments WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$paymentId]
    );
    if (!$payment) {
        json_error('NOT_FOUND', 'Payment not found.', 404);
    }
    // Same rule as allocate.php: QuickBooks-owned money is applied in QuickBooks.
    if (in_array($payment['origin'] ?? 'ff_native', ['qbo_payments_webhook', 'qbo_other'], true)) {
        json_error('QBO_OWNED_PAYMENT',
            'This payment came from QuickBooks — re-apply it in QuickBooks and FleetForge will follow.', 422);
    }
    if (in_array($payment['status'], ['void', 'refunded', 'failed', 'returned'], true)) {
        json_error('IMMUTABLE_RECORD', "A {$payment['status']} payment cannot be re-allocated.", 422);
    }

    if ((int) $alloc['invoice_id'] === $targetId) {
        json_validation_error(['target_invoice_id' => 'Choose a different invoice.']);
    }

    // Lock both invoices in id order (no deadlock between two opposite moves).
    $ids = [(int) $alloc['invoice_id'], $targetId];
    sort($ids);
    $rows = [];
    foreach ($ids as $iid) {
        $rows[$iid] = db_row(
            "SELECT id, invoice_number, customer_id, lease_id, currency, exchange_rate_to_cad, status,
                    total_amount, credits_applied, amount_paid, balance_due, deleted_at
               FROM invoices WHERE id = ? FOR UPDATE",
            [$iid]
        );
    }
    $from = $rows[(int) $alloc['invoice_id']];
    $to   = $rows[$targetId];
    if (!$to || $to['deleted_at'] !== null) {
        json_validation_error(['target_invoice_id' => 'Invoice not found.']);
    }
    if (!$from || $from['deleted_at'] !== null || in_array($from['status'], ['void', 'written_off'], true)) {
        json_validation_error(['allocation_id' => 'The invoice this money is on is void, written off or deleted — it cannot be moved.']);
    }
    if ((int) $to['customer_id'] !== (int) $payment['customer_id']) {
        json_validation_error(['target_invoice_id' => "The invoice belongs to a different customer."]);
    }
    if (!in_array($to['status'], ['sent', 'partially_paid', 'overdue'], true)) {
        json_validation_error(['target_invoice_id' => "Invoice {$to['invoice_number']} is '{$to['status']}' — only sent, partially paid or overdue invoices can take a payment."]);
    }
    if ($to['currency'] !== $payment['currency']) {
        json_validation_error(['target_invoice_id' => "Invoice {$to['invoice_number']} is in {$to['currency']}; the payment is in {$payment['currency']}."]);
    }
    if ($payment['currency'] !== 'CAD'
        && bccomp((string) ($from['exchange_rate_to_cad'] ?? '0'), (string) ($to['exchange_rate_to_cad'] ?? '0'), 6) !== 0) {
        json_validation_error(['target_invoice_id' => 'These USD invoices were issued at different exchange rates — moving the payment would change the realized FX. Void and re-record the payment instead.']);
    }

    $amount = ($amountIn === null || $amountIn === '') ? bcadd((string) $alloc['amount'], '0', 2) : $amountIn;
    if (bccomp($amount, (string) $alloc['amount'], 2) > 0) {
        json_validation_error(['amount' => "Only {$alloc['amount']} of this payment is on {$from['invoice_number']}."]);
    }
    if (bccomp($amount, (string) $to['balance_due'], 2) > 0) {
        json_validation_error(['amount' => "Invoice {$to['invoice_number']} only has {$to['balance_due']} left to pay."]);
    }

    // ── Allocation rows ──────────────────────────────────────
    $remaining = bcsub((string) $alloc['amount'], $amount, 2);
    if (bccomp($remaining, '0', 2) === 0) {
        db_execute("DELETE FROM payment_allocations WHERE id = ?", [$allocId]);
    } else {
        db_update('payment_allocations', ['amount' => $remaining], 'id = ?', [$allocId]);
    }
    $existing = db_row(
        "SELECT id, amount FROM payment_allocations WHERE payment_id = ? AND invoice_id = ? FOR UPDATE",
        [$paymentId, $targetId]
    );
    if ($existing) {
        db_update('payment_allocations', ['amount' => bcadd((string) $existing['amount'], $amount, 2)], 'id = ?', [(int) $existing['id']]);
    } else {
        db_insert('payment_allocations', [
            'payment_id'      => $paymentId,
            'invoice_id'      => $targetId,
            'amount'          => $amount,
            'currency'        => $payment['currency'],
            'allocation_type' => 'manual',
            'allocated_by'    => current_user_id(),
        ]);
    }

    // ── Invoice counters ────────────────────────────────────
    $recount = static function (array $inv, string $delta): string {
        // $delta: + adds payment to the invoice, − takes it away.
        $paid = bcadd((string) $inv['amount_paid'], $delta, 2);
        if (bccomp($paid, '0', 2) < 0) $paid = '0.00';
        $due = bcsub(bcsub((string) $inv['total_amount'], (string) $inv['credits_applied'], 2), $paid, 2);
        if (bccomp($due, '0', 2) < 0) $due = '0.00';
        if (bccomp($due, '0', 2) === 0) {
            $status = 'paid';
        } elseif (bccomp($paid, '0', 2) > 0 || bccomp((string) $inv['credits_applied'], '0', 2) > 0) {
            $status = 'partially_paid';
        } else {
            // Unpaid again: back to overdue when it was, else sent (the
            // overdue job re-checks due dates nightly).
            $status = $inv['status'] === 'overdue' ? 'overdue' : 'sent';
        }
        $upd = [
            'amount_paid' => $paid,
            'balance_due' => $due,
            'status'      => $status,
            'updated_at'  => ff_now_utc(),
            'paid_date'   => $status === 'paid' ? ff_today() : null,
        ];
        db_update('invoices', $upd, 'id = ?', [(int) $inv['id']]);
        return $status;
    };
    $fromStatus = $recount($from, bcmul($amount, '-1', 2));
    $toStatus   = $recount($to, $amount);

    // ── Lease totals move only between different leases ─────
    if ((int) ($from['lease_id'] ?? 0) !== (int) ($to['lease_id'] ?? 0)) {
        if ($from['lease_id']) {
            db_execute("UPDATE leases SET total_paid = GREATEST(0, total_paid - ?), updated_at = NOW() WHERE id = ?", [$amount, $from['lease_id']]);
        }
        if ($to['lease_id']) {
            db_execute("UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?", [$amount, $to['lease_id']]);
        }
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'payments',
        'entity_type'  => 'payment_allocation',
        'entity_id'    => $allocId,
        'entity_label' => "{$payment['payment_number']}: {$from['invoice_number']} → {$to['invoice_number']}",
        'notes'        => "Moved {$payment['currency']} {$amount} of {$payment['payment_number']} from {$from['invoice_number']} ({$fromStatus}) to {$to['invoice_number']} ({$toStatus}). No ledger change (same customer AR).",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'payment_id'     => $paymentId,
        'payment_number' => $payment['payment_number'],
        'from_invoice'   => ['id' => (int) $from['id'], 'number' => $from['invoice_number'], 'status' => $fromStatus],
        'to_invoice'     => ['id' => (int) $to['id'], 'number' => $to['invoice_number'], 'status' => $toStatus],
        'amount'         => $amount,
    ];
});

invalidate_dashboard_cache();

// The QuickBooks copy of the payment lists the invoices it pays — re-push.
\FleetForge\QboPushers\PaymentEnqueuer::enqueue((int) $result['payment_id'], 'update');

json_success($result);
