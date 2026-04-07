<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\FixedAssetService;
use FleetForge\Accounting\AccountingService;

$ids   = json_decode((string) file_get_contents(__DIR__ . '/test_s034_asset_ids.json'), true);
$asset1 = $ids['asset1']; // Kenworth declining_balance
$asset2 = $ids['asset2']; // Office straight_line
$asset3 = $ids['asset3']; // Trailer UoP

function pass(string $label, string $expected, string $actual): bool
{
    // Use bccomp for numeric strings, fall back to identity for non-numeric.
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = bccomp($expected, $actual, 2) === 0;
    } else {
        $ok = ($expected === $actual);
    }
    printf("  %s %s = %s %s %s\n",
        $ok ? '✅' : '❌',
        $label,
        $actual,
        $ok ? '==' : '!=',
        $expected);
    return $ok;
}

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — DEPRECIATION CALCULATION TESTS" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ============================================================
// TEST 1 — INDIVIDUAL CALCULATION FOR EACH METHOD
// ============================================================
echo "\n## TEST 1 — Individual depreciation calculations\n\n";

// Period: January 2025 (id=1)
$period = db_row("SELECT * FROM acc_periods WHERE id = 1");

// --- 1.1 Declining Balance Year 1 (Jan 2025): cost * rate * 0.5 / 12
echo "### 1.1 Declining Balance — Asset 1 (Kenworth) Jan 2025 (Year 1, half-year rule)\n";
$a1 = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$asset1]);
$calc = FixedAssetService::calculateForPeriod($a1, $period);
// Expected: 180000 * 0.30 * 0.5 / 12 = 27000 / 12 = 2250.00
pass("Monthly depreciation", "2250.00", $calc['amount']);
pass("Opening NBV", "180000.00", $calc['opening_nbv']);
pass("Closing NBV", "177750.00", $calc['closing_nbv']);

// --- 1.2 Straight Line: (cost - salvage) / (life * 12)
echo "\n### 1.2 Straight Line — Asset 2 (Office) Jan 2025\n";
$a2 = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$asset2]);
$calc = FixedAssetService::calculateForPeriod($a2, $period);
// Expected: (15000 - 500) / 60 = 241.6666... → roundMoney → 241.67
pass("Monthly depreciation", "241.67", $calc['amount']);
pass("Opening NBV", "15000.00", $calc['opening_nbv']);
pass("Closing NBV", "14758.33", $calc['closing_nbv']);

// --- 1.3 Units of Production: (cost - salvage) * (units / total)
echo "\n### 1.3 Units of Production — Asset 3 (Trailer) with 5000 unit override\n";
$a3 = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$asset3]);
$calc = FixedAssetService::calculateForPeriod($a3, $period, 5000);
// Expected: (45000 - 5000) * (5000 / 500000) = 40000 * 0.01 = 400.00
pass("Monthly depreciation (5000 mi)", "400.00", $calc['amount']);
pass("Opening NBV", "45000.00", $calc['opening_nbv']);
pass("Closing NBV", "44600.00", $calc['closing_nbv']);

// ============================================================
// TEST 2 — CONSOLIDATED RUN: PREVIEW
// ============================================================
echo "\n## TEST 2 — Consolidated preview run for January 2025 (period #1)\n\n";

$preview = FixedAssetService::previewRun(1, 1, [
    $asset3 => 5000, // override units for trailer
]);
$run = $preview['run'];
printf("  Run #%d created in status='%s' with %d assets, total \$%s\n",
    $run['id'], $run['status'], $run['asset_count'], $run['total_depreciation']);

// Expected total: 2250.00 + 241.67 + 400.00 = 2891.67
$expectedTotal = bcadd(bcadd("2250.00", "241.67", 2), "400.00", 2);
pass("Total depreciation", $expectedTotal, $run['total_depreciation']);
pass("Asset count", "3", (string) $run['asset_count']);

// Verify the totals_by_account dictionary has the right account groupings
echo "\n  Totals by account:\n";
foreach ($preview['totals_by_account'] as $acctId => $sides) {
    printf("    Acct #%d: DR \$%s / CR \$%s\n",
        $acctId,
        $sides['debit'] ?? '0.00',
        $sides['credit'] ?? '0.00');
}

// ============================================================
// TEST 3 — POST THE PREVIEW
// ============================================================
echo "\n## TEST 3 — Post preview run #{$run['id']}\n\n";

$posted = FixedAssetService::postRun((int) $run['id'], 1);
printf("  Run status: %s\n", $posted['status']);
printf("  Linked JE: #%d\n", $posted['journal_entry_id']);

// Verify JE balanced
$jeLines = db_select("SELECT * FROM acc_journal_entry_lines WHERE journal_entry_id = ?", [$posted['journal_entry_id']]);
$dr = '0.00'; $cr = '0.00';
foreach ($jeLines as $l) {
    $dr = bcadd($dr, $l['debit'], 2);
    $cr = bcadd($cr, $l['credit'], 2);
}
echo "  JE lines: " . count($jeLines) . PHP_EOL;
pass("JE total DR", $expectedTotal, $dr);
pass("JE total CR", $expectedTotal, $cr);
pass("DR == CR (balanced)", $dr, $cr);

// Verify each asset's accumulated_depreciation moved
echo "\n  Asset state after posting:\n";
foreach ([$asset1, $asset2, $asset3] as $i => $aid) {
    $a = db_row("SELECT id, asset_number, accumulated_depreciation, net_book_value, status FROM acc_fixed_assets WHERE id = ?", [$aid]);
    printf("    %s — accum=\$%s NBV=\$%s status=%s\n",
        $a['asset_number'], $a['accumulated_depreciation'], $a['net_book_value'], $a['status']);
}

$a1New = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset1]);
pass("Asset 1 accum after post", "2250.00", $a1New['accumulated_depreciation']);
pass("Asset 1 NBV after post", "177750.00", $a1New['net_book_value']);

$a2New = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset2]);
pass("Asset 2 accum after post", "241.67", $a2New['accumulated_depreciation']);
pass("Asset 2 NBV after post", "14758.33", $a2New['net_book_value']);

$a3New = db_row("SELECT accumulated_depreciation, net_book_value, units_used_to_date FROM acc_fixed_assets WHERE id = ?", [$asset3]);
pass("Asset 3 accum after post", "400.00", $a3New['accumulated_depreciation']);
pass("Asset 3 NBV after post", "44600.00", $a3New['net_book_value']);
pass("Asset 3 units used", "5000", (string) $a3New['units_used_to_date']);

// ============================================================
// TEST 4 — DOUBLE-POST PREVENTION
// ============================================================
echo "\n## TEST 4 — Re-running preview on a posted period must fail\n\n";
try {
    FixedAssetService::previewRun(1, 1);
    echo "  ❌ Should have thrown but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 5 — FEBRUARY 2025 RUN  (no half-year, still year 1)
// ============================================================
echo "\n## TEST 5 — February 2025 run (Year 1, second month)\n\n";

$preview2 = FixedAssetService::previewRun(2, 1, [$asset3 => 3000]);
$posted2  = FixedAssetService::postRun((int) $preview2['run']['id'], 1);
printf("  Posted run #%d, total \$%s\n", $posted2['id'], $posted2['total_depreciation']);

// Asset 1 declining balance year 1 still uses half-year: 2250.00
// Asset 2 straight line: 241.67
// Asset 3 UoP with 3000 units: (40000 * 3000/500000) = 240.00
// Total: 2250 + 241.67 + 240 = 2731.67
$expected2 = bcadd(bcadd("2250.00", "241.67", 2), "240.00", 2);
pass("Feb total", $expected2, $posted2['total_depreciation']);

$a1State = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset1]);
pass("Asset 1 accum after Feb (2 months)", "4500.00", $a1State['accumulated_depreciation']);

// ============================================================
// TEST 6 — REVERSE A POSTED RUN
// ============================================================
echo "\n## TEST 6 — Reverse February run\n\n";

$reversed = FixedAssetService::reverseRun((int) $posted2['id'], 1);
printf("  Run #%d status: %s\n", $reversed['run']['id'], $reversed['run']['status']);
printf("  Reversal JE: #%d (is_reversal=%d)\n",
    $reversed['reversal_je']['id'],
    $reversed['reversal_je']['is_reversal'] ?? 0);

// Verify reversal JE has DR/CR swapped relative to original
$origLines = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY id", [$posted2['journal_entry_id']]);
$revLines  = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ? ORDER BY id", [$reversed['reversal_je']['id']]);
echo "  Original JE: " . count($origLines) . " lines, Reversal JE: " . count($revLines) . " lines\n";

// Verify totals are equal (the reversal should net to zero with the original)
$origDr = '0.00'; $revDr = '0.00';
foreach ($origLines as $l) $origDr = bcadd($origDr, $l['debit'], 2);
foreach ($revLines as $l) $revDr = bcadd($revDr, $l['debit'], 2);
pass("Reversal DR total matches original DR total", $origDr, $revDr);

// Verify Asset 1 NBV restored to post-Jan state (only Jan run remains posted)
$a1Restored = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset1]);
pass("Asset 1 accum after Feb reversal (back to Jan only)", "2250.00", $a1Restored['accumulated_depreciation']);
pass("Asset 1 NBV after Feb reversal", "177750.00", $a1Restored['net_book_value']);

// Verify original JE is marked reversed
$origJe = db_row("SELECT status, is_reversal, reversed_by_id FROM acc_journal_entries WHERE id = ?", [$posted2['journal_entry_id']]);
echo "  Original JE status: {$origJe['status']}, reversed_by_id: " . ($origJe['reversed_by_id'] ?? 'null') . "\n";
pass("Original JE marked reversed", "reversed", $origJe['status']);

// ============================================================
// TEST 7 — RE-PREVIEW ON REVERSED PERIOD (should now succeed)
// ============================================================
echo "\n## TEST 7 — Re-preview on Feb (after reversal) should succeed\n\n";

try {
    $rePreview = FixedAssetService::previewRun(2, 1, [$asset3 => 3000]);
    printf("  ✅ Re-preview created: run #%d, total \$%s\n",
        $rePreview['run']['id'], $rePreview['run']['total_depreciation']);
} catch (\RuntimeException $e) {
    echo "  ❌ Re-preview failed: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 8 — POST FIRST 12 MONTHS, THEN VERIFY YTD AND YEAR-2 TRANSITION
// ============================================================
echo "\n## TEST 8 — Post Mar..Dec 2025 then Jan 2026 (Year 2 transition)\n\n";

// Post the re-previewed Feb run first
FixedAssetService::postRun((int) $rePreview['run']['id'], 1);
echo "  Re-posted Feb 2025\n";

// Loop through Mar..Dec 2025 (period IDs 3..12). Only 3 and 12 are open.
// We need to open the others temporarily.
db_execute("UPDATE acc_periods SET status='open' WHERE id BETWEEN 3 AND 12");

for ($pid = 3; $pid <= 12; $pid++) {
    $period = db_row("SELECT name FROM acc_periods WHERE id = ?", [$pid]);
    $units = match (true) {
        $pid <= 6  => 4000,
        $pid <= 9  => 5500,
        default    => 4500,
    };
    $p   = FixedAssetService::previewRun($pid, 1, [$asset3 => $units]);
    $pp  = FixedAssetService::postRun((int) $p['run']['id'], 1);
    printf("  ✓ %s posted: \$%s\n", $period['name'], $pp['total_depreciation']);
}

// Verify Year 1 YTD numbers
$a1Y1 = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset1]);
echo "\n  Asset 1 (Kenworth) Year 1 YTD: accum=\${$a1Y1['accumulated_depreciation']} NBV=\${$a1Y1['net_book_value']}\n";
// 12 months × 2250.00 = 27000.00
pass("Asset 1 Y1 YTD accum", "27000.00", $a1Y1['accumulated_depreciation']);
pass("Asset 1 Y1 YTD NBV", "153000.00", $a1Y1['net_book_value']);

$a2Y1 = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset2]);
echo "  Asset 2 (Office) Year 1 YTD: accum=\${$a2Y1['accumulated_depreciation']} NBV=\${$a2Y1['net_book_value']}\n";
// 12 × 241.67 = 2900.04
pass("Asset 2 Y1 YTD accum", "2900.04", $a2Y1['accumulated_depreciation']);

// --- Now Jan 2026 (Year 2) — declining_balance should switch off half-year
echo "\n  --- Jan 2026 (Year 2) ---\n";
$jan26 = FixedAssetService::previewRun(13, 1, [$asset3 => 4000]);
$jan26p = FixedAssetService::postRun((int) $jan26['run']['id'], 1);
echo "  Posted Jan 2026: \${$jan26p['total_depreciation']}\n";

$a1Y2 = db_row("SELECT accumulated_depreciation, net_book_value FROM acc_fixed_assets WHERE id = ?", [$asset1]);
echo "  Asset 1 after Jan 2026: accum=\${$a1Y2['accumulated_depreciation']} NBV=\${$a1Y2['net_book_value']}\n";
// Year 2 monthly = (180000 - 27000) * 0.30 / 12 = 153000 * 0.025 = 3825.00
// Accum = 27000 + 3825 = 30825
pass("Asset 1 Y2 monthly (delta)", "3825.00", bcsub($a1Y2['accumulated_depreciation'], "27000.00", 2));
pass("Asset 1 Y2 month 1 accum", "30825.00", $a1Y2['accumulated_depreciation']);
pass("Asset 1 Y2 month 1 NBV", "149175.00", $a1Y2['net_book_value']);

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "DEPRECIATION TESTS COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
