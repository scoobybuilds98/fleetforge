<?php declare(strict_types=1);

/**
 * api/v1/accounting/depreciation/preview.php
 *
 * Generate a depreciation preview run for a given period.
 * Creates an acc_depreciation_runs row in 'preview' status with one
 * acc_depreciation_run_lines per active asset.
 * Use with /post.php to actually post the JE.
 *
 * @method  POST
 * @body    JSON: period_id, unit_overrides? (per-asset units for UOP)
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 201 { run, lines } | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$periodId = clean_int($body['period_id'] ?? null);
if (!$periodId) {
    json_error('MISSING_REQUIRED', 'period_id is required.', 422);
}

$overrides = [];
if (!empty($body['unit_overrides']) && is_array($body['unit_overrides'])) {
    foreach ($body['unit_overrides'] as $assetId => $units) {
        $aid = (int) $assetId;
        $u   = (int) $units;
        if ($aid > 0 && $u >= 0) $overrides[$aid] = $u;
    }
}

try {
    $result = FixedAssetService::previewRun($periodId, current_user_id(), $overrides);
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result, 201);
