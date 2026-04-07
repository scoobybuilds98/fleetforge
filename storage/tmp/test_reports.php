<?php declare(strict_types=1);

/**
 * storage/tmp/test_reports.php
 *
 * Reports verification — runs the four canonical fixed-asset reports against
 * the seeded test data and prints the resulting rows. Used as part of the
 * S029 closing audit. Reports verified:
 *   1. Asset register (every asset, current NBV)
 *   2. Depreciation schedule for the test assets
 *   3. Fully-depreciated / disposed / impaired listing
 *   4. CapEx budget vs actual variance
 */

require_once dirname(__DIR__, 2) . '/config/app.php';

function header_print(string $t): void { echo "\n========== $t ==========\n"; }
function row(string $label, $val): void { printf("  %-26s %s\n", $label, is_string($val) ? $val : json_encode($val)); }

// ============================================================
// 1. ASSET REGISTER
// ============================================================
header_print('REPORT 1 — Asset Register (every asset, current NBV)');

$assets = db_select(
    "SELECT id, asset_number, name, asset_class, depreciation_method, status,
            acquisition_cost, accumulated_depreciation, net_book_value
     FROM acc_fixed_assets
     ORDER BY id"
);

printf("  %-4s %-12s %-32s %-18s %-12s %-14s %-14s %-14s\n",
    'ID', 'Asset #', 'Name', 'Method', 'Status', 'Cost', 'Accum', 'NBV');
echo str_repeat('-', 130) . "\n";
$totalCost = $totalAccum = $totalNbv = '0.00';
foreach ($assets as $a) {
    printf("  %-4d %-12s %-32s %-18s %-12s %14s %14s %14s\n",
        $a['id'],
        substr($a['asset_number'], 0, 12),
        substr($a['name'], 0, 32),
        $a['depreciation_method'],
        $a['status'],
        number_format((float) $a['acquisition_cost'], 2),
        number_format((float) $a['accumulated_depreciation'], 2),
        number_format((float) $a['net_book_value'], 2)
    );
    $totalCost  = bcadd($totalCost,  $a['acquisition_cost'], 2);
    $totalAccum = bcadd($totalAccum, $a['accumulated_depreciation'], 2);
    $totalNbv   = bcadd($totalNbv,   $a['net_book_value'], 2);
}
echo str_repeat('-', 130) . "\n";
printf("  %-4s %-12s %-32s %-18s %-12s %14s %14s %14s\n",
    '', '', 'TOTAL', '', '',
    number_format((float) $totalCost, 2),
    number_format((float) $totalAccum, 2),
    number_format((float) $totalNbv, 2)
);

// ============================================================
// 2. DEPRECIATION SCHEDULE — show what was actually posted
// ============================================================
header_print('REPORT 2 — Posted Depreciation Runs (per asset)');

$lines = db_select(
    "SELECT rl.id, r.id AS run_id, p.name AS period,
            a.asset_number, a.name AS asset_name, rl.method_used,
            rl.opening_nbv, rl.depreciation, rl.closing_nbv
     FROM acc_depreciation_run_lines rl
     JOIN acc_depreciation_runs r ON r.id = rl.run_id
     JOIN acc_periods p ON p.id = rl.period_id
     JOIN acc_fixed_assets a ON a.id = rl.asset_id
     WHERE r.status = 'posted'
     ORDER BY r.id, a.id"
);

printf("  %-12s %-32s %-18s %14s %14s %14s\n",
    'Period', 'Asset', 'Method', 'Open NBV', 'Depr', 'Close NBV');
echo str_repeat('-', 110) . "\n";
foreach ($lines as $l) {
    printf("  %-12s %-32s %-18s %14s %14s %14s\n",
        substr($l['period'], 0, 12),
        substr($l['asset_name'], 0, 32),
        $l['method_used'],
        number_format((float) $l['opening_nbv'], 2),
        number_format((float) $l['depreciation'], 2),
        number_format((float) $l['closing_nbv'], 2)
    );
}

// ============================================================
// 3. STATUS BREAKDOWN — fully_depreciated / disposed / impaired
// ============================================================
header_print('REPORT 3 — Status Breakdown');

$counts = db_select(
    "SELECT status, COUNT(*) AS c, SUM(net_book_value) AS nbv
     FROM acc_fixed_assets
     GROUP BY status
     ORDER BY status"
);
printf("  %-20s %6s %16s\n", 'Status', 'Count', 'Total NBV');
echo str_repeat('-', 50) . "\n";
foreach ($counts as $c) {
    printf("  %-20s %6d %16s\n", $c['status'], $c['c'], number_format((float) $c['nbv'], 2));
}

// Disposed assets — disposal records
echo "\n  Disposals:\n";
$disposals = db_select(
    "SELECT d.disposal_date, d.disposal_type, d.proceeds, d.net_book_value_at_disposal,
            d.gain_loss, a.asset_number, a.name
     FROM acc_asset_disposals d
     JOIN acc_fixed_assets a ON a.id = d.asset_id
     ORDER BY d.disposal_date"
);
foreach ($disposals as $d) {
    printf("    %s — %s %s for $%s (NBV $%s, %s $%s)\n",
        $d['disposal_date'],
        $d['asset_number'],
        $d['disposal_type'],
        number_format((float) $d['proceeds'], 2),
        number_format((float) $d['net_book_value_at_disposal'], 2),
        ((float) $d['gain_loss']) >= 0 ? 'gain' : 'loss',
        number_format(abs((float) $d['gain_loss']), 2)
    );
}

// Impairments
echo "\n  Impairments:\n";
$impairs = db_select(
    "SELECT i.impairment_date, i.impairment_loss, i.reason, a.asset_number, a.name
     FROM acc_asset_impairments i
     JOIN acc_fixed_assets a ON a.id = i.asset_id
     ORDER BY i.impairment_date"
);
foreach ($impairs as $i) {
    printf("    %s — %s loss $%s (%s)\n",
        $i['impairment_date'],
        $i['asset_number'],
        number_format((float) $i['impairment_loss'], 2),
        substr($i['reason'], 0, 50)
    );
}

// ============================================================
// 4. CAPEX BUDGET vs ACTUAL VARIANCE
// ============================================================
header_print('REPORT 4 — CapEx Budget vs Actual Variance');

$capex = db_select(
    "SELECT c.request_number, c.title, c.status, c.budget_amount, c.actual_amount,
            (c.budget_amount - COALESCE(c.actual_amount, 0)) AS variance,
            a.asset_number AS linked
     FROM acc_capex_requests c
     LEFT JOIN acc_fixed_assets a ON a.id = c.asset_id
     ORDER BY c.id"
);

printf("  %-12s %-30s %-14s %14s %14s %14s %-10s\n",
    'Request #', 'Title', 'Status', 'Budget', 'Actual', 'Variance', 'Linked');
echo str_repeat('-', 130) . "\n";
$totBud = $totAct = '0.00';
foreach ($capex as $c) {
    $varPct = ((float)$c['budget_amount']) > 0 && $c['actual_amount']
        ? sprintf('%.2f%%', ((float)$c['variance']) / ((float)$c['budget_amount']) * 100)
        : '—';
    printf("  %-12s %-30s %-14s %14s %14s %14s %-10s\n",
        $c['request_number'],
        substr($c['title'], 0, 30),
        $c['status'],
        number_format((float) $c['budget_amount'], 2),
        $c['actual_amount'] ? number_format((float) $c['actual_amount'], 2) : '—',
        $c['actual_amount'] ? number_format((float) $c['variance'], 2) : '—',
        $c['linked'] ?? '—'
    );
    $totBud = bcadd($totBud, $c['budget_amount'] ?? '0', 2);
    $totAct = bcadd($totAct, $c['actual_amount'] ?? '0', 2);
}
echo str_repeat('-', 130) . "\n";
printf("  %-12s %-30s %-14s %14s %14s %14s\n",
    '', 'TOTALS', '',
    number_format((float) $totBud, 2),
    number_format((float) $totAct, 2),
    number_format((float) bcsub($totBud, $totAct, 2), 2)
);

echo "\n=== REPORTS VERIFICATION COMPLETE ===\n";
