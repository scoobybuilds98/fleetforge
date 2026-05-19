<?php
declare(strict_types=1);

/**
 * api/v1/accounting/impairment/preview-cf.php
 *
 * Preview the default ASPE 3063 step 1 undiscounted-CF estimate for
 * an asset without writing anything. Used by the admin UI's per-asset
 * "Inspect Estimator" affordance so the operator can see the breakdown
 * (revenue history, remaining useful life, estimated disposal) before
 * deciding whether to accept the default or supply a cf_override.
 *
 * @method  GET
 * @query   asset_id (required, int > 0)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { asset_id, avg_monthly_revenue, remaining_useful_months,
 *                future_revenue, estimated_disposal, undiscounted_cf,
 *                breakdown_json, has_revenue_history }
 *          404 asset not found
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.8
 * Session: S-ACCT-LESSOR-6
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\ImpairmentTestService;

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$assetId = clean_positive_int($_GET['asset_id'] ?? null);
if ($assetId === null) {
    json_error('VALIDATION_ERROR', 'asset_id is required and must be a positive integer.', 422);
}

try {
    $estimate = ImpairmentTestService::estimateUndiscountedCf($assetId);
} catch (\InvalidArgumentException $e) {
    json_error('NOT_FOUND', $e->getMessage(), 404);
}

json_success($estimate);
