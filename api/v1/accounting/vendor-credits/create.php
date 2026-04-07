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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$vendorId      = clean_int($input['vendor_id'] ?? null);
$creditDate    = clean_date($input['credit_date'] ?? null);
$amount        = clean_decimal($input['amount'] ?? null);
$reason        = clean_string($input['reason'] ?? null, 500);
$sourceBillId  = clean_int($input['source_bill_id'] ?? null);
$expenseAcctId = clean_int($input['expense_account_id'] ?? null);
$notes         = clean_string($input['notes'] ?? null, 2000);
$currency      = clean_string($input['currency'] ?? null) ?? 'CAD';

if (!$vendorId)   $fields['vendor_id']   = 'Please select a vendor.';
if (!$creditDate) $fields['credit_date'] = 'Credit date is required.';
if ($amount === null || $amount === '') {
    $fields['amount'] = 'Credit amount is required.';
} elseif (bccomp($amount, '0', 2) <= 0) {
    $fields['amount'] = 'Credit amount must be greater than zero.';
}
if (!$reason) $fields['reason'] = 'Please provide a reason for this credit.';

if ($fields) {
    json_validation_error($fields);
}

$vendor = db_row("SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
if (!$vendor) {
    json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
}

// If linked to a source bill, validate it
if ($sourceBillId) {
    $sourceBill = db_row("SELECT id, vendor_id FROM acc_bills WHERE id = ?", [$sourceBillId]);
    if (!$sourceBill) {
        json_validation_error(['source_bill_id' => 'Source bill not found.'], 'Source bill not found.');
    }
    if ((int) $sourceBill['vendor_id'] !== $vendorId) {
        json_validation_error(['source_bill_id' => 'Source bill does not belong to this vendor.']);
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
