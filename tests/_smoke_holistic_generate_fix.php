<?php
declare(strict_types=1);

/**
 * tests/_smoke_holistic_generate_fix.php
 *
 * S-BILLING-HOLISTIC-GENERATE-FIX — end-to-end smoke for Revision 2 of the
 * Holistic Lease Billing Engine. Exercises the REAL generation path
 * (InvoiceGenerator::generateForLease → createFromLease → engine → invoices +
 * invoice_line_items rows) against the real db.php + schema inside
 * BEGIN/ROLLBACK, asserting the computed cents — not offline math.
 *
 * Rates throughout: daily $100, weekly $500, monthly $1,500
 *   → monthly ÷ 30 = $50.00/day ; weekly ÷ 7 = $71.4286/day.
 *
 *   T1  Rate ladder (single-month leases): 1/5/7/8/14/21/22/30 days.
 *   T2  Spanning lease, end_date Jul 7 → TWO invoices (Jun $1,200 + Jul $350).
 *   T3  Prorate-in-the-past (open lease): June flat $1,500, then July $1,200.
 *   T4  Long lease closing Oct 12 → 5 invoices summing $6,300.
 *   T5  Duplicate-period gate (subprocess; json_error exits).
 *   T6  Void invoice 2 then regenerate July → reproduces $350.
 *   T7  Regenerate over a SENT invoice → refused, immutability (subprocess).
 *   T8  Inverted-date attempt → hard refuse (subprocess).
 *   T9  period_independent lease — legacy path untouched (no fan-out).
 *
 * Run: php tests/_smoke_holistic_generate_fix.php
 *      FF_GATE_SCENARIO=T5|T7|T8 php tests/_smoke_holistic_generate_fix.php  (internal)
 * Exit: 0 = all pass; 1 = any fail.
 *
 * @session S-BILLING-HOLISTIC-GENERATE-FIX
 * @spec    FleetForge_Holistic_Billing_Engine_Spec_Revision2_2026-06-07
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

const R2_DAILY   = '100.00';
const R2_WEEKLY  = '500.00';
const R2_MONTHLY = '1500.00';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else       { $fail++; echo "FAIL  {$label}\n"; }
}
function eq(string $exp, $act, string $label): void {
    ok(bccomp($exp, (string)$act, 2) === 0, "{$label} (exp {$exp}, got {$act})");
}

/** Make a holistic lease at R2 rates. */
function r2_lease(array $overrides = []): int {
    $cust = Fixtures::createCustomer(['province' => 'BC']);
    return Fixtures::createLease($cust, array_merge([
        'engine_version' => 'holistic',
        'billing_cycle'  => 'monthly',
        'daily_rate'     => R2_DAILY,
        'weekly_rate'    => R2_WEEKLY,
        'monthly_rate'   => R2_MONTHLY,
        'gps_opt_in'     => 0,
        'status'         => 'active',
    ], $overrides));
}

/** base_rental NET (base_rental minus reconciliation credit) for an invoice. */
function base_net(int $invoiceId): string {
    $row = db_row(
        "SELECT COALESCE(SUM(CASE WHEN is_credit=1 THEN -amount ELSE amount END),'0.00') AS s
           FROM invoice_line_items
          WHERE invoice_id=? AND item_type IN ('base_rental','base_rental_reconciliation_credit')",
        [$invoiceId]
    );
    return (string)($row['s'] ?? '0.00');
}

function inv_meta(int $invoiceId): array {
    return db_row(
        "SELECT billing_type, invoice_type, billing_period_start, billing_period_end, billing_period_days, rate_method_used
           FROM invoices WHERE id=?",
        [$invoiceId]
    ) ?: [];
}

$gen = new InvoiceGenerator();

// ════════════════════════════════════════════════════════════════════
// Gate scenarios run as subprocesses (json_error exits the process).
// ════════════════════════════════════════════════════════════════════
$scenario = getenv('FF_GATE_SCENARIO') ?: '';
if ($scenario !== '') {
    DbState::inTransaction(function () use ($gen, $scenario) {
        if ($scenario === 'T8') {
            // Inverted: period_start after period_end — refuse before any insert.
            $lease = r2_lease(['start_date' => '2026-06-07', 'end_date' => '2026-07-07']);
            $gen->generateForLease([
                'lease_id' => $lease, 'period_start' => '2026-07-08', 'period_end' => '2026-07-07',
                'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
            ]);
            return;
        }
        // T5 / T7 need an existing overlapping invoice.
        $lease = r2_lease(['start_date' => '2026-06-07', 'end_date' => '2026-07-07']);
        $first = $gen->createFromLease([
            'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
            'billing_type' => 'partial_start', 'invoice_type' => 'regular',
            'created_by' => null, 'generation_source' => 'manual',
        ]);
        if ($scenario === 'T7') {
            db_execute("UPDATE invoices SET status='sent' WHERE id=?", [$first['invoice_id']]);
        }
        // Re-generate the SAME June period → overlap gate fires (json_error/exit).
        $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
            'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
        ]);
    });
    // Should never reach here — the gate must have exited.
    fwrite(STDERR, "GATE {$scenario}: no error raised\n");
    exit(2);
}

// ════════════════════════════════════════════════════════════════════
// T1 — Rate ladder, single-month leases (start on the 1st so each stays
// inside one calendar month). One invoice each.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $ladder = [
        ['2026-06-01', '2026-06-01', '100.00', '1 day'],
        ['2026-06-01', '2026-06-05', '500.00', '5 days'],
        ['2026-06-01', '2026-06-07', '500.00', '7 days'],
        ['2026-06-01', '2026-06-08', '571.43', '8 days'],
        ['2026-06-01', '2026-06-14', '1000.00', '14 days'],
        ['2026-06-01', '2026-06-21', '1500.00', '21 days'],
        ['2026-06-01', '2026-06-22', '1500.00', '22 days (capped)'],
        ['2026-06-01', '2026-06-30', '1500.00', '30 days (full month flat)'],
    ];
    foreach ($ladder as [$start, $end, $expected, $label]) {
        $lease = r2_lease(['start_date' => $start, 'end_date' => $end]);
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => $start, 'period_end' => $end,
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        ok($batch['count'] === 1, "T1 {$label}: single invoice");
        eq($expected, base_net($batch['invoices'][0]['invoice_id']), "T1 {$label} amount");
    }
});

// ════════════════════════════════════════════════════════════════════
// T2 — Spanning lease, end_date Jul 7 known. ONE generate → TWO invoices.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $lease = r2_lease(['start_date' => '2026-06-07', 'end_date' => '2026-07-07']);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 2 && $batch['fanned'] === true, 'T2 fans out to exactly TWO invoices');
    $i1 = $batch['invoices'][0]; $m1 = inv_meta($i1['invoice_id']);
    $i2 = $batch['invoices'][1]; $m2 = inv_meta($i2['invoice_id']);
    eq('1200.00', base_net($i1['invoice_id']), 'T2 inv1 Jun 7-30 base');
    ok($m1['billing_type'] === 'partial_start', 'T2 inv1 billing_type=partial_start');
    ok((int)$m1['billing_period_days'] === 24, 'T2 inv1 billing_days=24');
    eq('350.00', base_net($i2['invoice_id']), 'T2 inv2 Jul 1-7 base');
    ok($m2['billing_type'] === 'partial_end', 'T2 inv2 billing_type=partial_end');
    ok($m2['invoice_type'] === 'final', 'T2 inv2 invoice_type=final');
    ok((int)$m2['billing_period_days'] === 7, 'T2 inv2 billing_days=7');
    eq('1550.00', bcadd(base_net($i1['invoice_id']), base_net($i2['invoice_id']), 2), 'T2 lease total');
});

// ════════════════════════════════════════════════════════════════════
// T3 — Prorate-in-the-past: open lease (no end_date). June flat $1,500,
// then July $1,200 (cumulative $2,700 − $1,500), bakes in the $300 credit.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $lease = r2_lease(['start_date' => '2026-06-07']); // open, no end_date
    $b1 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($b1['count'] === 1, 'T3 June: single invoice');
    eq('1500.00', base_net($b1['invoices'][0]['invoice_id']), 'T3 June flat month $1,500');

    $b2 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        'billing_type' => 'full_month', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($b2['count'] === 1, 'T3 July: single invoice');
    eq('1200.00', base_net($b2['invoices'][0]['invoice_id']), 'T3 July $1,200 (300 June credit baked in)');

    $total = bcadd(base_net($b1['invoices'][0]['invoice_id']), base_net($b2['invoices'][0]['invoice_id']), 2);
    eq('2700.00', $total, 'T3 lease total $2,700');
});

// ════════════════════════════════════════════════════════════════════
// T4 — Long open lease, closes Oct 12 → 5 invoices summing $6,300.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $lease = r2_lease(['start_date' => '2026-06-07', 'actual_return_date' => '2026-10-12', 'status' => 'completed']);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 5, 'T4 fans out to 5 calendar-month invoices');
    $expected = ['1200.00', '1500.00', '1500.00', '1500.00', '600.00'];
    $sum = '0';
    foreach ($batch['invoices'] as $idx => $inv) {
        $amt = base_net($inv['invoice_id']);
        eq($expected[$idx] ?? '0.00', $amt, "T4 inv" . ($idx + 1) . " base");
        $sum = bcadd($sum, $amt, 2);
    }
    eq('6300.00', $sum, 'T4 lease total $6,300');
    $last = inv_meta($batch['invoices'][4]['invoice_id']);
    ok($last['invoice_type'] === 'final' && $last['billing_type'] === 'partial_end', 'T4 last = final partial_end');
});

// ════════════════════════════════════════════════════════════════════
// T6 — Void invoice 2 then regenerate July → reproduces $350.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $lease = r2_lease(['start_date' => '2026-06-07', 'end_date' => '2026-07-07']);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-07', 'period_end' => '2026-06-30',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $julyId = $batch['invoices'][1]['invoice_id'];
    db_execute("UPDATE invoices SET status='void' WHERE id=?", [$julyId]);

    $regen = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-07-01', 'period_end' => '2026-07-07',
        'billing_type' => 'partial_end', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($regen['count'] === 1, 'T6 regenerate July: single invoice');
    eq('350.00', base_net($regen['invoices'][0]['invoice_id']), 'T6 reproduces $350');
});

// ════════════════════════════════════════════════════════════════════
// T9 — period_independent lease: legacy path untouched (no fan-out, flat month).
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    $lease = r2_lease(['start_date' => '2026-06-01', 'end_date' => '2026-08-31', 'engine_version' => 'period_independent']);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'billing_type' => 'full_month', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 1 && $batch['fanned'] === false, 'T9 period_independent: single invoice, no fan-out');
    eq('1500.00', base_net($batch['invoices'][0]['invoice_id']), 'T9 legacy full_month flat $1,500');
    // Legacy invoices leave the holistic audit columns NULL.
    $row = db_row("SELECT total_days_at_period_end FROM invoices WHERE id=?", [$batch['invoices'][0]['invoice_id']]);
    ok($row['total_days_at_period_end'] === null, 'T9 legacy invoice: holistic audit columns NULL');
});

// ════════════════════════════════════════════════════════════════════
// T5 / T7 / T8 — gate scenarios via subprocess (json_error exits).
// ════════════════════════════════════════════════════════════════════
function run_gate(string $scenario, string $expectCode, string $expectNeedle = ''): array {
    $php  = PHP_BINARY;
    $self = __FILE__;
    $cmd  = "FF_GATE_SCENARIO={$scenario} " . escapeshellarg($php) . ' ' . escapeshellarg($self) . ' 2>/dev/null';
    $out  = shell_exec($cmd) ?? '';
    $json = json_decode(trim($out), true);
    $code = $json['error']['code'] ?? '';
    $msg  = $json['error']['message'] ?? '';
    return [$code === $expectCode && ($expectNeedle === '' || str_contains($msg, $expectNeedle)), $code, $msg];
}

[$t5ok, $t5code] = run_gate('T5', 'PERIOD_OVERLAP');
ok($t5ok, "T5 duplicate-period gate → PERIOD_OVERLAP (got {$t5code})");

[$t7ok, $t7code, $t7msg] = run_gate('T7', 'PERIOD_OVERLAP', 'immutable');
ok($t7ok, "T7 regenerate over SENT → PERIOD_OVERLAP + immutability (got {$t7code})");

[$t8ok, $t8code] = run_gate('T8', 'INVERTED_PERIOD');
ok($t8ok, "T8 inverted-date attempt → INVERTED_PERIOD (got {$t8code})");

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
