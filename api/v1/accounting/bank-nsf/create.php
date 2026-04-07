<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-nsf/create.php
 *
 * Process an NSF (Non-Sufficient Funds) returned payment.
 * Reverses the original payment JE, reopens invoices, and optionally
 * charges an NSF fee to the customer account.
 *
 * IMPORTANT: NSF reversal requires manual confirmation — never auto-reverse.
 *
 * @method  POST
 * @body    payment_id, bank_account_id, nsf_fee? (defaults to 0.00)
 * @auth    Session required; require_permission('bank_accounts','create')
 * @returns 200 NSF processing result
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (NSF / returned payments)
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

$paymentId = clean_int($input['payment_id'] ?? null);
$bankAccountId = clean_int($input['bank_account_id'] ?? null);
$nsfFee = clean_decimal($input['nsf_fee'] ?? null) ?? '0.00';

if (!$paymentId)     $fields['payment_id']      = 'Please select a payment.';
if (!$bankAccountId) $fields['bank_account_id'] = 'Please select a bank account.';
if (bccomp($nsfFee, '0.00', 2) < 0) {
    $fields['nsf_fee'] = 'NSF fee cannot be negative.';
}

if ($fields) {
    json_validation_error($fields);
}

$userId = current_user_id();

try {
    $result = BankService::processNsf(
        $paymentId,
        $nsfFee,
        $bankAccountId,
        $userId
    );
    json_success($result);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'payment') !== false) $slot = 'payment_id';
    elseif (stripos($msg, 'bank') !== false) $slot = 'bank_account_id';
    elseif (stripos($msg, 'fee') !== false) $slot = 'nsf_fee';
    json_validation_error([$slot => $msg], $msg);
}
