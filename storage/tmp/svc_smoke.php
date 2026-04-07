<?php
// Quick smoke test that the FixedAssetService class loads.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

if (!class_exists(FixedAssetService::class)) {
    echo "FAIL: class not autoloaded\n";
    exit(1);
}
echo "OK: FixedAssetService class available\n";

// Try a no-op pure-math call (no DB write)
$asset = [
    'id' => 0,
    'status' => 'active',
    'depreciation_method' => 'straight_line',
    'acquisition_cost' => '15000.00',
    'salvage_value' => '500.00',
    'depreciable_cost' => '14500.00',
    'accumulated_depreciation' => '0.00',
    'net_book_value' => '15000.00',
    'useful_life_years' => '5',
    'depreciation_start_date' => '2026-04-01',
    'cra_cca_rate' => null,
    'total_expected_units' => null,
    'equipment_unit_id' => null,
];
$period = [
    'name' => 'April 2026',
    'year' => 2026,
    'month' => 4,
    'start_date' => '2026-04-01',
    'end_date'   => '2026-04-30',
];
$res = FixedAssetService::calculateForPeriod($asset, $period);
echo "Straight-line monthly amount: {$res['amount']}\n";
echo "Expected:                     241.67  (=14500/60)\n";
echo "Detail: " . json_encode($res['calculation_detail']) . "\n";
