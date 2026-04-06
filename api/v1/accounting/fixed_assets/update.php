<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/update.php
 *
 * Update a fixed asset using D19 optimistic locking on updated_at.
 * Disposed assets cannot be edited.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required — optimistic lock),
 *                editable fields (name, description, asset_class, cra_class,
 *                cra_cca_rate, equipment_unit_id, depreciation_method,
 *                useful_life_years, salvage_value, total_expected_units,
 *                vendor_id, asset_account_id, accum_depr_account_id,
 *                depr_expense_account_id, location, serial_number, notes)
 * @auth    Session required; require_permission('fixed_assets','edit')
 * @returns 200 updated asset | 409 STALE_DATA | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$updatedAt = clean_string($body['updated_at'] ?? null);
if (!$updatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for the optimistic lock.', 422);
}

// Build sanitized update set — only fields explicitly present
$data = ['updated_at' => $updatedAt];
$fields = [
    'name'                    => 'string',
    'description'             => 'string',
    'asset_class'             => 'string',
    'cra_class'               => 'string',
    'cra_cca_rate'            => 'decimal',
    'equipment_unit_id'       => 'int',
    'depreciation_method'     => 'string',
    'useful_life_years'       => 'decimal',
    'salvage_value'           => 'decimal',
    'total_expected_units'    => 'int',
    'vendor_id'               => 'int',
    'asset_account_id'        => 'int',
    'accum_depr_account_id'   => 'int',
    'depr_expense_account_id' => 'int',
    'location'                => 'string',
    'serial_number'           => 'string',
    'notes'                   => 'string',

    // ── PAYOFF-1: acquisition detail + financing + fixed costs ──
    // WHY: Any of these fields can be edited after creation as the
    // user gathers real invoices or refinances the asset.
    'purchase_tax_gst'           => 'decimal',
    'purchase_tax_pst'           => 'decimal',
    'delivery_cost'              => 'decimal',
    'setup_cost'                 => 'decimal',
    'is_financed'                => 'bool',
    'financing_monthly_payment'  => 'decimal',
    'financing_interest_rate'    => 'decimal',
    'financing_remaining_months' => 'int',
    'monthly_insurance_cost'     => 'decimal',
    'monthly_licensing_cost'     => 'decimal',
    'monthly_registration_cost'  => 'decimal',
];

foreach ($fields as $field => $type) {
    if (array_key_exists($field, $body)) {
        $val = $body[$field];
        if ($type === 'int') {
            $data[$field] = clean_int($val);
        } elseif ($type === 'decimal') {
            $data[$field] = clean_decimal($val);
        } elseif ($type === 'bool') {
            // Coerce any truthy value to 1, any falsy value to 0.
            $data[$field] = !empty($val) ? 1 : 0;
        } else {
            $data[$field] = clean_string($val, 2000);
        }
    }
}

try {
    $asset = FixedAssetService::update($id, $data, current_user_id());
} catch (\RuntimeException $e) {
    // STALE_DATA → 409, everything else → 422
    if (str_starts_with($e->getMessage(), 'STALE_DATA')) {
        json_error('STALE_DATA', 'This asset was modified by another user. Please refresh and try again.', 409);
    }
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($asset);
