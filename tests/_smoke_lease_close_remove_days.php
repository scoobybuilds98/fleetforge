<?php
declare(strict_types=1);

/**
 * tests/_smoke_lease_close_remove_days.php
 *
 * S-LEASE-CLOSE-REMOVE-DAYS — end-to-end smoke for the "remove N billable days
 * at lease close" feature. At close an operator enters "Remove ___ days"; that
 * count (leases.billing_days_removed, TINYINT UNSIGNED DEFAULT 0) is subtracted
 * off the END of the lease's BILLABLE period — the removed day(s) drop out of
 * the billing math — while the lease's actual_return_date and every displayed
 * date stay UNCHANGED.
 *
 * The N-day subtraction is applied at THREE lockstep sites, all keyed on
 * billing_days_removed, all clamped so the extent never precedes start_date
 * (minimum 1 billed day):
 *   (A) lib/Billing/InvoiceGenerator.php — re-derives extent_end from
 *       actual_return_date for the holistic engine, then subtracts N there.
 *   (B) api/v1/leases/_close_reconciliation.php — lease_billable_extent() takes
 *       a $daysRemoved param and subtracts internally (used by the overshoot
 *       clamp so OTHER invoices clamp to the same reduced extent).
 *   (C) api/v1/leases/close.php — passes billing_days_removed into
 *       lease_billable_extent() so the final invoice's period_end + the legacy
 *       period_independent path reduce in step.
 * They MUST agree, or the overshoot clamp and the final invoice diverge
 * (S-CLOSE-OVERSHOOT invariant).
 *
 * FLOOR ORDERING: the engine measures totalBillableDays against the REDUCED
 * extent_end, then the existing S-LEASE-MIN-DAYS floor (HolisticLeaseEngine::
 * cumulativeCorrect) bills a flat minDays × daily when that reduced total falls
 * below minDays. So removal happens first and the floor clamps the bottom — you
 * can never shave below the minimum. No engine-math change is involved.
 *
 * CUSTOMER vs INTERNAL: the removed-days note is INTERNAL-ONLY. It is stamped
 * ONLY into the holistic audit_meta (the base_rental line's detail_lines JSON,
 * which the admin invoice page renders as a staff "how this was calculated"
 * panel). The customer-facing line item DESCRIPTION stays clean — it shows the
 * naturally reduced period with NO "removed" text.
 *
 * This exercises REAL code against the real db.php + schema inside
 * BEGIN/ROLLBACK — InvoiceGenerator → generateForLease → the holistic engine
 * writing real invoices + invoice_line_items rows — plus a direct unit check of
 * lease_billable_extent(). It does NOT re-implement the tier/floor math.
 *
 * Rates throughout: daily $100, weekly $500, monthly $1,500 (holistic), so a
 * removed day ($100 off) and the 3-day floor ($300) are both unmistakable.
 *
 *   T1  5-day holistic lease (daily tier), billing_days_removed=1 → base rental
 *         bills the reduced 4 × $100 = $400 (the removed day is dropped from the
 *         calc). The lease row's actual_return_date is UNCHANGED.
 *   T2  billing_days_removed=0 → unchanged, full 5 × $100 = $500.
 *   T3  FLOOR WINS: 4-day lease, minimum_billing_days=3, billing_days_removed=2
 *         → reduced to 2 days but the floor clamps → flat 3 × $100 = $300.
 *   T4  OVER-REMOVAL: 5-day lease, billing_days_removed=12 → extent clamps to
 *         start (1 day); with minimum_billing_days=3 → flat $300; never
 *         negative, no crash.
 *   T5  INTERNAL-ONLY note: the base_rental detail_lines JSON (audit_meta)
 *         carries billing_days_removed=1 and a removal note, BUT the line
 *         DESCRIPTION contains NO "removed" text (customer-facing stays clean).
 *   T6  lease_billable_extent() unit check: $daysRemoved=1 returns one day
 *         earlier than $daysRemoved=0; an over-large $daysRemoved clamps to
 *         start_date.
 *   T7  LEGACY period_independent END-TO-END: a short period_independent lease
 *         billed with a reduced period_end bills the reduced day count without
 *         a fatal.
 *
 * Run:  php tests/_smoke_lease_close_remove_days.php     Exit 0 = pass, 1 = fail.
 *
 * Migration gate: if leases.billing_days_removed is not yet applied, the file
 * prints a clear SKIP and exits 0 — it is meant to be RUN after the column
 * lands.
 *
 * @session S-LEASE-CLOSE-REMOVE-DAYS
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';
// lease_billable_extent() lives here (Site B). The file is pure function
// definitions (no top-level execution), so requiring it has no side effects
// beyond making the helper callable for the T6 unit check.
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

// Rates: daily $100 makes a removed day ($100 off) and the 3-day floor ($300)
// unmistakable next to the natural daily ($100) and weekly_flat ($500) tiers.
const RD_DAILY   = '100.00';
const RD_WEEKLY  = '500.00';
const RD_MONTHLY = '1500.00';

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
 * createFromLease()/generateForLease() inside a transaction needs this first).
 * Reverts with the scenario's ROLLBACK.
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

/** Make a holistic lease at RD rates with optional floor/removal + overrides. */
function rd_lease(array $overrides = []): int {
    $cust = Fixtures::createCustomer(['province' => 'BC']);
    return Fixtures::createLease($cust, array_merge([
        'engine_version' => 'holistic',
        'billing_cycle'  => 'monthly',
        'daily_rate'     => RD_DAILY,
        'weekly_rate'    => RD_WEEKLY,
        'monthly_rate'   => RD_MONTHLY,
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

/** The base_rental line row (description + detail_lines) for an invoice. */
function base_rental_line(int $invoiceId): ?array {
    return db_row(
        "SELECT description, detail_lines FROM invoice_line_items
          WHERE invoice_id=? AND item_type='base_rental'
          ORDER BY id LIMIT 1",
        [$invoiceId]
    ) ?: null;
}

// ════════════════════════════════════════════════════════════════════
// Migration gate — the removal column must exist before any case can run.
// (T6 is pure helper math, but we gate the whole file on one clear signal:
//  it is meant to run AFTER the migration lands.)
// ════════════════════════════════════════════════════════════════════
$hasCol = (bool) db_select("SHOW COLUMNS FROM leases LIKE 'billing_days_removed'");
if (!$hasCol) {
    echo "SKIP  S-LEASE-CLOSE-REMOVE-DAYS schema not applied (leases.billing_days_removed absent).\n";
    echo "      Apply the billing_days_removed migration, then re-run.\n";
    echo "\n----------------------------------------------------------------------\n";
    echo "TOTAL: 0 pass / 0 fail (skipped — migration not applied)\n";
    echo "----------------------------------------------------------------------\n";
    exit(0);
}

$gen = new InvoiceGenerator();

// ════════════════════════════════════════════════════════════════════
// T1 — 10-day holistic lease, billing_days_removed=1.
// start..return = 2026-06-01..2026-06-05 (5 inclusive days, daily tier). The
// holistic engine re-derives the extent from actual_return_date, then subtracts
// the 1 removed day → reduced extent 2026-06-04 → 4 billable days → 4 × $100 = $400.
// The lease's actual_return_date row value MUST stay 2026-06-05 (unchanged).
// (10+ day spans bill on the weekly_math tier, not flat daily — a 5-day span
// keeps the "$100 per removed day" demonstration exact.)
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-05',
        'actual_return_date'  => '2026-06-05',   // 5 inclusive days (daily tier)
        'status'              => 'completed',
        'billing_days_removed' => 1,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-05',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    ok($batch['count'] === 1, 'T1 5-day, remove 1: single invoice');
    $inv = $batch['invoices'][0]['invoice_id'];
    // 4 billable days on the holistic daily ladder = 4 × $100 = $400 (the removed
    // day is dropped from the calc — NOT 5 × $100 = $500).
    eq('400.00', base_net($inv), 'T1 base rental = reduced 4 × $100 = $400 (1 day removed)');
    // The displayed/return date on the lease is UNCHANGED by removal.
    $row = db_row("SELECT actual_return_date FROM leases WHERE id=?", [$lease]);
    ok(($row['actual_return_date'] ?? '') === '2026-06-05',
        "T1 lease.actual_return_date UNCHANGED at 2026-06-05 (got '" . ($row['actual_return_date'] ?? '') . "')");
    // S-LEASE-CLOSE-REMOVE-DAYS regression guard: generateForLease must reduce its
    // OWN fan-out extent ($target), so the stored billing_period_end matches the
    // reduced amount. Without that fix this would be 2026-06-05 (un-reduced period
    // end against a 4-day amount) — the exact period-end-vs-amount divergence.
    $pe = db_row("SELECT billing_period_end FROM invoices WHERE id=?", [$inv]);
    ok(($pe['billing_period_end'] ?? '') === '2026-06-04',
        "T1 invoice billing_period_end = reduced 2026-06-04 (period matches amount; got '" . ($pe['billing_period_end'] ?? '') . "')");
});

// ════════════════════════════════════════════════════════════════════
// T2 — billing_days_removed=0 → NO-OP. Same 5-day lease bills the full
// 5 × $100 = $500. Proves the subtraction only engages when N > 0.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-05',
        'actual_return_date'  => '2026-06-05',   // 5 inclusive days (daily tier)
        'status'              => 'completed',
        'billing_days_removed' => 0,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-05',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $inv = $batch['invoices'][0]['invoice_id'];
    eq('500.00', base_net($inv), 'T2 remove 0: full 5 × $100 = $500 (no-op)');
});

// ════════════════════════════════════════════════════════════════════
// T3 — FLOOR WINS. 4-day lease, minimum_billing_days=3, billing_days_removed=2.
// Removal first: 4 − 2 = 2 billable days. Then the 3-day floor clamps the
// bottom: 2 < 3 → flat 3 × $100 = $300 (you can never shave below the floor).
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-04',
        'actual_return_date'   => '2026-06-04',   // 4 inclusive days
        'status'               => 'completed',
        'minimum_billing_days' => 3,
        'billing_days_removed'  => 2,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-04',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $inv = $batch['invoices'][0]['invoice_id'];
    // Reduced 2 days falls below the 3-day floor → flat $300, NOT 2 × $100 = $200.
    eq('300.00', base_net($inv), 'T3 floor wins: reduced 2 days clamps UP to flat 3 × $100 = $300');
});

// ════════════════════════════════════════════════════════════════════
// T4 — OVER-REMOVAL. 5-day lease, billing_days_removed=12. The subtraction
// clamps the extent to start_date (min 1 billed day), never going negative and
// never crashing. With minimum_billing_days=3 the floor then clamps the single
// remaining day up to the flat 3 × $100 = $300.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-05',
        'actual_return_date'   => '2026-06-05',   // 5 inclusive days
        'status'               => 'completed',
        'minimum_billing_days' => 3,
        'billing_days_removed'  => 12,            // >> the span
    ]);
    $threw = false;
    $inv = 0;
    try {
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-05',
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        $inv = $batch['invoices'][0]['invoice_id'];
    } catch (\Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'T4 over-removal: extent clamps to start (1 day), no crash / no negative');
    if (!$threw && $inv) {
        // Clamped to 1 billed day, then the 3-day floor → flat $300.
        eq('300.00', base_net($inv), 'T4 clamped-to-start 1 day, floor → flat 3 × $100 = $300');
    }
});

// ════════════════════════════════════════════════════════════════════
// T5 — INTERNAL-ONLY note. The removal note belongs ONLY in the holistic
// audit_meta (the base_rental line's detail_lines JSON, an admin-only "how
// this was calculated" panel). The customer-facing line DESCRIPTION must stay
// clean — it shows the naturally reduced period with NO "removed" text.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'start_date'          => '2026-06-01',
        'end_date'            => '2026-06-10',
        'actual_return_date'  => '2026-06-10',   // 10 inclusive days
        'status'              => 'completed',
        'billing_days_removed' => 1,
    ]);
    $batch = $gen->generateForLease([
        'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-10',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $inv  = $batch['invoices'][0]['invoice_id'];
    $line = base_rental_line($inv);
    ok($line !== null, 'T5 base_rental line exists');

    // (a) INTERNAL: the detail_lines audit_meta carries billing_days_removed=1
    //     and a human note. We assert on the decoded JSON so a reordered key set
    //     or differing whitespace does not falsely fail.
    $meta = is_array($line) ? json_decode((string)($line['detail_lines'] ?? ''), true) : null;
    ok(is_array($meta), 'T5 detail_lines is valid JSON (audit_meta)');
    $metaRemoved = is_array($meta) ? (int)($meta['billing_days_removed'] ?? 0) : 0;
    ok($metaRemoved === 1,
        "T5 audit_meta.billing_days_removed = 1 (internal forensic trail; got {$metaRemoved})");
    // A note about the removal lives in the audit_meta (key name not pinned —
    // accept any string value mentioning "remov" across the meta payload).
    $metaJson = is_array($meta) ? strtolower(json_encode($meta)) : '';
    ok(str_contains($metaJson, 'remov'),
        'T5 audit_meta carries a removed-days note (internal only)');

    // (b) CUSTOMER-FACING: the line DESCRIPTION must NOT mention removal.
    $desc = strtolower((string)($line['description'] ?? ''));
    ok(!str_contains($desc, 'remov'),
        "T5 line description has NO 'removed' text (customer-facing stays clean; got '" . ($line['description'] ?? '') . "')");
});

// ════════════════════════════════════════════════════════════════════
// T6 — lease_billable_extent() unit check (Site B). With $daysRemoved=1 the
// returned extent is one calendar day earlier than with $daysRemoved=0; an
// over-large $daysRemoved clamps to start_date. We pass NULL return/start
// times so the function takes its date-only branch (returns $returnDate), then
// the removal clamp applies on top — isolating the subtraction logic.
// ════════════════════════════════════════════════════════════════════
(function () {
    $start  = '2026-06-01';
    $return = '2026-06-10';

    // Baseline (no removal) returns the raw return date.
    $base = lease_billable_extent($return, null, null, $start, 0);
    ok($base === '2026-06-10', "T6 daysRemoved=0 → extent = return date 2026-06-10 (got '{$base}')");

    // One day removed → one calendar day earlier.
    $minus1 = lease_billable_extent($return, null, null, $start, 1);
    ok($minus1 === '2026-06-09', "T6 daysRemoved=1 → extent one day earlier = 2026-06-09 (got '{$minus1}')");
    ok($minus1 < $base, 'T6 daysRemoved=1 extent strictly earlier than daysRemoved=0');

    // Over-large removal clamps to start_date (never before start).
    $clamped = lease_billable_extent($return, null, null, $start, 99);
    ok($clamped === '2026-06-01', "T6 over-large daysRemoved clamps to start_date 2026-06-01 (got '{$clamped}')");
})();

// ════════════════════════════════════════════════════════════════════
// T7 — LEGACY period_independent path, END-TO-END. A short period_independent
// lease billed with a reduced period_end (the day already removed by close.php
// before it calls the generator) must bill the reduced day count with no fatal.
// We bill 2026-06-01..2026-06-03 (3 inclusive days = the 4-day lease minus its
// 1 removed day) and assert it persists and bills 3 × $100 = $300.
// ════════════════════════════════════════════════════════════════════
DbState::inTransaction(function () use ($gen) {
    mbcron_bump_counter();
    $lease = rd_lease([
        'engine_version'       => 'period_independent',
        'start_date'           => '2026-06-01',
        'end_date'             => '2026-06-04',
        'actual_return_date'   => '2026-06-04',   // displayed return: 4 days
        'status'               => 'completed',
        'billing_days_removed'  => 1,             // close.php reduces extent → 06-03
    ]);
    $threw = false;
    $inv = null;
    try {
        // close.php drives the legacy path with a period_end already reduced by
        // the removal (lease_billable_extent's local extentEnd) — emulate that
        // by billing through the reduced 2026-06-03.
        $batch = $gen->generateForLease([
            'lease_id' => $lease, 'period_start' => '2026-06-01', 'period_end' => '2026-06-03',
            'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
        ]);
        $inv = $batch['invoices'][0]['invoice_id'];
    } catch (\Throwable $e) {
        $threw = true;
    }
    ok(!$threw, 'T7 legacy period_independent reduced period: persists without a fatal');
    if ($inv !== null) {
        // 3 reduced billable days on the legacy ladder = 3 × $100 = $300.
        eq('300.00', base_net($inv), 'T7 legacy reduced 3 × $100 = $300 (removed day not billed)');
    }
});

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
