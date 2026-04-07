<?php
declare(strict_types=1);
require __DIR__ . '/../../config/app.php';

use FleetForge\Accounting\FixedAssetService;
use FleetForge\Accounting\AccountingService;

// ============================================================
// S034 — DISPOSAL TESTS (gain + loss scenarios)
// Creates two short-lived assets, depreciates them a bit, then
// disposes of each and verifies the JE math + GL impact.
// ============================================================

function pass_d(string $label, string $expected, string $actual): bool
{
    if (is_numeric($expected) && is_numeric($actual)) {
        $ok = bccomp($expected, $actual, 2) === 0;
    } else {
        $ok = ($expected === $actual);
    }
    printf("  %s %s = %s %s %s\n",
        $ok ? '✅' : '❌', $label, $actual,
        $ok ? '==' : '!=', $expected);
    return $ok;
}

echo str_repeat('=', 70) . PHP_EOL;
echo "S034 — DISPOSAL TESTS" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;

// ── Create two disposal-test assets ──────────────────────────────────
echo "\n## Creating disposal test assets\n\n";

$gainAsset = FixedAssetService::create([
    'name'                    => 'Disposal Test — GAIN scenario',
    'description'             => 'SL 5y, will be disposed for proceeds > NBV',
    'asset_class'             => 'office_equipment',
    'acquisition_date'        => '2025-01-01',
    'depreciation_start_date' => '2025-01-01',
    'acquisition_cost'        => '12000.00',
    'salvage_value'           => '0.00',
    'depreciation_method'     => 'straight_line',
    'useful_life_years'       => '5',
    'asset_account_id'        => 16,
    'accum_depr_account_id'   => 17,
    'depr_expense_account_id' => 73,
    'location'                => 'Surrey Office',
], 1);
echo "  Created gain-test asset: {$gainAsset['asset_number']} cost \${$gainAsset['acquisition_cost']}\n";

$lossAsset = FixedAssetService::create([
    'name'                    => 'Disposal Test — LOSS scenario',
    'description'             => 'SL 5y, will be disposed for proceeds < NBV',
    'asset_class'             => 'office_equipment',
    'acquisition_date'        => '2025-01-01',
    'depreciation_start_date' => '2025-01-01',
    'acquisition_cost'        => '6000.00',
    'salvage_value'           => '0.00',
    'depreciation_method'     => 'straight_line',
    'useful_life_years'       => '5',
    'asset_account_id'        => 16,
    'accum_depr_account_id'   => 17,
    'depr_expense_account_id' => 73,
    'location'                => 'Surrey Office',
], 1);
echo "  Created loss-test asset: {$lossAsset['asset_number']} cost \${$lossAsset['acquisition_cost']}\n";

// Manually bring depreciation forward (skip the consolidated run flow,
// since the depreciation suite already covered that). We just want NBV
// non-zero so we can prove the gain/loss math.
//   Gain asset:  12000 / 60 = 200/mo, 12 months = 2400 accum, NBV 9600
//   Loss asset:  6000 / 60 = 100/mo, 12 months = 1200 accum, NBV 4800
db_execute("UPDATE acc_fixed_assets SET accumulated_depreciation = ?, net_book_value = ? WHERE id = ?",
    ['2400.00', '9600.00', $gainAsset['id']]);
db_execute("UPDATE acc_fixed_assets SET accumulated_depreciation = ?, net_book_value = ? WHERE id = ?",
    ['1200.00', '4800.00', $lossAsset['id']]);
echo "  Seeded accum-depr: gain asset NBV=\$9600.00, loss asset NBV=\$4800.00\n";

// ============================================================
// TEST 1 — GAIN ON DISPOSAL
// Sell gain asset for $11,000. NBV is $9600 → gain $1400.
// JE: DR cash 11000, DR accum 2400 / CR asset 12000, CR gain 1400
// ============================================================
echo "\n## TEST 1 — Gain on disposal (sell for \$11,000, NBV \$9,600)\n\n";

$cashAcctId = (int) AccountingService::setting('accounting.default_cash_account_id');
echo "  Default cash account: #$cashAcctId\n";

$disposed1 = FixedAssetService::dispose([
    'asset_id'      => $gainAsset['id'],
    'disposal_date' => '2026-01-15',
    'disposal_type' => 'sale',
    'proceeds'      => '11000.00',
    'buyer_name'    => 'Test Buyer Co',
    'notes'         => 'Sold above NBV — gain on disposal',
], 1, 'super_admin');

printf("  Disposal #%d created. NBV=\$%s, Proceeds=\$%s, Gain/Loss=\$%s, JE #%d\n",
    $disposed1['id'],
    $disposed1['net_book_value_at_disposal'],
    $disposed1['proceeds'],
    $disposed1['gain_loss'],
    $disposed1['journal_entry_id']);

pass_d("Disposal NBV", "9600.00", $disposed1['net_book_value_at_disposal']);
pass_d("Gain/loss (positive=gain)", "1400.00", $disposed1['gain_loss']);

// Verify JE balance and account distribution
$jeLines = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
    [$disposed1['journal_entry_id']]);
$dr = '0.00'; $cr = '0.00';
foreach ($jeLines as $l) {
    $dr = bcadd($dr, $l['debit'], 2);
    $cr = bcadd($cr, $l['credit'], 2);
}
echo "  JE lines: " . count($jeLines) . PHP_EOL;
foreach ($jeLines as $l) {
    $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$l['account_id']]);
    printf("    %s %s — DR \$%s / CR \$%s\n",
        $acct['code'], $acct['name'], $l['debit'], $l['credit']);
}
pass_d("JE balanced (DR == CR)", $dr, $cr);
pass_d("JE total DR", "13400.00", $dr);

// Verify asset state after disposal
$gAfter = db_row("SELECT status, net_book_value, accumulated_depreciation FROM acc_fixed_assets WHERE id = ?", [$gainAsset['id']]);
pass_d("Asset status after disposal", "disposed", $gAfter['status']);

// ============================================================
// TEST 2 — LOSS ON DISPOSAL
// Sell loss asset for $3,000. NBV is $4800 → loss $1800.
// JE: DR cash 3000, DR accum 1200, DR loss 1800 / CR asset 6000
// ============================================================
echo "\n## TEST 2 — Loss on disposal (sell for \$3,000, NBV \$4,800)\n\n";

$disposed2 = FixedAssetService::dispose([
    'asset_id'      => $lossAsset['id'],
    'disposal_date' => '2026-01-15',
    'disposal_type' => 'sale',
    'proceeds'      => '3000.00',
    'buyer_name'    => 'Test Buyer Co',
    'notes'         => 'Sold below NBV — loss on disposal',
], 1, 'super_admin');

printf("  Disposal #%d created. NBV=\$%s, Proceeds=\$%s, Gain/Loss=\$%s, JE #%d\n",
    $disposed2['id'],
    $disposed2['net_book_value_at_disposal'],
    $disposed2['proceeds'],
    $disposed2['gain_loss'],
    $disposed2['journal_entry_id']);

pass_d("Disposal NBV", "4800.00", $disposed2['net_book_value_at_disposal']);
pass_d("Gain/loss (negative=loss)", "-1800.00", $disposed2['gain_loss']);

$jeLines2 = db_select("SELECT account_id, debit, credit FROM acc_journal_entry_lines WHERE journal_entry_id = ?",
    [$disposed2['journal_entry_id']]);
$dr = '0.00'; $cr = '0.00';
foreach ($jeLines2 as $l) {
    $dr = bcadd($dr, $l['debit'], 2);
    $cr = bcadd($cr, $l['credit'], 2);
}
echo "  JE lines: " . count($jeLines2) . PHP_EOL;
foreach ($jeLines2 as $l) {
    $acct = db_row("SELECT code, name FROM acc_accounts WHERE id = ?", [$l['account_id']]);
    printf("    %s %s — DR \$%s / CR \$%s\n",
        $acct['code'], $acct['name'], $l['debit'], $l['credit']);
}
pass_d("JE balanced (DR == CR)", $dr, $cr);
pass_d("JE total DR", "6000.00", $dr);

$lAfter = db_row("SELECT status, net_book_value FROM acc_fixed_assets WHERE id = ?", [$lossAsset['id']]);
pass_d("Asset status after disposal", "disposed", $lAfter['status']);

// ============================================================
// TEST 3 — DOUBLE-DISPOSAL PREVENTION
// ============================================================
echo "\n## TEST 3 — Cannot dispose an already-disposed asset\n\n";
try {
    FixedAssetService::dispose([
        'asset_id'      => $gainAsset['id'],
        'disposal_date' => '2026-01-15',
        'disposal_type' => 'sale',
        'proceeds'      => '500.00',
    ], 1, 'super_admin');
    echo "  ❌ Should have thrown but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 4 — ROLE GATING (driver should be denied)
// ============================================================
echo "\n## TEST 4 — Driver role cannot dispose\n\n";
try {
    FixedAssetService::dispose([
        'asset_id'      => $lossAsset['id'],
        'disposal_date' => '2026-01-15',
        'disposal_type' => 'sale',
        'proceeds'      => '100.00',
    ], 1, 'driver');
    echo "  ❌ Should have thrown role error but didn't\n";
} catch (\RuntimeException $e) {
    echo "  ✅ Correctly blocked: " . $e->getMessage() . "\n";
}

// ============================================================
// TEST 5 — AUDIT TRAIL
// ============================================================
echo "\n## TEST 5 — Verify audit log entries\n\n";
$disposeAudit = db_count(
    "SELECT COUNT(*) FROM audit_log
     WHERE entity_type = 'fixed_asset' AND action = 'status_change'
     AND notes LIKE '%dispose%'
     AND created_at >= NOW() - INTERVAL 5 MINUTE"
);
echo "  Disposal audit entries (last 5 min): $disposeAudit\n";
pass_d("Audit count >= 2", "1", $disposeAudit >= 2 ? "1" : "0");

// Save IDs for downstream cleanup if needed
file_put_contents(__DIR__ . '/test_s034_disposal_ids.json', json_encode([
    'gain_asset_id' => (int) $gainAsset['id'],
    'loss_asset_id' => (int) $lossAsset['id'],
    'gain_disposal_id' => (int) $disposed1['id'],
    'loss_disposal_id' => (int) $disposed2['id'],
]));

echo "\n" . str_repeat('=', 70) . PHP_EOL;
echo "DISPOSAL TESTS COMPLETE" . PHP_EOL;
echo str_repeat('=', 70) . PHP_EOL;
