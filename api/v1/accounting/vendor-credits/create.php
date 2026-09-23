<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/create.php
 *
 * Create a vendor credit memo — reduces AP balance.
 * Posts JE: DR 2010 AP / CR expense account.
 *
 * SOP I3: the credit side must be a real account. It used to fall back to the
 * AP account itself ("Auto (from AP)"), posting DR AP / CR AP — nothing — so
 * AP drifted from the bills once the credit was applied. Now either an
 * expense_account_id is given, or a source_bill_id, in which case the credit
 * reverses that bill's own entry pro rata (each expense line AND the GST/PST
 * input-tax share, so the ITC comes back out of 1050 too).
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

$apAccountId = (int) AccountingService::setting('accounting.ap_account_id', 0);
if ($apAccountId <= 0) {
    json_error('CONFIG_INCOMPLETE', 'Accounting configuration incomplete: set the Accounts Payable account in Accounting → Settings.', 422);
}

// The credit side of the entry. Explicit account wins; else the source bill's
// own posted entry, prorated; else refuse (never AP against AP).
$creditLines = null;   // null → single line to $expenseAcctId
if ($expenseAcctId) {
    $acct = db_row("SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?", [$expenseAcctId]);
    if (!$acct || (int) $acct['is_active'] !== 1 || (int) $acct['is_header'] === 1) {
        json_validation_error(['expense_account_id' => 'Choose an active, non-header account.']);
    }
    $controlIds = array_filter([
        $apAccountId,
        (int) AccountingService::setting('accounting.ar_account_id', 0),
    ]);
    if (in_array($expenseAcctId, $controlIds, true)) {
        json_validation_error(['expense_account_id' => 'The credit cannot go to the Accounts Payable or Receivable control account. Choose the account the original bill was charged to.']);
    }
} elseif ($sourceBillId) {
    $billJe = db_row("SELECT journal_entry_id FROM acc_bills WHERE id = ?", [$sourceBillId]);
    $billLines = !empty($billJe['journal_entry_id'])
        ? db_select(
            "SELECT account_id, debit FROM acc_journal_entry_lines
              WHERE journal_entry_id = ? AND debit > 0 AND account_id <> ?
              ORDER BY debit DESC, id ASC",
            [(int) $billJe['journal_entry_id'], $apAccountId]
        )
        : [];
    $billDebit = '0.00';
    foreach ($billLines as $bl) {
        $billDebit = bcadd($billDebit, (string) $bl['debit'], 2);
    }
    if (!$billLines || bccomp($billDebit, '0', 2) <= 0) {
        json_validation_error(['expense_account_id' => 'The source bill has no posted entry to reverse. Choose an expense account.']);
    }
    if (bccomp($amount, $billDebit, 2) > 0) {
        json_validation_error(['amount' => "The credit is larger than the source bill ({$billDebit})."]);
    }
    // Prorate each debit line by amount / bill total; the rounding remainder
    // goes to the largest line so the credits sum exactly to $amount.
    $creditLines = [];
    $allocated   = '0.00';
    foreach ($billLines as $bl) {
        $share = bcdiv(bcmul((string) $bl['debit'], $amount, 6), $billDebit, 6);
        $share = bcadd($share, '0.005', 2); // half-up to cents (shares are positive)
        if (bccomp($share, '0', 2) <= 0) continue;
        $creditLines[] = ['account_id' => (int) $bl['account_id'], 'credit' => $share];
        $allocated = bcadd($allocated, $share, 2);
    }
    $creditLines[0]['credit'] = bcadd($creditLines[0]['credit'], bcsub($amount, $allocated, 2), 2);
} else {
    json_validation_error(['expense_account_id' => 'Choose the account this credit reverses (usually the account on the original bill).']);
}

$result = db_transaction(function () use (
    $vendorId, $creditDate, $amount, $reason, $sourceBillId,
    $expenseAcctId, $notes, $currency, $vendor, $apAccountId, $creditLines
) {
    $year = substr($creditDate, 0, 4);
    $creditNumber = AccountingService::nextVendorCreditNumber($year);

    // Post JE: DR AP / CR expense (one account, or the source bill's lines pro rata)
    $jeLines = [
        [
            'account_id'  => $apAccountId,
            'debit'       => $amount,
            'credit'      => '0.00',
            'description' => "Vendor credit {$creditNumber} — {$vendor['name']}",
            'vendor_id'   => $vendorId,
        ],
    ];
    foreach ($creditLines ?? [['account_id' => (int) $expenseAcctId, 'credit' => $amount]] as $cl) {
        $jeLines[] = [
            'account_id'  => (int) $cl['account_id'],
            'debit'       => '0.00',
            'credit'      => $cl['credit'],
            'description' => "Expense reversal — {$reason}",
            'vendor_id'   => $vendorId,
        ];
    }

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
