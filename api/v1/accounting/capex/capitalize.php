<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/capitalize.php
 *
 * Capitalize a flagged work order: creates an acc_fixed_assets row
 * and an acc_capex_requests row (status='completed') linking them.
 *
 * @method  POST
 * @body    JSON: work_order_id, asset_data{...}
 *           asset_data fields override defaults inside
 *           FixedAssetService::capitalizeFlaggedWorkOrder().
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 { capex_request, asset } | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$woId = clean_int($body['work_order_id'] ?? null);
if (!$woId) {
    json_error('MISSING_REQUIRED', 'work_order_id is required.', 422);
}

$assetData = $body['asset_data'] ?? [];
if (!is_array($assetData)) {
    json_error('VALIDATION_ERROR', 'asset_data must be an object.', 422);
}

// Required GL accounts must always be supplied
foreach (['asset_account_id', 'accum_depr_account_id', 'depr_expense_account_id'] as $k) {
    if (empty($assetData[$k])) {
        json_error('MISSING_REQUIRED', "asset_data.{$k} is required.", 422);
    }
}

try {
    $u = current_user();
    $result = FixedAssetService::capitalizeFlaggedWorkOrder(
        $woId,
        $assetData,
        current_user_id(),
        (string) ($u['role_slug'] ?? '')
    );
} catch (\RuntimeException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($result);
