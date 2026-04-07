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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$body     = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id        = clean_int($body['id'] ?? null);
$filedDate = clean_date($body['filed_date'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        $fields['id']         = 'Tax period ID is required.';
if (!$filedDate) $fields['filed_date'] = 'Filed date is required.';
if (!$updatedAt) $fields['updated_at'] = 'Concurrency token (updated_at) is required.';

if ($fields) {
    json_validation_error($fields);
}

// D19 optimistic lock check — read current updated_at and compare
// before delegating to the service. The service does its own status
// guard inside a SELECT FOR UPDATE so this is just an early bail.
$current = db_row(
    "SELECT updated_at FROM acc_tax_filing_periods WHERE id = ?",
    [$id]
);
if (!$current) {
    json_error('NOT_FOUND', 'Tax filing period not found.', 404, [
        'fields' => ['id' => 'Tax filing period not found.'],
    ]);
}
if ((string) $current['updated_at'] !== $updatedAt) {
    json_error(
        'STALE_DATA',
        'This tax period was modified by another user. Please refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This tax period was modified by another user. Please refresh and try again.']]
    );
}

try {
    $period = TaxFilingService::markFiled($id, $filedDate, current_user_id());
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_starts_with($msg, 'INVALID_TRANSITION')) {
        json_error('INVALID_TRANSITION', $msg, 409, ['fields' => ['id' => $msg]]);
    }
    $slot = '_general';
    if (stripos($msg, 'date') !== false || stripos($msg, 'filed') !== false) $slot = 'filed_date';
    elseif (stripos($msg, 'period') !== false || stripos($msg, 'not') !== false) $slot = 'id';
    json_validation_error([$slot => $msg], $msg);
}

json_success($period);
