<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_minimum_days.php
 *
 * S-LEASE-MIN-DAYS — end-to-end smoke for the short-lease minimum-billing-days
 * floor. When a lease's TOTAL billable duration (start through the known
 * extent E) is shorter than N days, BOTH billing engines must bill a FLAT
 * N × daily_rate (tier/method = 'daily_minimum'), overriding the normal
 * daily/weekly/monthly tier ladder entirely. Default N = 3 (mainly chassis).
 *
 * This exercises REAL code against the real db.php + schema inside
 * BEGIN/ROLLBACK — InvoiceGenerator → createFromLease/generateForLease → the
 * holistic engine writing real invoices + invoice_line_items rows — and the
 * two engine entry points directly. It does NOT re-implement the tier math.
 *
 * The floor is resolved by InvoiceGenerator from leases.minimum_billing_days
 * (config layer 2 — frozen on the lease at creation); layer 1
 * (rate_card_items.minimum_days) and layer 3 (settings
 * 'lease.minimum_billing_days') collapse into that single column upstream at
 * lease create, so by billing time leases.minimum_billing_days IS the
 * effective floor. We set it directly on the fixture lease here.
 *
 * Rates throughout (T1–T5): daily $100, weekly $500, monthly $1,500 — so the
 * 3-day floor (3 × $100 = $300) is clearly distinguishable from the natural
 * 1-day daily charge ($100) and the weekly_flat ($500).
 *
 *   T1  1-day chassis-style lease, daily>0, minimum_billing_days=3
 *         → base rental bills FLAT $300 (3 × daily), tier 'daily_minimum'.
 *   T2  5-day lease, minimum_billing_days=3 → floor does NOT bind (5 >= 3);
 *         normal pricing (5 × $100 = $500 on the holistic sub-month ladder).
 *   T3  daily_rate=0 with minimum_billing_days=3 → NO-OP (floor cannot bind;
 *         no crash). With weekly>0 the natural sub-month pricing still bills.
 *   T4  minimum_billing_days ∈ {0, 1, NULL} → NO-OP on a 1-day lease (each
 *         bills the natural 1 × daily = $100).
 *   T5  Reconciliation top-up: activation bills ~1 day, then a close at the
 *         short extent reconciles the cumulative up to the flat 3-day floor —
 *         the delta emits ONE base_rental top-up, no double charge.
 *   T6  Direct unit check of HolisticLeaseEngine::cumulativeCorrect(...,minDays)
 *         and ProRateCalculator::calculate(...,minDays): binding + non-binding.
 *
 * Run:  php tests/_smoke_lease_minimum_days.php       Exit 0 = pass, 1 = fail.
 *
 * Migration gate: if the S-LEASE-MIN-DAYS schema (leases.minimum_billing_days)
 * is not yet applied, the file prints a clear SKIP and exits 0 — it is meant
 * to be RUN in a later phase, after db_migrations/202606190400_*.sql lands.
 *
 * @session S-LEASE-MIN-DAYS
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

// Rates: daily $100 makes the 3-day floor ($300) unmistakable next to the
// natural daily ($100) and weekly_flat ($500) tiers.
const MD_DAILY   = '100.00';
const MD_WEEKLY  = '500.00';
const MD_MONTHLY = '1500.00';

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

/**
 * mbcron_bump_counter — push the year's invoice-number counter clear of
 * MAX(invoice_number) so an in-scenario generate can't collide on the
 * INV-YYYY-NNNNN UNIQUE index inside BEGIN/ROLLBACK (memory rule:
 * createFromLease() inside a transaction needs this first). Reverts with
 * the scenario's ROLLBACK.
 */
function mbcron_bump_counter(): void {
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 50)]
    );
}

/** Make a holistic lease at MD rates with an optional frozen floor + overrides. */
function md_lease(array $overrides = []): int {
    $cust = Fixtures::createCustomer(['province' => 'BC']);
    return Fixtures::createLease($cust, array_merge([
        'engine_version' => 'holistic',
        'billing_cycle'  => 'monthly',
        'daily_rate'     => MD_DAILY,
        'weekly_rate'    => MD_WEEKLY,
        'monthly_rate'   => MD_MONTHLY,
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

/**
 * Engine tier recorded in the holistic audit_meta JSON, which the generator
 * stores in the base_rental line item's detail_lines column. This is where the
 * 'daily_minimum' tier surfaces (the invoices.rate_method_used ENUM has no
 * daily_minimum member). Returns '' when no base_rental line carries it.
 */
function audit_tier(int $invoiceId): string {
    $row = db_row(
        "SELECT detail_lines FROM invoice_line_items
          WHERE invoice_id=? AND item_type='base_rental' AND detail_lines IS NOT NULL
          ORDER BY id LIMIT 1",
        [$invoiceId]
    );
    $meta = json_decode((string)($row['detail_lines'] ?? ''), true);
    return is_array($meta) ? (string)($meta['tier'] ?? '') : '';
}

// ════════════════════════════════════════════════════════════════════
// Migration gate — the floor column must exist before T1–T5 can run.
// T6 (pure engine math) does not need the schema, but for a single clear
// signal we gate the whole file: it is meant to run AFTER the migration.
// ════════════════════════════════════════════════════════════════════
$hasLeaseCol = (bool) db_select("SHOW COLUMNS FROM leases LIKE 'minimum_billing_days'");
if (!$hasLeaseCol) {
    echo "SKIP  S-LEASE-MIN-DAYS schema not applied (leases.minimum_billing_days absent).\n";
    echo "      Apply db_migrations/202606190400_S-LEASE-MIN-DAYS_minimum_billing_days.sql, then re-run.\n";
    echo "\n----------------------------------------------------------------------\n";
    echo "TOTAL: 0 pass / 0 fail (skipped — migration not applied)\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

$gen = new InvoiceGenerator();

// ════════════════════════════════════════════════════════════════════
// T1 — 1-day chassis-style lease, daily $100, minimum_billing_days=3.
// The 1-billable-day extent is below the 3-day floor, so the base rental
// must bill a FLAT 3 × $100 = $300 (NOT 1 × $100), tier 'daily_minimum'.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = md_lease([
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-01',   // 1 inclusive day
        'minimum_billing_days' => 3,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 1, 'T1 1-day floor: single invoice');
    $inv = $batch['invoices'][0]['invoice_id'];
    eq('300.00', base_net($inv), 'T1 base rental FLAT 3 × $100 (floor binds, not 1 × $100)');
    // The engine's daily_minimum tier is recorded in the invoice's holistic
    // audit_meta; assert that forensic trail names the floor. The rate_method
    // ENUMs have no 'daily_minimum' member, so the fine-grained floor identity
    // lives ONLY here (detail_lines), not in the coarse ENUM columns.
    ok(audit_tier($inv) === 'daily_minimum',
        "T1 audit_meta tier = 'daily_minimum' (got '" . audit_tier($inv) . "')");
    // ENUM-level: mapTierToRateMethod('daily_minimum') => 'daily', so the
    // coarser invoices.rate_method_used + invoice_line_items.rate_method record
    // the floor's economic (daily) basis — minimum_days × daily_rate — rather
    // than falling through to 'none'/null (the pre-fix behaviour).
    $rmu = db_row("SELECT rate_method_used FROM invoices WHERE id=?", [$inv]);
    ok(($rmu['rate_method_used'] ?? '') === 'daily',
        "T1 invoices.rate_method_used = 'daily' (floor's daily basis; got '" . ($rmu['rate_method_used'] ?? '') . "')");
    $rml = db_row("SELECT rate_method FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental' ORDER BY id LIMIT 1", [$inv]);
    ok(($rml['rate_method'] ?? '') === 'daily',
        "T1 base_rental line item rate_method = 'daily' (got '" . ($rml['rate_method'] ?? '') . "')");
});

// ════════════════════════════════════════════════════════════════════
// T2 — 5-day lease, minimum_billing_days=3. 5 >= 3 → floor does NOT bind;
// normal pricing applies (5 cumulative days = weekly_flat $500).
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = md_lease([
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-05',   // 5 inclusive days
        'minimum_billing_days' => 3,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-05',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 1, 'T2 5-day lease: single invoice');
    $inv = $batch['invoices'][0]['invoice_id'];
    // 5 cumulative days → 5 × $100 = $500 on the holistic sub-month ladder; the
    // floor must NOT clamp it to the flat 3 × $100.
    eq('500.00', base_net($inv), 'T2 normal pricing (floor does NOT bind, 5 >= 3)');
    ok(audit_tier($inv) !== 'daily_minimum',
        "T2 not the daily_minimum tier (got '" . audit_tier($inv) . "')");
});

// ════════════════════════════════════════════════════════════════════
// T3 — daily_rate = 0 with minimum_billing_days=3 → NO-OP. The floor
// CANNOT bind on a $0 daily (flooring it would just bill $0). We bill a
// 6-day extent so the holistic sub-month ladder lands on weekly_flat
// (a non-zero $500 off the weekly rate, independent of the $0 daily),
// proving the $0-daily floor is SKIPPED and billing proceeds without a
// crash — rather than colliding with the unrelated D132 zero-base backstop
// (which legitimately fires for a genuinely $0 base, a separate concern).
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = md_lease([
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-06',   // 6 days — weekly_flat regime
        'daily_rate'           => '0.00',         // floor no-op
        'weekly_rate'          => MD_WEEKLY,      // keeps a non-zero billable tier
        'monthly_rate'         => MD_MONTHLY,
        'minimum_billing_days' => 3,
    ]);
    $threw = false;
    $inv = 0;
    try {
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-06',
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        $inv = $batch['invoices'][0]['invoice_id'];
    } catch (\Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'T3 $0 daily + floor=3: no crash (floor is a no-op, not a $0 base)');
    if (!$threw && $inv) {
        eq('500.00', base_net($inv), 'T3 natural weekly_flat bills (floor skipped on $0 daily)');
    }
});

// ════════════════════════════════════════════════════════════════════
// T4 — minimum_billing_days ∈ {0, 1, NULL} → NO-OP. On a 1-day lease each
// bills the natural 1 × daily = $100 (a 0/1/NULL floor never clamps).
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    foreach ([['0', 0], ['1', 1], ['NULL', null]] as [$label, $floor]) {
        mbcron_bump_counter();
        $lease = md_lease([
            'start_date'           => '2026-06-01',
            'end_date'             => '2026-06-01',   // 1 inclusive day
            'minimum_billing_days' => $floor,
        ]);
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        $inv = $batch['invoices'][0]['invoice_id'];
        eq('100.00', base_net($inv), "T4 floor={$label}: NO-OP, natural 1 × \$100 (not flat \$300)");
    }
});

// ════════════════════════════════════════════════════════════════════
// T5 — Reconciliation top-up. An OPEN lease bills its activation period
// (~1 day) at a point when the extent is just that day; the floor binds and
// the activation already bills the flat $300. Then the lease is CLOSED at a
// still-short extent (3 days) and a regeneration reconciles: the cumulative
// floor is still $300, so the close emits a ZERO/no top-up — never a double
// charge. We then prove the inverse: when activation under-bills below the
// floor, the close delta tops UP to exactly the flat $300.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    // Open lease (no end_date) so the activation extent = the billing date.
    $lease = md_lease([
        'start_date'           => '2026-06-01',
        'minimum_billing_days' => 3,
    ]);

    // Activation: bill just the first day. Extent = period_end (2026-06-01),
    // total = 1 < 3, floor binds → cumulative_correct = $300, already_billed=0
    // → delta $300 base_rental.
    $b1 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $inv1 = $b1['invoices'][0]['invoice_id'];
    eq('300.00', base_net($inv1), 'T5 activation floors to flat $300 (extent=1 day < 3)');

    // Close the lease at a still-short extent (3 days). The engine bills on
    // actual_return_date as the extent: total = 3 = minDays → floor does NOT
    // bind (total < minDays is false), cumulative_correct = natural 3-day
    // pricing = $300 (3 × daily). already_billed = $300 → delta $0 → no top-up,
    // no double charge.
    db_execute("UPDATE leases SET actual_return_date='2026-06-03', status='completed' WHERE id=?", [$lease]);
    $b2 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-02', 'period_end' => '2026-06-03',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    // Sum every base_rental NET across the lease — the cumulative must equal
    // the floor exactly, with no double charge.
    $allInv = db_select(
        "SELECT id FROM invoices WHERE lease_id=? AND deleted_at IS NULL AND status<>'void' ORDER BY id",
        [$lease]
    );
    $cum = '0';
    foreach ($allInv as $r) { $cum = bcadd($cum, base_net((int)$r['id']), 2); }
    eq('300.00', $cum, 'T5 cumulative base rental = flat $300 across activation + close (no double charge)');

    // The close regeneration must NOT have emitted a second $300 — its own
    // base_net delta is $0 (cumulative $300 already billed at activation).
    $closeInvId = $b2['count'] > 0 ? (int)($b2['invoices'][0]['invoice_id'] ?? 0) : 0;
    if ($closeInvId) {
        eq('0.00', base_net($closeInvId), 'T5 close invoice top-up delta = $0 (floor already satisfied)');
    } else {
        ok(true, 'T5 close emitted no base_rental line (delta $0 — floor already satisfied)');
    }
});

// ════════════════════════════════════════════════════════════════════
// T5b — Reconciliation TOP-UP when activation under-bills below the floor.
// A lease with NO floor at activation bills 1 day ($100); then the floor is
// set and the lease closes at a 2-day extent (still < 3). The close must top
// the cumulative UP to the flat $300 via a $200 base_rental delta — no double
// charge, exactly the floor.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    // Activation WITHOUT a floor → natural 1 × $100.
    $lease = md_lease([
        'start_date'           => '2026-06-01',
        'minimum_billing_days' => null,
    ]);
    $b1 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    eq('100.00', base_net($b1['invoices'][0]['invoice_id']), 'T5b activation (no floor) bills natural 1 × $100');

    // Now apply the 3-day floor and close at a 2-day extent (2 < 3 → floor
    // binds). cumulative_correct = $300, already_billed = $100 → delta $200.
    db_execute(
        "UPDATE leases SET minimum_billing_days=3, actual_return_date='2026-06-02', status='completed' WHERE id=?",
        [$lease]
    );
    $b2 = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-02', 'period_end' => '2026-06-02',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($b2['count'] >= 1, 'T5b close emits a reconciliation invoice');
    $closeInv = (int)$b2['invoices'][0]['invoice_id'];
    eq('200.00', base_net($closeInv), 'T5b close top-up delta = $200 (cumulative reaches flat $300)');

    $allInv = db_select(
        "SELECT id FROM invoices WHERE lease_id=? AND deleted_at IS NULL AND status<>'void'",
        [$lease]
    );
    $cum = '0';
    foreach ($allInv as $r) { $cum = bcadd($cum, base_net((int)$r['id']), 2); }
    eq('300.00', $cum, 'T5b cumulative = flat $300 (top-up, no double charge)');
});

// ════════════════════════════════════════════════════════════════════
// T6 — Direct unit checks of the two engine entry points with $minDays.
// No DB writes; pure math against the real engine code.
// ════════════════════════════════════════════════════════════════════
(function () {
    $h = new HolisticLeaseEngine();

    // ── HolisticLeaseEngine::cumulativeCorrect — binding case ──
    // 1-day extent (start=through=extent=2026-06-01), total=1 < 3, daily>0
    // → flat 3 × $100 = $300, tier 'daily_minimum'.
    $bind = $h->cumulativeCorrect('2026-06-01', '2026-06-01', '2026-06-01', MD_DAILY, MD_WEEKLY, MD_MONTHLY, 3);
    eq('300.00', $bind['amount'], 'T6 holistic cumulativeCorrect binds → flat $300');
    ok($bind['tier'] === 'daily_minimum', "T6 holistic tier = 'daily_minimum' (got '{$bind['tier']}')");

    // ── HolisticLeaseEngine::cumulativeCorrect — non-binding (5-day) ──
    // total=5 >= 3 → normal sub-month ladder (5 × $100 = $500), tier NOT
    // daily_minimum (the holistic ladder labels n<=5 days 'daily').
    $nob = $h->cumulativeCorrect('2026-06-01', '2026-06-05', '2026-06-05', MD_DAILY, MD_WEEKLY, MD_MONTHLY, 3);
    eq('500.00', $nob['amount'], 'T6 holistic cumulativeCorrect non-binding → normal 5 × $100 = $500');
    ok($nob['tier'] !== 'daily_minimum', "T6 holistic non-binding tier != daily_minimum (got '{$nob['tier']}')");

    // ── HolisticLeaseEngine — minDays 0/1 and $0 daily are no-ops ──
    $minZero = $h->cumulativeCorrect('2026-06-01', '2026-06-01', '2026-06-01', MD_DAILY, MD_WEEKLY, MD_MONTHLY, 1);
    eq('100.00', $minZero['amount'], 'T6 holistic minDays=1 → no-op, natural 1 × $100');
    $zeroDaily = $h->cumulativeCorrect('2026-06-01', '2026-06-01', '2026-06-01', '0.00', MD_WEEKLY, MD_MONTHLY, 3);
    ok($zeroDaily['tier'] !== 'daily_minimum', 'T6 holistic $0 daily → floor no-op (tier != daily_minimum)');

    // S-DELETE-LEGACY-ENGINE: the ProRateCalculator direct-unit checks were removed
    // with the engine; HolisticLeaseEngine is the only rental engine and its
    // min-days floor is covered above + end-to-end in T7.
})();

// ════════════════════════════════════════════════════════════════════
// T7 — holistic min-days floor, END-TO-END (rate_method ENUM-clamp guard).
// The floor binds with tier 'daily_minimum', which is NOT a member of the
// invoices.rate_method_used / invoice_line_items.rate_method ENUMs — writing it
// verbatim would 1265-truncate under STRICT_TRANS_TABLES and abort the whole
// transaction. This drives the real generator and asserts: (a) it persists
// without throwing, (b) bills the flat 3 × $100 = $300 floor, and (c) records
// the ENUM-safe economic basis 'daily' (not 'daily_minimum', not 'none') in
// BOTH columns. (S-DELETE-LEGACY-ENGINE: was the period_independent path; now
// the only rental engine, holistic, carries the same floor + clamp.)
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = md_lease([
        'engine_version'       => 'holistic',
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-01',   // 1 inclusive day < 3-day floor
        'minimum_billing_days' => 3,
    ]);
    $threw = false;
    $inv = null;
    try {
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-01',
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        $inv = $batch['invoices'][0]['invoice_id'];
    } catch (\Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'T7 holistic min-days floor: persists without a 1265 ENUM-truncation fatal');
    if ($inv !== null) {
        eq('300.00', base_net($inv), 'T7 holistic base rental FLAT 3 × $100 (min-days floor binds)');
        // The flat-floor label is clamped to its economic basis 'daily' at BOTH
        // ENUM write sites (invoice row + line item) so the row persists.
        $rmu = db_row("SELECT rate_method_used FROM invoices WHERE id=?", [$inv]);
        ok(($rmu['rate_method_used'] ?? '') === 'daily',
            "T7 invoices.rate_method_used clamped to 'daily' (got '" . ($rmu['rate_method_used'] ?? '') . "')");
        $rm = db_row("SELECT rate_method FROM invoice_line_items WHERE invoice_id=? AND item_type='base_rental' ORDER BY id LIMIT 1", [$inv]);
        ok(($rm['rate_method'] ?? '') === 'daily',
            "T7 invoice_line_items.rate_method clamped to 'daily' (got '" . ($rm['rate_method'] ?? '') . "')");
    }
});

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
