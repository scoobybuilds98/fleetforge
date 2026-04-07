<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\FixedAssetService;

// ============================================================
// S034 — TEST ASSET CREATION
// Three assets exercising the three depreciation methods.
// ============================================================

$pad = fn(string $s) => str_pad($s, 70);

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — CREATE TEST FIXED ASSETS" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ── Asset 1: Kenworth Truck — Declining Balance 30% (CRA Class 10) ──────
$asset1 = FixedAssetService::create([
    'name'                    => '2020 Kenworth T680 Truck',
    'description'             => 'Highway tractor — CRA Class 10 declining balance test',
    'asset_class'             => 'fleet_equipment',
    'cra_class'               => '10',
    'cra_cca_rate'            => '0.30',
    'acquisition_date'        => '2025-01-01',
    'depreciation_start_date' => '2025-01-01',
    'acquisition_cost'        => '180000.00',
    'salvage_value'           => '0.00',
    'depreciation_method'     => 'declining_balance',
    'asset_account_id'        => 12,  // 1210 Fleet Equipment Cost
    'accum_depr_account_id'   => 13,  // 1220 Accum Depr
    'depr_expense_account_id' => 50,  // 5010 Depr - Rental Fleet
    'serial_number'           => 'KW-T680-2020-001',
    'location'                => 'Surrey Yard',
], 1);
printf("✅ Asset 1: %s — %s (cost \$%s, method %s, rate %s)\n",
    $asset1['asset_number'], $asset1['name'],
    $asset1['acquisition_cost'], $asset1['depreciation_method'], $asset1['cra_cca_rate']);

// ── Asset 2: Surrey Office Equipment — Straight Line 5yr ─────────────
$asset2 = FixedAssetService::create([
    'name'                    => 'Surrey Yard Office Equipment',
    'description'             => 'Desks, monitors, chairs — straight line test',
    'asset_class'             => 'office_equipment',
    'acquisition_date'        => '2025-01-01',
    'depreciation_start_date' => '2025-01-01',
    'acquisition_cost'        => '15000.00',
    'salvage_value'           => '500.00',
    'depreciation_method'     => 'straight_line',
    'useful_life_years'       => '5',
    'asset_account_id'        => 16, // 1250 Office Equipment Cost
    'accum_depr_account_id'   => 17, // 1260 Office Equip Accum Depr
    'depr_expense_account_id' => 73, // 6190 Depr - Non-Fleet Assets
    'location'                => 'Surrey Office',
], 1);
printf("✅ Asset 2: %s — %s (cost \$%s, salvage \$%s, life %sy, method %s)\n",
    $asset2['asset_number'], $asset2['name'],
    $asset2['acquisition_cost'], $asset2['salvage_value'],
    $asset2['useful_life_years'], $asset2['depreciation_method']);

// ── Asset 3: Trailer Fleet Unit — Units of Production ────────────────
// Use equipment unit #6 (RFR-001) so mileage_logs can drive UoP later.
$asset3 = FixedAssetService::create([
    'name'                    => '2022 Trailer Fleet Unit #47',
    'description'             => '53ft dry van — units of production test',
    'asset_class'             => 'fleet_equipment',
    'equipment_unit_id'       => 6,
    'acquisition_date'        => '2025-01-01',
    'depreciation_start_date' => '2025-01-01',
    'acquisition_cost'        => '45000.00',
    'salvage_value'           => '5000.00',
    'depreciation_method'     => 'units_of_production',
    'total_expected_units'    => 500000,  // 500K miles
    'asset_account_id'        => 12,
    'accum_depr_account_id'   => 13,
    'depr_expense_account_id' => 50,
    'serial_number'           => 'TR-2022-0047',
], 1);
printf("✅ Asset 3: %s — %s (cost \$%s, salvage \$%s, total units %d, method %s)\n",
    $asset3['asset_number'], $asset3['name'],
    $asset3['acquisition_cost'], $asset3['salvage_value'],
    $asset3['total_expected_units'], $asset3['depreciation_method']);

echo PHP_EOL . "All 3 test assets created successfully." . PHP_EOL;

// ── Verify audit log was written ────────────────────────────────────
$auditCount = db_count(
    "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'fixed_asset' AND action = 'create' AND created_at >= NOW() - INTERVAL 5 MINUTE"
);
printf("\nAudit trail: %d 'create' entries written for fixed_asset entities (expected 3) — %s\n",
    $auditCount, $auditCount === 3 ? '✅' : '❌ MISMATCH');

// ── Persist asset IDs for downstream tests ───────────────────────────
file_put_contents(__DIR__ . '/test_s034_asset_ids.json', json_encode([
    'asset1' => (int) $asset1['id'],
    'asset2' => (int) $asset2['id'],
    'asset3' => (int) $asset3['id'],
]));
echo "Asset IDs saved to test_s034_asset_ids.json\n";
