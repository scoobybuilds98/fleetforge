<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-reconciliations/toggle-cleared.php
 *
 * Toggle a bank transaction's cleared status during reconciliation.
 * Each check-off updates the running reconciliation balances.
 *
 * @method  POST
 * @body    reconciliation_id, transaction_id, is_cleared (0 or 1)
 * @auth    Session required; require_permission('bank_accounts','edit')
 * @returns 200 updated reconciliation summary
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BankService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$reconId = clean_int($input['reconciliation_id'] ?? null);
$txnId = clean_int($input['transaction_id'] ?? null);
$rawCleared = $input['is_cleared'] ?? null;
$isCleared = $rawCleared === null ? null : (int) $rawCleared;

if (!$reconId) $fields['reconciliation_id'] = 'Reconciliation ID is required.';
if (!$txnId)   $fields['transaction_id']    = 'Transaction ID is required.';
if ($isCleared === null || !in_array($isCleared, [0, 1], true)) {
    $fields['is_cleared'] = 'Cleared status must be 0 or 1.';
}

if ($fields) {
    json_validation_error($fields);
}

$recon = db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$reconId]);
if (!$recon) {
    json_error('NOT_FOUND', 'Reconciliation not found.', 404, [
        'fields' => ['reconciliation_id' => 'Reconciliation not found.'],
    ]);
}
if ($recon['status'] !== 'in_progress') {
    json_error('IMMUTABLE_RECORD',
        'Reconciliation is locked — no changes allowed.', 422,
        ['fields' => ['reconciliation_id' => 'Reconciliation is locked — no changes allowed.']]);
}

$txn = db_row(
    "SELECT * FROM acc_bank_transactions WHERE id = ? AND bank_account_id = ?",
    [$txnId, $recon['bank_account_id']]
);
if (!$txn) {
    json_error('NOT_FOUND', 'Transaction not found for this bank account.', 404, [
        'fields' => ['transaction_id' => 'Transaction not found for this bank account.'],
    ]);
}
if ($txn['status'] === 'excluded') {
    json_validation_error(
        ['transaction_id' => 'Cannot clear an excluded transaction.'],
        'Cannot clear an excluded transaction.'
    );
}

db_transaction(function () use ($reconId, $txnId, $isCleared, $recon) {
    db_update('acc_bank_transactions', [
        'is_cleared'        => $isCleared,
        'cleared_date'      => $isCleared ? date('Y-m-d') : null,
        'reconciliation_id' => $isCleared ? $reconId : null,
    ], 'id = ?', [$txnId]);

    // Recalculate reconciliation summary
    $summary = BankService::reconciliationSummary(
        (int) $recon['bank_account_id'],
        $reconId,
        $recon['statement_ending_balance']
    );

    // Update the reconciliation record with current totals
    db_update('acc_bank_reconciliations', [
        'book_balance'          => $summary['book_balance'],
        'outstanding_deposits'  => $summary['outstanding_deposits'],
        'outstanding_checks'    => $summary['outstanding_checks'],
        'adjusted_book_balance' => $summary['adjusted_book_balance'],
        'difference'            => $summary['difference'],
    ], 'id = ?', [$reconId]);
});

// Return updated summary
$summary = BankService::reconciliationSummary(
    (int) $recon['bank_account_id'],
    $reconId,
    $recon['statement_ending_balance']
);

json_success([
    'transaction_id' => $txnId,
    'is_cleared'     => $isCleared,
    'summary'        => $summary,
]);
