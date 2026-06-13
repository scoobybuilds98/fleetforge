<?php
declare(strict_types=1);

/**
 * tests/_smoke_depreciation_declining_balance.php
 *
 * H3 (Wave 2) — CRA declining-balance depreciation must apply the rate to the
 * YEAR-OPENING UCC held constant across the 12 monthly runs, not to a shrinking
 * mid-year UCC.
 *
 * FixedAssetService::calculateForPeriod() (non-first-year branch) computed
 * UCC = acquisition_cost − accumulated_depreciation using the LIVE accumulated,
 * which grows with every monthly run. So the CRA rate hit a UCC that shrank
 * each month → the schedule compounded monthly and under-depreciated (~12.7% in
 * year 2: a 100k @ 30% asset gave year-2 total ≈ 22,270 instead of 25,500).
 *
 * Two checks:
 *   1. calculateForPeriod for a MID-year-2 month (the real-run path): with the
 *      live accumulated reflecting year-1 (15,000) + 3 months of year-2 already
 *      booked, the monthly charge must still be 2125.00 (= year-opening UCC
 *      85,000 × 30% / 12), NOT the shrunk 1965.63.
 *   2. depreciationSchedule() (the projection path): year-2 total == 25,500.00
 *      and year-1 (half-year) total == 15,000.00.
 *
 * PRE-FIX  : check 1 → 1965.63; check 2 year-2 ≈ 22,270 → FAIL.
 * POST-FIX : 2125.00 and 25,500.00.
 *
 * Run:  php tests/_smoke_depreciation_declining_balance.php
 * Exit: 0 all pass, 1 on failure, 2 on setup error.
 *
 * @session H3-DECLINING-BALANCE
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

$PID = getmypid();
$assetId = null;

try {
    echo str_repeat('─', 72) . "\n";
    echo "H3 DECLINING-BALANCE DEPRECIATION — 100k @ 30% CRA\n";
    echo str_repeat('─', 72) . "\n";

    // ── Check 1 — mid-year-2 month via calculateForPeriod (real-run path) ────
    // Year 1 half-year total = 100000 * 0.30 * 0.5 = 15000.
    // Year-opening UCC for year 2 = 100000 - 15000 = 85000.
    // Constant monthly = 85000 * 0.30 / 12 = 2125.00.
    // Simulate April 2027 (month 4) with Jan–Mar of 2027 already booked:
    //   accumulated = 15000 + 3*2125 = 21375  (live, mid-year).
    $asset = [
        'id'                       => 0,
        'acquisition_cost'         => '100000.00',
        'salvage_value'            => '0.00',
        'depreciable_cost'         => '100000.00',
        'accumulated_depreciation' => '21375.00',
        'net_book_value'           => '78625.00',
        'depreciation_method'      => 'declining_balance',
        'cra_cca_rate'             => '0.3000',
        'depreciation_start_date'  => '2026-01-01',
        'status'                   => 'active',
        'useful_life_years'        => '10',
    ];
    $calc = FixedAssetService::calculateForPeriod($asset, ['name' => 'April 2027', 'year' => 2027, 'month' => 4]);
    if (bccomp((string) $calc['amount'], '2125.00', 2) === 0) {
        $pass("1 mid-year-2 month — monthly = 2125.00 (year-opening UCC 85,000 × 30% / 12), not the shrunk 1965.63");
    } else {
        $fail("1 mid-year-2 month — monthly = {$calc['amount']} (expected 2125.00; pre-fix 1965.63 from compounding the live UCC)");
    }

    // ── Check 2 — full schedule via depreciationSchedule (projection path) ───
    $glAcct = (int) (db_row("SELECT id FROM acc_accounts WHERE is_active=1 AND is_header=0 LIMIT 1")['id'] ?? 0);
    if (!$glAcct) { echo "SETUP FAIL no acc_accounts row for FK\n"; exit(2); }

    $assetId = db_insert('acc_fixed_assets', [
        'asset_number'             => "H3-{$PID}",
        'name'                     => "H3 Smoke Asset {$PID}",
        'asset_class'              => 'fleet_equipment',
        'acquisition_date'         => '2026-01-01',
        'acquisition_cost'         => '100000.00',
        'salvage_value'            => '0.00',
        'depreciable_cost'         => '100000.00',
        'net_book_value'           => '100000.00',
        'accumulated_depreciation' => '0.00',
        'depreciation_method'      => 'declining_balance',
        'cra_cca_rate'             => '0.3000',
        'depreciation_start_date'  => '2026-01-01',
        'useful_life_years'        => 10,
        'status'                   => 'active',
        'asset_account_id'         => $glAcct,
        'accum_depr_account_id'    => $glAcct,
        'depr_expense_account_id'  => $glAcct,
    ]);

    $sched = FixedAssetService::depreciationSchedule($assetId, 36);
    $y1 = '0.00'; $y2 = '0.00';
    foreach ($sched as $row) {
        if (str_contains((string) $row['period_name'], '2026')) $y1 = bcadd($y1, (string) $row['amount'], 2);
        if (str_contains((string) $row['period_name'], '2027')) $y2 = bcadd($y2, (string) $row['amount'], 2);
    }
    if (bccomp($y2, '25500.00', 2) === 0) {
        $pass("2 projection — year-2 (2027) total = 25,500.00 (= year-opening UCC 85,000 × 30%)");
    } else {
        $fail("2 projection — year-2 total = {$y2} (expected 25,500.00; pre-fix ≈ 22,270 from monthly compounding)");
    }
    if (bccomp($y1, '15000.00', 2) === 0) {
        $pass("2b projection — year-1 (2026) half-year total = 15,000.00 (non-vacuous; first-year unchanged)");
    } else {
        $fail("2b projection — year-1 total = {$y1} (expected 15,000.00 half-year)");
    }

} finally {
    echo "\n=== CLEANUP ===\n";
    if ($assetId) { db_execute("DELETE FROM acc_fixed_assets WHERE id = ?", [$assetId]); }
    echo "  removed H3 smoke asset\n";
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("H3 DECLINING-BALANCE — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
