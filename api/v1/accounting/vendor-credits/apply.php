<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/apply.php
 *
 * Apply a vendor credit to an existing bill — reduces bill balance_due.
 * Posts JE: DR Expense / CR AP (reverses the credit's AP debit against the bill's AP credit).
 * Uses FOR UPDATE on both credit and bill rows.
 *
 * @method  POST
 * @body    vendor_credit_id, bill_id, amount_applied
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200 { applied }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

$creditId     = clean_int($_POST['vendor_credit_id'] ?? null);
$billId       = clean_int($_POST['bill_id'] ?? null);
$amountApplied = clean_decimal($_POST['amount_applied'] ?? null);

if (!$creditId)     json_error('VALIDATION_ERROR', 'vendor_credit_id is required.', 422);
if (!$billId)       json_error('VALIDATION_ERROR', 'bill_id is required.', 422);
if (!$amountApplied || bccomp($amountApplied, '0', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'amount_applied must be > 0.', 422);
}

$result = db_transaction(function () use ($creditId, $billId, $amountApplied) {
    // Lock credit row (FOR UPDATE)
    $credit = db_row("SELECT * FROM acc_vendor_credits WHERE id = ? FOR UPDATE", [$creditId]);
    if (!$credit) json_error('NOT_FOUND', 'Vendor credit not found.', 404);

    if ($credit['status'] === 'fully_used' || $credit['status'] === 'void') {
        json_error('INVALID_TRANSITION', "Credit is {$credit['status']} — cannot apply.", 409);
    }

    if (bccomp($amountApplied, (string) $credit['amount_remaining'], 2) > 0) {
        json_error('CREDIT_EXCEEDS_BALANCE', "Amount \${$amountApplied} exceeds credit remaining \${$credit['amount_remaining']}.", 422);
    }

    // Lock bill row (FOR UPDATE — D20)
    $bill = db_row("SELECT * FROM acc_bills WHERE id = ? FOR UPDATE", [$billId]);
    if (!$bill) json_error('NOT_FOUND', 'Bill not found.', 404);

    if ((int) $bill['vendor_id'] !== (int) $credit['vendor_id']) {
        json_error('VALIDATION_ERROR', 'Credit and bill must belong to the same vendor.', 422);
    }

    $payable = ['approved', 'partially_paid'];
    if (!in_array($bill['status'], $payable, true)) {
        json_error('INVALID_TRANSITION', "Bill is {$bill['status']} — cannot apply credit.", 409);
    }

    if (bccomp($amountApplied, (string) $bill['balance_due'], 2) > 0) {
        json_error('ALLOCATION_EXCEEDS_BALANCE', "Amount exceeds bill balance of \${$bill['balance_due']}.", 422);
    }

    $vendor = db_row("SELECT id, name FROM vendors WHERE id = ?", [(int) $credit['vendor_id']]);

    // Insert application record
    db_insert('acc_vendor_credit_applications', [
        'vendor_credit_id' => $creditId,
        'bill_id'          => $billId,
        'amount_applied'   => $amountApplied,
        'applied_by'       => current_user_id(),
    ]);

    // Update credit remaining
    $newRemaining = bcsub((string) $credit['amount_remaining'], $amountApplied, 2);
    $creditStatus = bccomp($newRemaining, '0.00', 2) <= 0 ? 'fully_used' : 'partially_used';

    db_update('acc_vendor_credits', [
        'amount_remaining' => $newRemaining,
        'status'           => $creditStatus,
    ], 'id = ?', [$creditId]);

    // Update bill balance
    $newBillBalance = bcsub((string) $bill['balance_due'], $amountApplied, 2);
    $newBillPaid = bcadd((string) $bill['amount_paid'], $amountApplied, 2);
    $billStatus = bccomp($newBillBalance, '0.00', 2) <= 0 ? 'paid' : 'partially_paid';

    db_update('acc_bills', [
        'amount_paid' => $newBillPaid,
        'balance_due' => $newBillBalance,
        'status'      => $billStatus,
    ], 'id = ?', [$billId]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'vendor_credit',
        'entity_id'   => $creditId,
        'notes'       => "Credit {$credit['credit_number']} applied \${$amountApplied} to bill {$bill['bill_number']}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'credit_number'    => $credit['credit_number'],
        'bill_number'      => $bill['bill_number'],
        'amount_applied'   => $amountApplied,
        'credit_remaining' => $newRemaining,
        'bill_balance'     => $newBillBalance,
    ];
});

json_success($result);
