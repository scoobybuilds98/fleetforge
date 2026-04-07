<?php
declare(strict_types=1);

/**
 * api/v1/payments/create.php
 *
 * Record a payment and allocate it to one invoice. Everything lives in a single
 * transaction to guarantee consistency between the payment row, the allocation,
 * the invoice status transition, and all four denormalized counters.
 *
 * Business rules enforced here:
 *   - D18:   Payment currency MUST match invoice currency → 422 CURRENCY_MISMATCH
 *   - D20:   FOR UPDATE on invoice row prevents concurrent over-allocation race
 *   - D16:   All monetary math via bcmath strings — never float
 *   - Trap 6: invoices.amount_paid / balance_due, leases.total_paid,
 *             customers.outstanding_balance — ALL updated in the SAME transaction
 *   - Invoice state machine: sent → partially_paid, partially_paid → paid
 *   - Payment number: PAY-YYYY-NNNNN, gap-free via FOR UPDATE on settings row (D15)
 *   - D13/D5: payment row is soft-delete only — never hard-deleted
 *
 * @method  POST
 * @body    JSON: invoice_id (required), amount (required), payment_method (required),
 *               payment_date (required), currency (required), reference_number,
 *               bank_name, check_number, card_last_four, notes, internal_notes
 * @auth    Session required; require_permission('payments','create')
 * @returns 201 { id, payment_number, amount, invoice_status, balance_due }
 *
 * Decisions: D5/D13/D15/D16/D18/D20, Trap 6, §12 Invoice State Machine
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'create');

// -----------------------------------------------------------------------
// 1. Input validation
// -----------------------------------------------------------------------
$body = json_body();

$invoiceId = clean_int($body['invoice_id'] ?? null);
if (!$invoiceId) {
    json_error('MISSING_REQUIRED', 'invoice_id is required.', 422);
}

$amountRaw = clean_decimal($body['amount'] ?? null);
if ($amountRaw === null || bccomp($amountRaw, '0', 6) <= 0) {
    json_error('VALIDATION_ERROR', 'amount must be a positive number.', 422);
}

$currency = strtoupper(clean_string($body['currency'] ?? 'CAD') ?? 'CAD');
if (!in_array($currency, ['CAD', 'USD'], true)) {
    json_error('VALIDATION_ERROR', 'currency must be CAD or USD.', 422);
}

$paymentMethod = clean_string($body['payment_method'] ?? null);
$validMethods  = ['check', 'ach', 'wire', 'credit_card', 'cash', 'e_transfer', 'account_credit', 'other'];
if (!$paymentMethod || !in_array($paymentMethod, $validMethods, true)) {
    json_error('VALIDATION_ERROR', 'payment_method is required and must be one of: ' . implode(', ', $validMethods) . '.', 422);
}

$paymentDate = clean_date($body['payment_date'] ?? null);
if (!$paymentDate) {
    json_error('VALIDATION_ERROR', 'payment_date is required (YYYY-MM-DD).', 422);
}

// Optional metadata
$referenceNumber = clean_string($body['reference_number'] ?? null, 100);
$bankName        = clean_string($body['bank_name'] ?? null, 100);
$checkNumber     = clean_string($body['check_number'] ?? null, 50);
$cardLastFour    = clean_string($body['card_last_four'] ?? null, 4);
$notes           = clean_string($body['notes'] ?? null, 2000);
$internalNotes   = clean_string($body['internal_notes'] ?? null, 2000);

// -----------------------------------------------------------------------
// 2. Pre-flight checks (outside transaction — fast-fail before locking)
// -----------------------------------------------------------------------

// Verify the invoice exists
$invoiceCheck = db_row(
    "SELECT id, status, currency, balance_due, amount_paid, credits_applied,
            customer_id, lease_id, invoice_number, total_amount
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invoiceCheck) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// D18: Payment currency must match invoice currency
if ($currency !== $invoiceCheck['currency']) {
    json_error(
        'CURRENCY_MISMATCH',
        "Payment currency ({$currency}) does not match invoice currency ({$invoiceCheck['currency']}). " .
        "Record a currency-matched payment or use the FX conversion workflow in the accounting module.",
        422
    );
}

// Cannot pay a void invoice
if ($invoiceCheck['status'] === 'void') {
    json_error('INVOICE_VOID', 'Cannot allocate a payment to a voided invoice.', 422);
}

// Cannot pay an already-paid invoice
if ($invoiceCheck['status'] === 'paid') {
    json_error('ALLOCATION_EXCEEDS_BALANCE', 'This invoice is already fully paid.', 422);
}

// -----------------------------------------------------------------------
// 3. Main transaction — lock, allocate, update counters, transition status
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $invoiceId, $amountRaw, $currency, $paymentMethod, $paymentDate,
    $referenceNumber, $bankName, $checkNumber, $cardLastFour, $notes,
    $internalNotes, $invoiceCheck, &$result
) {
    // ------------------------------------------------------------------
    // 3a. Re-fetch invoice WITH FOR UPDATE (D20 — prevents race condition
    //     where two simultaneous requests both see balance_due > 0)
    // ------------------------------------------------------------------
    $invoice = db_row(
        "SELECT id, status, currency, balance_due, amount_paid, credits_applied,
                customer_id, lease_id, invoice_number, total_amount
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) {
        json_error('NOT_FOUND', 'Invoice not found.', 404);
    }

    // D18 re-check inside lock (paranoid but correct)
    if ($currency !== $invoice['currency']) {
        json_error('CURRENCY_MISMATCH', 'Currency mismatch.', 422);
    }
    if ($invoice['status'] === 'void') {
        json_error('INVOICE_VOID', 'Cannot allocate a payment to a voided invoice.', 422);
    }

    $balanceDue = (string) $invoice['balance_due'];  // bcmath string

    // D20: Prevent over-allocation — amount must not exceed current balance_due
    if (bccomp($amountRaw, $balanceDue, 6) > 0) {
        // Overpayment: allow it but track the overpayment_amount
        // (spec §7.8: "Overpayment creates credit" — we record it, credit_note created separately)
        $allocatedAmount   = $balanceDue;    // apply only up to balance
        $overpaymentAmount = bcround(bcsub($amountRaw, $balanceDue, 6), 2);
    } else {
        $allocatedAmount   = bcround($amountRaw, 2);
        $overpaymentAmount = '0.00';
    }

    // ------------------------------------------------------------------
    // 3b. Generate gap-free payment number via FOR UPDATE on settings row
    //     Pattern: PAY-YYYY-NNNNN (same atomic counter pattern as invoices D15)
    // ------------------------------------------------------------------
    $year = date('Y');
    $key  = "payment.next_number.{$year}";

    $settingsRow = db_row(
        "SELECT `key`, `value` FROM settings WHERE `key` = ? FOR UPDATE",
        [$key]
    );
    $next          = $settingsRow ? (int) $settingsRow['value'] : 1;
    // WHY: prefix from settings so admin can rebrand without code change
    $prefix        = settings_get('payment.prefix', 'PAY');
    $paymentNumber = sprintf('%s-%s-%05d', $prefix, $year, $next);

    if ($settingsRow) {
        db_execute(
            "UPDATE settings SET `value` = ? WHERE `key` = ?",
            [$next + 1, $key]
        );
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, `group_name`) VALUES (?, ?, 'payments')",
            [$key, $next + 1]
        );
    }

    // ------------------------------------------------------------------
    // 3c. Insert payment row
    // ------------------------------------------------------------------
    $paymentId = db_insert('payments', [
        'payment_number'   => $paymentNumber,
        'customer_id'      => $invoice['customer_id'],
        'amount'           => $amountRaw,            // total received (may include overpayment)
        'currency'         => $currency,
        'payment_method'   => $paymentMethod,
        'reference_number' => $referenceNumber,
        'bank_name'        => $bankName,
        'check_number'     => $checkNumber,
        'card_last_four'   => $cardLastFour,
        'payment_date'     => $paymentDate,
        'received_at'      => date('Y-m-d H:i:s'),
        'status'           => 'cleared',
        'overpayment_amount'   => $overpaymentAmount,
        'overpayment_action'   => bccomp($overpaymentAmount, '0', 2) > 0 ? 'credit_to_account' : null,
        'overpayment_resolved' => 0,
        'notes'            => $notes,
        'internal_notes'   => $internalNotes,
        'recorded_by'      => current_user_id(),
    ]);

    // ------------------------------------------------------------------
    // 3d. Insert payment_allocation row
    // ------------------------------------------------------------------
    db_insert('payment_allocations', [
        'payment_id'      => $paymentId,
        'invoice_id'      => $invoiceId,
        'amount'          => $allocatedAmount,
        'currency'        => $currency,
        'allocation_type' => 'auto',
        'allocated_by'    => current_user_id(),
    ]);

    // ------------------------------------------------------------------
    // 3e. Update invoice financial counters (Trap 6 — same transaction)
    //     invoices.amount_paid += allocatedAmount
    //     invoices.balance_due  = total_amount - credits_applied - new_amount_paid
    // ------------------------------------------------------------------
    $newAmountPaid = bcround(bcadd((string) $invoice['amount_paid'], $allocatedAmount, 6), 2);
    $newBalanceDue = bcround(
        bcsub(
            bcsub((string) $invoice['total_amount'], (string) $invoice['credits_applied'], 6),
            $newAmountPaid,
            6
        ),
        2
    );
    // Clamp to 0 — floating-point remnants from bcmath are impossible but defensive
    if (bccomp($newBalanceDue, '0', 2) < 0) {
        $newBalanceDue = '0.00';
    }

    // ------------------------------------------------------------------
    // 3f. Determine new invoice status (§12 Invoice State Machine)
    //     sent | overdue | partially_paid  →  partially_paid  (balance > 0)
    //     any  →  paid  (balance = 0)
    // ------------------------------------------------------------------
    $validPayableStatuses = ['sent', 'partially_paid', 'overdue'];
    if (!in_array($invoice['status'], $validPayableStatuses, true)) {
        // Guard: already paid or in invalid state (should not reach here due to pre-flight)
        json_error('ALLOCATION_EXCEEDS_BALANCE', 'Invoice cannot accept further payments in its current status.', 422);
    }

    $newInvoiceStatus = (bccomp($newBalanceDue, '0', 2) === 0) ? 'paid' : 'partially_paid';

    $invoiceUpdates = [
        'amount_paid' => $newAmountPaid,
        'balance_due' => $newBalanceDue,
        'status'      => $newInvoiceStatus,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];
    if ($newInvoiceStatus === 'paid') {
        $invoiceUpdates['paid_date'] = date('Y-m-d');
    }

    db_update('invoices', $invoiceUpdates, 'id = ?', [$invoiceId]);

    // ------------------------------------------------------------------
    // 3g. Update leases.total_paid (Trap 6 — same transaction)
    // ------------------------------------------------------------------
    if ($invoice['lease_id']) {
        db_execute(
            "UPDATE leases SET total_paid = total_paid + ?, updated_at = NOW() WHERE id = ?",
            [$allocatedAmount, $invoice['lease_id']]
        );
    }

    // ------------------------------------------------------------------
    // 3h. Update customers.outstanding_balance (Trap 6 — same transaction)
    //     outstanding_balance decreases by the amount actually applied to the invoice
    // ------------------------------------------------------------------
    if ($invoice['customer_id']) {
        db_execute(
            "UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?), updated_at = NOW() WHERE id = ?",
            [$allocatedAmount, $invoice['customer_id']]
        );
    }

    // ------------------------------------------------------------------
    // 3i. Audit log — inside same transaction (FIX #19 pattern)
    // ------------------------------------------------------------------
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'payments',
        'entity_type'  => 'payment',
        'entity_id'    => $paymentId,
        'entity_label' => $paymentNumber,
        'notes'        => "Payment {$paymentNumber} ({$currency} {$allocatedAmount}) applied to invoice {$invoice['invoice_number']}. Invoice status: {$newInvoiceStatus}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // Auto-JE: DR 1010 Cash / CR 1030 AR for the allocated amount
    // WHY: Inside same transaction — JE failure rolls back the payment (A8, §16)
    \FleetForge\Accounting\AutoEntryBridge::onPaymentReceived(
        $paymentId,
        $invoiceId,
        $allocatedAmount,
        current_user_id()
    );

    // Return values to outer scope
    $result = [
        'id'               => $paymentId,
        'payment_number'   => $paymentNumber,
        'amount'           => $amountRaw,
        'allocated_amount' => $allocatedAmount,
        'overpayment'      => $overpaymentAmount,
        'invoice_id'       => $invoiceId,
        'invoice_number'   => $invoice['invoice_number'],
        'invoice_status'   => $newInvoiceStatus,
        'balance_due'      => $newBalanceDue,
    ];
});

json_success($result, 201);
