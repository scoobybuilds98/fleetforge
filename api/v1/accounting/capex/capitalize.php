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

$body   = json_body();
$fields = [];

$woId      = clean_int($body['work_order_id'] ?? null);
$assetData = $body['asset_data'] ?? [];

// ── Phase 1: required-field accumulator ──
if (!$woId) {
    $fields['work_order_id'] = 'Please select a work order to capitalize.';
}
if (!is_array($assetData)) {
    $fields['_general'] = 'Asset data is missing or malformed.';
    $assetData = [];
}

// Required GL accounts must always be supplied
$slotMap = [
    'asset_account_id'        => 'Please select an asset GL account.',
    'accum_depr_account_id'   => 'Please select an accumulated depreciation account.',
    'depr_expense_account_id' => 'Please select a depreciation expense account.',
];
foreach ($slotMap as $k => $msg) {
    if (empty($assetData[$k])) {
        $fields["asset_data.{$k}"] = $msg;
    }
}

// Asset name & cost are always required at capitalize time
if (empty($assetData['name'])) {
    $fields['asset_data.name'] = 'Asset name is required.';
}
if (empty($assetData['acquisition_cost'])
    || bccomp((string)$assetData['acquisition_cost'], '0.00', 2) <= 0) {
    $fields['asset_data.acquisition_cost'] = 'Acquisition cost must be greater than zero.';
}

if ($fields) {
    json_validation_error($fields);
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
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'work order') !== false || stripos($msg, 'not found') !== false) {
        $slot = 'work_order_id';
    } elseif (stripos($msg, 'acquisition') !== false || stripos($msg, 'cost') !== false) {
        $slot = 'asset_data.acquisition_cost';
    } elseif (stripos($msg, 'manager') !== false) {
        json_error('FORBIDDEN', $msg, 403, ['fields' => ['_general' => $msg]]);
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($result);
