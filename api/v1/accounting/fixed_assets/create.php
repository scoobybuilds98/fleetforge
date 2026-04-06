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

$body = json_body();

// Build a sanitized payload — service does its own validation
$data = [
    'name'                    => clean_string($body['name'] ?? null, 255),
    'description'             => clean_string($body['description'] ?? null, 2000),
    'asset_class'             => clean_string($body['asset_class'] ?? null, 50),
    'cra_class'               => clean_string($body['cra_class'] ?? null, 20),
    'cra_cca_rate'            => clean_decimal($body['cra_cca_rate'] ?? null),
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
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

json_success($asset, 201);
