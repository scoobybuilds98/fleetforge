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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

// Input validation
$name = clean_string($input['name'] ?? null);
$glAccountId = clean_int($input['gl_account_id'] ?? null);
$currency = clean_string($input['currency'] ?? null);
$accountType = clean_string($input['account_type'] ?? null);

if (!$name) $fields['name'] = 'Bank account name is required.';
if (!$glAccountId) $fields['gl_account_id'] = 'Please select a GL cash account.';
if (!$currency) {
    $fields['currency'] = 'Please select a currency.';
} elseif (!in_array($currency, ['CAD', 'USD'], true)) {
    $fields['currency'] = 'Currency must be CAD or USD.';
}
$validAccountTypes = ['checking', 'savings', 'line_of_credit', 'credit_card'];
if (!$accountType) {
    $fields['account_type'] = 'Please select an account type.';
} elseif (!in_array($accountType, $validAccountTypes, true)) {
    $fields['account_type'] = 'Account type must be checking, savings, line of credit, or credit card.';
}

$last4 = clean_string($input['account_number_last4'] ?? null, 4);
if ($last4 !== null && $last4 !== '' && !preg_match('/^\d{1,4}$/', $last4)) {
    $fields['account_number_last4'] = 'Last 4 digits must be numeric (up to 4 digits).';
}

$openingBalance = clean_decimal($input['opening_balance'] ?? null) ?? '0.00';
$openingDate = clean_date($input['opening_balance_date'] ?? null);

if ($openingBalance !== '0.00' && !$openingDate) {
    $fields['opening_balance_date'] = 'Opening balance date is required when opening balance is non-zero.';
}

if ($fields) {
    json_validation_error($fields);
}

// Validate GL account exists and is a bank account
$glAccount = db_row(
    "SELECT id, is_bank_account, is_active FROM acc_accounts WHERE id = ?",
    [$glAccountId]
);
if (!$glAccount) {
    json_validation_error(['gl_account_id' => 'GL account not found.'], 'GL account not found.');
}
if (!$glAccount['is_active']) {
    json_validation_error(['gl_account_id' => 'GL account is inactive.'], 'GL account is inactive.');
}

$institution = clean_string($input['institution'] ?? null);
$routingNumber = clean_string($input['routing_number'] ?? null, 20);
$isDefault = (int) ($input['is_default'] ?? 0);
$notes = clean_string($input['notes'] ?? null, 2000);

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
