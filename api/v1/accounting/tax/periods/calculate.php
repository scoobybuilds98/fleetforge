<?php declare(strict_types=1);

/**
 * api/v1/accounting/tax/periods/calculate.php
 *
 * Recompute the tax filing period's totals from posted GL entries
 * (total_sales, total_tax_collected, total_itc, net_tax_owing) and
 * flip its status from 'open'/'calculated' to 'calculated'. Locks
 * the row FOR UPDATE to serialize concurrent recalcs (D20).
 *
 * Refuses if status is 'remitted' (terminal). Filed periods CAN be
 * recalculated as long as they have not been remitted yet, in case
 * a back-dated invoice or bill changes the totals before payment.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('tax_management','edit')
 * @returns 200 updated period | 422 validation error
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

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Tax period ID is required.']);
}

try {
    $period = TaxFilingService::calculatePeriod($id, current_user_id());
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    // PERIOD_LOCKED → 409, everything else → 422
    if (str_starts_with($msg, 'PERIOD_LOCKED')) {
        json_error('PERIOD_LOCKED', $msg, 409, ['fields' => ['id' => $msg]]);
    }
    json_validation_error(['id' => $msg], $msg);
}

json_success($period);
