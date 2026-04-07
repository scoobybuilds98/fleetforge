<?php
// Seed the three test assets specified by the user.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

function header_print(string $t): void { echo "\n========== $t ==========\n"; }
function check(string $label, bool $pass, string $extra = ''): void {
    $marker = $pass ? '[PASS]' : '[FAIL]';
    echo "  $marker  $label" . ($extra ? "  ($extra)" : '') . "\n";
}

// ── Wipe any prior test assets so the script is idempotent
$pdo = db_pdo();
// 1. Drop ALL depreciation/disposal/impairment JEs ever posted (test-only DB)
$pdo->exec("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id IN (SELECT id FROM acc_journal_entries WHERE source_type IN ('depreciation','asset_disposal'))");
$pdo->exec("DELETE FROM acc_journal_entries WHERE source_type IN ('depreciation','asset_disposal')");
// 2. Drop all dep run lines + runs
$pdo->exec("DELETE FROM acc_depreciation_run_lines");
$pdo->exec("DELETE FROM acc_depreciation_runs");
// 3. Drop all asset disposals + impairments
$pdo->exec("DELETE FROM acc_asset_disposals");
$pdo->exec("DELETE FROM acc_asset_impairments");
// 4. Drop test capex requests + fixed assets
$pdo->exec("DELETE FROM acc_capex_requests");
$pdo->exec("DELETE FROM acc_fixed_assets WHERE name IN ('2020 Kenworth T680 Truck','Surrey Yard Office Equipment','2022 Trailer Fleet Unit #47')");
// 5. Reset asset/capex counters
$pdo->exec("DELETE FROM settings WHERE `key` LIKE 'accounting.asset_next_number.%' OR `key` LIKE 'accounting.capex_next_number.%'");

header_print('Resolve test data IDs');
$superAdmin = db_row("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 1");
$userId = (int) $superAdmin['id'];
echo "  superAdmin user: #$userId\n";

// Pick equipment units to link
$truckUnit = db_row("SELECT id, unit_number, mileage FROM equipment_units WHERE unit_number = 'TEST-U001' LIMIT 1");
$trailerUnit = db_row("SELECT id, unit_number, mileage FROM equipment_units WHERE unit_number = 'CHS-002' LIMIT 1");
echo "  truck unit:   #{$truckUnit['id']}  {$truckUnit['unit_number']}\n";
echo "  trailer unit: #{$trailerUnit['id']}  {$trailerUnit['unit_number']}  mi={$trailerUnit['mileage']}\n";

// Resolve account IDs by code
$acct = function (string $code): int {
    $row = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code]);
    if (!$row) { fwrite(STDERR, "no account $code\n"); exit(1); }
    return (int) $row['id'];
};
$ids = [
    'fleet_cost'     => $acct('1210'),
    'fleet_accum'    => $acct('1220'),
    'vehicle_cost'   => $acct('1230'),
    'vehicle_accum'  => $acct('1240'),
    'office_cost'    => $acct('1250'),
    'office_accum'   => $acct('1260'),
    'depr_fleet'     => $acct('5010'),
    'depr_nonfleet'  => $acct('6190'),
];
print_r($ids);

// ── Asset 1: Kenworth Truck — declining balance ────────────────────
header_print('Asset 1 — Kenworth T680 (declining_balance, half-year, CCA 30%)');
$asset1 = FixedAssetService::create([
    'name'                    => '2020 Kenworth T680 Truck',
    'asset_class'             => 'fleet_equipment',
    'description'             => 'Test fixture for declining balance method',
    'acquisition_date'        => '2026-04-01',
    'depreciation_start_date' => '2026-04-01',
    'acquisition_cost'        => '180000.00',
    'salvage_value'           => '20000.00',
    'useful_life_years'       => '10',
    'depreciation_method'     => 'declining_balance',
    'cra_class'               => 'Class 10',
    'cra_cca_rate'            => '0.30',
    'equipment_unit_id'       => $truckUnit['id'],
    'asset_account_id'        => $ids['fleet_cost'],
    'accum_depr_account_id'   => $ids['fleet_accum'],
    'depr_expense_account_id' => $ids['depr_fleet'],
    'serial_number'           => 'KW-T680-2020-001',
    'location'                => 'Surrey Yard',
], $userId);
echo "  Created #{$asset1['id']} {$asset1['asset_number']} NBV={$asset1['net_book_value']}\n";
check('cost saved correctly',     $asset1['acquisition_cost'] === '180000.00');
check('salvage saved correctly',  $asset1['salvage_value']    === '20000.00');
check('depreciable_cost = 160k',  $asset1['depreciable_cost'] === '160000.00');
check('NBV = cost on day 1',      $asset1['net_book_value']   === '180000.00');
check('linked to equipment',      (int)$asset1['equipment_unit_id'] === (int)$truckUnit['id']);

// ── Asset 2: Office Equipment — straight line ──────────────────────
header_print('Asset 2 — Surrey Yard Office Equipment (straight_line, 5y)');
$asset2 = FixedAssetService::create([
    'name'                    => 'Surrey Yard Office Equipment',
    'asset_class'             => 'office_equipment',
    'description'             => 'Test fixture for straight-line method',
    'acquisition_date'        => '2026-04-01',
    'depreciation_start_date' => '2026-04-01',
    'acquisition_cost'        => '15000.00',
    'salvage_value'           => '500.00',
    'useful_life_years'       => '5',
    'depreciation_method'     => 'straight_line',
    'asset_account_id'        => $ids['office_cost'],
    'accum_depr_account_id'   => $ids['office_accum'],
    'depr_expense_account_id' => $ids['depr_nonfleet'],
    'location'                => 'Surrey Yard Office',
], $userId);
echo "  Created #{$asset2['id']} {$asset2['asset_number']} depreciable={$asset2['depreciable_cost']}\n";
check('depreciable = 14500',      $asset2['depreciable_cost'] === '14500.00');

// ── Asset 3: Trailer — units of production ─────────────────────────
header_print('Asset 3 — 2022 Trailer Fleet Unit #47 (units_of_production)');
$asset3 = FixedAssetService::create([
    'name'                    => '2022 Trailer Fleet Unit #47',
    'asset_class'             => 'fleet_equipment',
    'description'             => 'Test fixture for units of production method',
    'acquisition_date'        => '2026-04-01',
    'depreciation_start_date' => '2026-04-01',
    'acquisition_cost'        => '45000.00',
    'salvage_value'           => '5000.00',
    'depreciation_method'     => 'units_of_production',
    'total_expected_units'    => 500000,
    'equipment_unit_id'       => $trailerUnit['id'],
    'asset_account_id'        => $ids['fleet_cost'],
    'accum_depr_account_id'   => $ids['fleet_accum'],
    'depr_expense_account_id' => $ids['depr_fleet'],
], $userId);
echo "  Created #{$asset3['id']} {$asset3['asset_number']} depreciable={$asset3['depreciable_cost']}\n";
check('depreciable = 40000',      $asset3['depreciable_cost'] === '40000.00');

// Save IDs for next phase
file_put_contents(
    sys_get_temp_dir() . '/ff_test_assets.json',
    json_encode([
        'asset1' => (int) $asset1['id'],
        'asset2' => (int) $asset2['id'],
        'asset3' => (int) $asset3['id'],
        'truck_unit' => (int) $truckUnit['id'],
        'trailer_unit' => (int) $trailerUnit['id'],
        'user_id' => $userId,
        'accounts' => $ids,
    ], JSON_PRETTY_PRINT)
);
echo "\nIDs saved to /tmp/ff_test_assets.json\n";
