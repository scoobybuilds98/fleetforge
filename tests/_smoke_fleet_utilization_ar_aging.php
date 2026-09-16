<?php
declare(strict_types=1);
/**
 * tests/_smoke_fleet_utilization_ar_aging.php
 *
 * Guards the two shared reporting helpers introduced by the reports/analytics
 * bug sweep (2026-09-16):
 *
 *   lib/Reports/FleetUtilization.php — occupied ÷ available unit-days, used by
 *     Reports → Fleet, Analytics → Utilization Matrix, Dashboard trend.
 *     Bugs it prevents: Analytics averaged 357.9% (lease days multiplied by an
 *     invoice-count JOIN + overlapping leases summed); Reports showed a unit at
 *     1200% for "This Month" (overlaps summed, future days counted).
 *
 *   lib/Reports/ArAging.php — CAD-canonical, truly as-of AR aging, used by
 *     Accounting → AR Aging, Reports → AR Aging, Dashboard AR chart.
 *     Bugs it prevents: USD + CAD balances summed at face value; the as-of date
 *     only re-bucketed TODAY's balances (invoices issued later were listed,
 *     payments received later were already deducted).
 *
 * Part A/B call the helpers directly inside BEGIN … ROLLBACK (hermetic — the
 * fixtures live in the year 2001, before any real data, so real rows cannot
 * leak into the assertions). Part C executes the REAL endpoints in a
 * subprocess against the live dev data and checks structural invariants
 * (no utilization above 100%, endpoints agree with each other).
 *
 * Run:  php tests/_smoke_fleet_utilization_ar_aging.php   (exit 0 PASS / 1 FAIL)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Reports\ArAging;
use FleetForge\Reports\FleetUtilization;

$ROOT = dirname(__DIR__);
$pass = 0;
$fail = 0;
/** Record a passing assertion. */
function ok(string $m): void { global $pass; $pass++; echo "  \033[32mPASS\033[0m — {$m}\n"; }
/** Record a failing assertion. */
function no(string $m): void { global $fail; $fail++; echo "  \033[31mFAIL\033[0m — {$m}\n"; }
/** Assert equality with a readable message. */
function eq(mixed $got, mixed $want, string $m): void { $got === $want ? ok($m) : no($m . ' — got ' . json_encode($got) . ', want ' . json_encode($want)); }

$TOKEN = 'SMKFUAR' . getmypid();
$pdo   = db_pdo();

// ════════════════════════════════════════════════════════════════════════
echo "\nA — FleetUtilization (hermetic, BEGIN/ROLLBACK)\n";
// ════════════════════════════════════════════════════════════════════════

// A1: pure merge rules
$m = FleetUtilization::mergeSpells([
    ['2001-03-10', '2001-03-31'], ['2001-03-01', '2001-03-20'],   // overlap
    ['2001-04-01', '2001-04-05'],                                 // adjacent → joins
    ['2001-04-10', '2001-04-12'],                                 // disjoint
    ['2001-05-02', '2001-05-01'],                                 // inverted → dropped
]);
eq($m, [['2001-03-01', '2001-04-05'], ['2001-04-10', '2001-04-12']], 'A1 mergeSpells merges overlap + adjacency, keeps disjoint, drops inverted');

$pdo->beginTransaction();
try {
    $tpl = (int) (db_row("SELECT id FROM equipment_templates WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
    $mkUnit = static function (string $createdAt, string $sfx) use ($tpl, $TOKEN): int {
        $id = db_insert('equipment_units', [
            'template_id' => $tpl, 'unit_number' => $TOKEN . $sfx, 'ownership_type' => 'owned', 'status' => 'available',
        ]);
        db_execute("UPDATE equipment_units SET created_at = ? WHERE id = ?", [$createdAt . ' 08:00:00', $id]);
        return $id;
    };
    $mkLease = static function (int $unit, string $start, ?string $end, ?string $ret, string $sfx, string $status = 'completed') use ($TOKEN): int {
        return db_insert('leases', [
            'contract_number' => $TOKEN . $sfx, 'equipment_unit_id' => $unit, 'start_date' => $start,
            'end_date' => $end, 'actual_return_date' => $ret, 'status' => $status,
        ]);
    };

    // Unit 1: two OVERLAPPING leases covering all of March 2001 (raw 20 + 22 = 42 days).
    $u1 = $mkUnit('2001-01-01', 'U1');
    $mkLease($u1, '2001-03-01', '2001-03-20', '2001-03-20', 'L1');
    $mkLease($u1, '2001-03-10', '2001-03-31', '2001-03-31', 'L2');
    // A pending lease must not count.
    $mkLease($u1, '2001-02-01', '2001-02-28', null, 'L3', 'pending');
    // Unit 2: joins the fleet mid-March, never leased → available 16 days.
    $u2 = $mkUnit('2001-03-16', 'U2');
    // Unit 3: row created LATER than its back-dated lease (import case). Completed
    // lease with NULL actual_return → ladder falls to end_date (Mar 1–10 = 10 days).
    $u3 = $mkUnit('2005-01-01', 'U3');
    $mkLease($u3, '2001-03-01', '2001-03-10', null, 'L4');

    $r = FleetUtilization::forWindow('2001-03-01', '2001-03-31', [$u1, $u2, $u3], '2026-09-16');
    eq($r['units'][$u1]['days_on_rent'], 31, 'A2 overlapping leases MERGED: 31 days on rent, not 42');
    eq($r['units'][$u1]['raw_lease_day_sum'], 42, 'A3 unmerged per-lease sum still reported (42)');
    eq($r['units'][$u1]['utilization_pct'], '100.0', 'A4 unit capped at 100.0% by construction');
    eq($r['units'][$u2]['available_days'], 16, 'A5 unit placed in service Mar 16 is available 16 days, not 31');
    eq($r['units'][$u3]['available_days'], 31, 'A6 in-service = earliest of created_at / first lease (back-dated import stays available)');
    eq($r['units'][$u3]['days_on_rent'], 10, 'A7 completed lease with NULL return uses end_date (ladder)');
    eq([$r['occupied_unit_days'], $r['available_unit_days'], $r['utilization_pct']], [41, 78, '52.6'], 'A8 fleet = 41 occupied ÷ 78 available = 52.6%');
    eq($r['overlap_adjusted'], true, 'A9 overlap_adjusted flag set');

    $future = FleetUtilization::forWindow('2099-01-01', '2099-01-31', [$u1], '2026-09-16');
    eq([$future['window_days'], $future['utilization_pct']], [0, '0.0'], 'A10 wholly-future window → 0 days, 0.0%');

    $capped = FleetUtilization::forWindow('2001-03-01', '2001-12-31', [$u1], '2001-03-15');
    eq([$capped['window_to'], $capped['units'][$u1]['available_days'], $capped['units'][$u1]['days_on_rent']], ['2001-03-15', 15, 15], 'A11 window end capped at today (no future days in either side)');

    $multi = FleetUtilization::forWindows([['2001-02-01', '2001-02-28'], ['2001-03-01', '2001-03-31']], [$u1, $u2, $u3], '2026-09-16');
    eq([$multi[1]['occupied_unit_days'], $multi[1]['available_unit_days']], [41, 78], 'A12 forWindows() month result == forWindow()');
    eq($multi[0]['occupied_unit_days'], 0, 'A13 pending lease in February not counted');
} finally {
    $pdo->rollBack();
}

// ════════════════════════════════════════════════════════════════════════
echo "\nB — ArAging (hermetic, BEGIN/ROLLBACK)\n";
// ════════════════════════════════════════════════════════════════════════
$pdo->beginTransaction();
try {
    $cust = db_insert('customers', ['company_name' => $TOKEN . ' Co', 'currency' => 'CAD', 'outstanding_balance' => '0.00']);
    $n = 0;
    $mkInv = static function (array $f) use ($cust, $TOKEN, &$n): int {
        $n++;
        return db_insert('invoices', array_merge([
            'invoice_number' => $TOKEN . '-I' . $n, 'customer_id' => $cust,
            'billing_period_start' => '2001-01-01', 'billing_period_end' => '2001-01-31', 'billing_period_days' => 31,
            'billing_type' => 'single_period', 'currency' => 'CAD', 'exchange_rate_to_cad' => null,
            'amount_paid' => '0.00', 'credits_applied' => '0.00',
        ], $f));
    };
    $mkPay = static function (int $inv, string $amount, string $date, ?string $deletedAt = null) use ($cust, $TOKEN, &$n): void {
        $n++;
        $pid = db_insert('payments', [
            'payment_number' => $TOKEN . '-P' . $n, 'customer_id' => $cust, 'amount' => $amount,
            'payment_method' => 'check', 'payment_date' => $date, 'status' => 'cleared', 'deleted_at' => $deletedAt,
        ]);
        db_insert('payment_allocations', ['payment_id' => $pid, 'invoice_id' => $inv, 'amount' => $amount]);
    };

    // I1 CAD 1000, due May 31, 400 paid on Jul 15 (AFTER the Jun 30 as-of) → today 600.
    $i1 = $mkInv(['invoice_date' => '2001-05-01', 'due_date' => '2001-05-31', 'status' => 'partially_paid',
                  'total_amount' => '1000.00', 'amount_paid' => '400.00', 'balance_due' => '600.00']);
    $mkPay($i1, '400.00', '2001-07-15');
    // I2 USD 1000 @ 1.37, not yet due at as-of → CAD 1370.00 current.
    $mkInv(['invoice_date' => '2001-06-01', 'due_date' => '2001-07-01', 'status' => 'sent', 'currency' => 'USD',
            'exchange_rate_to_cad' => '1.370000', 'total_amount' => '1000.00', 'balance_due' => '1000.00']);
    // I3 issued AFTER the as-of date → excluded as of Jun 30.
    $mkInv(['invoice_date' => '2001-07-05', 'due_date' => '2001-08-04', 'status' => 'sent',
            'total_amount' => '250.00', 'balance_due' => '250.00']);
    // I4 fully paid BEFORE as-of → not AR.
    $i4 = $mkInv(['invoice_date' => '2001-04-01', 'due_date' => '2001-05-01', 'status' => 'paid',
                  'total_amount' => '500.00', 'amount_paid' => '500.00', 'balance_due' => '0.00']);
    $mkPay($i4, '500.00', '2001-06-10');
    // I5 200 paid Jun 15, payment VOIDED Aug 1 (after as-of): today full 800 open,
    //    but at Jun 30 the payment still stood → 600.
    $i5 = $mkInv(['invoice_date' => '2001-06-01', 'due_date' => '2001-07-01', 'status' => 'sent',
                  'total_amount' => '800.00', 'balance_due' => '800.00']);
    $mkPay($i5, '200.00', '2001-06-15', '2001-08-01 10:00:00');
    // I6 written off Aug 1 (after as-of) for 300 → was 300 of AR at Jun 30.
    $i6 = $mkInv(['invoice_date' => '2001-05-15', 'due_date' => '2001-06-14', 'status' => 'written_off',
                  'total_amount' => '300.00', 'balance_due' => '0.00']);
    db_execute("UPDATE invoices SET written_off_at = '2001-08-01 09:00:00' WHERE id = ?", [$i6]);
    db_insert('acc_bad_debt_writeoffs', ['invoice_id' => $i6, 'customer_id' => $cust, 'writeoff_date' => '2001-08-01',
                                         'amount' => '300.00', 'reason' => 'smoke']);
    // I7 draft → never AR.
    $mkInv(['invoice_date' => '2001-06-01', 'due_date' => '2001-07-01', 'status' => 'draft',
            'total_amount' => '999.00', 'balance_due' => '999.00']);

    // I8 credit + deposit branches: total 900, today fully settled by a credit
    // note applied Jul 10 (400, still applied), a deposit applied Jul 12 (300),
    // and a credit note applied Jun 5 but REVERSED Jul 20 (200, re-opened, then
    // a 200 payment dated Jul 25). At Jun 30: 900 − 200 (CN still applied then) = 700.
    $i8 = $mkInv(['invoice_date' => '2001-06-01', 'due_date' => '2001-07-01', 'status' => 'paid',
                  'total_amount' => '900.00', 'credits_applied' => '700.00', 'amount_paid' => '200.00', 'balance_due' => '0.00']);
    $cn = db_insert('credit_notes', ['credit_note_number' => $TOKEN . '-CN', 'customer_id' => $cust, 'source' => 'goodwill',
                                     'amount' => '600.00', 'amount_remaining' => '0.00', 'reason' => 'smoke', 'status' => 'fully_used']);
    db_insert('credit_note_applications', ['credit_note_id' => $cn, 'invoice_id' => $i8, 'amount_applied' => '400.00',
                                           'status' => 'applied', 'applied_at' => '2001-07-10 12:00:00']);
    db_insert('credit_note_applications', ['credit_note_id' => $cn, 'invoice_id' => $i8, 'amount_applied' => '200.00',
                                           'status' => 'reversed', 'applied_at' => '2001-06-05 12:00:00', 'reversed_at' => '2001-07-20 12:00:00']);
    db_insert('acc_customer_deposits', ['deposit_number' => $TOKEN . '-D', 'customer_id' => $cust, 'amount' => '300.00',
                                        'received_date' => '2001-05-01', 'status' => 'applied', 'applied_to_invoice_id' => $i8,
                                        'applied_date' => '2001-07-12']);
    $mkPay($i8, '200.00', '2001-07-25');

    $a = ArAging::asOf('2001-06-30');
    $byNum = [];
    foreach ($a['invoices'] as $inv) { $byNum[(int) $inv['invoice_id']] = $inv; }

    eq($a['invoice_count'], 5, 'B1 as-of Jun 30: I1, I2, I5, I6, I8 only (later-issued, paid-before, draft excluded)');
    eq($byNum[$i1]['balance_due'] ?? null, '1000.00', 'B2 payment dated after as-of NOT deducted (1000, not 600)');
    eq($byNum[$i1]['bucket'] ?? null, 'days_1_30', 'B3 I1 is 30 days past due at as-of → 1–30 bucket');
    eq($byNum[$i5]['balance_due'] ?? null, '600.00', 'B4 payment voided after as-of still counted at as-of (600)');
    eq($byNum[$i6]['balance_due'] ?? null, '300.00', 'B5 invoice written off after as-of is AR at as-of (300)');
    eq($byNum[$i8]['balance_due'] ?? null, '700.00', 'B5b credit/deposit/payment after as-of added back; CN reversed after as-of still counted (700)');
    eq($a['totals']['total'], '3970.00', 'B6 total CAD = 1000 + 1370 (USD 1000 × 1.37) + 600 + 300 + 700');
    eq($a['native_totals']['USD'], '1000.00', 'B7 native USD subtotal kept for reconciliation');
    eq($a['totals']['current'], '2670.00', 'B8 current bucket = USD 1370 + I5 600 + I8 700 (all due Jul 1)');

    $today = ArAging::asOf('2001-12-31');
    eq($today['totals']['total'], '3020.00', 'B9 as-of after everything: 600 + 1370 + 250 + 800 (voided payment reopened I5; I6 written off; I8 settled)');
    // As of Jul 15: CN 200 (Jun 5, not yet reversed) + CN 400 (Jul 10) + deposit 300
    // (Jul 12) = 900 settled → nothing outstanding on I8.
    $jul15 = array_filter(ArAging::asOf('2001-07-15')['invoices'], fn($x) => $x['invoice_id'] === $i8);
    eq(count($jul15), 0, 'B10 I8 as of Jul 15 fully settled (credits + deposit dated on/before) → excluded');
} finally {
    $pdo->rollBack();
}

// ════════════════════════════════════════════════════════════════════════
echo "\nC — real endpoints on live dev data (subprocess)\n";
// ════════════════════════════════════════════════════════════════════════
$harness = sys_get_temp_dir() . '/_ff_fuar_harness_' . getmypid() . '.php';
file_put_contents($harness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint = \$argv[1] ?? ''; \$qs = \$argv[2] ?? '';
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'.\$endpoint.'?'.\$qs;
\$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
@session_start(); \$_SESSION['ff_user'] = ['id'=>1,'role_slug'=>'super_admin'];
require '{$ROOT}/' . \$endpoint;
PHP);
$get = static function (string $endpoint, string $qs) use ($harness): ?array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg($endpoint) . ' ' . escapeshellarg($qs) . ' 2>/dev/null');
    if (!is_string($out)) return null;
    $s = strpos($out, '{"success"');
    $j = json_decode($s === false ? $out : substr($out, $s), true);
    return is_array($j) ? $j : null;
};

try {
    $an = $get('api/v1/analytics/index.php', 'view=utilization_matrix');
    $k  = $an['data']['kpis'] ?? null;
    if (!$k) {
        no('C1 analytics utilization_matrix returned no kpis: ' . json_encode($an));
    } else {
        $maxX = 0.0;
        foreach ($an['data']['chart_data']['series'] ?? [] as $srs) {
            foreach ($srs['data'] as $pt) { $maxX = max($maxX, (float) $pt['x']); }
        }
        ($k['avg_utilization'] <= 100 && $maxX <= 100)
            ? ok("C1 analytics avg utilization {$k['avg_utilization']}% and every unit <= 100% (was 357.9%)")
            : no("C1 analytics utilization above 100%: avg {$k['avg_utilization']}, max unit {$maxX}");
        $direct = FleetUtilization::forWindow((string) $k['window_from'], (string) $k['window_to']);
        eq((string) $direct['utilization_pct'], number_format((float) $k['avg_utilization'], 1, '.', ''),
           'C2 analytics avg utilization == FleetUtilization for the same window');
    }

    $fl = $get('api/v1/reports/fleet.php', 'preset=last_year&view=utilization');
    $fk = $fl['data']['kpis'] ?? null;
    if (!$fk) {
        no('C3 reports fleet returned no kpis: ' . json_encode($fl));
    } else {
        $over = array_filter($fl['data']['table'] ?? [], fn($r) => (float) $r['utilization_pct'] > 100);
        eq(count($over), 0, 'C3 reports fleet: no unit above 100% (a unit showed 1200% before)');
        $direct = FleetUtilization::forWindow((string) $fk['util_window_from'], (string) $fk['util_window_to']);
        eq((string) $fk['avg_utilization'], (string) $direct['utilization_pct'], 'C4 reports fleet KPI == FleetUtilization (same helper as Analytics/Dashboard)');
    }

    $asOf = date('Y-m-d');
    $acct = $get('api/v1/accounting/reports/ar-aging.php', 'as_of_date=' . $asOf);
    $rev  = $get('api/v1/reports/revenue.php', 'preset=all_time&view=ar_aging');
    $dash = ArAging::asOf($asOf);
    eq($acct['data']['totals']['total'] ?? null, $dash['totals']['total'], 'C5 Accounting AR aging total == ArAging::asOf(today)');
    eq($rev['data']['totals']['total_outstanding'] ?? null, $dash['totals']['total'], 'C6 Reports AR aging total == Accounting AR aging total');
} finally {
    @unlink($harness);
    // Reports cache rows written by the subprocess calls are ordinary 15-min cache entries.
}

echo str_repeat('─', 72) . "\n";
printf("FLEET UTILIZATION + AR AGING — %d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
