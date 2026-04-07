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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$body     = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$taxType     = clean_string($body['tax_type'] ?? null);
$periodStart = clean_date($body['period_start'] ?? null);
$periodEnd   = clean_date($body['period_end'] ?? null);
$frequency   = clean_string($body['frequency'] ?? null);

$validTaxTypes = ['gst_hst', 'pst_bc', 'pst_sk', 'pst_mb', 'qst'];
if (!$taxType) {
    $fields['tax_type'] = 'Please select a tax type.';
} elseif (!in_array($taxType, $validTaxTypes, true)) {
    $fields['tax_type'] = 'Tax type must be one of: ' . implode(', ', $validTaxTypes) . '.';
}

if (!$periodStart) $fields['period_start'] = 'Period start date is required.';
if (!$periodEnd)   $fields['period_end']   = 'Period end date is required.';

if ($periodStart && $periodEnd && strtotime($periodEnd) < strtotime($periodStart)) {
    $fields['period_end'] = 'Period end date must be on or after period start date.';
}

$validFrequencies = ['monthly', 'quarterly', 'annual'];
if (!$frequency) {
    $fields['frequency'] = 'Please select a filing frequency.';
} elseif (!in_array($frequency, $validFrequencies, true)) {
    $fields['frequency'] = 'Frequency must be monthly, quarterly, or annual.';
}

if ($fields) {
    json_validation_error($fields);
}

$data = [
    'tax_type'     => $taxType,
    'period_start' => $periodStart,
    'period_end'   => $periodEnd,
    'frequency'    => $frequency,
    'notes'        => clean_string($body['notes'] ?? null, 1000),
];

try {
    $newId = TaxFilingService::createPeriod($data, current_user_id());
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'tax') !== false && stripos($msg, 'type') !== false) $slot = 'tax_type';
    elseif (stripos($msg, 'start') !== false) $slot = 'period_start';
    elseif (stripos($msg, 'end') !== false) $slot = 'period_end';
    elseif (stripos($msg, 'frequency') !== false) $slot = 'frequency';
    elseif (stripos($msg, 'overlap') !== false || stripos($msg, 'exist') !== false) $slot = 'period_start';
    json_validation_error([$slot => $msg], $msg);
}

json_success(['id' => $newId], 201);
