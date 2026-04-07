<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transactions/create.php
 *
 * Manually create a bank transaction (e.g. bank charge, interest, manual entry).
 * Optionally creates a JE for the transaction.
 *
 * @method  POST
 * @body    bank_account_id, transaction_date, description, amount,
 *          transaction_type, reference?, expense_account_id?, notes?
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 201 created bank transaction
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\JournalEntryService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

$bankAccountId = clean_int($_POST['bank_account_id'] ?? null);
$txnDate = clean_date($_POST['transaction_date'] ?? null);
$description = clean_string($_POST['description'] ?? null, 500);
$amount = clean_decimal($_POST['amount'] ?? null);
$txnType = clean_string($_POST['transaction_type'] ?? null);

if (!$bankAccountId) json_error('VALIDATION_ERROR', 'bank_account_id is required.', 422);
if (!$txnDate) json_error('VALIDATION_ERROR', 'transaction_date is required.', 422);
if (!$description) json_error('VALIDATION_ERROR', 'description is required.', 422);
if ($amount === null) json_error('VALIDATION_ERROR', 'amount is required.', 422);
if (!$txnType || !in_array($txnType, ['deposit', 'withdrawal', 'bank_charge', 'interest', 'other'])) {
    json_error('VALIDATION_ERROR', 'transaction_type must be deposit, withdrawal, bank_charge, interest, or other.', 422);
}

$bankAccount = db_row("SELECT * FROM acc_bank_accounts WHERE id = ? AND is_active = 1", [$bankAccountId]);
if (!$bankAccount) json_error('NOT_FOUND', 'Bank account not found or inactive.', 404);

// Normalize amount sign: deposits positive, withdrawals/charges negative
if (in_array($txnType, ['withdrawal', 'bank_charge']) && bccomp($amount, '0.00', 2) > 0) {
    $amount = bcmul($amount, '-1', 2);
}

$reference = clean_string($_POST['reference'] ?? null);
$expenseAccountId = clean_int($_POST['expense_account_id'] ?? null);
$notes = clean_string($_POST['notes'] ?? null, 2000);
$userId = current_user_id();

$newId = db_transaction(function () use (
    $bankAccountId, $txnDate, $description, $amount, $txnType,
    $reference, $expenseAccountId, $notes, $userId, $bankAccount
) {
    $jeId = null;

    // WHY: If an expense account is specified, create a JE automatically
    // (e.g. bank fee → DR Expense / CR Cash)
    if ($expenseAccountId) {
        $absAmount = bccomp($amount, '0.00', 2) < 0 ? bcmul($amount, '-1', 2) : $amount;
        $cashAccountId = (int) $bankAccount['gl_account_id'];

        $lines = [];
        if (bccomp($amount, '0.00', 2) < 0) {
            // Withdrawal: DR Expense, CR Cash
            $lines = [
                ['account_id' => $expenseAccountId, 'debit' => $absAmount, 'credit' => '0.00', 'description' => $description],
                ['account_id' => $cashAccountId, 'debit' => '0.00', 'credit' => $absAmount, 'description' => $description],
            ];
        } else {
            // Deposit: DR Cash, CR Income
            $lines = [
                ['account_id' => $cashAccountId, 'debit' => $absAmount, 'credit' => '0.00', 'description' => $description],
                ['account_id' => $expenseAccountId, 'debit' => '0.00', 'credit' => $absAmount, 'description' => $description],
            ];
        }

        $je = JournalEntryService::create([
            'entry_date'       => $txnDate,
            'description'      => "Bank transaction: {$description}",
            'entry_type'       => 'manual',
            'source_type'      => 'bank_transaction',
            'reference'        => $reference,
            'post_immediately' => true,
        ], $lines, $userId);

        $jeId = (int) $je['id'];
    }

    $id = db_insert('acc_bank_transactions', [
        'bank_account_id'  => $bankAccountId,
        'transaction_date' => $txnDate,
        'description'      => $description,
        'reference'        => $reference,
        'amount'           => $amount,
        'transaction_type' => $txnType,
        'source'           => 'manual',
        'status'           => $jeId ? 'matched' : 'unmatched',
        'matched_type'     => $jeId ? 'journal_entry' : null,
        'matched_id'       => $jeId,
        'matched_at'       => $jeId ? date('Y-m-d H:i:s') : null,
        'matched_by'       => $jeId ? $userId : null,
        'journal_entry_id' => $jeId,
        'notes'            => $notes,
        'created_by'       => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'bank_transaction',
        'entity_id'   => $id,
        'notes'       => "Bank transaction created: {$description} ({$amount})",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

$created = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$newId]);
json_success($created, 201);
