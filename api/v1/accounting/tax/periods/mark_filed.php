<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/periods/mark_filed.php
 *
 * Mark a calculated tax filing period as filed with the tax authority.
 * Records who filed it and on what date. Does NOT post any JE — filing
 * is paperwork; payment happens via /remittances/create.php.
 *
 * D19 optimistic locking: caller must echo back the period's updated_at
 * so a stale view is rejected with 409 STALE_DATA.
 *
 * @method  POST
 * @body    JSON: id, filed_date, updated_at
 * @auth    Session required; require_permission('tax_management','edit')
 * @returns 200 updated period | 409 STALE_DATA | 422 validation error
 *
 * @depends api/bootstrap.php, TaxFilingService
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\TaxFilingService;

require_method('POST');
require_auth_api();
require_permission('tax_management', 'edit');

require_input([
    'id'         => 'Period ID',
    'filed_date' => 'Filed date',
    'updated_at' => 'updated_at (optimistic lock)',
]);

$body       = json_body();
$id         = clean_int($body['id']);
$filedDate  = clean_date($body['filed_date']);
$updatedAt  = clean_string($body['updated_at']);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id must be a positive integer.', 422);
}

// D19 optimistic lock check — read current updated_at and compare
// before delegating to the service. The service does its own status
// guard inside a SELECT FOR UPDATE so this is just an early bail.
$current = db_row(
    "SELECT updated_at FROM acc_tax_filing_periods WHERE id = ?",
    [$id]
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

try {
    $period = TaxFilingService::markFiled($id, $filedDate, current_user_id());
} catch (\RuntimeException $e) {
    if (str_starts_with($e->getMessage(), 'INVALID_TRANSITION')) {
        json_error('INVALID_TRANSITION', $e->getMessage(), 409);
    }
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($period);
