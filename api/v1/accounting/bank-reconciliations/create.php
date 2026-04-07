<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-reconciliations/create.php
 *
 * Start a new reconciliation for a bank account and statement period.
 * Only one in_progress reconciliation per account at a time.
 *
 * @method  POST
 * @body    bank_account_id, statement_date, statement_ending_balance
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 201 created reconciliation
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank reconciliation process)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\BankService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$bankAccountId = clean_int($input['bank_account_id'] ?? null);
$statementDate = clean_date($input['statement_date'] ?? null);
$statementEndingBalance = clean_decimal($input['statement_ending_balance'] ?? null);

if (!$bankAccountId) $fields['bank_account_id'] = 'Please select a bank account.';
if (!$statementDate) $fields['statement_date'] = 'Statement date is required.';
if ($statementEndingBalance === null || $statementEndingBalance === '') {
    $fields['statement_ending_balance'] = 'Statement ending balance is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$bankAccount = db_row("SELECT * FROM acc_bank_accounts WHERE id = ? AND is_active = 1", [$bankAccountId]);
if (!$bankAccount) {
    json_error('NOT_FOUND', 'Bank account not found or inactive.', 404, [
        'fields' => ['bank_account_id' => 'Bank account not found or inactive.'],
    ]);
}

// Check for existing in_progress reconciliation
$existing = db_row(
    "SELECT id FROM acc_bank_reconciliations WHERE bank_account_id = ? AND status = 'in_progress'",
    [$bankAccountId]
);
if ($existing) {
    json_error('ALREADY_EXISTS',
        'An in-progress reconciliation already exists for this account. Complete or delete it first.', 409,
        ['fields' => ['bank_account_id' => 'An in-progress reconciliation already exists for this account. Complete or delete it first.']]);
}

// Find the accounting period for the statement date
$period = AccountingService::periodForDate($statementDate);
if (!$period) {
    json_validation_error(
        ['statement_date' => "No accounting period found for date {$statementDate}."],
        "No accounting period found for date {$statementDate}."
    );
}

$userId = current_user_id();

// Compute initial balances
$bookBalance = AccountingService::accountBalance((int) $bankAccount['gl_account_id']);

$newId = db_transaction(function () use (
    $bankAccountId, $statementDate, $statementEndingBalance,
    $period, $bookBalance, $userId
) {
    // D20: FOR UPDATE on bank account during reconciliation
    db_row("SELECT id FROM acc_bank_accounts WHERE id = ? FOR UPDATE", [$bankAccountId]);

    $id = db_insert('acc_bank_reconciliations', [
        'bank_account_id'         => $bankAccountId,
        'period_id'               => $period['id'],
        'statement_date'          => $statementDate,
        'statement_ending_balance'=> $statementEndingBalance,
        'book_balance'            => $bookBalance,
        'outstanding_deposits'    => '0.00',
        'outstanding_checks'      => '0.00',
        'adjusted_book_balance'   => $bookBalance,
        'difference'              => bcsub($bookBalance, $statementEndingBalance, 2),
        'status'                  => 'in_progress',
        'created_by'              => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'bank_reconciliation',
        'entity_id'   => $id,
        'notes'       => "Bank reconciliation started for statement date {$statementDate}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

$created = db_row("SELECT * FROM acc_bank_reconciliations WHERE id = ?", [$newId]);
json_success($created, 201);
