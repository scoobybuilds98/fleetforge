<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_time_of_day.php
 *
 * Adversarial billing-engine integration test for S-LEASE-RENTAL-DAY-TIME.
 *
 * Each scenario:
 *   1. Creates a lease with time fields set directly on the DB row.
 *   2. Calls InvoiceGenerator::createFromLease() (the real close path) or
 *      generateForLease() inside a BEGIN/ROLLBACK transaction.
 *   3. Asserts the base_rental net matches the expected amount.
 *
 * Rates throughout: daily $100, weekly $500, monthly $1500.
 *   1 billed day  = $100.00
 *   2 billed days = $200.00
 *   30 billed days (Jun, full month) = $1500.00 (monthly flat)
 *   31 billed days (Jun + Jul 1) = $1500.00 + $50.00 = $1550.00
 *
 * Grace buckets exercised: 0 / 60 / 120 minutes.
 * Time edge cases: midnight (00:00), late-night (23:30), noon (12:00).
 * Boundary: return == pickup always on-time (inclusive == ).
 * Legacy: NULL start_time/actual_return_time → legacy inclusive billing.
 *
 * Run: php tests/_smoke_billing_time_of_day.php
 * Exit: 0 = all pass, 1 = any fail.
 *
 * @session S-LEASE-RENTAL-DAY-TIME
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Billing\HolisticLeaseEngine;
use FleetForge\Tests\Fixtures;

// ── Rates ───────────────────────────────────────────────────────────────────
const TOD_DAILY   = '100.00';
const TOD_WEEKLY  = '500.00';
const TOD_MONTHLY = '1500.00';

$pass = 0;
$fail = 0;

function tod_pass(string $label): void { global $pass; $pass++; echo "  PASS  {$label}\n"; }
function tod_fail(string $label, string $detail = ''): void {
    global $fail; $fail++;
    echo "  FAIL  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
}
function tod_eq(string $exp, mixed $got, string $label): void {
    $gotStr = (string)($got ?? 'null');
    if (bccomp($exp, $gotStr, 2) === 0) tod_pass($label);
    else tod_fail($label, "expected {$exp}, got {$gotStr}");
}

/** Net base_rental for an invoice (credits counted negative). */
function tod_base_net(int $invoiceId): string {
    $row = db_row(
        "SELECT COALESCE(SUM(CASE WHEN is_credit=1 THEN -amount ELSE amount END), '0.00') AS s
           FROM invoice_line_items
          WHERE invoice_id = ? AND item_type IN ('base_rental','base_rental_reconciliation_credit')",
        [$invoiceId]
    );
    return (string)($row['s'] ?? '0.00');
}

/** Bump invoice counter above MAX so no collision inside the transaction. */
function tod_bump_counter(): void {
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int)substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string)($maxNum + 50)]
    );
}

/** Set grace_minutes setting inside current transaction. Reverts on ROLLBACK. */
function tod_set_grace(int $minutes): void {
    db_execute(
        "UPDATE settings SET `value` = ? WHERE `key` = 'lease.return_grace_minutes'",
        [(string)$minutes]
    );
}

/**
 * Create a holistic lease and stamp the time fields + actual_return_date.
 * Returns lease_id. Must be called inside an open BEGIN transaction.
 */
function tod_make_lease(
    string $startDate,
    string $startTime,
    ?string $actualReturnDate,
    ?string $actualReturnTime,
    ?string $endDate = null,
    array $overrides = []
): int {
    $cust = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $lid  = Fixtures::createLease($cust, array_merge([
        'engine_version'  => 'holistic',
        'billing_cycle'   => 'monthly',
        'start_date'      => $startDate,
        'end_date'        => $endDate,
        'status'          => 'active',
        'daily_rate'      => TOD_DAILY,
        'weekly_rate'     => TOD_WEEKLY,
        'monthly_rate'    => TOD_MONTHLY,
        'gps_opt_in'      => 0,
        'insurance_opt_in'=> 0,
        'warranty_opt_in' => 0,
        'tax_exempt'      => 1, // exclude tax from assertions
        'gst_exempt'      => 1,
        'pst_exempt'      => 1,
    ], $overrides));

    // Stamp time fields (Fixtures::createLease doesn't set these new columns).
    db_execute(
        "UPDATE leases
            SET start_time          = ?,
                actual_return_date  = ?,
                actual_return_time  = ?
          WHERE id = ?",
        [$startTime, $actualReturnDate, $actualReturnTime, $lid]
    );
    return $lid;
}

/**
 * Close a lease: call createFromLease() for the full period and return
 * the invoice_id. period_end supplied = actual_return_date (the engine
 * will override it anyway via the time rule, but we pass it for correctness).
 */
function tod_close(int $leaseId, string $periodStart, string $periodEnd): int {
    $gen = new InvoiceGenerator();
    $res = $gen->createFromLease([
        'lease_id'     => $leaseId,
        'period_start' => $periodStart,
        'period_end'   => $periodEnd,
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => 1,
    ]);
    return (int)$res['invoice_id'];
}

/** Run $fn inside BEGIN/ROLLBACK, bump counter first. */
function tod_run(string $label, callable $fn): void {
    db_execute('BEGIN');
    try {
        tod_bump_counter();
        $fn($label);
    } finally {
        db_execute('ROLLBACK');
    }
}

echo "\n=== S-LEASE-RENTAL-DAY-TIME — billing engine integration ===\n\n";
echo "Rates: daily \$" . TOD_DAILY . " / weekly \$" . TOD_WEEKLY . " / monthly \$" . TOD_MONTHLY . "\n\n";

// ════════════════════════════════════════════════════════════════════════════
// GROUP A — grace = 0, noon pickup (12:00)
// ════════════════════════════════════════════════════════════════════════════
echo "── Group A: grace=0, pickup 12:00 ──────────────────────────────────────\n";

tod_run('A1: late return (12:05 > 12:00) → 2 days → $200', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '12:05');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'A1: late return → 2 days = $200');
});

tod_run('A2: on-time return (11:55 < 12:00) → 1 day → $100', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '11:55');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'A2: on-time return → 1 day = $100');
});

tod_run('A3: boundary — exactly at pickup (12:00 == 12:00) → on-time → 1 day', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '12:00');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'A3: boundary == on-time → 1 day = $100');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP B — grace = 60 minutes
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group B: grace=60 min ────────────────────────────────────────────────\n";

tod_run('B1: grace 60, return 12:30 (within grace) → 1 day', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '12:30');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'B1: within grace → 1 day = $100');
});

tod_run('B2: grace 60, deadline 13:00 exactly → on-time → 1 day', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '13:00');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'B2: at deadline == on-time → 1 day = $100');
});

tod_run('B3: grace 60, return 13:01 (1 min past) → extra day → 2 days', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '13:01');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'B3: 1 min past grace → 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP C — grace = 120 minutes
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group C: grace=120 min ───────────────────────────────────────────────\n";

tod_run('C1: grace 120, return 11:59 (1 min short of deadline 12:00) → on-time', function () {
    tod_set_grace(120);
    $lid = tod_make_lease('2026-06-01', '10:00', '2026-06-02', '11:59');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'C1: within 120-min grace → 1 day = $100');
});

tod_run('C2: grace 120, return 12:01 (1 min past deadline) → extra day', function () {
    tod_set_grace(120);
    $lid = tod_make_lease('2026-06-01', '10:00', '2026-06-02', '12:01');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'C2: 1 min past 120-min grace → 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP D — monthly billing tier
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group D: monthly-tier (Jun 1 → Jul 1) ────────────────────────────────\n";

tod_run('D1: Jun-1→Jul-1 12:05 late → 31 days → $1500 + $50 = $1550', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-07-01', '12:05');
    $inv = tod_close($lid, '2026-06-01', '2026-07-01');
    tod_eq('1550.00', tod_base_net($inv), 'D1: Jun flat + 1 extra pro-rated day = $1550');
});

tod_run('D2: Jun-1→Jul-1 11:55 on-time → 30 days → $1500 flat', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-07-01', '11:55');
    $inv = tod_close($lid, '2026-06-01', '2026-07-01');
    tod_eq('1500.00', tod_base_net($inv), 'D2: exactly Jun flat = $1500');
});

tod_run('D3: Jun-1→Jul-1 12:00 boundary → on-time → 30 days → $1500', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-07-01', '12:00');
    $inv = tod_close($lid, '2026-06-01', '2026-07-01');
    tod_eq('1500.00', tod_base_net($inv), 'D3: boundary == on-time → $1500');
});

tod_run('D4: grace 60, return 12:30 → within grace → 30 days → $1500', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-07-01', '12:30');
    $inv = tod_close($lid, '2026-06-01', '2026-07-01');
    tod_eq('1500.00', tod_base_net($inv), 'D4: grace saves monthly return → $1500');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP E — midnight pickup (00:00) edge cases
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group E: midnight pickup (00:00) ─────────────────────────────────────\n";

tod_run('E1: midnight pickup, return 00:01 next day → extra day → 2 days', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '00:00', '2026-06-02', '00:01');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'E1: 1 min past midnight pickup → 2 days = $200');
});

tod_run('E2: midnight pickup, return 00:00 next day → on-time (==) → 1 day', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '00:00', '2026-06-02', '00:00');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'E2: midnight == midnight on-time → 1 day = $100');
});

tod_run('E3: midnight pickup, return 23:59 next day → late → 2 days', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '00:00', '2026-06-02', '23:59');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'E3: 23:59 past midnight pickup → 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP F — late-night pickup (23:30) edge cases
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group F: late-night pickup (23:30) ───────────────────────────────────\n";

tod_run('F1: 23:30 pickup, return 23:29 next day → on-time → 1 day', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '23:30', '2026-06-02', '23:29');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('100.00', tod_base_net($inv), 'F1: 23:29 < 23:30 pickup → on-time → 1 day = $100');
});

tod_run('F2: 23:30 pickup, return 23:31 next day → extra day → 2 days', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '23:30', '2026-06-02', '23:31');
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'F2: 23:31 > 23:30 pickup → extra day → 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP G — same-day return (min 1 day guard)
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group G: same-day return (min 1 day guard) ───────────────────────────\n";

tod_run('G1: same-day on-time → adjusted to May31 < startDate → clamp Jun1 = 1 day', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-01', '11:55');
    $inv = tod_close($lid, '2026-06-01', '2026-06-01');
    tod_eq('100.00', tod_base_net($inv), 'G1: same-day on-time clamped to start = 1 day = $100');
});

tod_run('G2: same-day late (12:01 > 12:00) → return_date returned as-is = Jun1 = 1 day', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-01', '12:01');
    $inv = tod_close($lid, '2026-06-01', '2026-06-01');
    tod_eq('100.00', tod_base_net($inv), 'G2: same-day late stays Jun1 = 1 day = $100');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP H — NULL times (legacy fall-through)
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group H: NULL times (legacy inclusive) ────────────────────────────────\n";

tod_run('H1: NULL start_time + NULL actual_return_time → inclusive 2 days = $200', function () {
    // No time rule fires — actual_return_date used as-is (Jun2 inclusive from Jun1 = 2 days).
    $cust = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $lid  = Fixtures::createLease($cust, [
        'engine_version'  => 'holistic',
        'billing_cycle'   => 'monthly',
        'start_date'      => '2026-06-01',
        'status'          => 'active',
        'daily_rate'      => TOD_DAILY,
        'weekly_rate'     => TOD_WEEKLY,
        'monthly_rate'    => TOD_MONTHLY,
        'gps_opt_in'      => 0,
        'insurance_opt_in'=> 0,
        'warranty_opt_in' => 0,
        'tax_exempt'      => 1,
        'gst_exempt'      => 1,
        'pst_exempt'      => 1,
    ]);
    // Set actual_return_date but NOT the time columns → legacy path.
    db_execute("UPDATE leases SET actual_return_date = '2026-06-02', start_time = NULL, actual_return_time = NULL WHERE id = ?", [$lid]);

    tod_bump_counter(); // already inside BEGIN, re-bump after the insert
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'H1: NULL times → legacy inclusive Jun1-Jun2 = 2 days = $200');
});

tod_run('H2: NULL actual_return_time only (start_time set) → time rule skipped → inclusive', function () {
    // start_time set but actual_return_time missing → time rule guard fails → use actual_return_date as-is.
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', null);
    $inv = tod_close($lid, '2026-06-01', '2026-06-02');
    tod_eq('200.00', tod_base_net($inv), 'H2: missing return time → rule skipped → 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP I — multi-day stay, verify extent counted correctly
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group I: multi-day stays ─────────────────────────────────────────────\n";

tod_run('I1: 4-night stay, late return → 5 days = $500', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-05', '12:05');
    $inv = tod_close($lid, '2026-06-01', '2026-06-05');
    tod_eq('500.00', tod_base_net($inv), 'I1: 5 billed days = $500');
});

tod_run('I2: 4-night stay, on-time return → 4 days = $400', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-05', '11:55');
    $inv = tod_close($lid, '2026-06-01', '2026-06-05');
    tod_eq('400.00', tod_base_net($inv), 'I2: on-time → 4 billed days = $400');
});

tod_run('I3: 2-night stay, grace 60, return +45min → within grace → 2 days = $200', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '09:00', '2026-06-03', '09:45');
    $inv = tod_close($lid, '2026-06-01', '2026-06-03');
    tod_eq('200.00', tod_base_net($inv), 'I3: grace saves 3rd day → 2 days = $200');
});

tod_run('I4: 2-night stay, grace 60, return +61min → past grace → 3 days = $300', function () {
    tod_set_grace(60);
    $lid = tod_make_lease('2026-06-01', '09:00', '2026-06-03', '10:01');
    $inv = tod_close($lid, '2026-06-01', '2026-06-03');
    tod_eq('300.00', tod_base_net($inv), 'I4: 1 min past grace → 3 days = $300');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP J — generateForLease() path (the monthly generate route)
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group J: generateForLease() path ─────────────────────────────────────\n";

tod_run('J1: generateForLease with actual_return_time, late → time rule applied', function () {
    tod_set_grace(0);
    // 2-day lease (Jun1→Jun2), late return 12:05 → effective_end = Jun2 → 2 days
    $cust = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $lid  = Fixtures::createLease($cust, [
        'engine_version'  => 'holistic',
        'billing_cycle'   => 'monthly',
        'start_date'      => '2026-06-01',
        'end_date'        => '2026-06-02',
        'status'          => 'active',
        'daily_rate'      => TOD_DAILY,
        'weekly_rate'     => TOD_WEEKLY,
        'monthly_rate'    => TOD_MONTHLY,
        'gps_opt_in'      => 0,
        'insurance_opt_in'=> 0,
        'warranty_opt_in' => 0,
        'tax_exempt'      => 1,
        'gst_exempt'      => 1,
        'pst_exempt'      => 1,
    ]);
    db_execute(
        "UPDATE leases SET start_time='12:00', actual_return_date='2026-06-02', actual_return_time='12:05' WHERE id=?",
        [$lid]
    );
    tod_bump_counter();
    $gen     = new InvoiceGenerator();
    $result  = $gen->generateForLease([
        'lease_id'     => $lid,
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-02',
        'billing_type' => 'single_segment',
        'invoice_type' => 'regular',
        'created_by'   => 1,
    ]);
    $invId   = $result['invoices'][0]['invoice_id'] ?? null;
    if ($invId === null) {
        tod_fail('J1: generateForLease returned no invoice (result: ' . json_encode($result) . ')'); return;
    }
    tod_eq('200.00', tod_base_net($invId), 'J1: generateForLease late return → 2 days = $200');
});

tod_run('J2: generateForLease with actual_return_time, on-time → 1 day', function () {
    tod_set_grace(0);
    $cust = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $lid  = Fixtures::createLease($cust, [
        'engine_version'  => 'holistic',
        'billing_cycle'   => 'monthly',
        'start_date'      => '2026-06-01',
        'end_date'        => '2026-06-02',
        'status'          => 'active',
        'daily_rate'      => TOD_DAILY,
        'weekly_rate'     => TOD_WEEKLY,
        'monthly_rate'    => TOD_MONTHLY,
        'gps_opt_in'      => 0,
        'insurance_opt_in'=> 0,
        'warranty_opt_in' => 0,
        'tax_exempt'      => 1,
        'gst_exempt'      => 1,
        'pst_exempt'      => 1,
    ]);
    db_execute(
        "UPDATE leases SET start_time='12:00', actual_return_date='2026-06-02', actual_return_time='11:55' WHERE id=?",
        [$lid]
    );
    tod_bump_counter();
    $gen    = new InvoiceGenerator();
    $result = $gen->generateForLease([
        'lease_id'     => $lid,
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-02',
        'billing_type' => 'single_segment',
        'invoice_type' => 'regular',
        'created_by'   => 1,
    ]);
    $invId  = $result['invoices'][0]['invoice_id'] ?? null;
    if ($invId === null) {
        tod_fail('J2: generateForLease returned no invoice'); return;
    }
    tod_eq('100.00', tod_base_net($invId), 'J2: generateForLease on-time return → 1 day = $100');
});

tod_run('J3: generateForLease open-ended lease (no actual_return) → normal billing', function () {
    // No actual_return_date/time set → time rule doesn't fire → normal end_date billing.
    // Jun1 → Jun2 end_date, open-ended partial: should bill for 2 days.
    $cust = Fixtures::createCustomer(['province' => 'BC', 'tax_exempt' => 0]);
    $lid  = Fixtures::createLease($cust, [
        'engine_version'  => 'holistic',
        'billing_cycle'   => 'monthly',
        'start_date'      => '2026-06-01',
        'end_date'        => '2026-06-02',
        'status'          => 'active',
        'daily_rate'      => TOD_DAILY,
        'weekly_rate'     => TOD_WEEKLY,
        'monthly_rate'    => TOD_MONTHLY,
        'gps_opt_in'      => 0,
        'insurance_opt_in'=> 0,
        'warranty_opt_in' => 0,
        'tax_exempt'      => 1,
        'gst_exempt'      => 1,
        'pst_exempt'      => 1,
    ]);
    // start_time set, but NO actual_return_date → time rule never reaches return-time check.
    db_execute("UPDATE leases SET start_time='12:00' WHERE id=?", [$lid]);
    tod_bump_counter();
    $gen    = new InvoiceGenerator();
    $result = $gen->generateForLease([
        'lease_id'     => $lid,
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-02',
        'billing_type' => 'single_segment',
        'invoice_type' => 'regular',
        'created_by'   => 1,
    ]);
    $invId  = $result['invoices'][0]['invoice_id'] ?? null;
    if ($invId === null) {
        tod_fail('J3: generateForLease returned no invoice'); return;
    }
    // end_date=Jun2, so billing is Jun1→Jun2 = 2 days regardless of start_time.
    tod_eq('200.00', tod_base_net($invId), 'J3: open-ended (no actual_return) → end_date billing = 2 days = $200');
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP K — S-LEASE-CLOSE-ACTUAL-DATE: customer-facing invoice DATES
// The return-day-not-billed time-of-day trim must NOT hide the real return date
// from the customer. Mimics close.php EXACTLY: period_end = the TRIMMED billable
// extent (NOT the actual return date), which is the condition the description
// relabel keys on. The amount/day-count stay the trimmed value; only the label
// gains the actual return date + a "return day not charged" note.
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group K: actual-date label on time-trimmed close ─────────────────────\n";

/** base_rental line description for an invoice. */
function tod_base_desc(int $invoiceId): string {
    return (string) (db_row(
        "SELECT description FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'base_rental' LIMIT 1",
        [$invoiceId]
    )['description'] ?? '');
}

/** Close mimicking close.php: pass the TRIMMED extent as period_end. */
function tod_close_extent(int $leaseId, string $periodStart, string $extentEnd): int {
    $gen = new InvoiceGenerator();
    $res = $gen->createFromLease([
        'lease_id'     => $leaseId,
        'period_start' => $periodStart,
        'period_end'   => $extentEnd,   // close.php passes lease_billable_extent here
        'billing_type' => 'partial_end',
        'invoice_type' => 'final',
        'created_by'   => 1,
    ]);
    return (int) $res['invoice_id'];
}

tod_run('K1: morning return (09:00 <= pickup 10:00) → invoice shows ACTUAL return date + note', function () {
    tod_set_grace(0);
    $lid    = tod_make_lease('2025-07-18', '10:00', '2025-07-25', '09:00');
    $extent = HolisticLeaseEngine::effectiveBillableEndDate('2025-07-25', '09:00', '10:00', 0, '2025-07-18');
    if ($extent !== '2025-07-24') { tod_fail('K1 setup', "extent expected 2025-07-24, got {$extent}"); return; }
    $desc = tod_base_desc(tod_close_extent($lid, '2025-07-18', $extent));
    $ok = str_contains($desc, '2025-07-25')              // ACTUAL return date shown
        && str_contains($desc, 'return day not charged') // explanatory note
        && str_contains($desc, 'billed 7 days')          // billed (trimmed) day count
        && !str_contains($desc, 'to 2025-07-24');        // NOT the old trimmed-date framing
    $ok ? tod_pass("K1: {$desc}") : tod_fail('K1', "desc=[{$desc}]");
});

tod_run('K2: late return (12:05 > pickup 12:00) → not trimmed → original framing, no note', function () {
    tod_set_grace(0);
    $lid    = tod_make_lease('2025-07-18', '12:00', '2025-07-25', '12:05');
    $extent = HolisticLeaseEngine::effectiveBillableEndDate('2025-07-25', '12:05', '12:00', 0, '2025-07-18');
    if ($extent !== '2025-07-25') { tod_fail('K2 setup', "extent expected 2025-07-25, got {$extent}"); return; }
    $desc = tod_base_desc(tod_close_extent($lid, '2025-07-18', $extent));
    $ok = !str_contains($desc, 'return day not charged') && str_contains($desc, 'to 2025-07-25');
    $ok ? tod_pass("K2: {$desc}") : tod_fail('K2', "desc=[{$desc}]");
});

tod_run('K3: operator Remove-N-days close is NOT relabelled (reduced period stays customer-facing)', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2025-07-18', '12:00', '2025-07-25', '12:05');   // late return → no time-trim
    db_execute("UPDATE leases SET billing_days_removed = 2 WHERE id = ?", [$lid]);
    $desc = tod_base_desc(tod_close_extent($lid, '2025-07-18', '2025-07-23'));   // Jul25 − 2 removed = Jul23
    $ok = !str_contains($desc, 'return day not charged');   // billing_days_removed>0 → excluded from relabel
    $ok ? tod_pass("K3: {$desc}") : tod_fail('K3', "desc=[{$desc}]");
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP L — S-LEASE-MIN-DAYS-CATEGORY (chassis-only minimum) + min-days label
// The 3-day minimum must bind ONLY for equipment categories in the setting
// lease.minimum_billing_days_categories (default 'chassis'); a non-chassis short
// lease bills actual days. And the base_rental label must show the billed
// minimum, not the actual extent days, when it binds.
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group L: chassis-only 3-day minimum + label ──────────────────────────\n";

/**
 * Point the lease's equipment-unit template at a category (rolled back with the
 * txn). S-EQTAX: the minimum is now gated by the category's
 * enforce_minimum_billing_days flag resolved via category_id, so set the FK too
 * and force the canonical enforce value (chassis enforces; everything else does
 * not) — keeps each scenario hermetic regardless of dev-DB drift.
 */
function tod_set_unit_category(int $leaseId, string $category): void {
    $enforce = ($category === 'chassis') ? 1 : 0;
    db_execute(
        "UPDATE equipment_categories SET enforce_minimum_billing_days = ? WHERE slug = ? AND deleted_at IS NULL",
        [$enforce, $category]
    );
    db_execute(
        "UPDATE equipment_templates et
           JOIN equipment_units eu ON eu.template_id = et.id
           JOIN leases l ON l.equipment_unit_id = eu.id
           JOIN equipment_categories ec ON ec.slug = ? AND ec.deleted_at IS NULL
            SET et.category = ?, et.category_id = ec.id, et.subcategory_id = NULL
          WHERE l.id = ?",
        [$category, $category, $leaseId]
    );
}

tod_run('L1: chassis short-lease → 3-day minimum binds ($300) + "minimum" label', function () {
    tod_set_grace(0);
    // 1-day lease (return 13:00 > pickup 12:00 → no trim), min_days=3, daily $100.
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-01', '13:00');
    db_execute("UPDATE leases SET minimum_billing_days = 3 WHERE id = ?", [$lid]);
    tod_set_unit_category($lid, 'chassis');
    $inv  = tod_close($lid, '2026-06-01', '2026-06-01');
    $net  = tod_base_net($inv);
    $desc = tod_base_desc($inv);
    bccomp($net, '300.00', 2) === 0 && str_contains($desc, '3-day minimum')
        ? tod_pass("L1: net={$net} · {$desc}")
        : tod_fail('L1', "net={$net} (expect 300.00) desc=[{$desc}]");
});

tod_run('L2: dry_van short-lease → minimum does NOT apply ($100, actual day)', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-01', '13:00');
    db_execute("UPDATE leases SET minimum_billing_days = 3 WHERE id = ?", [$lid]);
    tod_set_unit_category($lid, 'dry_van');
    $inv  = tod_close($lid, '2026-06-01', '2026-06-01');
    $net  = tod_base_net($inv);
    $desc = tod_base_desc($inv);
    bccomp($net, '100.00', 2) === 0 && stripos($desc, 'minimum') === false
        ? tod_pass("L2: net={$net} · {$desc}")
        : tod_fail('L2', "net={$net} (expect 100.00) desc=[{$desc}]");
});

tod_run('L3: chassis + time-trim → combined label (minimum + return day not charged)', function () {
    tod_set_grace(0);
    // start 12:00, return next day 10:00 (<= pickup) → trim → extent = start day (1 day) → min binds (3 days).
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '10:00');
    db_execute("UPDATE leases SET minimum_billing_days = 3 WHERE id = ?", [$lid]);
    tod_set_unit_category($lid, 'chassis');
    $extent = HolisticLeaseEngine::effectiveBillableEndDate('2026-06-02', '10:00', '12:00', 0, '2026-06-01'); // 2026-06-01
    $inv  = tod_close_extent($lid, '2026-06-01', $extent);
    $net  = tod_base_net($inv);
    $desc = tod_base_desc($inv);
    $ok = bccomp($net, '300.00', 2) === 0
        && str_contains($desc, '2026-06-02')             // ACTUAL return date
        && str_contains($desc, '3-day minimum')          // minimum
        && str_contains($desc, 'return day not charged');// trim note
    $ok ? tod_pass("L3: net={$net} · {$desc}")
        : tod_fail('L3', "net={$net} (expect 300.00) desc=[{$desc}]");
});

tod_run('L4: combo sub-type INHERITS the Chassis minimum (S-EQTAX — the Jete\'s fix)', function () {
    tod_set_grace(0);
    // The real prod scenario: a "Combo" template whose legacy mirror is still
    // 'combo' but whose category_id now points at Chassis. Billing reads the
    // category flag via category_id, so the chassis minimum must inherit even
    // though the mirror slug is not 'chassis'.
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-01', '13:00');
    db_execute("UPDATE leases SET minimum_billing_days = 3 WHERE id = ?", [$lid]);
    db_execute("UPDATE equipment_categories SET enforce_minimum_billing_days = 1 WHERE slug = 'chassis' AND deleted_at IS NULL");
    // Ensure a Combo sub-category under Chassis, then point the template at it
    // while leaving the mirror = 'combo'.
    $chassisId = (int) db_row("SELECT id FROM equipment_categories WHERE slug = 'chassis' AND deleted_at IS NULL")['id'];
    db_execute(
        "INSERT INTO equipment_subcategories (category_id, slug, label)
         VALUES (?, 'combo', 'Combo')
         ON DUPLICATE KEY UPDATE label = VALUES(label)",
        [$chassisId]
    );
    $comboSubId = (int) db_row("SELECT id FROM equipment_subcategories WHERE category_id = ? AND slug = 'combo'", [$chassisId])['id'];
    db_execute(
        "UPDATE equipment_templates et
           JOIN equipment_units eu ON eu.template_id = et.id
           JOIN leases l ON l.equipment_unit_id = eu.id
            SET et.category = 'combo', et.category_id = ?, et.subcategory_id = ?
          WHERE l.id = ?",
        [$chassisId, $comboSubId, $lid]
    );
    $inv  = tod_close($lid, '2026-06-01', '2026-06-01');
    $net  = tod_base_net($inv);
    $desc = tod_base_desc($inv);
    bccomp($net, '300.00', 2) === 0 && str_contains($desc, '3-day minimum')
        ? tod_pass("L4: net={$net} · combo inherits chassis minimum · {$desc}")
        : tod_fail('L4', "net={$net} (expect 300.00 — combo should inherit) desc=[{$desc}]");
});

// ════════════════════════════════════════════════════════════════════════════
// GROUP M — S-LEASE-CLOSE-ONE-INVOICE: GPS/insurance/warranty suppressed on the
// close-out 'adjustment' invoice (so the time-trimmed return day is not charged
// a per-day GPS line on a separate invoice).
// ════════════════════════════════════════════════════════════════════════════
echo "\n── Group M: no GPS/add-ons on adjustment invoice ────────────────────────\n";

tod_run('M1: adjustment invoice with gps_opt_in carries NO gps line', function () {
    tod_set_grace(0);
    $lid = tod_make_lease('2026-06-01', '12:00', '2026-06-02', '12:05', null, [
        'gps_opt_in' => 1, 'gps_cost' => '1.00',
        'insurance_opt_in' => 1, 'insurance_cost' => '5.00',
    ]);
    $gen = new InvoiceGenerator();
    $res = $gen->createFromLease([
        'lease_id' => $lid, 'period_start' => '2026-06-02', 'period_end' => '2026-06-02',
        'billing_type' => 'adjustment', 'invoice_type' => 'final', 'created_by' => 1,
        'extra_lines' => [[
            'item_type' => 'mileage_usage', 'description' => 'Mileage usage: 10.00 km × $0.04/km',
            'quantity' => '10', 'unit' => 'km', 'unit_price' => '0.04', 'amount' => '0.40',
            'is_credit' => 0, 'taxable' => 1,
        ]],
    ]);
    $iid = (int) $res['invoice_id'];
    $gpsN = (int) (db_row("SELECT COUNT(*) c FROM invoice_line_items WHERE invoice_id=? AND item_type IN ('gps','insurance','warranty')", [$iid])['c'] ?? 0);
    $milN = (int) (db_row("SELECT COUNT(*) c FROM invoice_line_items WHERE invoice_id=? AND item_type='mileage_usage'", [$iid])['c'] ?? 0);
    $gpsN === 0 && $milN === 1
        ? tod_pass("M1: adjustment carries mileage only (gps/add-on lines={$gpsN})")
        : tod_fail('M1', "gps/add-on lines={$gpsN} (expect 0), mileage={$milN} (expect 1)");
});

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════════════
$total = $pass + $fail;
echo "\n══════════════════════════════════════════════════════════════════════════\n";
echo "RESULTS: {$pass}/{$total} PASS" . ($fail > 0 ? " — {$fail} FAIL" : ' — ALL PASS') . "\n\n";
exit($fail > 0 ? 1 : 0);
