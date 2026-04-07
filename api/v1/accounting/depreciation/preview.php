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
    json_validation_error(['period_id' => 'Please select an accounting period.']);
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
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'period') !== false || stripos($msg, 'not found') !== false) {
        $slot = 'period_id';
    } elseif (stripos($msg, 'units') !== false) {
        $slot = 'unit_overrides';
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($result, 201);
