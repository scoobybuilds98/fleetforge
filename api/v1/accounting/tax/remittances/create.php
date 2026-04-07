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

require_input([
    'filing_period_id' => 'Filing period',
    'remittance_date'  => 'Remittance date',
    'amount'           => 'Amount',
    'payment_method'   => 'Payment method',
    'updated_at'       => 'updated_at (optimistic lock)',
]);

$body            = json_body();
$periodId        = clean_int($body['filing_period_id']);
$remittanceDate  = clean_date($body['remittance_date']);
$amount          = clean_decimal($body['amount']);
$paymentMethod   = clean_string($body['payment_method']);
$bankAccountId   = clean_int($body['bank_account_id'] ?? null);
$referenceNumber = clean_string($body['reference_number'] ?? null, 100);
$notes           = clean_string($body['notes'] ?? null, 1000);
$updatedAt       = clean_string($body['updated_at']);

if (!$periodId) {
    json_error('MISSING_REQUIRED', 'filing_period_id must be a positive integer.', 422);
}

// D19 optimistic lock check on the parent period.
$current = db_row(
    "SELECT updated_at, status FROM acc_tax_filing_periods WHERE id = ?",
    [$periodId]
);
if (!$current) {
    json_error('NOT_FOUND', 'Tax filing period not found.', 404);
}
if ((string) $current['updated_at'] !== $updatedAt) {
    json_error(
        'STALE_DATA',
        'This tax period was modified by another user. Please refresh and try again.',
        409
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
    if (str_starts_with($e->getMessage(), 'INVALID_TRANSITION')) {
        json_error('INVALID_TRANSITION', $e->getMessage(), 409);
    }
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result, 201);
