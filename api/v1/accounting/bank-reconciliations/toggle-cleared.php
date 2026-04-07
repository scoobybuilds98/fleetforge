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

$reconId = clean_int($_POST['reconciliation_id'] ?? null);
$txnId = clean_int($_POST['transaction_id'] ?? null);
$isCleared = clean_int($_POST['is_cleared'] ?? null);

if (!$reconId) json_error('VALIDATION_ERROR', 'reconciliation_id is required.', 422);
if (!$txnId) json_error('VALIDATION_ERROR', 'transaction_id is required.', 422);
if ($isCleared === null || !in_array($isCleared, [0, 1])) {
    json_error('VALIDATION_ERROR', 'is_cleared must be 0 or 1.', 422);
}

$recon = db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$reconId]);
if (!$recon) json_error('NOT_FOUND', 'Reconciliation not found.', 404);
if ($recon['status'] !== 'in_progress') {
    json_error('IMMUTABLE_RECORD', 'Reconciliation is locked — no changes allowed.', 422);
}

$txn = db_row(
    "SELECT * FROM acc_bank_transactions WHERE id = ? AND bank_account_id = ?",
    [$txnId, $recon['bank_account_id']]
);
if (!$txn) json_error('NOT_FOUND', 'Transaction not found for this bank account.', 404);
if ($txn['status'] === 'excluded') {
    json_error('VALIDATION_ERROR', 'Cannot clear an excluded transaction.', 422);
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
