<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/show.php
 *
 * Return a single fixed asset with its full depreciation forecast
 * (depreciationSchedule), linked equipment unit info, and asset accounts.
 *
 * @method  GET
 * @query   id (required), include_schedule (default 1)
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 { asset, schedule? } | 404
 *
 * @depends api/bootstrap.php, FixedAssetService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Accounting\FixedAssetService;

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$asset = db_row(
    "SELECT a.*, eu.unit_number AS equipment_unit_number,
            ac.code AS asset_account_code, ac.name AS asset_account_name,
            ad.code AS accum_depr_account_code, ad.name AS accum_depr_account_name,
            de.code AS depr_expense_account_code, de.name AS depr_expense_account_name
     FROM acc_fixed_assets a
     LEFT JOIN equipment_units eu ON eu.id = a.equipment_unit_id
     LEFT JOIN acc_accounts ac ON ac.id = a.asset_account_id
     LEFT JOIN acc_accounts ad ON ad.id = a.accum_depr_account_id
     LEFT JOIN acc_accounts de ON de.id = a.depr_expense_account_id
     WHERE a.id = ?",
    [$id]
);

if (!$asset) {
    json_error('NOT_FOUND', 'Asset not found.', 404);
}

$includeSchedule = ($_GET['include_schedule'] ?? '1') !== '0';
$schedule = $includeSchedule ? FixedAssetService::depreciationSchedule($id) : [];

// Disposal record (if any)
$disposal = db_row(
    "SELECT * FROM acc_asset_disposals WHERE asset_id = ? ORDER BY id DESC LIMIT 1",
    [$id]
);

// Impairment history
$impairments = db_select(
    "SELECT * FROM acc_asset_impairments WHERE asset_id = ? ORDER BY impairment_date DESC",
    [$id]
);

// S-ACCT-COMP: component children + parent context. Skipped when the asset
// is itself a component (no nesting) — instead surface a parent_summary
// pointing back up so the UI can render a "Part of: {parent}" link.
$components = [];
$totalNbv   = (string) $asset['net_book_value'];
$parentSummary = null;
if ((int) ($asset['is_component'] ?? 0) === 0) {
    $components = db_select(
        "SELECT id, asset_number, name, asset_class, acquisition_date,
                available_for_use_date, acquisition_cost, accumulated_depreciation,
                net_book_value, useful_life_years, depreciation_method, status,
                cca_class_id, is_aiip_eligible
           FROM acc_fixed_assets
          WHERE parent_asset_id = ?
          ORDER BY id ASC",
        [$id]
    );
    foreach ($components as $c) {
        $totalNbv = bcadd($totalNbv, (string) $c['net_book_value'], 2);
    }
} elseif (!empty($asset['parent_asset_id'])) {
    $parentSummary = db_row(
        "SELECT id, asset_number, name FROM acc_fixed_assets WHERE id = ?",
        [(int) $asset['parent_asset_id']]
    );
}

json_success([
    'asset'           => $asset,
    'schedule'        => $schedule,
    'disposal'        => $disposal,
    'impairments'     => $impairments,
    'components'      => $components,
    'component_count' => count($components),
    'total_nbv'       => $totalNbv,
    'parent_summary'  => $parentSummary,
]);
