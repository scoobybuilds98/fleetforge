<?php
declare(strict_types=1);

/**
 * api/v1/payments/allocate.php
 *
 * Allocate an existing payment's unapplied/overpayment balance to a different invoice.
 * Use case: payment recorded without a target invoice (or overpayment from a prior invoice)
 * is later applied to an outstanding balance.
 *
 * Business rules:
 *   - D18: Payment currency must match target invoice currency
 *   - D20: FOR UPDATE on target invoice (concurrent allocation race prevention)
 *   - D16: bcmath only — no floats
 *   - Trap 6: invoice + lease + customer counters updated in same transaction
 *   - ALLOCATION_EXCEEDS_BALANCE: cannot allocate more than invoice balance_due
 *
 * @method  POST
 * @body    JSON: payment_id (required), invoice_id (required), amount (required)
 * @auth    Session required; require_permission('payments','create')
 * @returns 201 { allocation_id, payment_number, invoice_number, invoice_status, balance_due }
 *
 * Decisions: D5/D13/D16/D18/D20, Trap 6
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'create');

$body   = json_body();
$fields = [];

$paymentId = clean_int($body['payment_id'] ?? null);
if (!$paymentId) {
    $fields['payment_id'] = 'Please select a payment.';
}

$invoiceId = clean_int($body['invoice_id'] ?? null);
if (!$invoiceId) {
    $fields['invoice_id'] = 'Please select an invoice.';
}

// VALID-2: differentiate missing / negative / zero amounts
$rawAmount = $body['amount'] ?? null;
$amountRaw = clean_decimal($rawAmount);
if ($rawAmount === null || $rawAmount === '') {
    $fields['amount'] = 'Please enter an allocation amount.';
} elseif ($amountRaw === null) {
    $fields['amount'] = 'Please enter a valid allocation amount.';
} elseif (bccomp($amountRaw, '0', 6) < 0) {
    $fields['amount'] = 'Allocation amount cannot be negative.';
} elseif (bccomp($amountRaw, '0', 6) === 0) {
    $fields['amount'] = 'Allocation amount must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
}

// --- Load payment ---
$payment = db_row(
    "SELECT id, payment_number, customer_id, amount, currency, status
     FROM payments WHERE id = ? AND deleted_at IS NULL",
    [$paymentId]
);
if (!$payment) {
    json_error('NOT_FOUND', 'Payment not found.', 404,
        ['fields' => ['payment_id' => 'Payment not found.']]);
}

if ($payment['status'] === 'void' || $payment['status'] === 'refunded') {
    json_error('IMMUTABLE_RECORD',
        'Cannot allocate a voided or refunded payment.', 422,
        ['fields' => ['payment_id' => 'Cannot allocate a voided or refunded payment.']]);
}

// --- Pre-flight check on invoice ---
$invoiceCheck = db_row(
    "SELECT id, status, currency, balance_due, invoice_number FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invoiceCheck) {
    json_error('NOT_FOUND', 'Invoice not found.', 404,
        ['fields' => ['invoice_id' => 'Invoice not found.']]);
}

// VALID-2: accumulate cross-field errors (currency / status / balance)
$crossFieldErrors = [];

// D18: currency match
if ($payment['currency'] !== $invoiceCheck['currency']) {
    $crossFieldErrors['invoice_id'] =
        "Payment currency must match invoice currency ({$invoiceCheck['currency']}).";
}

// S-AUDIT-LIFECYCLE-1 #8: only ISSUED invoices are payable — the old gate
// rejected just void/paid, so a DRAFT could be allocated a payment, flipping
// it to paid without ever being sent (no Path-B counters, no revenue JE, no
// QBO push). Mirror payments/create.php's validPayableStatuses.
$validPayableStatuses = ['sent', 'partially_paid', 'overdue'];
if ($invoiceCheck['status'] === 'paid') {
    $crossFieldErrors['invoice_id'] = 'This invoice is already fully paid.';
} elseif (!in_array($invoiceCheck['status'], $validPayableStatuses, true)) {
    $crossFieldErrors['invoice_id'] =
        "Cannot allocate a payment to a '{$invoiceCheck['status']}' invoice — only sent, partially paid, or overdue invoices are payable.";
}

// Allocation amount must not exceed balance due
if (empty($crossFieldErrors['invoice_id'])
    && bccomp($amountRaw, (string) $invoiceCheck['balance_due'], 6) > 0) {
    $balanceFormatted = '$' . number_format((float) $invoiceCheck['balance_due'], 2);
    $crossFieldErrors['amount'] =
        "Allocation amount exceeds invoice balance of {$balanceFormatted}.";
}

if ($crossFieldErrors) {
    json_validation_error($crossFieldErrors);
}

// Check this payment is not already allocated to this invoice
$existingAlloc = db_row(
    "SELECT id FROM payment_allocations WHERE payment_id = ? AND invoice_id = ?",
    [$paymentId, $invoiceId]
);
if ($existingAlloc) {
    json_error('ALREADY_EXISTS',
        'This payment already has an allocation for the specified invoice.', 409,
        ['fields' => ['invoice_id' => 'This payment already has an allocation for the specified invoice.']]);
}

$result = null;

db_transaction(function () use ($paymentId, $invoiceId, $amountRaw, $payment, &$result) {
    // FOR UPDATE on invoice (D20)
    $invoice = db_row(
        "SELECT id, status, currency, balance_due, amount_paid, credits_applied,
                total_amount, customer_id, lease_id, invoice_number
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) {
        json_error('NOT_FOUND', 'Invoice not found.', 404);
    }

    // WAVE 1 / lock the payment row too (consistent order: invoice then payment)
    // so two concurrent allocations of the same payment can't both pass the
    // cumulative-balance bound below.
    $paymentLocked = db_row(
        "SELECT customer_id, amount FROM payments WHERE id = ? FOR UPDATE",
        [$paymentId]
    );
    if (!$paymentLocked) {
        json_error('NOT_FOUND', 'Payment not found.', 404);
    }

    // WAVE 1 (cross-customer, Codex): a payment may only be applied to an
    // invoice belonging to the SAME customer. The endpoint loaded both
    // customer_ids but never compared them, so Customer A's payment could be
    // applied to Customer B's invoice.
    if ($invoice['customer_id'] !== null
        && (int) $paymentLocked['customer_id'] !== (int) $invoice['customer_id']) {
        json_error('CUSTOMER_MISMATCH',
            'This payment belongs to a different customer than the selected invoice.', 422,
            ['fields' => ['invoice_id' => 'This payment belongs to a different customer than the selected invoice.']]);
    }

    // WAVE 1 (C4): bound the CUMULATIVE allocations for this payment against
    // payments.amount. The per-invoice balance check below never saw the running
    // total, so a $100 payment could be allocated $100 to invoice after invoice
    // (each ≤ that invoice's balance), over-crediting outstanding_balance /
    // leases.total_paid and re-introducing the S-FIX-2 drift. uq_payment_invoice
    // only blocks the same (payment,invoice) pair twice — not this.
    $alreadyAllocated = db_row(
        "SELECT COALESCE(SUM(amount), '0') AS s FROM payment_allocations WHERE payment_id = ?",
        [$paymentId]
    )['s'];
    $unapplied = bcsub((string) $paymentLocked['amount'], (string) $alreadyAllocated, 2);
    if (bccomp($amountRaw, $unapplied, 2) > 0) {
        $unappliedFmt = '$' . number_format(max(0, (float) $unapplied), 2);
        json_error('ALLOCATION_EXCEEDS_PAYMENT',
            "Allocation exceeds the payment's unapplied balance of {$unappliedFmt}.", 422,
            ['fields' => ['amount' => "Allocation exceeds the payment's unapplied balance of {$unappliedFmt}."]]);
    }

    // D20: Cannot exceed balance_due (defense in depth; pre-flight already rejected this)
    $balanceDue = (string) $invoice['balance_due'];
    if (bccomp($amountRaw, $balanceDue, 6) > 0) {
        $balanceFormatted = '$' . number_format((float) $balanceDue, 2);
        json_error('ALLOCATION_EXCEEDS_BALANCE',
            "Allocation amount exceeds invoice balance of {$balanceFormatted}.", 422,
            ['fields' => ['amount' => "Allocation amount exceeds invoice balance of {$balanceFormatted}."]]);
    }

    $allocatedAmount = bcround($amountRaw, 2);

    // Insert allocation row
    $allocId = db_insert('payment_allocations', [
        'payment_id'      => $paymentId,
        'invoice_id'      => $invoiceId,
        'amount'          => $allocatedAmount,
        'currency'        => $invoice['currency'],
        'allocation_type' => 'manual',
        'allocated_by'    => current_user_id(),
    ]);

    // Update invoice counters (D16 bcmath)
    $newAmountPaid = bcround(bcadd((string) $invoice['amount_paid'], $allocatedAmount, 6), 2);
    $newBalanceDue = bcround(
        bcsub(
            bcsub((string) $invoice['total_amount'], (string) $invoice['credits_applied'], 6),
            $newAmountPaid,
            6
        ),
        2
    );
    if (bccomp($newBalanceDue, '0', 2) < 0) {
        $newBalanceDue = '0.00';
    }

    $newStatus  = (bccomp($newBalanceDue, '0', 2) === 0) ? 'paid' : 'partially_paid';
    $invUpdates = [
        'amount_paid' => $newAmountPaid,
        'balance_due' => $newBalanceDue,
        'status'      => $newStatus,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
    if ($newStatus === 'paid') {
        $invUpdates['paid_date'] = date('Y-m-d');
    }
    db_update('invoices', $invUpdates, 'id = ?', [$invoiceId]);

    // Reverse lease + customer counters (Trap 6)
    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?",
            [$allocatedAmount, $invoice['lease_id']]
        );
    }
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
            [$allocatedAmount, $invoice['customer_id']]
        );
    }

    // Audit log inside transaction
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'payments',
        'entity_type'  => 'payment_allocation',
        'entity_id'    => $allocId,
        'entity_label' => $payment['payment_number'] . '→' . $invoice['invoice_number'],
        'notes'        => "Manual allocation of {$invoice['currency']} {$allocatedAmount} from {$payment['payment_number']} to {$invoice['invoice_number']}. Invoice status: {$newStatus}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'allocation_id'  => $allocId,
        'payment_number' => $payment['payment_number'],
        'invoice_number' => $invoice['invoice_number'],
        'invoice_status' => $newStatus,
        'balance_due'    => $newBalanceDue,
    ];
});

json_success($result, 201);
