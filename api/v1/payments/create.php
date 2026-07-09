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
// 1. Input validation — VALID-2: accumulate errors into $fields, one 422
// -----------------------------------------------------------------------
$body   = json_body();
$fields = [];

$invoiceId = clean_int($body['invoice_id'] ?? null);
if (!$invoiceId) {
    $fields['invoice_id'] = 'Please select an invoice.';
}

// VALID-2: differentiate missing / negative / zero amounts with specific messages
$rawAmount = $body['amount'] ?? null;
$amountRaw = clean_decimal($rawAmount);
if ($rawAmount === null || $rawAmount === '') {
    $fields['amount'] = 'Please enter a payment amount.';
} elseif ($amountRaw === null) {
    $fields['amount'] = 'Please enter a valid payment amount.';
} elseif (bccomp($amountRaw, '0', 6) < 0) {
    $fields['amount'] = 'Payment amount cannot be negative.';
} elseif (bccomp($amountRaw, '0', 6) === 0) {
    $fields['amount'] = 'Payment amount must be greater than zero.';
}

$currency = strtoupper(clean_string($body['currency'] ?? 'CAD') ?? 'CAD');
if (!in_array($currency, ['CAD', 'USD'], true)) {
    $fields['currency'] = 'Currency must be CAD or USD.';
}

$paymentMethod = clean_string($body['payment_method'] ?? null);
$validMethods  = ['check', 'ach', 'wire', 'credit_card', 'cash', 'e_transfer', 'account_credit', 'other'];
if (!$paymentMethod) {
    $fields['payment_method'] = 'Please select a payment method.';
} elseif (!in_array($paymentMethod, $validMethods, true)) {
    $fields['payment_method'] = 'Invalid payment method.';
}

$paymentDate = clean_date($body['payment_date'] ?? null);
if (!$paymentDate) {
    $fields['payment_date'] = 'Payment date is required.';
}

// Short-circuit if required-field errors — cross-field checks need these values
if ($fields) {
    json_validation_error($fields);
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
            customer_id, lease_id, invoice_number, total_amount,
            company_name_snapshot
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);
if (!$invoiceCheck) {
    json_error('NOT_FOUND', 'Invoice not found.', 404,
        ['fields' => ['invoice_id' => 'Invoice not found.']]);
}

// VALID-2: accumulate cross-field errors (currency, status, balance) before returning
$crossFieldErrors = [];

// D18: Payment currency must match invoice currency
if ($currency !== $invoiceCheck['currency']) {
    $crossFieldErrors['currency'] =
        "Payment currency must match invoice currency ({$invoiceCheck['currency']}).";
}

// Cannot pay a void invoice
if ($invoiceCheck['status'] === 'void') {
    $crossFieldErrors['invoice_id'] = 'Cannot allocate a payment to a voided invoice.';
}

// Cannot pay an already-paid invoice
if ($invoiceCheck['status'] === 'paid') {
    $crossFieldErrors['invoice_id'] = 'This invoice is already fully paid.';
}

// S-FIX-2 Bug #2: overpayments are NO LONGER rejected. When amount > balance_due
// AND currency matches, the excess is auto-routed to a credit_notes row with
// source='overpayment' (see transaction body below). Currency mismatch + overage
// still produces 422 CURRENCY_MISMATCH because mixed-currency overpayment has no
// clean rounding pattern (and the FX gain/loss should be the user's call, not ours).
if ($crossFieldErrors) {
    json_validation_error($crossFieldErrors);
}

// CURRENCY-MARKUP-1: freeze exchange rate + markup for USD payments at receipt time
$paymentExchangeRate = null;
$paymentMarkupPct    = '0.0000';
$paymentAmountInCad  = null;
if ($currency === 'USD') {
    $fxRow = db_row(
        "SELECT rate FROM exchange_rates WHERE from_currency = 'USD' AND to_currency = 'CAD' ORDER BY rate_date DESC, id DESC LIMIT 1",
        []
    );
    if ($fxRow) {
        $paymentExchangeRate = (string) $fxRow['rate'];
        $paymentMarkupPct    = (string) (settings_get('currency.usd_cad_markup_pct', '0.0000') ?? '0.0000');
        if (bccomp($paymentMarkupPct, '0', 4) > 0) {
            $_pFactor           = bcadd('1', bcdiv($paymentMarkupPct, '100', 10), 10);
            $_pEffRate          = bcmul($paymentExchangeRate, $_pFactor, 6);
            $paymentAmountInCad = bcmul($amountRaw, $_pEffRate, 2);
        } else {
            $paymentAmountInCad = bcmul($amountRaw, $paymentExchangeRate, 2);
        }
    }
}

// -----------------------------------------------------------------------
// 3. Main transaction — lock, allocate, update counters, transition status
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $invoiceId, $amountRaw, $currency, $paymentMethod, $paymentDate,
    $referenceNumber, $bankName, $checkNumber, $cardLastFour, $notes,
    $internalNotes, $invoiceCheck, &$result,
    $paymentExchangeRate, $paymentMarkupPct, $paymentAmountInCad
) {
    // ------------------------------------------------------------------
    // 3a. Re-fetch invoice WITH FOR UPDATE (D20 — prevents race condition
    //     where two simultaneous requests both see balance_due > 0)
    // ------------------------------------------------------------------
    $invoice = db_row(
        "SELECT id, status, currency, balance_due, amount_paid, credits_applied,
                customer_id, lease_id, invoice_number, total_amount,
                company_name_snapshot, customer_name_snapshot,
                billing_address_snapshot, province_snapshot, customer_email_snapshot
         FROM invoices WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$invoiceId]
    );
    if (!$invoice) {
        json_error('NOT_FOUND', 'Invoice not found.', 404);
    }

    // D18 re-check inside lock (paranoid but correct)
    if ($currency !== $invoice['currency']) {
        json_error('CURRENCY_MISMATCH',
            "Payment currency must match invoice currency ({$invoice['currency']}).", 422,
            ['fields' => ['currency' => "Payment currency must match invoice currency ({$invoice['currency']})."]]);
    }
    if ($invoice['status'] === 'void') {
        json_error('INVOICE_VOID', 'Cannot allocate a payment to a voided invoice.', 422,
            ['fields' => ['invoice_id' => 'Cannot allocate a payment to a voided invoice.']]);
    }

    $balanceDue = (string) $invoice['balance_due'];  // bcmath string

    // S-FIX-2 Bug #2: split-allocation. If amount <= balance_due, allocate the
    // full payment and overpayment is zero. If amount > balance_due, allocate
    // exactly balance_due to the invoice and route the remainder to a
    // credit_notes row with source='overpayment' (created later in this txn).
    if (bccomp($amountRaw, $balanceDue, 6) > 0) {
        $allocatedAmount   = bcround($balanceDue, 2);
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
        'notes'                => $notes,
        'internal_notes'       => $internalNotes,
        'exchange_rate_to_cad' => $paymentExchangeRate,
        'currency_markup_pct'  => $paymentMarkupPct,
        'amount_in_cad'        => $paymentAmountInCad,
        'recorded_by'          => current_user_id(),
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
        json_error('ALLOCATION_EXCEEDS_BALANCE',
            'Invoice cannot accept further payments in its current status.', 422,
            ['fields' => ['invoice_id' => 'Invoice cannot accept further payments in its current status.']]);
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
    $auditNote = "Payment {$paymentNumber} ({$currency} {$allocatedAmount}) applied to invoice {$invoice['invoice_number']}. Invoice status: {$newInvoiceStatus}.";
    if (bccomp($overpaymentAmount, '0', 2) > 0) {
        $auditNote .= " Overpayment {$currency} {$overpaymentAmount} routed to customer credit (S-FIX-2 Bug #2).";
    }
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'payments',
        'entity_type'  => 'payment',
        'entity_id'    => $paymentId,
        'entity_label' => $paymentNumber,
        'notes'        => $auditNote,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    // S-FIX-2 Bug #2: if there is an overpayment, create the credit_notes row
    // (source='overpayment') and refresh the payment row's overpayment fields.
    // Then post the 3-line JE via onOverpaymentReceived() instead of the regular
    // 2-line onPaymentReceived(). Both events stay inside this transaction —
    // any failure rolls back the entire payment.
    $creditNoteOut = null;
    if (bccomp($overpaymentAmount, '0', 2) > 0) {
        // The INSERT above already wrote overpayment_amount + overpayment_action;
        // mark resolved=1 because we are auto-routing the overpayment to a
        // credit_notes row in this same transaction (no staff follow-up needed).
        db_update('payments', [
            'overpayment_resolved' => 1,
        ], 'id = ?', [$paymentId]);

        // S-AUDIT-LIFECYCLE-1 #24e: shared gap-free minting helper (D15) —
        // was the FIFTH verbatim copy of this pattern.
        $cnNumber = ff_next_credit_note_number();

        $cnId = db_insert('credit_notes', [
            'credit_note_number'      => $cnNumber,
            'company_name_snapshot'   => $invoice['company_name_snapshot']   ?? null,
            'customer_name_snapshot'  => $invoice['customer_name_snapshot']  ?? null,
            'billing_address_snapshot' => $invoice['billing_address_snapshot'] ?? null,
            'province_snapshot'        => $invoice['province_snapshot']        ?? null,
            'customer_email_snapshot'  => $invoice['customer_email_snapshot']  ?? null,
            'customer_id'             => $invoice['customer_id'],
            'lease_id'                => $invoice['lease_id'],
            'source'                  => 'overpayment',
            'source_invoice_id'       => $invoiceId,
            'source_payment_id'       => $paymentId,
            'amount'                  => $overpaymentAmount,
            'currency'                => $currency,
            'amount_remaining'        => $overpaymentAmount,
            'status'                  => 'active',
            'reason'                  => "Overpayment from payment {$paymentNumber} (received {$currency} {$amountRaw}, invoice balance was {$currency} {$balanceDue})",
            'created_by'              => current_user_id(),
        ]);

        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => current_user()['name'] ?? 'System',
            'action'       => 'create',
            'module'       => 'invoices',
            'entity_type'  => 'credit_note',
            'entity_id'    => $cnId,
            'entity_label' => $cnNumber,
            'notes'        => "Auto-created from overpayment on payment {$paymentNumber} (S-FIX-2 Bug #2). Source: overpayment, amount: {$currency} {$overpaymentAmount}.",
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        $creditNoteOut = [
            'id'                 => $cnId,
            'credit_note_number' => $cnNumber,
            'amount'             => $overpaymentAmount,
            'currency'           => $currency,
            'status'             => 'active',
            'source'             => 'overpayment',
        ];

        // 3-line JE: DR Cash full / CR AR allocated / CR 2060 Liability overpayment.
        // We DO NOT call onCreditNoteIssued() here — its DR Revenue / CR 2060
        // pattern would double-count the liability we already credited.
        \FleetForge\Accounting\AutoEntryBridge::onOverpaymentReceived(
            $paymentId,
            $invoiceId,
            $allocatedAmount,
            $overpaymentAmount,
            current_user_id()
        );
    } else {
        // Standard payment, no overpayment — 2-line JE: DR Cash / CR AR.
        // WHY: Inside same transaction — JE failure rolls back the payment (A8, §16)
        \FleetForge\Accounting\AutoEntryBridge::onPaymentReceived(
            $paymentId,
            $invoiceId,
            $allocatedAmount,
            current_user_id()
        );
    }

    // Return values to outer scope
    $result = [
        'id'               => $paymentId,
        'payment_number'   => $paymentNumber,
        'amount'           => $amountRaw,
        'allocated_amount' => $allocatedAmount,
        'overpayment'      => $overpaymentAmount,
        'credit_note'      => $creditNoteOut,
        'invoice_id'       => $invoiceId,
        'invoice_number'   => $invoice['invoice_number'],
        'invoice_status'   => $newInvoiceStatus,
        'balance_due'      => $newBalanceDue,
    ];

    // ── In-app notifications (NOTIF-1) ─────────────────────────
    // Three notifications fire from a payment.create call:
    //   1. payment.received — staff with payments view
    //   2. invoice.paid OR invoice.partially_paid — depends on new status
    //   3. portal payment.received — confirms to the customer
    try {
        $companyName = $invoiceCheck['company_name_snapshot'] ?? 'customer';
        $amtFmt      = '$' . number_format((float) $amountRaw, 2);

        \FleetForge\Notifications\NotificationService::notify(
            type:       'payment.received',
            title:      "Payment received from {$companyName}",
            message:    "Payment {$paymentNumber} of {$amtFmt} received from {$companyName} — {$paymentMethod}",
            entityType: 'payment',
            entityId:   (int) $paymentId,
            url:        '/fleetforge/payments/show?id=' . $paymentId
        );

        if ($newInvoiceStatus === 'paid') {
            \FleetForge\Notifications\NotificationService::notify(
                type:       'invoice.paid',
                title:      "Invoice {$invoice['invoice_number']} paid",
                message:    "Invoice {$invoice['invoice_number']} paid in full by {$companyName}",
                entityType: 'invoice',
                entityId:   (int) $invoiceId,
                url:        '/fleetforge/invoices/show?id=' . $invoiceId
            );
        } else {
            \FleetForge\Notifications\NotificationService::notify(
                type:       'invoice.partially_paid',
                title:      "Partial payment received",
                message:    "Partial payment received on invoice {$invoice['invoice_number']} ({$amtFmt})",
                entityType: 'invoice',
                entityId:   (int) $invoiceId,
                url:        '/fleetforge/invoices/show?id=' . $invoiceId
            );
        }

        // Portal confirmation
        if (!empty($invoiceCheck['customer_id'])) {
            \FleetForge\Notifications\NotificationService::notifyPortal(
                type:       'payment.received',
                customerId: (int) $invoiceCheck['customer_id'],
                title:      "Payment received — thank you!",
                message:    "Payment received. Invoice {$invoice['invoice_number']} is now {$newInvoiceStatus}.",
                entityType: 'invoice',
                entityId:   (int) $invoiceId,
                url:        '/fleetforge/portal/invoices/view?id=' . $invoiceId
            );
        }
    } catch (\Throwable $e) {
        error_log('[NOTIF payment.received] ' . $e->getMessage());
    }
});

// ── QBO sync enqueue (S-QBO-14 / Phase QBO-6 / 2 of 3) ──────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws; silent reject
// when sync_enabled=0 or origin != 'ff_native'. Defense-in-depth with
// PaymentPusher::pushImpl origin filter (D-QBO-14-1; closes D-QBO-13-1/2
// bidirectional dedup invariant). Mirrors send.php (S-QBO-11) and
// bills/approve.php (S-QBO-18) hook pattern: AFTER db_transaction commits,
// BEFORE json_success — failure here must not break the FF payment flow.
if (!empty($result['id'])) {
    \FleetForge\QboPushers\PaymentEnqueuer::enqueue((int) $result['id'], 'create');
}

json_success($result, 201);
