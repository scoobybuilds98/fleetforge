<?php
declare(strict_types=1);

/**
 * tests/_smoke_post_impairment_depreciation.php
 *
 * MEDIUM [01c] — straight-line depreciation after an impairment.
 *
 * impair() resets net_book_value / depreciable_cost to the lower carrying value
 * but does NOT shorten useful_life_years. The old SL calc divided the reduced
 * depreciable_cost by the FULL original life, so it under-depreciated and never
 * reached salvage by end-of-life. Fixed: straight-line over the REMAINING life
 * — monthly = (opening_nbv − salvage) / remaining_months.
 *
 * Scenario: 12,000 asset, 10y (120mo) SL, salvage 0 → normally 100/mo. After 48
 * months an impairment leaves NBV = 6,000. Remaining life = 72 months, so the
 * correct monthly is 6000/72 = 83.33 (reaches 0 at month 120). The old code gave
 * 6000/120 = 50.00 (leaves 2,400 un-depreciated at EOL).
 *
 * PRE-FIX  : 50.00 → FAIL.
 * POST-FIX : 83.33; a never-impaired asset still computes 100.00 (non-vacuous).
 *
 * Run:  php tests/_smoke_post_impairment_depreciation.php
 * Exit: 0 all pass, 1 on failure.
 *
 * @session MEDIUM-01c-POST-IMPAIRMENT-SL
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

$failures = [];
$passes   = 0;
$pass = static function (string $m) use (&$passes): void { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; };
$fail = static function (string $m) use (&$failures): void { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; };

echo str_repeat('─', 72) . "\n";
echo "MEDIUM [01c] POST-IMPAIRMENT STRAIGHT-LINE — remaining-life re-spread\n";
echo str_repeat('─', 72) . "\n";

// Impaired-state asset: 48 months elapsed, NBV down to 6000, life still 120mo.
$impaired = [
    'id'                       => 0,
    'acquisition_cost'         => '12000.00',
    'salvage_value'            => '0.00',
    'depreciable_cost'         => '6000.00',   // reset by impair() to NBV - salvage
    'accumulated_depreciation' => '6000.00',
    'net_book_value'           => '6000.00',
    'depreciation_method'      => 'straight_line',
    'useful_life_years'        => '10',
    'depreciation_start_date'  => '2026-01-01',
    'status'                   => 'active',
];
// Period at elapsed = 48 months (Jan 2030) → remaining = 72.
$calc = FixedAssetService::calculateForPeriod($impaired, ['name' => 'January 2030', 'year' => 2030, 'month' => 1]);
if (bccomp((string) $calc['amount'], '83.33', 2) === 0) {
    $pass("post-impairment — 6000 over remaining 72mo = 83.33/mo (reaches salvage at EOL), not the old 50.00");
} else {
    $fail("post-impairment — monthly = {$calc['amount']} (expected 83.33; pre-fix 50.00 = 6000/full-120mo)");
}

// Never-impaired asset, month 1 → classic 100/mo (proves normal path unchanged).
$normal = [
    'id' => 0, 'acquisition_cost' => '12000.00', 'salvage_value' => '0.00',
    'depreciable_cost' => '12000.00', 'accumulated_depreciation' => '0.00',
    'net_book_value' => '12000.00', 'depreciation_method' => 'straight_line',
    'useful_life_years' => '10', 'depreciation_start_date' => '2026-01-01', 'status' => 'active',
];
$calc = FixedAssetService::calculateForPeriod($normal, ['name' => 'January 2026', 'year' => 2026, 'month' => 1]);
if (bccomp((string) $calc['amount'], '100.00', 2) === 0) {
    $pass("non-vacuous — never-impaired SL asset still computes 100.00/mo (12000/120)");
} else {
    $fail("non-vacuous — normal SL monthly = {$calc['amount']} (expected 100.00)");
}

echo "\n" . str_repeat('─', 72) . "\n";
printf("POST-IMPAIRMENT SL — %d passed, %d failed\n", $passes, count($failures));
if ($failures) { echo "\033[31m✗ FAILURES:\033[0m\n"; foreach ($failures as $f) echo "  - {$f}\n"; }
else           { echo "\033[32m✓ ALL PASSED\033[0m\n"; }
echo str_repeat('─', 72) . "\n";
exit($failures ? 1 : 0);
