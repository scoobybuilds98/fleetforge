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

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

try {
    $period = TaxFilingService::calculatePeriod($id, current_user_id());
} catch (\RuntimeException $e) {
    // PERIOD_LOCKED → 409, everything else → 422
    if (str_starts_with($e->getMessage(), 'PERIOD_LOCKED')) {
        json_error('PERIOD_LOCKED', $e->getMessage(), 409);
    }
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($period);
