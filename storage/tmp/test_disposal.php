<?php
// Disposal test — Asset 2 sold for $8,000 after 2 years of SL depreciation.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

use FleetForge\Accounting\FixedAssetService;

$ids = json_decode(file_get_contents(sys_get_temp_dir() . '/ff_test_assets.json'), true);
$userId = (int) $ids['user_id'];

function check(string $label, bool $pass, string $extra = ''): void {
    $marker = $pass ? '[PASS]' : '[FAIL]';
    echo "  $marker  $label" . ($extra ? "  ($extra)" : '') . "\n";
}
function header_print(string $t): void { echo "\n========== $t ==========\n"; }

// Simulate "2 years of SL depreciation already posted" by setting accum directly.
// (The full 24-month run would require periods we don't have yet.)
// Disposal date is in 2026 (June 2026 = first open period after Apr/May runs).
header_print('Prepare Asset 2: simulate 2 years of straight-line depreciation');
$annual = '2900.00';                             // (15000 - 500) / 5
$accum  = bcmul($annual, '2', 2);                // 5800.00
$nbv    = bcsub('15000.00', $accum, 2);          // 9200.00
db_execute(
    "UPDATE acc_fixed_assets SET accumulated_depreciation = ?, net_book_value = ?, last_depreciation_date = '2026-05-31' WHERE id = ?",
    [$accum, $nbv, $ids['asset2']]
);
$asset2 = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$ids['asset2']]);
echo "  asset 2 accum = {$asset2['accumulated_depreciation']}  NBV = {$asset2['net_book_value']}\n";
check('asset 2 accum = 5800.00', $asset2['accumulated_depreciation'] === '5800.00');
check('asset 2 NBV   = 9200.00', $asset2['net_book_value']           === '9200.00');

// ============================================================
// Try as accountant — should be blocked (manager only)
// ============================================================
header_print('Test: Accountant role cannot dispose (must be manager)');
try {
    FixedAssetService::dispose([
        'asset_id'      => $ids['asset2'],
        'disposal_date' => '2026-06-15',
        'disposal_type' => 'sale',
        'proceeds'      => '8000.00',
        'buyer_name'    => 'Test Buyer Inc',
        'notes'         => 'TEST-disposal',
    ], $userId, 'accountant');
    check('blocked accountant role', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked accountant role', true);
}

// ============================================================
// Dispose as manager
// ============================================================
header_print('Dispose Asset 2 as manager — sale for $8,000');
$disposal = FixedAssetService::dispose([
    'asset_id'      => $ids['asset2'],
    'disposal_date' => '2026-06-15',
    'disposal_type' => 'sale',
    'proceeds'      => '8000.00',
    'buyer_name'    => 'Test Buyer Inc',
    'buyer_reference'=> 'BR-12345',
    'notes'         => 'TEST-disposal-Asset2',
], $userId, 'manager');
echo "  disposal id={$disposal['id']} gain_loss={$disposal['gain_loss']} JE=#{$disposal['journal_entry_id']}\n";
check('disposal proceeds = 8000.00', $disposal['proceeds']                  === '8000.00');
check('disposal NBV at disposal = 9200.00', $disposal['net_book_value_at_disposal'] === '9200.00');
check('gain_loss = -1200.00 (loss)',  $disposal['gain_loss']                 === '-1200.00');

// ============================================================
// Verify journal entry
// ============================================================
header_print('Verify disposal JE lines');
$lines = db_select(
    "SELECT jel.*, a.code, a.name FROM acc_journal_entry_lines jel JOIN acc_accounts a ON a.id = jel.account_id WHERE jel.journal_entry_id = ? ORDER BY jel.line_number",
    [$disposal['journal_entry_id']]
);
$totDr = '0.00'; $totCr = '0.00';
foreach ($lines as $l) {
    printf("  [%s] %-45s DR=%-10s CR=%-10s\n", $l['code'], $l['name'], $l['debit'], $l['credit']);
    $totDr = bcadd($totDr, $l['debit'], 2);
    $totCr = bcadd($totCr, $l['credit'], 2);
}
echo "  totals: DR=$totDr CR=$totCr\n";
check('JE balanced', $totDr === $totCr);

// Specific lines
$drCash = '0.00'; $drAccum = '0.00'; $drLoss = '0.00'; $crCost = '0.00';
foreach ($lines as $l) {
    if ($l['code'] === '1010') $drCash  = $l['debit'];
    if ($l['code'] === '1260') $drAccum = $l['debit'];
    if ($l['code'] === '7020') $drLoss  = $l['debit'];
    if ($l['code'] === '1250') $crCost  = $l['credit'];
}
check('DR 1010 Cash             = 8000.00',  $drCash  === '8000.00');
check('DR 1260 Accum Depr       = 5800.00',  $drAccum === '5800.00');
check('DR 7020 Loss on Disposal = 1200.00',  $drLoss  === '1200.00');
check('CR 1250 Asset Cost       = 15000.00', $crCost  === '15000.00');

// ============================================================
// Verify asset state after disposal
// ============================================================
header_print('Verify asset state after disposal');
$asset2After = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$ids['asset2']]);
echo "  status={$asset2After['status']}  NBV={$asset2After['net_book_value']}\n";
check('asset status = disposed', $asset2After['status'] === 'disposed');

// ============================================================
// Verify cannot dispose twice
// ============================================================
header_print('Test: Cannot dispose an already-disposed asset');
try {
    FixedAssetService::dispose([
        'asset_id'      => $ids['asset2'],
        'disposal_date' => '2026-07-01',
        'disposal_type' => 'sale',
        'proceeds'      => '5000.00',
    ], $userId, 'manager');
    check('blocked second disposal', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked second disposal', true);
}

// ============================================================
// Sanity: disposal record exists and is queryable
// ============================================================
header_print('Disposal record audit');
$d = db_row("SELECT d.*, a.asset_number, a.name AS asset_name FROM acc_asset_disposals d JOIN acc_fixed_assets a ON a.id = d.asset_id WHERE d.id = ?", [$disposal['id']]);
echo "  disposed asset: {$d['asset_number']} — {$d['asset_name']}\n";
echo "  disposal type: {$d['disposal_type']}  proceeds: {$d['proceeds']}\n";
echo "  buyer: {$d['buyer_name']}  ref: {$d['buyer_reference']}\n";
echo "  notes: {$d['notes']}\n";
check('buyer recorded', $d['buyer_name'] === 'Test Buyer Inc');
