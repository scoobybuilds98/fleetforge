<?php
declare(strict_types=1);

/**
 * tests/_stress_holistic_engine.php
 *
 * S-BILLING-HOLISTIC-ENGINE stress test — comprehensive coverage of the
 * new running-reconciliation billing engine.
 *
 *   Unit tests (pure math, no DB):
 *     - applyTierFormula across every tier boundary (Appendix A)
 *     - whicheverPaysMore picks the higher of two computations
 *     - inclusiveDays: leap years, year boundaries, same-day
 *
 *   Integration tests (DB round-trip, transactional rollback):
 *     - 4-day, 7-day, 14-day, 34-day cumulative tier verifications
 *     - 365-day annual lease
 *     - Leap year February (29 days)
 *     - Boss's exact example: Mar 28 → Apr 30 = $200 + $593.33 = $793.33
 *     - 6-month sequence (spec §12)
 *     - Tier crossing reconciliation
 *     - Lease closed early at day 8
 *     - Negative-delta reconciliation credit
 *     - Reconciliation credit overflow → credit_notes row
 *
 *   Regression tests:
 *     - period_independent leases keep using ProRateCalculator
 *     - holistic leases use HolisticLeaseEngine
 *
 * Run: php tests/_stress_holistic_engine.php
 * Exit codes: 0 = all pass; 1 = any fail.
 *
 * @session  S-BILLING-HOLISTIC-ENGINE
 * @spec     FleetForge_Holistic_Billing_Engine_Spec.docx §34
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';  // for json_error()

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\BillingRateException;

$pass = 0;
$fail = 0;
$out  = [];

function ok(string $msg) {
    global $pass, $out;
    $pass++;
    $out[] = "PASS  $msg";
}
function bad(string $msg) {
    global $fail, $out;
    $fail++;
    $out[] = "FAIL  $msg";
}
function eq(string $name, $expected, $actual): void {
    if ((string)$expected === (string)$actual) {
        ok("$name = " . var_export($actual, true));
    } else {
        bad("$name expected=" . var_export($expected, true) . ' got=' . var_export($actual, true));
    }
}

// ════════════════════════════════════════════════════════════
// UNIT TESTS — pure math, no DB
// ════════════════════════════════════════════════════════════

$engine = new HolisticLeaseEngine();

// ── Appendix A: every tier boundary at $50/$350/$700 ──────
// Days 65, 365, 366 differ from the spec's Appendix A by $0.02 due to
// bcmath precision (spec uses display-rounded $23.33 × N; engine uses
// full-precision bcdiv($monthly,'30',6) × N then rounds). Boss approved
// this rounding tolerance in spec §35.7.
$tier_cases = [
    [0,   'none',          '0.00'],
    [1,   'daily',         '50.00'],
    [2,   'daily',         '100.00'],
    [3,   'daily',         '150.00'],
    [4,   'daily',         '200.00'],
    [5,   'daily',         '250.00'],
    [6,   'weekly_flat',   '350.00'],
    [7,   'weekly_flat',   '350.00'],
    [8,   'weekly_math',   '400.00'],
    [9,   'weekly_math',   '450.00'],
    [10,  'weekly_math',   '500.00'],
    [13,  'weekly_math',   '650.00'],
    [14,  'weekly_math',   '700.00'],
    [15,  'weekly_capped', '700.00'],
    [21,  'weekly_capped', '700.00'],
    [29,  'weekly_capped', '700.00'],
    [30,  'monthly_math',  '700.00'],
    [31,  'monthly_math',  '723.33'],
    [34,  'monthly_math',  '793.33'],
    [60,  'monthly_math',  '1400.00'],
    [90,  'monthly_math',  '2100.00'],
];
foreach ($tier_cases as [$days, $expectedTier, $expectedAmt]) {
    $r = $engine->applyTierFormula($days, '50.00', '350.00', '700.00');
    if ($r['tier'] === $expectedTier && $r['amount'] === $expectedAmt) {
        ok("applyTierFormula({$days}) → tier={$r['tier']} amt={$r['amount']}");
    } else {
        bad("applyTierFormula({$days}) expected tier={$expectedTier} amt={$expectedAmt} got tier={$r['tier']} amt={$r['amount']}");
    }
}

// Spec §35.7 tolerance — log but don't fail
$tolerance_cases = [
    [65,  'monthly_math',  '1516.65', '1516.67'],  // 5 × $23.3333 = $116.6665
    [365, 'monthly_math',  '8516.65', '8516.67'],
    [366, 'monthly_math',  '8539.98', '8540.00'],
];
foreach ($tolerance_cases as [$days, $tier, $specAmt, $engineAmt]) {
    $r = $engine->applyTierFormula($days, '50.00', '350.00', '700.00');
    if ($r['amount'] === $engineAmt) {
        ok("applyTierFormula({$days}) tolerance — spec=\${$specAmt}, engine=\${$engineAmt} (spec §35.7 boss-approved 2-cent precision band)");
    } else {
        bad("applyTierFormula({$days}) expected={$engineAmt} got={$r['amount']}");
    }
}

// ── whicheverPaysMore ────────────────────────────────────────
// 4 days @ $50/$350/$700: A = 4 × $50 = $200; B = 4 × $23.3333 = $93.33; A wins
$wpm = $engine->whicheverPaysMore(4, '50.00', '350.00', '700.00');
eq('WPM 4d chosen', '200.00', $wpm['chosen_amount']);
eq('WPM 4d option', 'A', $wpm['chosen_option']);

// 2 days @ $10/$350/$700: A = 2 × $10 = $20; B = 2 × $23.3333 = $46.67; B wins (protect company)
$wpm2 = $engine->whicheverPaysMore(2, '10.00', '350.00', '700.00');
eq('WPM 2d cheap-daily chosen', '46.67', $wpm2['chosen_amount']);
eq('WPM 2d cheap-daily option', 'B', $wpm2['chosen_option']);

// Tie: 0 days (both options = $0). Defensive — picks A by convention.
$wpm0 = $engine->whicheverPaysMore(0, '50.00', '350.00', '700.00');
eq('WPM 0d tie chosen', '0.00', $wpm0['chosen_amount']);

// ── inclusiveDays ────────────────────────────────────────────
$inc_cases = [
    ['2026-03-28', '2026-03-28', 1,   'same-day'],
    ['2026-03-28', '2026-03-31', 4,   '4-day march tail'],
    ['2026-03-28', '2026-04-30', 34,  'boss 34-day'],
    ['2024-02-28', '2024-02-29', 2,   'leap year feb 28→29'],
    ['2025-02-28', '2025-03-01', 2,   'non-leap feb→mar'],
    ['2025-12-31', '2026-01-01', 2,   'year boundary'],
    ['2024-02-01', '2024-02-29', 29,  'leap feb full month'],
    ['2025-02-01', '2025-02-28', 28,  'non-leap feb full month'],
    ['2025-01-01', '2025-12-31', 365, '365-day year'],
    ['2024-01-01', '2024-12-31', 366, '366-day leap year'],
    ['2026-04-30', '2026-04-15', 0,   'inverted range returns 0 (defensive)'],
];
foreach ($inc_cases as [$s, $e, $expected, $label]) {
    $got = HolisticLeaseEngine::inclusiveDays($s, $e);
    if ($got === $expected) {
        ok("inclusiveDays($s, $e) = $got ($label)");
    } else {
        bad("inclusiveDays($s, $e) expected=$expected got=$got ($label)");
    }
}

// ════════════════════════════════════════════════════════════
// INTEGRATION TESTS — DB round-trip
// ════════════════════════════════════════════════════════════
//
// Strategy: wrap every test in a transaction we then ROLLBACK so the
// DB stays clean even if assertions fail. createFromLease's own
// db_transaction() nests inside the outer rollback via SAVEPOINT
// semantics (the existing test pattern in _stress_invoice_generator_precharge.php).

$pdo = db_pdo();

/**
 * Spawn a fresh holistic-engine lease with $50/$350/$700 rates,
 * BC province, no exemptions/insurance/warranty/GPS, simple daily
 * billing cycle. Returns lease_id.
 */
function spawn_test_lease(string $startDate, ?string $endDate = null): int {
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL AND province IS NOT NULL LIMIT 1");

    $contractNum = 'TEST-HOL-' . uniqid();
    $leaseId = db_insert('leases', [
        'contract_number'   => $contractNum,
        'customer_id'       => $cust['id'],
        'equipment_unit_id' => $unit['id'],
        'customer_name_snapshot' => 'Holistic Test',
        'company_name_snapshot'  => 'Holistic Test Co',
        'unit_number_snapshot'   => 'TEST-UNIT',
        'start_date'        => $startDate,
        'end_date'          => $endDate,
        'status'            => 'active',
        'daily_rate'        => '50.00',
        'weekly_rate'       => '350.00',
        'monthly_rate'      => '700.00',
        'currency'          => 'CAD',
        'mileage_unit'      => 'km',
        'mileage_rate'      => '0.0000',
        'mileage_rate_km'   => '0.0000',
        'estimated_mileage' => '0.00',
        'estimated_mileage_km' => '0.000',
        'tax_exempt'        => 0,
        'gst_exempt'        => 0,
        'pst_exempt'        => 0,
        'discount_type'     => 'none',
        'discount_value'    => '0.0000',
        'insurance_opt_in'  => 0,
        'insurance_cost'    => '0.00',
        'warranty_opt_in'   => 0,
        'warranty_cost'     => '0.00',
        'billing_cycle'     => 'monthly',
        'advance_billing_periods' => 0,
        'engine_version'    => 'holistic',
        'precharge_enabled' => 0,
        'gps_opt_in'        => 0,
        'gps_cost'          => '0.00',
    ]);
    return $leaseId;
}

// S-DELETE-LEGACY-ENGINE: spawn_old_engine_lease() + the OLD-engine regression
// tests (REG 1/2) were removed with the period_independent engine. HolisticLeaseEngine
// is the only rental engine now.

function gen_invoice(int $leaseId, string $periodStart, string $periodEnd, string $billingType = 'partial_start'): array {
    $gen = new InvoiceGenerator();
    return $gen->createFromLease([
        'lease_id'     => $leaseId,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => $billingType,
        'invoice_type' => 'regular',
    ]);
}

function fetch_base_rental_lines(int $invoiceId): array {
    return db_select(
        "SELECT item_type, amount, is_credit FROM invoice_line_items WHERE invoice_id = ? AND item_type IN ('base_rental','base_rental_reconciliation_credit') ORDER BY sort_order",
        [$invoiceId]
    );
}

function fetch_audit_columns(int $invoiceId): ?array {
    return db_row(
        "SELECT total_days_at_period_end, cumulative_correct_amount, already_billed_before_this FROM invoices WHERE id = ?",
        [$invoiceId]
    );
}

// ── INT 1: Boss's exact example (Revision 2) — Mar 28 → Apr 30 ──
// end_date Apr 30 is KNOWN, so the lease is a 34-day monthly lease that spans
// Mar→Apr. R2 prorates the partial start month immediately at monthly÷30 and
// bills the complete month flat: $93.33 + $700 = $793.33. (The original spec's
// $200/$593.33 split was the open-lease escalating behaviour — see RR-001 in
// tier1_reconciliation.php, where no end_date is set and the split is preserved.)
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-30');

    // Invoice 1: Mar 28-31, partial start month (4 days × $700/30 = $93.33)
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $lines1 = fetch_base_rental_lines($inv1['invoice_id']);
    $audit1 = fetch_audit_columns($inv1['invoice_id']);

    eq("BOSS Invoice 1 line count", 1, count($lines1));
    eq("BOSS Invoice 1 amount", '93.33', $lines1[0]['amount']);
    eq("BOSS Invoice 1 type", 'base_rental', $lines1[0]['item_type']);
    eq("BOSS Invoice 1 total_days", '4', (string)$audit1['total_days_at_period_end']);
    eq("BOSS Invoice 1 cumulative_correct", '93.33', $audit1['cumulative_correct_amount']);
    eq("BOSS Invoice 1 already_billed", '0.00', $audit1['already_billed_before_this']);

    // Invoice 2: Apr 1-30, complete calendar month → flat $700. total_days = 34
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-30', 'full_month');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    $audit2 = fetch_audit_columns($inv2['invoice_id']);

    eq("BOSS Invoice 2 line count", 1, count($lines2));
    eq("BOSS Invoice 2 amount", '700.00', $lines2[0]['amount']);
    eq("BOSS Invoice 2 type", 'base_rental', $lines2[0]['item_type']);
    eq("BOSS Invoice 2 total_days", '34', (string)$audit2['total_days_at_period_end']);
    eq("BOSS Invoice 2 cumulative_correct", '793.33', $audit2['cumulative_correct_amount']);
    eq("BOSS Invoice 2 already_billed", '93.33', $audit2['already_billed_before_this']);

    $bossTotal = bcadd($lines1[0]['amount'], $lines2[0]['amount'], 2);
    eq("BOSS lease total", '793.33', $bossTotal);
    $out[] = "        ┌─ BOSS EXAMPLE VERIFIED (Revision 2) ──";
    $out[] = "        │  Invoice 1 (Mar 28-31):  \$93.33";
    $out[] = "        │  Invoice 2 (Apr 1-30):   \$700.00";
    $out[] = "        │  Lease total:            \$793.33";
    $out[] = "        └──────────────────────────────────────";
} finally {
    $pdo->rollBack();
}

// ── INT 2: Lease closed at day 5 (boundary of daily tier) ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-01');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-01', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    eq("Day 5 close: invoice 2 amount", '50.00', $lines2[0]['amount']);  // 5d cumulative = $250 - $200 = $50
    eq("Day 5 close: lease total",
       '250.00',
       bcadd(fetch_base_rental_lines($inv1['invoice_id'])[0]['amount'], $lines2[0]['amount'], 2));
} finally {
    $pdo->rollBack();
}

// ── INT 3: Lease closed at day 8 (tier crossing daily→weekly_math) ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-04');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-04', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    eq("Day 8 close: invoice 2 amount", '200.00', $lines2[0]['amount']);  // 8d weekly_math = $400 - $200 = $200
} finally {
    $pdo->rollBack();
}

// ── INT 4: Lease closed at day 6 (tier crossing daily→weekly_flat) ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-02');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-02', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    // S-AUDIT-BILLING-ENGINE-1 #14 (D-R2-2 cheaper-of): 6 days now bills
    // min(6 × $50 = $300, weekly flat $350) = $300 — the old fixed band
    // charged the flat week even when days × daily was cheaper.
    eq("Day 6 close: invoice 2 amount", '100.00', $lines2[0]['amount']);  // 6d cheaper-of $300 - $200 = $100
} finally {
    $pdo->rollBack();
}

// ── INT 5: 7-day lease (spec §10) ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-03');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-03', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    eq("7-day lease invoice 2 amount", '150.00', $lines2[0]['amount']);  // 7d = $350 - $200 = $150
} finally {
    $pdo->rollBack();
}

// ── INT 6: 14-day lease total = $700 capped ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-10');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $inv2 = gen_invoice($leaseId, '2026-04-01', '2026-04-10', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);
    // total = 14 days: weekly_math 2 × $350 = $700; already_billed = $200; delta = $500
    eq("14-day lease invoice 2 amount", '500.00', $lines2[0]['amount']);
} finally {
    $pdo->rollBack();
}

// ── INT 7: Leap year Feb 1-29 = 29 days → $700 capped ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2024-02-01', '2024-02-29');
    $inv1 = gen_invoice($leaseId, '2024-02-01', '2024-02-29', 'single_period');
    $lines1 = fetch_base_rental_lines($inv1['invoice_id']);
    $audit1 = fetch_audit_columns($inv1['invoice_id']);
    // Activation invoice — whichever pays more. A = weekly_capped at $700; B = 29 × $23.33 = $676.67.
    // A wins. Engine charges $700.
    eq("Leap Feb 1-29: amount", '700.00', $lines1[0]['amount']);
    eq("Leap Feb 1-29: total_days", '29', (string)$audit1['total_days_at_period_end']);
} finally {
    $pdo->rollBack();
}

// ── INT 8: 365-day lease (year-long) ──
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2025-01-01', '2025-12-31');
    // R2: a full calendar year is 12 COMPLETE calendar months billed flat —
    // 12 × $700 = $8,400.00 ("a month is a month"). The original 30-day-block
    // math charged $8,516.67 (12×30 + 5 leftover days); that is removed.
    $inv1 = gen_invoice($leaseId, '2025-01-01', '2025-12-31', 'single_period');
    $lines1 = fetch_base_rental_lines($inv1['invoice_id']);
    // 365 days monthly_math: 12 × $700 + 5 × $23.3333 = $8516.67 (engine precision; spec §35.7 allows ±$0.02)
    // Activation invoice — whichever pays more: A = $8516.67; B = 365 × $23.3333 = $8516.67. Equal → A.
    eq("365-day lease amount", '8400.00', $lines1[0]['amount']);
} finally {
    $pdo->rollBack();
}

// ── INT 9: 6-month sequence (spec §12) — 6 invoices ──────────
// Mar 28 → Aug 12 (138 days)
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-08-12');
    $i1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $i2 = gen_invoice($leaseId, '2026-04-01', '2026-04-30', 'full_month');
    $i3 = gen_invoice($leaseId, '2026-05-01', '2026-05-31', 'full_month');
    $i4 = gen_invoice($leaseId, '2026-06-01', '2026-06-30', 'full_month');
    $i5 = gen_invoice($leaseId, '2026-07-01', '2026-07-31', 'full_month');
    $i6 = gen_invoice($leaseId, '2026-08-01', '2026-08-12', 'partial_end');

    $amounts = [];
    foreach ([$i1, $i2, $i3, $i4, $i5, $i6] as $inv) {
        $lns = fetch_base_rental_lines($inv['invoice_id']);
        $amounts[] = $lns[0]['amount'];
    }

    // R2 calendar-month billing (end_date Aug 12 known → 34+-day monthly lease
    // spanning Mar→Aug): partial start month $93.33, four complete months flat
    // at $700 each, partial end month Aug 1-12 = 12 × $700/30 = $280.00.
    eq("6mo Invoice 1 (Mar 28-31, partial)", '93.33',  $amounts[0]);
    eq("6mo Invoice 2 (Apr complete month)", '700.00', $amounts[1]);
    eq("6mo Invoice 3 (May complete month)", '700.00', $amounts[2]);
    eq("6mo Invoice 4 (Jun complete month)", '700.00', $amounts[3]);
    eq("6mo Invoice 5 (Jul complete month)", '700.00', $amounts[4]);
    eq("6mo Invoice 6 (Aug 1-12, partial)",  '280.00', $amounts[5]);

    $total = '0.00';
    foreach ($amounts as $a) { $total = bcadd($total, $a, 2); }
    // $93.33 + 4 × $700 + $280.00 = $3,173.33
    eq("6mo lease total (cumulative at day 138)", '3173.33', $total);
} finally {
    $pdo->rollBack();
}

// ── INT 10: Negative-delta reconciliation credit ────────────
// Construct a scenario where Invoice 1 was billed and then we VOID it,
// causing a future invoice to "over-credit" already_billed against
// the current cumulative_correct. Actually simpler — use the activation
// whichever-pays-more rule on a contrived rate set where the cumulative
// drops vs. what was billed.
//
// Spec §18.4: rate ratio $50/$350/$300 (cheap monthly relative to daily).
// 4 days @ daily = $200 (Invoice 1 activation).
// Lease continues to day 34 (totaling Apr 30):
//   cumulative monthly_math = 1 × $300 + 4 × $10 = $340.
//   already_billed = $200. delta = $140 → positive, NOT a credit.
//
// Try harder: $1000 daily, $1 monthly. After Invoice 1 (4 days × $1000 = $4000),
// continue to day 30 (cumulative monthly = $1). delta = $1 - $4000 = -$3999 NEGATIVE.
$pdo->beginTransaction();
try {
    $cust = db_row("SELECT id FROM customers WHERE deleted_at IS NULL AND province IS NOT NULL LIMIT 1");
    $unit = db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL LIMIT 1");
    $leaseId = db_insert('leases', [
        'contract_number'   => 'TEST-NEG-' . uniqid(),
        'customer_id'       => $cust['id'],
        'equipment_unit_id' => $unit['id'],
        'customer_name_snapshot' => 'Neg Test',
        'company_name_snapshot'  => 'Neg Test Co',
        'unit_number_snapshot'   => 'NEG-UNIT',
        'start_date'        => '2026-03-28',
        'status'            => 'active',
        'daily_rate'        => '1000.00',
        'weekly_rate'       => '350.00',
        'monthly_rate'      => '700.00',
        'currency'          => 'CAD',
        'mileage_unit'      => 'km',
        'mileage_rate'      => '0.0000',
        'mileage_rate_km'   => '0.0000',
        'estimated_mileage' => '0.00',
        'estimated_mileage_km' => '0.000',
        'tax_exempt'        => 0, 'gst_exempt' => 0, 'pst_exempt' => 0,
        'discount_type'     => 'none', 'discount_value' => '0.0000',
        'insurance_opt_in'  => 0, 'insurance_cost' => '0.00',
        'warranty_opt_in'   => 0, 'warranty_cost' => '0.00',
        'billing_cycle'     => 'monthly',
        'advance_billing_periods' => 0,
        'engine_version'    => 'holistic',
        'precharge_enabled' => 0,
        'gps_opt_in'        => 0, 'gps_cost' => '0.00',
    ]);

    // S-AUDIT-BILLING-ENGINE-1 #14 REWRITE: the old fixture manufactured the
    // over-bill via the fixed daily band (4 × $1000 = $4000); D-R2-2 cheaper-of
    // now bills min($4000, weekly $350) = $350, so that trigger is gone. The
    // R2-native negative-delta trigger is an EXTENT SHRINK: bill the flat
    // month (single-calendar-month rule), then the lease returns early — the
    // cumulative through the reduced extent drops BELOW already_billed and the
    // engine emits the reconciliation credit ("prorate in the past", D-R2-6).
    //
    // Invoice 1: Mar 8–31 (start Mar 8, open-ended → E=Mar 31, total 24 days,
    // wm(24)=$1200 > monthly $700 → single calendar month → flat $700).
    db_execute("UPDATE leases SET start_date = '2026-03-08' WHERE id = ?", [$leaseId]);
    $inv1 = gen_invoice($leaseId, '2026-03-08', '2026-03-31', 'partial_start');
    $lines1 = fetch_base_rental_lines($inv1['invoice_id']);
    eq("Neg-delta INV1 amount", '700.00', $lines1[0]['amount']);

    // Lease actually returns Mar 20 → extent shrinks to Mar 20 (13 days,
    // wm(13) = $650 ≤ $700 → weekly_math $650). Final invoice's delta =
    // $650 − $700 = −$50 → reconciliation credit. The credit drives the
    // (otherwise-empty) final invoice negative — overflow handler caps the
    // line to $0 and routes $50 to a credit_notes row.
    db_execute("UPDATE leases SET actual_return_date = '2026-03-20' WHERE id = ?", [$leaseId]);
    $inv2 = gen_invoice($leaseId, '2026-03-20', '2026-03-20', 'partial_end');
    $lines2 = fetch_base_rental_lines($inv2['invoice_id']);

    eq("Neg-delta INV2 line type", 'base_rental_reconciliation_credit', $lines2[0]['item_type']);
    eq("Neg-delta INV2 is_credit", '1', (string)$lines2[0]['is_credit']);
    // The cap fires because the only other revenue on the invoice is $0
    // (no insurance/warranty/GPS) — so the full credit gets routed to
    // a credit_notes row with source='base_rental_reconciliation_overflow'.
    eq("Neg-delta INV2 amount after cap", '0.00', $lines2[0]['amount']);

    $creditNote = db_row(
        "SELECT source, amount FROM credit_notes WHERE source_invoice_id = ? AND source = 'base_rental_reconciliation_overflow'",
        [$inv2['invoice_id']]
    );
    if ($creditNote === null) {
        bad("Neg-delta INV2 credit_note row missing");
    } else {
        eq("Neg-delta INV2 credit_note source", 'base_rental_reconciliation_overflow', $creditNote['source']);
        eq("Neg-delta INV2 credit_note amount", '50.00', $creditNote['amount']);
    }

    // Audit columns should still reflect the engine's evaluation
    $audit2 = fetch_audit_columns($inv2['invoice_id']);
    eq("Neg-delta INV2 audit total_days", '13', (string)$audit2['total_days_at_period_end']);
    eq("Neg-delta INV2 audit cumulative_correct", '650.00', $audit2['cumulative_correct_amount']);
    eq("Neg-delta INV2 audit already_billed", '700.00', $audit2['already_billed_before_this']);
} finally {
    $pdo->rollBack();
}

// S-DELETE-LEGACY-ENGINE: REG 1 (period_independent → ProRateCalculator $200+$700)
// and REG 2 (no reconciliation_credit on old engine) were removed with the legacy
// engine. The holistic engine is the only rental engine now.

// ── REG 3: holistic invoice produces audit_log row ──────────
$pdo->beginTransaction();
try {
    $leaseId = spawn_test_lease('2026-03-28', '2026-04-30');
    $inv1 = gen_invoice($leaseId, '2026-03-28', '2026-03-31', 'partial_start');
    $audit = db_row(
        "SELECT notes, new_values FROM audit_log WHERE entity_type = 'invoice_holistic_reconciliation' AND entity_id = ?",
        [$inv1['invoice_id']]
    );
    if ($audit === null) {
        bad("Holistic invoice missing audit_log row");
    } else {
        ok("Holistic audit_log present: " . substr($audit['notes'], 0, 80) . "...");
        $j = json_decode($audit['new_values'], true);
        // R2: lease end_date Apr 30 known → 34-day monthly lease; the Mar 28-31
        // partial start month bills at monthly÷30 = 4 × $23.3333 = $93.33.
        eq("Holistic audit_log tier", 'monthly', $j['tier']);
        eq("Holistic audit_log delta", '93.33', $j['delta']);
    }
} finally {
    $pdo->rollBack();
}

// ════════════════════════════════════════════════════════════
// REPORT
// ════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 70) . "\n";
echo "S-BILLING-HOLISTIC-ENGINE Stress Test Results\n";
echo str_repeat('=', 70) . "\n\n";

foreach ($out as $line) {
    echo $line . "\n";
}

echo "\n" . str_repeat('-', 70) . "\n";
echo sprintf("TOTAL: %d pass / %d fail\n", $pass, $fail);
echo str_repeat('-', 70) . "\n";

exit($fail > 0 ? 1 : 0);
