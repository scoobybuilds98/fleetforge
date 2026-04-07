<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transfers/create.php
 *
 * Record a transfer between two bank accounts.
 * Generates JE: DR destination / CR source.
 * Handles cross-currency transfers with FX gain/loss.
 *
 * @method  POST
 * @body    from_account_id, to_account_id, from_amount, to_amount?,
 *          transfer_date, exchange_rate?, reference?
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 201 transfer result with JE and bank transaction IDs
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank transfers)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\BankService;

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'create');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$fromAccountId = clean_int($input['from_account_id'] ?? null);
$toAccountId   = clean_int($input['to_account_id'] ?? null);
$fromAmount    = clean_decimal($input['from_amount'] ?? null);
$toAmount      = clean_decimal($input['to_amount'] ?? null);
$transferDate  = clean_date($input['transfer_date'] ?? null);
$exchangeRate  = clean_decimal($input['exchange_rate'] ?? null);
$reference     = clean_string($input['reference'] ?? null);

if (!$fromAccountId) $fields['from_account_id'] = 'Please select a source bank account.';
if (!$toAccountId)   $fields['to_account_id']   = 'Please select a destination bank account.';
if ($fromAccountId && $toAccountId && $fromAccountId === $toAccountId) {
    $fields['to_account_id'] = 'Destination account must be different from source account.';
}

if ($fromAmount === null || $fromAmount === '') {
    $fields['from_amount'] = 'Transfer amount is required.';
} elseif (bccomp($fromAmount, '0.00', 2) <= 0) {
    $fields['from_amount'] = 'Transfer amount must be greater than zero.';
}

if (!$transferDate) $fields['transfer_date'] = 'Transfer date is required.';

// Default to_amount = from_amount if same currency
if (!$toAmount && $fromAmount) {
    $toAmount = $fromAmount;
}

if ($toAmount !== null && $toAmount !== '' && bccomp($toAmount, '0.00', 2) <= 0) {
    $fields['to_amount'] = 'Destination amount must be greater than zero.';
}

if ($exchangeRate !== null && $exchangeRate !== '' && bccomp($exchangeRate, '0', 6) <= 0) {
    $fields['exchange_rate'] = 'Exchange rate must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
}

$userId = current_user_id();

try {
    $result = BankService::recordTransfer(
        $fromAccountId,
        $toAccountId,
        $fromAmount,
        $toAmount,
        $transferDate,
        $exchangeRate,
        $reference,
        $userId
    );
    json_success($result, 201);
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'from') !== false)        $slot = 'from_account_id';
    elseif (stripos($msg, 'to') !== false
        || stripos($msg, 'destination') !== false) $slot = 'to_account_id';
    elseif (stripos($msg, 'amount') !== false)  $slot = 'from_amount';
    elseif (stripos($msg, 'exchange') !== false) $slot = 'exchange_rate';
    elseif (stripos($msg, 'period') !== false || stripos($msg, 'date') !== false) $slot = 'transfer_date';
    json_validation_error([$slot => $msg], $msg);
}
