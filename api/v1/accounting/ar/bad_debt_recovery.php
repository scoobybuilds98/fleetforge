<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/bad_debt_recovery.php
 *
 * Record money received after an invoice was written off.
 * Posts: DR the bank it went into / CR Bad Debt Expense (source_type
 * bad_debt_recovery, source_id = the write-off).
 *
 * SOP I13: this used to post DR AR / CR Bad Debt with no invoice behind it —
 * GL AR rose while the invoice stayed written off (AR reconciliation broke)
 * and nothing recorded the cash. The money is received and the expense
 * reduced in one step instead; the invoice stays written off. The entry is
 * pushed to QuickBooks like a manual journal entry.
 *
 * @method  POST
 * @body    writeoff_id (required), recovered_amount (required, in the
 *          invoice's currency), recovered_date?, bank_account_id?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 200 { writeoff_id, recovered_amount, journal_entry_id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Bad debt recovery)
 * Session: S031
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\JournalEntryService;

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$writeoffId   = clean_int($input['writeoff_id'] ?? null);
$recoveredAmt = clean_decimal($input['recovered_amount'] ?? null);
$recoveredOn  = clean_date($input['recovered_date'] ?? null) ?? ff_today();
$bankIdIn     = clean_int($input['bank_account_id'] ?? null);

if (!$writeoffId) $fields['writeoff_id'] = 'Please select a write-off record.';
if ($recoveredAmt === null || $recoveredAmt === '') {
    $fields['recovered_amount'] = 'Recovery amount is required.';
} elseif (bccomp($recoveredAmt, '0', 2) <= 0) {
    $fields['recovered_amount'] = 'Recovery amount must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
}

$result = db_transaction(function () use ($writeoffId, $recoveredAmt, $recoveredOn, $bankIdIn) {
    $writeoff = db_row(
        "SELECT bw.*, i.invoice_number, i.customer_id, i.currency, i.exchange_rate_to_cad
         FROM acc_bad_debt_writeoffs bw
         JOIN invoices i ON i.id = bw.invoice_id
         WHERE bw.id = ? FOR UPDATE",
        [$writeoffId]
    );
    if (!$writeoff) {
        json_error('NOT_FOUND', 'Write-off record not found.', 404, [
            'fields' => ['writeoff_id' => 'Write-off record not found.'],
        ]);
    }

    if ($writeoff['recovered']) {
        json_error('IMMUTABLE_RECORD',
            'This write-off has already been recovered.', 422,
            ['fields' => ['writeoff_id' => 'This write-off has already been recovered.']]);
    }

    // Cannot recover more than original write-off amount
    if (bccomp($recoveredAmt, (string)$writeoff['amount'], 2) > 0) {
        json_validation_error(
            ['recovered_amount' => "Recovery amount cannot exceed original write-off amount (\${$writeoff['amount']})."],
            "Recovery amount cannot exceed original write-off amount (\${$writeoff['amount']})."
        );
    }

    // DR the bank the money went into / CR Bad Debt Expense (SOP I13).
    $currency = (string) ($writeoff['currency'] ?? 'CAD');
    $bank = \FleetForge\Accounting\BankService::resolveReceivingBank($bankIdIn, $currency);
    if ($bank['error'] !== null) {
        json_validation_error(['bank_account_id' => $bank['error']], $bank['error']);
    }
    $badDebtAccountId = (int) AccountingService::setting('accounting.bad_debt_expense_account_id', 0);
    try {
        $cashAccountId = \FleetForge\Accounting\AutoEntryBridge::cashAccountForBank($bank['id']);
    } catch (\RuntimeException $e) {
        $cashAccountId = 0;
    }
    if (!$badDebtAccountId || !$cashAccountId) {
        json_error('ACCOUNTING_CONFIG_INCOMPLETE',
            'Cannot record the recovery — map the Cash and Bad Debt Expense accounts in Accounting → Settings.', 422);
    }

    // CAD at the invoice's frozen rate — the rate the write-off used.
    $rate = $currency === 'CAD' ? '1' : (string) ($writeoff['exchange_rate_to_cad'] ?? '');
    if ($rate !== '1' && (trim($rate) === '' || bccomp($rate, '0', 6) <= 0)) {
        json_error('FX_RATE_MISSING', "Invoice {$writeoff['invoice_number']} has no frozen exchange rate.", 422);
    }
    $cad = $rate === '1' ? $recoveredAmt : bcround(bcmul($recoveredAmt, $rate, 6), 2);

    $customer = db_row(
        "SELECT company_name FROM customers WHERE id = ?",
        [$writeoff['customer_id']]
    );
    $companyName = $customer['company_name'] ?? 'Unknown';

    $je = JournalEntryService::create([
        'entry_date'       => $recoveredOn,
        'description'      => "Bad debt recovered — {$writeoff['invoice_number']} — {$companyName}",
        'entry_type'       => 'system',
        'reference'        => $writeoff['invoice_number'],
        'source_type'      => 'bad_debt_recovery',
        'source_id'        => $writeoffId,
        'post_immediately' => true,
    ], [
        [
            'account_id'  => $cashAccountId,
            'debit'       => $cad,
            'credit'      => '0.00',
            'description' => "Recovered after write-off — {$writeoff['invoice_number']}",
            'customer_id' => $writeoff['customer_id'],
        ] + \FleetForge\Accounting\AutoEntryBridge::foreignLeg($currency, $recoveredAmt, $rate),
        [
            'account_id'  => $badDebtAccountId,
            'debit'       => '0.00',
            'credit'      => $cad,
            'description' => "Bad debt recovery — {$writeoff['invoice_number']}",
            'customer_id' => $writeoff['customer_id'],
        ],
    ], current_user_id());

    // Update writeoff record
    db_update('acc_bad_debt_writeoffs', [
        'recovered'                 => 1,
        'recovered_amount'          => $recoveredAmt,
        'recovered_date'            => $recoveredOn,
        'recovery_journal_entry_id' => $je['id'] ?? null,
    ], 'id = ?', [$writeoffId]);

    // Audit
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'bad_debt_writeoff',
        'entity_id'   => $writeoffId,
        'notes'       => "Bad debt recovery: {$writeoff['invoice_number']} — {$recoveredAmt} recovered",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return [
        'writeoff_id'      => $writeoffId,
        'recovered_amount' => $recoveredAmt,
        'journal_entry_id' => $je['id'] ?? null,
    ];
});

json_success($result);
