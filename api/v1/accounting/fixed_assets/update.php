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
use FleetForge\Accounting\CcaService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'edit');

$body        = json_body();
$fieldErrors = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fieldErrors['id'] = 'Asset ID is required.';
}

$updatedAt = clean_string($body['updated_at'] ?? null);
if (!$updatedAt) {
    $fieldErrors['updated_at'] = 'Optimistic lock token is required.';
}
if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

// Build sanitized update set — only fields explicitly present
$data = ['updated_at' => $updatedAt];
$updatableFields = [
    'name'                    => 'string',
    'description'             => 'string',
    'asset_class'             => 'string',
    'cra_class'               => 'string',
    'cra_cca_rate'            => 'decimal',
    // S-ACCT-CCA-1: new CCA fields on the FK side.
    'cca_class_id'            => 'int',
    'available_for_use_date'  => 'date',
    'is_aiip_eligible'        => 'bool',
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

foreach ($updatableFields as $field => $type) {
    if (array_key_exists($field, $body)) {
        $val = $body[$field];
        if ($type === 'int') {
            $data[$field] = clean_int($val);
        } elseif ($type === 'decimal') {
            $data[$field] = clean_decimal($val);
        } elseif ($type === 'bool') {
            // Coerce any truthy value to 1, any falsy value to 0.
            $data[$field] = !empty($val) ? 1 : 0;
        } elseif ($type === 'date') {
            $data[$field] = clean_date($val);
        } else {
            $data[$field] = clean_string($val, 2000);
        }
    }
}

// ── Range validation for common money fields BEFORE hitting service
if (array_key_exists('salvage_value', $data) && $data['salvage_value'] !== null && $data['salvage_value'] !== '') {
    if (bccomp($data['salvage_value'], '0.00', 2) < 0) {
        $fieldErrors['salvage_value'] = 'Salvage value cannot be negative.';
    }
}
if (array_key_exists('useful_life_years', $data) && $data['useful_life_years'] !== null && $data['useful_life_years'] !== '') {
    if (bccomp($data['useful_life_years'], '0', 2) <= 0) {
        $fieldErrors['useful_life_years'] = 'Useful life must be at least 1 year.';
    }
}
if (array_key_exists('cra_cca_rate', $data) && $data['cra_cca_rate'] !== null && $data['cra_cca_rate'] !== '') {
    if (bccomp($data['cra_cca_rate'], '0', 4) < 0) {
        $fieldErrors['cra_cca_rate'] = 'CCA rate cannot be negative.';
    } elseif (bccomp($data['cra_cca_rate'], '100', 4) > 0) {
        $fieldErrors['cra_cca_rate'] = 'CCA rate cannot exceed 100%.';
    }
}
if (array_key_exists('total_expected_units', $data) && $data['total_expected_units'] !== null) {
    if ($data['total_expected_units'] < 0) {
        $fieldErrors['total_expected_units'] = 'Total expected units cannot be negative.';
    }
}

if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

try {
    $asset = FixedAssetService::update($id, $data, current_user_id());
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    // STALE_DATA → 409, everything else → 422
    if (str_starts_with($msg, 'STALE_DATA')) {
        json_error('STALE_DATA',
            'This asset was modified by another user. Refresh and try again.', 409,
            ['fields' => ['updated_at' => 'This asset was modified by another user. Refresh and try again.']]);
    }
    // Map common messages to field slots
    $slot = '_general';
    if (stripos($msg, 'salvage') !== false)              $slot = 'salvage_value';
    elseif (stripos($msg, 'useful_life') !== false)      $slot = 'useful_life_years';
    elseif (stripos($msg, 'disposed') !== false)         $slot = 'id';
    elseif (stripos($msg, 'cca') !== false)              $slot = 'cra_cca_rate';
    elseif (stripos($msg, 'cannot be negative') !== false) {
        // Extract the field name before the colon
        if (preg_match('/^([a-z_]+)\s+cannot/i', $msg, $m)) $slot = $m[1];
    }
    json_validation_error([$slot => $msg], $msg);
}

// S-ACCT-CCA-1: GVWR validator — attach non-blocking warning as `_cca_warning`
// on the asset row (preserves the existing response shape).
$gvwrKg  = clean_int($body['gvwr_kg']  ?? null);
$gvwrLbs = clean_int($body['gvwr_lbs'] ?? null);
if (!$gvwrLbs && !empty($asset['equipment_unit_id'])) {
    $row = db_row(
        "SELECT weight_capacity_lbs FROM equipment_units WHERE id = ?",
        [(int) $asset['equipment_unit_id']]
    );
    $gvwrLbs = $row ? (int) $row['weight_capacity_lbs'] : null;
}
$warning = CcaService::classifyGvwrWarning(
    !empty($asset['cca_class_id']) ? (int) $asset['cca_class_id'] : null,
    $gvwrKg,
    $gvwrLbs
);
if ($warning) {
    $asset['_cca_warning'] = $warning;
}

json_success($asset);
