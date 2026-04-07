<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/create.php
 *
 * Create a vendor credit memo — reduces AP balance.
 * Posts JE: DR 2010 AP / CR expense account.
 *
 * @method  POST
 * @body    vendor_id, credit_date, amount, reason, source_bill_id?, expense_account_id?, notes?
 * @auth    Session required; require_permission('accounts_payable','create')
 * @returns 201 { id, credit_number }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'create');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

$vendorId        = clean_int($_POST['vendor_id'] ?? null);
$creditDate      = clean_date($_POST['credit_date'] ?? null);
$amount          = clean_decimal($_POST['amount'] ?? null);
$reason          = clean_string($_POST['reason'] ?? null, 500);
$sourceBillId    = clean_int($_POST['source_bill_id'] ?? null);
$expenseAcctId   = clean_int($_POST['expense_account_id'] ?? null);
$notes           = clean_string($_POST['notes'] ?? null, 2000);
$currency        = clean_string($_POST['currency'] ?? null) ?? 'CAD';

if (!$vendorId)    json_error('VALIDATION_ERROR', 'vendor_id is required.', 422);
if (!$creditDate)  json_error('VALIDATION_ERROR', 'credit_date is required.', 422);
if (!$amount || bccomp($amount, '0', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'amount must be greater than zero.', 422);
}
if (!$reason)      json_error('VALIDATION_ERROR', 'reason is required.', 422);

$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) json_error('NOT_FOUND', 'Vendor not found.', 404);

// If linked to a source bill, validate it
if ($sourceBillId) {
    $sourceBill = db_row("SELECT id, vendor_id FROM acc_bills WHERE id = ?", [$sourceBillId]);
    if (!$sourceBill) json_error('NOT_FOUND', 'Source bill not found.', 404);
    if ((int) $sourceBill['vendor_id'] !== $vendorId) {
        json_error('VALIDATION_ERROR', 'Source bill does not belong to this vendor.', 422);
    }
}

// Default expense account: use the first line account of the source bill, or a generic
if (!$expenseAcctId && $sourceBillId) {
    $firstLine = db_row("SELECT account_id FROM acc_bill_lines WHERE bill_id = ? ORDER BY sort_order LIMIT 1", [$sourceBillId]);
    if ($firstLine) $expenseAcctId = (int) $firstLine['account_id'];
}

$result = db_transaction(function () use (
    $vendorId, $creditDate, $amount, $reason, $sourceBillId,
    $expenseAcctId, $notes, $currency, $vendor
) {
    $year = substr($creditDate, 0, 4);
    $creditNumber = AccountingService::nextVendorCreditNumber($year);

    $apAccountId = AccountingService::setting('accounting.ap_account_id');
    if (!$apAccountId) {
        throw new \RuntimeException('AP account not configured.');
    }

    // Determine credit account — expense reversal
    $creditAcctId = $expenseAcctId ?? $apAccountId;

    // Post JE: DR AP / CR Expense
    $jeLines = [
        [
            'account_id'  => (int) $apAccountId,
            'debit'       => $amount,
            'credit'      => '0.00',
            'description' => "Vendor credit {$creditNumber} — {$vendor['name']}",
            'vendor_id'   => $vendorId,
        ],
        [
            'account_id'  => (int) $creditAcctId,
            'debit'       => '0.00',
            'credit'      => $amount,
            'description' => "Expense reversal — {$reason}",
            'vendor_id'   => $vendorId,
        ],
    ];

    $je = JournalEntryService::create([
        'entry_date'       => $creditDate,
        'description'      => "Vendor Credit {$creditNumber} — {$vendor['name']}: {$reason}",
        'entry_type'       => 'system',
        'reference'        => $creditNumber,
        'source_type'      => 'manual',
        'post_immediately' => true,
    ], $jeLines, current_user_id());

    $id = db_insert('acc_vendor_credits', [
        'credit_number'   => $creditNumber,
        'vendor_id'       => $vendorId,
        'credit_date'     => $creditDate,
        'reason'          => $reason,
        'amount'          => $amount,
        'amount_remaining'=> $amount,
        'currency'        => $currency,
        'status'          => 'active',
        'source_bill_id'  => $sourceBillId,
        'journal_entry_id'=> $je['id'],
        'notes'           => $notes,
        'created_by'      => current_user_id(),
    ]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'vendor_credit',
        'entity_id'   => $id,
        'notes'       => "Vendor credit {$creditNumber} — \${$amount} for {$vendor['name']}: {$reason}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return ['id' => $id, 'credit_number' => $creditNumber, 'journal_entry_id' => (int) $je['id']];
});

json_success($result, 201);
