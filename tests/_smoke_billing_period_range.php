<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_period_range.php
 *
 * FLEETFORGE-14 — an implausible billing period must fail LOUDLY and early,
 * never as an opaque PDO 22003 from deep inside db_insert().
 *
 * Prod incident (lease #237 / MTTS286, 2026-06-25 → 06-26, 7 events):
 *   1. A manual invoice was keyed with billing period 0001-03-02 → 0001-03-31
 *      — the year typed as 0001 instead of 2026. clean_date() accepted it
 *      (checkdate() considers year 1 a real calendar year), so it was stored.
 *   2. close.php derives the final period's start from the live coverage
 *      anchor MAX(billing_period_end) over the lease's non-void invoices.
 *      The typo'd row won that MAX, so the final period came out as
 *      0001-04-01 → 2026-03-13 = 739,708 days.
 *   3. invoices.billing_period_days is SMALLINT UNSIGNED (0..65535), so the
 *      INSERT raised "SQLSTATE[22003] ... Out of range value for column
 *      'billing_period_days'" ~27 frames from the cause, naming neither the
 *      lease nor the period. The lease could not be closed until an operator
 *      hand-deleted the bad invoice.
 *
 * Asserts, against the real db.php + schema inside BEGIN/ROLLBACK:
 *   T1  ff_billing_period_error() unit matrix — good periods pass; year-0001,
 *       inverted, >65535-day, and unparseable periods each return a message.
 *   T2  The DB ceiling constant matches the actual column type (a schema
 *       change to INT/MEDIUMINT must not leave the guard stale).
 *   T3  REPRO: createFromLease() with the exact poisoned period throws
 *       INVALID_BILLING_PERIOD naming the lease — NOT a PDOException 22003.
 *   T4  A normal period on the same fixture lease still generates an invoice
 *       (the guard is not over-broad), and a legitimately long historical
 *       period (prod has a lease starting 2005) is still accepted.
 *
 * PRE-FIX  : T3 fails with PDOException 22003; T1/T2 fatal (no such function).
 * POST-FIX : all pass.
 *
 * Run:  php tests/_smoke_billing_period_range.php    Exit 0 = pass, 1 = fail.
 *
 * @session FLEETFORGE-14
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$label}\n"; }
    else       { $fail++; echo "FAIL  {$label}\n"; }
}

/**
 * bpr_bump_counter — push the year's invoice-number counter clear of
 * MAX(invoice_number) so an in-scenario generate can't collide on the
 * INV-YYYY-NNNNN UNIQUE index inside BEGIN/ROLLBACK (memory rule:
 * createFromLease() inside a transaction needs this first).
 */
function bpr_bump_counter(): void {
    $yr     = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 50)]
    );
}

// ------------------------------------------------------------------
// T1 — ff_billing_period_error() unit matrix
// ------------------------------------------------------------------
echo "\n--- T1: ff_billing_period_error() matrix ---\n";

ok(function_exists('ff_billing_period_error'), 'T1.0 helper is autoloaded');

// Sound periods → null.
ok(ff_billing_period_error('2026-03-02', '2026-03-31') === null, 'T1.1 normal month passes');
ok(ff_billing_period_error('2026-03-02', '2026-03-02') === null, 'T1.2 single-day period passes');
ok(ff_billing_period_error('2005-05-28', '2025-11-25') === null, 'T1.3 real 20-year prod lease still passes');

// The exact prod poison — year 0001 instead of 2026.
$e = ff_billing_period_error('0001-03-02', '0001-03-31');
ok($e !== null && str_contains($e, 'year'), 'T1.4 year-0001 period rejected (the prod typo)');

// The derived period that actually overflowed the column.
$e = ff_billing_period_error('0001-04-01', '2026-03-13');
ok($e !== null, 'T1.5 derived 739,708-day period rejected');

// Inverted.
$e = ff_billing_period_error('2026-03-31', '2026-03-02');
ok($e !== null && str_contains($e, 'before'), 'T1.6 inverted period rejected');

// Unparseable / missing.
ok(ff_billing_period_error('not-a-date', '2026-03-31') !== null, 'T1.7 unparseable start rejected');
ok(ff_billing_period_error(null, '2026-03-31') !== null, 'T1.8 null start rejected');
ok(ff_billing_period_error('2026-02-30', '2026-03-31') !== null, 'T1.9 impossible calendar date rejected');

// Boundary: a period exactly at the column ceiling must pass; one past it must not.
$base   = new DateTimeImmutable('2026-01-01');
$atMax  = $base->modify('+' . (FF_BILLING_PERIOD_MAX_DAYS - 1) . ' days')->format('Y-m-d');
// (that end date lands past FF_BILLING_YEAR_MAX, so the ceiling is exercised
// via the year window in practice — assert the year guard fires, which is the
// stricter of the two and the one that catches real corruption.)
ok(ff_billing_period_error('2026-01-01', $atMax) !== null, 'T1.10 65535-day span rejected (year window binds first)');

// ------------------------------------------------------------------
// T2 — the guard constant must match the real column type
// ------------------------------------------------------------------
echo "\n--- T2: constant tracks the schema ---\n";

$col = db_row("SHOW COLUMNS FROM invoices LIKE 'billing_period_days'");
$type = strtolower((string) ($col['Type'] ?? ''));
$expectedMax = match (true) {
    str_starts_with($type, 'smallint')  => 65535,
    str_starts_with($type, 'mediumint') => 16777215,
    str_starts_with($type, 'int')       => 4294967295,
    str_starts_with($type, 'tinyint')   => 255,
    default                             => null,
};
ok(str_contains($type, 'unsigned'), "T2.1 billing_period_days is unsigned (got: {$type})");
ok(
    $expectedMax !== null && FF_BILLING_PERIOD_MAX_DAYS === $expectedMax,
    "T2.2 FF_BILLING_PERIOD_MAX_DAYS (" . FF_BILLING_PERIOD_MAX_DAYS . ") matches column type {$type}"
);

// ------------------------------------------------------------------
// T3 + T4 — real createFromLease() against the real schema
// ------------------------------------------------------------------
echo "\n--- T3/T4: createFromLease() behaviour ---\n";

DbState::inTransaction(function () {
    bpr_bump_counter();

    $cust = Fixtures::createCustomer(['province' => 'BC']);
    $lid  = Fixtures::createLease($cust, [
        'engine_version' => 'holistic',
        'billing_cycle'  => 'monthly',
        'daily_rate'     => '100.00',
        'weekly_rate'    => '500.00',
        'monthly_rate'   => '1500.00',
        'gps_opt_in'     => 0,
        'status'         => 'active',
        'start_date'     => '2026-03-02',
    ]);

    $gen = new InvoiceGenerator();

    // ---- T3: the exact poisoned period from the prod incident ----
    $threw = null;
    try {
        $gen->createFromLease([
            'lease_id'          => $lid,
            'period_start'      => '0001-04-01',   // derived from the typo'd anchor
            'period_end'        => '2026-03-13',   // actual return date
            'billing_type'      => 'partial_end',
            'invoice_type'      => 'final',
            'created_by'        => 1,
            'auto_generated'    => 1,
            'generation_source' => 'lease_close',
        ]);
    } catch (\Throwable $t) {
        $threw = $t;
    }

    ok($threw !== null, 'T3.1 poisoned period is refused (did not silently bill 739,708 days)');
    ok(
        !($threw instanceof \PDOException),
        'T3.2 failure is NOT a raw PDOException 22003 (got ' . ($threw ? get_class($threw) : 'none') . ')'
    );
    ok(
        $threw !== null && str_contains($threw->getMessage(), 'INVALID_BILLING_PERIOD'),
        'T3.3 message is tagged INVALID_BILLING_PERIOD'
    );
    ok(
        $threw !== null && str_contains($threw->getMessage(), "lease #{$lid}"),
        'T3.4 message names the lease (diagnosable without a debugger)'
    );

    // Nothing may have been written for the refused period.
    $orphan = db_row(
        "SELECT COUNT(*) c FROM invoices WHERE lease_id = ? AND billing_period_start = '0001-04-01'",
        [$lid]
    );
    ok((int) ($orphan['c'] ?? 0) === 0, 'T3.5 no partial invoice row left behind');

    // ---- T4: the guard is not over-broad ----
    $res = $gen->createFromLease([
        'lease_id'          => $lid,
        'period_start'      => '2026-03-02',
        'period_end'        => '2026-03-13',
        'billing_type'      => 'partial_end',
        'invoice_type'      => 'final',
        'created_by'        => 1,
        'auto_generated'    => 1,
        'generation_source' => 'lease_close',
    ]);
    ok(!empty($res['invoice_id']), 'T4.1 the correct period still generates an invoice');

    $inv = db_row("SELECT billing_period_days FROM invoices WHERE id = ?", [(int) $res['invoice_id']]);
    ok((int) ($inv['billing_period_days'] ?? 0) === 12, 'T4.2 inclusive day count is 12 (2026-03-02 → 03-13)');
});

echo "\n============================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
exit($fail > 0 ? 1 : 0);
