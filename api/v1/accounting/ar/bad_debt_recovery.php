<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/bad_debt_recovery.php
 *
 * Record recovery of a previously written-off bad debt.
 * Posts reversal JE: DR AR / CR Bad Debt Expense.
 *
 * @method  POST
 * @body    writeoff_id (required), recovered_amount (required)
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

if (!$writeoffId) $fields['writeoff_id'] = 'Please select a write-off record.';
if ($recoveredAmt === null || $recoveredAmt === '') {
    $fields['recovered_amount'] = 'Recovery amount is required.';
} elseif (bccomp($recoveredAmt, '0', 2) <= 0) {
    $fields['recovered_amount'] = 'Recovery amount must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
}

$result = db_transaction(function () use ($writeoffId, $recoveredAmt) {
    $writeoff = db_row(
        "SELECT bw.*, i.invoice_number, i.customer_id
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

    // Post reversal JE: DR 1030 AR / CR 6160 Bad Debt Expense
    $arAccountId      = AccountingService::setting('accounting.ar_account_id');
    $badDebtAccountId = AccountingService::setting('accounting.bad_debt_expense_account_id');

    $je = null;
    if ($arAccountId && $badDebtAccountId) {
        $customer = db_row(
            "SELECT company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$writeoff['customer_id']]
        );
        $companyName = $customer['company_name'] ?? 'Unknown';

        $jeLines = [
            [
                'account_id'  => (int)$arAccountId,
                'debit'       => $recoveredAmt,
                'credit'      => '0.00',
                'description' => "AR recovered — {$writeoff['invoice_number']}",
                'customer_id' => $writeoff['customer_id'],
            ],
            [
                'account_id'  => (int)$badDebtAccountId,
                'debit'       => '0.00',
                'credit'      => $recoveredAmt,
                'description' => "Bad debt recovery — {$writeoff['invoice_number']}",
                'customer_id' => $writeoff['customer_id'],
            ],
        ];

        $je = JournalEntryService::create([
            'entry_date'       => date('Y-m-d'),
            'description'      => "Bad debt recovery — {$writeoff['invoice_number']} — {$companyName}",
            'entry_type'       => 'system',
            'reference'        => $writeoff['invoice_number'],
            'source_type'      => 'invoice',
            'source_id'        => $writeoff['invoice_id'],
            'post_immediately' => true,
        ], $jeLines, current_user_id());
    }

    // Update writeoff record
    db_update('acc_bad_debt_writeoffs', [
        'recovered'                 => 1,
        'recovered_amount'          => $recoveredAmt,
        'recovered_date'            => date('Y-m-d'),
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
