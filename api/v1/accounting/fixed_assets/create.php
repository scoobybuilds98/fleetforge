<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/create.php
 *
 * Create a new fixed asset. Delegates all business validation to
 * FixedAssetService::create() (required fields, depreciable_cost,
 * salvage <= cost, method-specific guards).
 *
 * @method  POST
 * @body    JSON: name, asset_class, acquisition_date, acquisition_cost,
 *                salvage_value, depreciation_method (straight_line | declining_balance | units_of_production),
 *                useful_life_years (SL only), cra_cca_rate (DB only),
 *                total_expected_units (UOP only), asset_account_id,
 *                accum_depr_account_id, depr_expense_account_id,
 *                equipment_unit_id?, vendor_id?, location?, serial_number?, notes?,
 *                purchase_tax_gst?, purchase_tax_pst?, delivery_cost?, setup_cost?,
 *                is_financed?, financing_monthly_payment?, financing_interest_rate?,
 *                financing_remaining_months?, monthly_insurance_cost?,
 *                monthly_licensing_cost?, monthly_registration_cost?
 * @auth    Session required; require_permission('fixed_assets','create')
 * @returns 201 created asset row | 422 validation error
 *
 * @depends api/bootstrap.php, FixedAssetService
 *
 * @session PAYOFF-1 — added acquisition-detail, financing, and monthly
 *                     fixed-cost fields for the Unit Payoff Calculator.
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('POST');
require_auth_api();
require_permission('fixed_assets', 'create');

$body   = json_body();
$fields = [];

// ── Sanitize inputs
$name               = clean_string($body['name'] ?? null, 255);
$assetClass         = clean_string($body['asset_class'] ?? null, 50);
$acquisitionDate    = clean_date($body['acquisition_date'] ?? null);
$acquisitionCost    = clean_decimal($body['acquisition_cost'] ?? null);
$salvageValue       = clean_decimal($body['salvage_value'] ?? '0.00');
$assetAccountId     = clean_int($body['asset_account_id'] ?? null);
$accumDeprAcctId    = clean_int($body['accum_depr_account_id'] ?? null);
$deprExpenseAcctId  = clean_int($body['depr_expense_account_id'] ?? null);
$method             = clean_string($body['depreciation_method'] ?? 'straight_line', 50);
$usefulLifeYears    = clean_decimal($body['useful_life_years'] ?? null);
$ccaRate            = clean_decimal($body['cra_cca_rate'] ?? null);
$totalExpectedUnits = clean_int($body['total_expected_units'] ?? null);

// ── Required fields + ranges (VALID-2 accumulator)
if (!$name)              $fields['name'] = 'Asset name is required.';
if (!$assetClass)        $fields['asset_class'] = 'Please select an asset class.';
if (!$acquisitionDate)   $fields['acquisition_date'] = 'Acquisition date is required.';
if ($acquisitionCost === null || $acquisitionCost === '') {
    $fields['acquisition_cost'] = 'Acquisition cost is required.';
} elseif (bccomp($acquisitionCost, '0.00', 2) <= 0) {
    $fields['acquisition_cost'] = 'Acquisition cost must be greater than zero.';
}
if ($salvageValue !== null && $salvageValue !== '') {
    if (bccomp($salvageValue, '0.00', 2) < 0) {
        $fields['salvage_value'] = 'Salvage value cannot be negative.';
    } elseif ($acquisitionCost !== null && bccomp($salvageValue, $acquisitionCost, 2) > 0) {
        $fields['salvage_value'] = 'Salvage value cannot exceed acquisition cost.';
    }
}
if (!$assetAccountId)    $fields['asset_account_id'] = 'Please select an asset GL account.';
if (!$accumDeprAcctId)   $fields['accum_depr_account_id'] = 'Please select an accumulated depreciation account.';
if (!$deprExpenseAcctId) $fields['depr_expense_account_id'] = 'Please select a depreciation expense account.';

$validMethods = ['straight_line', 'declining_balance', 'units_of_production', 'none'];
if (!in_array($method, $validMethods, true)) {
    $fields['depreciation_method'] = 'Please select a valid depreciation method.';
} else {
    // Method-specific checks
    if ($method === 'straight_line') {
        if ($usefulLifeYears === null || $usefulLifeYears === '' || bccomp($usefulLifeYears, '0', 2) <= 0) {
            $fields['useful_life_years'] = 'Useful life must be at least 1 year for straight-line depreciation.';
        }
    }
    if ($method === 'declining_balance') {
        if ($ccaRate === null || $ccaRate === '' || bccomp($ccaRate, '0', 4) <= 0) {
            $fields['cra_cca_rate'] = 'CCA rate is required for declining balance.';
        } elseif (bccomp($ccaRate, '100', 4) > 0) {
            $fields['cra_cca_rate'] = 'CCA rate cannot exceed 100%.';
        }
    }
    if ($method === 'units_of_production') {
        if (!$totalExpectedUnits || $totalExpectedUnits <= 0) {
            $fields['total_expected_units'] = 'Total expected units must be greater than zero for units-of-production.';
        }
    }
}

if ($fields) {
    json_validation_error($fields);
}

// Build a sanitized payload — service does its own validation
$data = [
    'name'                    => $name,
    'description'             => clean_string($body['description'] ?? null, 2000),
    'asset_class'             => $assetClass,
    'cra_class'               => clean_string($body['cra_class'] ?? null, 20),
    'cra_cca_rate'            => $ccaRate,
    'equipment_unit_id'       => clean_int($body['equipment_unit_id'] ?? null),
    'acquisition_date'        => $acquisitionDate,
    'depreciation_start_date' => clean_date($body['depreciation_start_date'] ?? null),
    'acquisition_cost'        => $acquisitionCost,
    'vendor_id'               => clean_int($body['vendor_id'] ?? null),
    'depreciation_method'     => $method,
    'useful_life_years'       => $usefulLifeYears,
    'salvage_value'           => $salvageValue,
    'asset_account_id'        => $assetAccountId,
    'accum_depr_account_id'   => $accumDeprAcctId,
    'depr_expense_account_id' => $deprExpenseAcctId,
    'total_expected_units'    => $totalExpectedUnits,
    'location'                => clean_string($body['location'] ?? null, 255),
    'serial_number'           => clean_string($body['serial_number'] ?? null, 100),
    'notes'                   => clean_string($body['notes'] ?? null, 2000),

    // ── PAYOFF-1: acquisition-detail breakdown ──────────────────
    // WHY: Total acquisition cost used by the payoff calculator
    // needs tax / delivery / setup broken out so we can sum them
    // without touching the depreciable_cost or acquisition_cost
    // that the GL already depends on.
    'purchase_tax_gst'          => clean_decimal($body['purchase_tax_gst'] ?? null),
    'purchase_tax_pst'          => clean_decimal($body['purchase_tax_pst'] ?? null),
    'delivery_cost'             => clean_decimal($body['delivery_cost'] ?? null),
    'setup_cost'                => clean_decimal($body['setup_cost'] ?? null),

    // ── PAYOFF-1: financing (optional) ──────────────────────────
    // WHY: When the asset is financed we subtract payments already
    // made from net revenue before computing payoff progress.
    'is_financed'               => !empty($body['is_financed']) ? 1 : 0,
    'financing_monthly_payment' => clean_decimal($body['financing_monthly_payment'] ?? null),
    'financing_interest_rate'   => clean_decimal($body['financing_interest_rate'] ?? null),
    'financing_remaining_months'=> clean_int($body['financing_remaining_months'] ?? null),

    // ── PAYOFF-1: monthly recurring fixed costs ─────────────────
    // WHY: Insurance, licensing, and registration are true costs
    // of ownership that reduce net revenue every month.
    'monthly_insurance_cost'    => clean_decimal($body['monthly_insurance_cost'] ?? null),
    'monthly_licensing_cost'    => clean_decimal($body['monthly_licensing_cost'] ?? null),
    'monthly_registration_cost' => clean_decimal($body['monthly_registration_cost'] ?? null),
];

try {
    $asset = FixedAssetService::create($data, current_user_id());
} catch (\RuntimeException $e) {
    // Map service errors back to field slots when possible
    $msg  = $e->getMessage();
    $slot = '_general';
    if (stripos($msg, 'acquisition_cost') !== false)        $slot = 'acquisition_cost';
    elseif (stripos($msg, 'salvage') !== false)             $slot = 'salvage_value';
    elseif (stripos($msg, 'useful_life') !== false)         $slot = 'useful_life_years';
    elseif (stripos($msg, 'cca') !== false || stripos($msg, 'declining') !== false) $slot = 'cra_cca_rate';
    elseif (stripos($msg, 'total_expected_units') !== false) $slot = 'total_expected_units';
    elseif (stripos($msg, 'depreciation_method') !== false) $slot = 'depreciation_method';
    json_validation_error([$slot => $msg], $msg);
}

json_success($asset, 201);
