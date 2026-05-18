<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/add_component.php
 *
 * Add a child component to a parent fixed asset (ASPE 3061.18 per spec §23.5).
 * The component is itself a fixed asset row with its own useful life,
 * depreciation method, and salvage value — it depreciates independently
 * of the parent. The parent's display NBV is the sum of itself + all
 * components (see FixedAssetService::getWithComponents()).
 *
 * STOP CONDITIONS:
 *   - parent_asset_id must exist + is_component=0 (no nested components).
 *   - All asset-create required fields apply (acquisition_cost, GL accounts,
 *     depreciation_method-specific fields).
 *
 * @method  POST
 * @body    JSON: parent_asset_id (required, int), name, asset_class
 *                (K-22: not asset_category), acquisition_cost,
 *                acquisition_date, available_for_use_date?, useful_life_years,
 *                depreciation_method, salvage_value, cra_class_id?,
 *                asset_account_id/accum_depr_account_id/depr_expense_account_id,
 *                notes?
 * @auth    Session required; require_permission('fixed_assets','create')
 * @returns 201 created component row | 422 validation error
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.5
 * Session: S-ACCT-COMP
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'create');

$body   = json_body();
$fields = [];

$parentId = clean_int($body['parent_asset_id'] ?? null);
if (!$parentId) {
    $fields['parent_asset_id'] = 'parent_asset_id is required.';
}

if ($fields) {
    json_validation_error($fields);
}

// Parent guard: must exist and must NOT itself be a component.
$parent = db_row(
    "SELECT id, asset_number, name, is_component
       FROM acc_fixed_assets
      WHERE id = ?",
    [$parentId]
);
if (!$parent) {
    json_error('NOT_FOUND', 'Parent asset not found.', 404);
}
if ((int) $parent['is_component'] === 1) {
    json_error(
        'VALIDATION_ERROR',
        'Cannot nest components — parent asset is already a component. ASPE 3061.18 uses a flat parent+children model.',
        422
    );
}

// Reuse the standard FixedAssetService::create() validation by building the
// expected $data shape. The service handles required-field checks +
// method-specific validation + auto asset_number generation, then we
// post-update the row to set parent_asset_id + is_component + auto-rename
// to a {parent}-CN scheme.
$data = [
    'name'                    => clean_string($body['name'] ?? null, 255),
    'description'             => clean_string($body['description'] ?? null, 2000),
    'asset_class'             => clean_string($body['asset_class'] ?? null, 50),
    'cra_class'               => clean_string($body['cra_class'] ?? null, 20),
    'cra_cca_rate'            => clean_decimal($body['cra_cca_rate'] ?? null),
    'cca_class_id'            => clean_int($body['cca_class_id'] ?? null),
    'available_for_use_date'  => clean_date($body['available_for_use_date'] ?? null),
    'is_aiip_eligible'        => array_key_exists('is_aiip_eligible', $body)
                                    ? (!empty($body['is_aiip_eligible']) ? 1 : 0)
                                    : 1,
    'equipment_unit_id'       => clean_int($body['equipment_unit_id'] ?? null),
    'acquisition_date'        => clean_date($body['acquisition_date'] ?? null),
    'depreciation_start_date' => clean_date($body['depreciation_start_date'] ?? null),
    'acquisition_cost'        => clean_decimal($body['acquisition_cost'] ?? null),
    'vendor_id'               => clean_int($body['vendor_id'] ?? null),
    'depreciation_method'     => clean_string($body['depreciation_method'] ?? 'straight_line', 50),
    'useful_life_years'       => clean_decimal($body['useful_life_years'] ?? null),
    'salvage_value'           => clean_decimal($body['salvage_value'] ?? '0.00'),
    'asset_account_id'        => clean_int($body['asset_account_id'] ?? null),
    'accum_depr_account_id'   => clean_int($body['accum_depr_account_id'] ?? null),
    'depr_expense_account_id' => clean_int($body['depr_expense_account_id'] ?? null),
    'total_expected_units'    => clean_int($body['total_expected_units'] ?? null),
    'notes'                   => clean_string($body['notes'] ?? null, 2000),
];

try {
    $component = FixedAssetService::create($data, current_user_id());
} catch (\RuntimeException $e) {
    json_validation_error(['_general' => $e->getMessage()], $e->getMessage());
}

// Stamp parent + is_component + auto-rename to {parent}-CN.
$existingCount = (int) db_count(
    "SELECT COUNT(*) FROM acc_fixed_assets WHERE parent_asset_id = ?",
    [$parentId]
);
$componentNumber = $parent['asset_number'] . '-C' . ($existingCount + 1);

db_update('acc_fixed_assets', [
    'parent_asset_id' => $parentId,
    'is_component'    => 1,
    'asset_number'    => $componentNumber,
], 'id = ?', [(int) $component['id']]);

$updated = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [(int) $component['id']]);

json_success($updated, 201);
