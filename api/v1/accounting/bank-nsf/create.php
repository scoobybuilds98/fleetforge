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

$paymentId = clean_int($_POST['payment_id'] ?? null);
$bankAccountId = clean_int($_POST['bank_account_id'] ?? null);
$nsfFee = clean_decimal($_POST['nsf_fee'] ?? null) ?? '0.00';

if (!$paymentId) json_error('VALIDATION_ERROR', 'payment_id is required.', 422);
if (!$bankAccountId) json_error('VALIDATION_ERROR', 'bank_account_id is required.', 422);
if (bccomp($nsfFee, '0.00', 2) < 0) {
    json_error('VALIDATION_ERROR', 'nsf_fee cannot be negative.', 422);
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
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}
