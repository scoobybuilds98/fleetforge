<?php
// Impairment test — Asset 1 (Kenworth Truck) impaired by $15,000.
// Spec ref: §16 — DR 7020 Impairment Loss / CR 1220 Accum Depr (fleet equipment)
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

// Asset 1 state coming in:
// Apr+May depreciation already posted at $2,250/month → accum = 4500.00, NBV = 175,500.00
header_print('Initial Asset 1 state (post Apr+May depreciation)');
$pre = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$ids['asset1']]);
echo "  asset 1 cost  = {$pre['acquisition_cost']}\n";
echo "  asset 1 accum = {$pre['accumulated_depreciation']}\n";
echo "  asset 1 NBV   = {$pre['net_book_value']}\n";
echo "  asset 1 status= {$pre['status']}\n";
check('asset 1 accum = 4500.00', $pre['accumulated_depreciation'] === '4500.00');
check('asset 1 NBV = 175500.00', $pre['net_book_value']           === '175500.00');
check('asset 1 status = active', $pre['status']                   === 'active');

// ============================================================
// Try as accountant — should be blocked
// ============================================================
header_print('Test: Accountant role cannot impair (manager only)');
try {
    FixedAssetService::impair([
        'asset_id'        => $ids['asset1'],
        'impairment_date' => '2026-06-15',
        'impairment_loss' => '15000.00',
        'reason'          => 'TEST: market value collapse',
    ], $userId, 'accountant');
    check('blocked accountant role', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked accountant role', true);
}

// ============================================================
// Impair as manager — $15,000 loss
// ============================================================
header_print('Impair Asset 1 — $15,000 loss as manager');
$imp = FixedAssetService::impair([
    'asset_id'        => $ids['asset1'],
    'impairment_date' => '2026-06-15',
    'impairment_loss' => '15000.00',
    'reason'          => 'TEST: Engine overhaul changed economic outlook',
], $userId, 'manager');
echo "  impairment id={$imp['id']} loss={$imp['impairment_loss']} JE=#{$imp['journal_entry_id']}\n";
check('impairment loss = 15000.00',  $imp['impairment_loss']   === '15000.00');
// pre_impairment_nbv should equal current NBV (175500.00)
check('pre_impairment_nbv = 175500.00', $imp['pre_impairment_nbv'] === '175500.00');
// recoverable = 175500 - 15000 = 160500
check('recoverable_amount = 160500.00', $imp['recoverable_amount'] === '160500.00');

// ============================================================
// Verify JE — DR 7020 / CR 1220 (fleet accum)
// ============================================================
header_print('Verify impairment JE lines');
$lines = db_select(
    "SELECT jel.*, a.code, a.name FROM acc_journal_entry_lines jel JOIN acc_accounts a ON a.id = jel.account_id WHERE jel.journal_entry_id = ? ORDER BY jel.line_number",
    [$imp['journal_entry_id']]
);
$totDr = '0.00'; $totCr = '0.00';
foreach ($lines as $l) {
    printf("  [%s] %-45s DR=%-10s CR=%-10s\n", $l['code'], $l['name'], $l['debit'], $l['credit']);
    $totDr = bcadd($totDr, $l['debit'], 2);
    $totCr = bcadd($totCr, $l['credit'], 2);
}
echo "  totals: DR=$totDr CR=$totCr\n";
check('JE balanced', $totDr === $totCr);

$drLoss = '0.00'; $crAccum = '0.00';
foreach ($lines as $l) {
    if ($l['code'] === '7020') $drLoss  = $l['debit'];
    if ($l['code'] === '1220') $crAccum = $l['credit'];
}
check('DR 7020 Impairment Loss = 15000.00', $drLoss  === '15000.00');
check('CR 1220 Fleet Accum Depr = 15000.00', $crAccum === '15000.00');

// ============================================================
// Verify asset state after impairment
// ============================================================
header_print('Verify asset state after impairment');
$post = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$ids['asset1']]);
echo "  asset 1 cost   = {$post['acquisition_cost']}\n";
echo "  asset 1 accum  = {$post['accumulated_depreciation']}\n";
echo "  asset 1 NBV    = {$post['net_book_value']}\n";
echo "  asset 1 status = {$post['status']}\n";
// Cost is unchanged. Accum increased by 15000 → 19500. NBV = cost - accum = 160500.
check('asset 1 accum = 19500.00 (was 4500 + 15000)', $post['accumulated_depreciation'] === '19500.00');
check('asset 1 NBV   = 160500.00 (was 175500 - 15000)', $post['net_book_value'] === '160500.00');
check('asset 1 status = impaired', $post['status'] === 'impaired');

// ============================================================
// Verify impairment exceeding NBV is blocked
// ============================================================
header_print('Test: Impairment > NBV is blocked');
try {
    FixedAssetService::impair([
        'asset_id'        => $ids['asset1'],
        'impairment_date' => '2026-06-20',
        'impairment_loss' => '999999.00',
        'reason'          => 'TEST: too much',
    ], $userId, 'manager');
    check('blocked excessive impairment', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked excessive impairment', true);
}

// ============================================================
// Verify zero/negative impairment is blocked
// ============================================================
header_print('Test: Zero impairment is blocked');
try {
    FixedAssetService::impair([
        'asset_id'        => $ids['asset1'],
        'impairment_date' => '2026-06-20',
        'impairment_loss' => '0.00',
        'reason'          => 'TEST: zero',
    ], $userId, 'manager');
    check('blocked zero impairment', false, 'no exception thrown');
} catch (\Throwable $e) {
    echo "  blocked OK: " . $e->getMessage() . "\n";
    check('blocked zero impairment', true);
}

echo "\n=== IMPAIRMENT TESTS COMPLETE ===\n";
