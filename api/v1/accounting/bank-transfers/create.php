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

$fromAccountId = clean_int($_POST['from_account_id'] ?? null);
$toAccountId = clean_int($_POST['to_account_id'] ?? null);
$fromAmount = clean_decimal($_POST['from_amount'] ?? null);
$toAmount = clean_decimal($_POST['to_amount'] ?? null);
$transferDate = clean_date($_POST['transfer_date'] ?? null);
$exchangeRate = clean_decimal($_POST['exchange_rate'] ?? null);
$reference = clean_string($_POST['reference'] ?? null);

if (!$fromAccountId) json_error('VALIDATION_ERROR', 'from_account_id is required.', 422);
if (!$toAccountId) json_error('VALIDATION_ERROR', 'to_account_id is required.', 422);
if (!$fromAmount || bccomp($fromAmount, '0.00', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'from_amount must be positive.', 422);
}
if (!$transferDate) json_error('VALIDATION_ERROR', 'transfer_date is required.', 422);

// Default to_amount = from_amount if same currency
if (!$toAmount) {
    $toAmount = $fromAmount;
}

if (bccomp($toAmount, '0.00', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'to_amount must be positive.', 422);
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
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}
