<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/remittances/create.php
 *
 * Record a CRA / tax-authority remittance payment for a filed tax
 * period. Inserts the acc_tax_remittances row, posts a 2-line JE
 *   DR 2030 GST/HST Payable (or 2040 PST Payable)
 *     CR <bank.gl_account_id>     (or 1010 Cash fallback)
 * inside one db_transaction, then flips the period status from
 * 'filed' to 'remitted'.
 *
 * If remittance_date falls in a closed period, §16 redirect kicks
 * in transparently and the JE lands in the earliest open period
 * (logged to audit_log so the redirect is auditable).
 *
 * D19 optimistic locking: caller must echo back the period's
 * updated_at to guard against double-remit on a stale view.
 *
 * @method  POST
 * @body    JSON: filing_period_id, remittance_date, amount,
 *                payment_method, bank_account_id?, reference_number?,
 *                notes?, updated_at
 * @auth    Session required; require_permission('tax_management','edit')
 * @returns 201 { remittance, journal_entry_id } | 409 | 422
 *
 * @depends api/bootstrap.php, TaxFilingService
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\TaxFilingService;

require_method('POST');
require_auth_api();
require_permission('tax_management', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$body     = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$periodId        = clean_int($body['filing_period_id'] ?? null);
$remittanceDate  = clean_date($body['remittance_date'] ?? null);
$amount          = clean_decimal($body['amount'] ?? null);
$paymentMethod   = clean_string($body['payment_method'] ?? null);
$bankAccountId   = clean_int($body['bank_account_id'] ?? null);
$referenceNumber = clean_string($body['reference_number'] ?? null, 100);
$notes           = clean_string($body['notes'] ?? null, 1000);
$updatedAt       = clean_string($body['updated_at'] ?? null);

if (!$periodId)       $fields['filing_period_id'] = 'Please select a tax filing period.';
if (!$remittanceDate) $fields['remittance_date']  = 'Remittance date is required.';

if ($amount === null || $amount === '') {
    $fields['amount'] = 'Remittance amount is required.';
} elseif (bccomp($amount, '0', 2) <= 0) {
    $fields['amount'] = 'Remittance amount must be greater than zero.';
}

$validMethods = ['eft', 'wire', 'check', 'online_banking', 'credit_card', 'other'];
if (!$paymentMethod) {
    $fields['payment_method'] = 'Please select a payment method.';
} elseif (!in_array($paymentMethod, $validMethods, true)) {
    $fields['payment_method'] = 'Payment method must be one of: ' . implode(', ', $validMethods) . '.';
}

if (!$updatedAt) $fields['updated_at'] = 'Concurrency token (updated_at) is required.';

if ($fields) {
    json_validation_error($fields);
}

// D19 optimistic lock check on the parent period.
$current = db_row(
    "SELECT updated_at, status FROM acc_tax_filing_periods WHERE id = ?",
    [$periodId]
);
if (!$current) {
    json_error('NOT_FOUND', 'Tax filing period not found.', 404, [
        'fields' => ['filing_period_id' => 'Tax filing period not found.'],
    ]);
}
if (!optimistic_lock_matches($updatedAt, $current['updated_at'])) {
    json_error(
        'STALE_DATA',
        'This tax period was modified by another user. Please refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This tax period was modified by another user. Please refresh and try again.']]
    );
}

$payload = [
    'remittance_date'  => $remittanceDate,
    'amount'           => $amount,
    'payment_method'   => $paymentMethod,
    'bank_account_id'  => $bankAccountId,
    'reference_number' => $referenceNumber,
    'notes'            => $notes,
];

try {
    $result = TaxFilingService::recordRemittance($periodId, $payload, current_user_id());
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_starts_with($msg, 'INVALID_TRANSITION')) {
        json_error('INVALID_TRANSITION', $msg, 409, ['fields' => ['filing_period_id' => $msg]]);
    }
    $slot = '_general';
    if (stripos($msg, 'bank') !== false) $slot = 'bank_account_id';
    elseif (stripos($msg, 'amount') !== false) $slot = 'amount';
    elseif (stripos($msg, 'method') !== false) $slot = 'payment_method';
    elseif (stripos($msg, 'date') !== false || stripos($msg, 'period') !== false) $slot = 'remittance_date';
    json_validation_error([$slot => $msg], $msg);
}

json_success($result, 201);
