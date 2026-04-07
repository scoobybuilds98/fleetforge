<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/complete.php
 *
 * Mark an approved/in_progress CapEx complete and (optionally) auto-create
 * a linked fixed asset using values pre-filled from the request.
 *
 * @method  POST
 * @body    JSON: id, actual_amount?, asset_id? (link to existing asset),
 *                asset_data? (object — auto-create the asset using these fields)
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 capex row | 422
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

$id           = clean_int($body['id'] ?? null);
$actualAmount = clean_decimal($body['actual_amount'] ?? null);

if (!$id) {
    $fields['id'] = 'CapEx request ID is required.';
}
if ($actualAmount !== null && $actualAmount !== '' && bccomp($actualAmount, '0.00', 2) < 0) {
    $fields['actual_amount'] = 'Actual amount cannot be negative.';
}

if ($fields) {
    json_validation_error($fields);
}

$payload = [
    'actual_amount' => $actualAmount,
    'asset_id'      => clean_int($body['asset_id'] ?? null),
];

if (!empty($body['asset_data']) && is_array($body['asset_data'])) {
    $ad = $body['asset_data'];
    $payload['asset_data'] = [
        'name'                    => clean_string($ad['name'] ?? null, 255),
        'description'             => clean_string($ad['description'] ?? null, 2000),
        'asset_class'             => clean_string($ad['asset_class'] ?? null, 50),
        'acquisition_date'        => clean_date($ad['acquisition_date'] ?? null),
        'depreciation_start_date' => clean_date($ad['depreciation_start_date'] ?? null),
        'acquisition_cost'        => clean_decimal($ad['acquisition_cost'] ?? null),
        'salvage_value'           => clean_decimal($ad['salvage_value'] ?? '0.00'),
        'useful_life_years'       => clean_decimal($ad['useful_life_years'] ?? null),
        'depreciation_method'     => clean_string($ad['depreciation_method'] ?? 'straight_line', 50),
        'cra_class'               => clean_string($ad['cra_class'] ?? null, 20),
        'cra_cca_rate'            => clean_decimal($ad['cra_cca_rate'] ?? null),
        'total_expected_units'    => clean_int($ad['total_expected_units'] ?? null),
        'asset_account_id'        => clean_int($ad['asset_account_id'] ?? null),
        'accum_depr_account_id'   => clean_int($ad['accum_depr_account_id'] ?? null),
        'depr_expense_account_id' => clean_int($ad['depr_expense_account_id'] ?? null),
        'equipment_unit_id'       => clean_int($ad['equipment_unit_id'] ?? null),
        'vendor_id'               => clean_int($ad['vendor_id'] ?? null),
        'serial_number'           => clean_string($ad['serial_number'] ?? null, 100),
        'location'                => clean_string($ad['location'] ?? null, 255),
        'notes'                   => clean_string($ad['notes'] ?? null, 2000),
    ];
}

try {
    $capex = FixedAssetService::completeCapex($id, $payload, current_user_id());
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'not found') !== false || stripos($msg, 'status') !== false || stripos($msg, 'already') !== false) {
        $slot = 'id';
    } elseif (stripos($msg, 'actual') !== false) {
        $slot = 'actual_amount';
    } elseif (stripos($msg, 'asset_data') !== false || stripos($msg, 'asset data') !== false) {
        $slot = '_general';
    }
    json_validation_error([$slot => $msg], $msg);
}

json_success($capex);
