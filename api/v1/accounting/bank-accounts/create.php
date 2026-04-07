<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-accounts/create.php
 *
 * Create a new bank account linked to a GL cash account.
 * Validates GL account exists and is marked as bank account.
 *
 * @method  POST
 * @body    name, gl_account_id, currency, account_type, institution?,
 *          account_number_last4?, routing_number?, opening_balance?,
 *          opening_balance_date?, is_default?, notes?
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 201 created bank account
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

// Input validation
$name = clean_string($_POST['name'] ?? null);
$glAccountId = clean_int($_POST['gl_account_id'] ?? null);
$currency = clean_string($_POST['currency'] ?? null);
$accountType = clean_string($_POST['account_type'] ?? null);

if (!$name) json_error('VALIDATION_ERROR', 'name is required.', 422);
if (!$glAccountId) json_error('VALIDATION_ERROR', 'gl_account_id is required.', 422);
if (!$currency || !in_array($currency, ['CAD', 'USD'])) {
    json_error('VALIDATION_ERROR', 'currency must be CAD or USD.', 422);
}
if (!$accountType || !in_array($accountType, ['checking', 'savings', 'line_of_credit', 'credit_card'])) {
    json_error('VALIDATION_ERROR', 'account_type must be checking, savings, line_of_credit, or credit_card.', 422);
}

// Validate GL account exists and is a bank account
$glAccount = db_row(
    "SELECT id, is_bank_account, is_active FROM acc_accounts WHERE id = ?",
    [$glAccountId]
);
if (!$glAccount) json_error('VALIDATION_ERROR', 'GL account not found.', 422);
if (!$glAccount['is_active']) json_error('VALIDATION_ERROR', 'GL account is inactive.', 422);

$institution = clean_string($_POST['institution'] ?? null);
$last4 = clean_string($_POST['account_number_last4'] ?? null, 4);
$routingNumber = clean_string($_POST['routing_number'] ?? null, 20);
$openingBalance = clean_decimal($_POST['opening_balance'] ?? null) ?? '0.00';
$openingDate = clean_date($_POST['opening_balance_date'] ?? null);
$isDefault = (int) ($_POST['is_default'] ?? 0);
$notes = clean_string($_POST['notes'] ?? null, 2000);

$userId = current_user_id();

$newId = db_transaction(function () use (
    $name, $glAccountId, $currency, $accountType, $institution,
    $last4, $routingNumber, $openingBalance, $openingDate,
    $isDefault, $notes, $userId
) {
    // WHY: If this is set as default for its currency, clear any existing defaults
    if ($isDefault) {
        db_execute(
            "UPDATE acc_bank_accounts SET is_default = 0 WHERE currency = ? AND is_default = 1",
            [$currency]
        );
    }

    $id = db_insert('acc_bank_accounts', [
        'name'                 => $name,
        'account_number_last4' => $last4,
        'routing_number'       => $routingNumber,
        'institution'          => $institution,
        'account_type'         => $accountType,
        'currency'             => $currency,
        'gl_account_id'        => $glAccountId,
        'opening_balance'      => $openingBalance,
        'opening_balance_date' => $openingDate,
        'is_active'            => 1,
        'is_default'           => $isDefault,
        'notes'                => $notes,
        'created_by'           => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'bank_account',
        'entity_id'   => $id,
        'notes'       => "Bank account '{$name}' created ({$currency}, {$accountType})",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

$created = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$newId]);
json_success($created, 201);
