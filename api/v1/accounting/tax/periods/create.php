<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/periods/create.php
 *
 * Create a new tax filing period (gst_hst, pst_bc, pst_sk, pst_mb)
 * for a given date range and frequency. The service computes
 * filing_due_date per CRA rules and inserts the row in status='open'.
 *
 * @method  POST
 * @body    JSON: tax_type, period_start, period_end, frequency, notes?
 * @auth    Session required; require_permission('tax_management','create')
 * @returns 201 { id } | 422 validation error
 *
 * @depends api/bootstrap.php, TaxFilingService
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

use FleetForge\Accounting\TaxFilingService;

require_method('POST');
require_auth_api();
require_permission('tax_management', 'create');

require_input([
    'tax_type'     => 'Tax type',
    'period_start' => 'Period start',
    'period_end'   => 'Period end',
    'frequency'    => 'Frequency',
]);

$body = json_body();

$data = [
    'tax_type'     => clean_string($body['tax_type']),
    'period_start' => clean_date($body['period_start']),
    'period_end'   => clean_date($body['period_end']),
    'frequency'    => clean_string($body['frequency']),
    'notes'        => clean_string($body['notes'] ?? null, 1000),
];

try {
    $newId = TaxFilingService::createPeriod($data, current_user_id());
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success(['id' => $newId], 201);
