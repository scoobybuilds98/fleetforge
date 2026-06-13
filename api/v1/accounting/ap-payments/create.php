<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ap-payments/create.php
 *
 * Create an AP payment and allocate to one or more bills.
 * Posts JE: DR 2010 AP / CR Cash (bank GL account).
 * Supports partial payments — bill stays partially_paid until fully paid.
 * Uses FOR UPDATE on bill rows during allocation (D20).
 *
 * @method  POST
 * @body    vendor_id, bank_account_id, payment_date, payment_method,
 *          reference_number?, check_number?, notes?,
 *          allocations[] (each: bill_id, amount_applied)
 * @auth    Session required; require_permission('accounts_payable','create')
 * @returns 201 { id, payment_number }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (AP payment JE)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'create');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$vendorId       = clean_int($input['vendor_id'] ?? null);
$bankAccountId  = clean_int($input['bank_account_id'] ?? null);
$paymentDate    = clean_date($input['payment_date'] ?? null);
$paymentMethod  = clean_string($input['payment_method'] ?? null);
$referenceNum   = clean_string($input['reference_number'] ?? null);
$checkNumber    = clean_string($input['check_number'] ?? null);
$notes          = clean_string($input['notes'] ?? null, 2000);
$currency       = clean_string($input['currency'] ?? null) ?? 'CAD';

if (!$vendorId)      $fields['vendor_id']       = 'Please select a vendor.';
if (!$bankAccountId) $fields['bank_account_id'] = 'Please select a bank account.';
if (!$paymentDate)   $fields['payment_date']    = 'Payment date is required.';

$validMethods = ['check', 'eft', 'wire', 'credit_card', 'cash', 'other'];
if (!$paymentMethod) {
    $fields['payment_method'] = 'Please select a payment method.';
} elseif (!in_array($paymentMethod, $validMethods, true)) {
    $fields['payment_method'] = 'Please select a valid payment method.';
}

// Validate check_number required for check payments
if ($paymentMethod === 'check' && !$checkNumber) {
    $fields['check_number'] = 'Check number is required for check payments.';
}

if ($fields) {
    json_validation_error($fields);
}

// Validate vendor
$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) {
    json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
}

// Validate bank account
$bankAccount = db_row("SELECT id, gl_account_id, name FROM acc_bank_accounts WHERE id = ? AND is_active = 1", [$bankAccountId]);
if (!$bankAccount) {
    json_validation_error(['bank_account_id' => 'Bank account not found or inactive.'], 'Bank account not found or inactive.');
}

// Parse allocations
$rawAllocations = $input['allocations'] ?? null;
if (is_string($rawAllocations)) {
    $rawAllocations = json_decode($rawAllocations, true);
}
if (!is_array($rawAllocations) || count($rawAllocations) === 0) {
    json_validation_error(['allocations' => 'At least one bill allocation is required.']);
}

// Compute total payment amount from allocations (bcmath — D16)
$totalAmount = '0.00';
$validatedAllocations = [];
// VALID-2: per-allocation errors joined into single `allocations` slot
$allocErrors = [];

foreach ($rawAllocations as $i => $alloc) {
    $allocNum = $i + 1;
    $billId = clean_int($alloc['bill_id'] ?? null);
    $amountApplied = clean_decimal($alloc['amount_applied'] ?? null);

    $hasError = false;
    if (!$billId) {
        $allocErrors[] = "Allocation {$allocNum}: please select a bill.";
        $hasError = true;
    }
    if ($amountApplied === null || $amountApplied === '' || bccomp($amountApplied, '0', 2) <= 0) {
        $allocErrors[] = "Allocation {$allocNum}: amount applied must be greater than zero.";
        $hasError = true;
    }

    if ($hasError) continue;

    $totalAmount = bcadd($totalAmount, $amountApplied, 2);
    $validatedAllocations[] = [
        'bill_id'        => $billId,
        'amount_applied' => $amountApplied,
    ];
}

if ($allocErrors) {
    json_validation_error(['allocations' => implode(' ', $allocErrors)]);
}

// WAVE 1 (C3): reject the same bill listed more than once in a single payment.
// Each allocation row is validated against the bill's FULL balance in a pre-loop
// (before any UPDATE), so duplicate rows for one bill each pass the per-row
// balance check and the apply loop would double-apply. Today that is caught only
// by the acc_ap_payment_allocations uq_payment_bill unique key — as a raw 1062 →
// HTTP 500 on the second allocation insert (the txn rolls back, so no corruption,
// but the operator gets an opaque 500). Return a clean 422 instead; the unique
// key remains the DB backstop.
$billIds    = array_column($validatedAllocations, 'bill_id');
$dupBillIds = array_keys(array_filter(array_count_values($billIds), static fn(int $n): bool => $n > 1));
if ($dupBillIds) {
    $list = implode(', ', $dupBillIds);
    json_error('DUPLICATE_BILL',
        "A bill may only appear once per payment (duplicated bill id(s): {$list}). Combine the amounts into a single allocation.", 422,
        ['fields' => ['allocations' => 'Each bill may only be paid once per payment — combine duplicate rows into one allocation.']]);
}

$result = db_transaction(function () use (
    $vendorId, $bankAccountId, $paymentDate, $paymentMethod, $referenceNum,
    $checkNumber, $notes, $currency, $totalAmount, $validatedAllocations,
    $vendor, $bankAccount
) {
    $year = substr($paymentDate, 0, 4);
    $paymentNumber = AccountingService::nextApPaymentNumber($year);

    // Validate and lock each bill (FOR UPDATE — D20)
    foreach ($validatedAllocations as $alloc) {
        $bill = db_row(
            "SELECT id, bill_number, vendor_id, status, balance_due FROM acc_bills WHERE id = ? FOR UPDATE",
            [$alloc['bill_id']]
        );

        if (!$bill) {
            json_validation_error(['allocations' => "Bill #{$alloc['bill_id']} not found."], "Bill #{$alloc['bill_id']} not found.");
        }
        if ((int)$bill['vendor_id'] !== $vendorId) {
            json_validation_error(['allocations' => "Bill {$bill['bill_number']} does not belong to this vendor."]);
        }
        $payable = ['approved', 'partially_paid'];
        if (!in_array($bill['status'], $payable, true)) {
            json_error('INVALID_TRANSITION',
                "Bill {$bill['bill_number']} is {$bill['status']} — cannot apply payment.", 409,
                ['fields' => ['allocations' => "Bill {$bill['bill_number']} is {$bill['status']} — cannot apply payment."]]);
        }
        if (bccomp($alloc['amount_applied'], (string)$bill['balance_due'], 2) > 0) {
            json_error('ALLOCATION_EXCEEDS_BALANCE',
                "Payment of \${$alloc['amount_applied']} exceeds bill {$bill['bill_number']} balance of \${$bill['balance_due']}.", 422,
                ['fields' => ['allocations' => "Payment of \${$alloc['amount_applied']} exceeds bill {$bill['bill_number']} balance of \${$bill['balance_due']}."]]);
        }
    }

    // Post JE: DR AP / CR Cash
    $apAccountId = AccountingService::setting('accounting.ap_account_id');
    if (!$apAccountId) {
        throw new \RuntimeException('AP account not configured.');
    }

    $jeLines = [
        [
            'account_id'  => (int) $apAccountId,
            'debit'       => $totalAmount,
            'credit'      => '0.00',
            'description' => "AP payment {$paymentNumber} — {$vendor['name']}",
            'vendor_id'   => $vendorId,
        ],
        [
            'account_id'  => (int) $bankAccount['gl_account_id'],
            'debit'       => '0.00',
            'credit'      => $totalAmount,
            'description' => "Cash — AP payment {$paymentNumber}",
            'vendor_id'   => $vendorId,
        ],
    ];

    $je = JournalEntryService::create([
        'entry_date'       => $paymentDate,
        'description'      => "AP Payment {$paymentNumber} — {$vendor['name']}",
        'entry_type'       => 'system',
        'reference'        => $paymentNumber,
        'source_type'      => 'ap_payment',
        'post_immediately' => true,
    ], $jeLines, current_user_id());

    // Insert payment record
    $paymentId = db_insert('acc_ap_payments', [
        'payment_number'   => $paymentNumber,
        'vendor_id'        => $vendorId,
        'bank_account_id'  => $bankAccountId,
        'payment_date'     => $paymentDate,
        'payment_method'   => $paymentMethod,
        'reference_number' => $referenceNum,
        'check_number'     => $checkNumber,
        'amount'           => $totalAmount,
        'currency'         => $currency,
        'status'           => 'cleared',
        'journal_entry_id' => $je['id'],
        'notes'            => $notes,
        'created_by'       => current_user_id(),
    ]);

    // S-ACCT-FIX-AP guard: verify the subledger row was actually inserted.
    // db_insert returns the new id or throws, so this is defense-in-depth
    // against any future refactor or driver quirk that could silently skip
    // the row while reporting success. If the row is missing here we throw
    // — the surrounding db_transaction() rolls back the JE write as well,
    // so we never commit an orphan ap_payment JE. See spec §20.2 and
    // docs/FLEETFORGE_ACCOUNTING_AUDIT_2026-05-07.md §4 for the 2026-04-07
    // orphan-JE incident that motivates this check.
    $verifyPayment = db_row("SELECT id FROM acc_ap_payments WHERE id = ?", [$paymentId]);
    if (!$verifyPayment) {
        throw new \RuntimeException(
            "AP payment JE created but subledger row not found — transaction will roll back. payment_id={$paymentId}"
        );
    }

    // Allocate to bills and update bill balances
    foreach ($validatedAllocations as $alloc) {
        db_insert('acc_ap_payment_allocations', [
            'ap_payment_id' => $paymentId,
            'bill_id'       => $alloc['bill_id'],
            'amount_applied'=> $alloc['amount_applied'],
        ]);

        // Update bill amount_paid and balance_due
        // WHY: MySQL SET evaluates left-to-right, so balance_due is already
        // subtracted when the CASE runs — compare to 0, not subtract again.
        db_execute(
            "UPDATE acc_bills SET
                amount_paid = amount_paid + ?,
                balance_due = balance_due - ?,
                status = CASE
                    WHEN balance_due <= 0 THEN 'paid'
                    ELSE 'partially_paid'
                END
             WHERE id = ?",
            [$alloc['amount_applied'], $alloc['amount_applied'], $alloc['bill_id']]
        );
    }

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'ap_payment',
        'entity_id'   => $paymentId,
        'notes'       => "AP Payment {$paymentNumber} — \${$totalAmount} to {$vendor['name']} via {$paymentMethod}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'id'               => $paymentId,
        'payment_number'   => $paymentNumber,
        'amount'           => $totalAmount,
        'journal_entry_id' => (int) $je['id'],
    ];
});

// ── QBO sync enqueue (S-QBO-19 / Phase QBO-8 / 2 of 2) ──────────────────
// Best-effort per §6.9 D-ENQUEUER-CONTRACT — never throws; silent reject
// when sync_enabled=0 or status != 'cleared'. Mirrors send.php (S-QBO-11)
// + bills/approve.php (S-QBO-18) + payments/create.php (S-QBO-14) hook
// pattern: AFTER db_transaction commits, BEFORE json_success — failure
// here must not break the FF AP payment flow.
if (!empty($result['id'])) {
    \FleetForge\QboPushers\BillPaymentEnqueuer::enqueue((int) $result['id'], 'create');
}

json_success($result, 201);
