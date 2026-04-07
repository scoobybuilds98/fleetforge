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

$vendorId       = clean_int($_POST['vendor_id'] ?? null);
$bankAccountId  = clean_int($_POST['bank_account_id'] ?? null);
$paymentDate    = clean_date($_POST['payment_date'] ?? null);
$paymentMethod  = clean_string($_POST['payment_method'] ?? null);
$referenceNum   = clean_string($_POST['reference_number'] ?? null);
$checkNumber    = clean_string($_POST['check_number'] ?? null);
$notes          = clean_string($_POST['notes'] ?? null, 2000);
$currency       = clean_string($_POST['currency'] ?? null) ?? 'CAD';

if (!$vendorId)      json_error('VALIDATION_ERROR', 'vendor_id is required.', 422);
if (!$bankAccountId) json_error('VALIDATION_ERROR', 'bank_account_id is required.', 422);
if (!$paymentDate)   json_error('VALIDATION_ERROR', 'payment_date is required.', 422);
if (!$paymentMethod) json_error('VALIDATION_ERROR', 'payment_method is required.', 422);

$validMethods = ['check', 'eft', 'wire', 'credit_card', 'cash', 'other'];
if (!in_array($paymentMethod, $validMethods, true)) {
    json_error('VALIDATION_ERROR', 'payment_method must be one of: ' . implode(', ', $validMethods), 422);
}

// Validate check_number required for check payments
if ($paymentMethod === 'check' && !$checkNumber) {
    json_error('VALIDATION_ERROR', 'check_number is required for check payments.', 422);
}

// Validate vendor
$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) json_error('NOT_FOUND', 'Vendor not found.', 404);

// Validate bank account
$bankAccount = db_row("SELECT id, gl_account_id, name FROM acc_bank_accounts WHERE id = ? AND is_active = 1", [$bankAccountId]);
if (!$bankAccount) json_error('NOT_FOUND', 'Bank account not found or inactive.', 404);

// Parse allocations
$rawAllocations = $_POST['allocations'] ?? null;
if (is_string($rawAllocations)) {
    $rawAllocations = json_decode($rawAllocations, true);
}
if (!is_array($rawAllocations) || count($rawAllocations) === 0) {
    json_error('VALIDATION_ERROR', 'At least one bill allocation is required.', 422);
}

// Compute total payment amount from allocations (bcmath — D16)
$totalAmount = '0.00';
$validatedAllocations = [];

foreach ($rawAllocations as $i => $alloc) {
    $billId = clean_int($alloc['bill_id'] ?? null);
    $amountApplied = clean_decimal($alloc['amount_applied'] ?? null);

    if (!$billId) json_error('VALIDATION_ERROR', "Allocation " . ($i + 1) . ": bill_id is required.", 422);
    if (!$amountApplied || bccomp($amountApplied, '0', 2) <= 0) {
        json_error('VALIDATION_ERROR', "Allocation " . ($i + 1) . ": amount_applied must be > 0.", 422);
    }

    $totalAmount = bcadd($totalAmount, $amountApplied, 2);
    $validatedAllocations[] = [
        'bill_id'        => $billId,
        'amount_applied' => $amountApplied,
    ];
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
            json_error('NOT_FOUND', "Bill #{$alloc['bill_id']} not found.", 404);
        }
        if ((int)$bill['vendor_id'] !== $vendorId) {
            json_error('VALIDATION_ERROR', "Bill {$bill['bill_number']} does not belong to this vendor.", 422);
        }
        $payable = ['approved', 'partially_paid'];
        if (!in_array($bill['status'], $payable, true)) {
            json_error('INVALID_TRANSITION', "Bill {$bill['bill_number']} is {$bill['status']} — cannot apply payment.", 409);
        }
        if (bccomp($alloc['amount_applied'], (string)$bill['balance_due'], 2) > 0) {
            json_error('ALLOCATION_EXCEEDS_BALANCE', "Payment of \${$alloc['amount_applied']} exceeds bill {$bill['bill_number']} balance of \${$bill['balance_due']}.", 422);
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

json_success($result, 201);
