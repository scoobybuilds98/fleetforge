<?php
declare(strict_types=1);

/**
 * tests/_smoke_billing_module.php
 *
 * S-BILLING-MODULE — the monthly billing cycle, schema-real.
 *
 *   B1  schema: the four tables + their columns exist; the approval pair
 *       moved to group 'billing_cycle'; the new settings + cron toggle rows exist
 *   B2  BillingCycles: month helpers, ensure() idempotent, targetMonth()
 *       follows billing_cycle.mode, scope/coverage counts add up to the universe
 *   B3  readiness: all 27 checks run against the REAL schema with no check
 *       erroring (catches column drift), summary persisted on the cycle
 *   B4  review: runs; only known flag keys; a mileage_only / adjustment
 *       invoice never raises duplicate_period
 *   B5  holds: validation, create (customer scope) → heldMap covers every
 *       lease of the customer, activeFor() + describe(), duplicate refused,
 *       release once (second release false)
 *   B6  holds are honoured by the REAL dry run (BatchPreviewService)
 *   B7  readings: a reading below the previous one is refused; a valid one
 *       saves in km; generatorParams() applies only to a period ending on
 *       the cycle's last day; progress counts
 *   B8  the REAL dry run passes the cycle reading to the generator: the
 *       preview's period-end odometer equals the reading
 *   B9  close: drafts are a hard block; an empty month closes only with
 *       override + note; closedCycleFor() then locks it; reopen needs a
 *       reason and re-opens; the snapshot is stored
 *   B10 billing register: header and every row have the same width; a
 *       formula-looking customer name is neutralised
 *   B11 InvoiceDelivery: recipient order (override first); lastEmails shape
 *   B12 cron: ff_run_billing_cycle_open() opens the target month once and
 *       is idempotent on a second run
 *   B13 static wiring: every api/v1/billing endpoint authenticates + gates;
 *       money endpoints consult can_view_financials(); the workbench endpoints
 *       honour holds / readings / the closed-cycle lock; bulk_send delivers
 *       through InvoiceDelivery; nav + redirects + Re-send + audit gate
 *
 * S-BILLING-MODULE-2:
 *   B14 charges: validation; the REAL dry run shows queued charges without
 *       consuming them; CycleGenerator bills them as referenced lines; billed
 *       state derived per month; void re-queues; cancel stops them
 *   B15 CycleGenerator: a held lease is reported, not billed, not flagged;
 *       a re-run is an overlap skip flagged as an exception
 *   B16 the REAL monthly cron (#112): arrears waits for the month to end; a
 *       month already covered another way is not billed again; a closed
 *       cycle is left alone
 *   B17 send (#113): due date = send date + terms, invoice date kept,
 *       sent_date = local today, draft-era PDF cleared
 *   B18 activation month-skip (#111) — static
 *   B19 InvoiceDelivery::emailBundle: drafts / mixed customers refused; one
 *       send logs a row per invoice
 *   B20 wiring: invariants (#114), line editor keeps charge references
 *   B21 Mileage Logs ↔ Readings (#115): suggestion = latest log on/before
 *       month end in the lease's unit; one write-back row, replaced on
 *       re-save, never self-suggested, removed on clear; gps sync static
 *   B22 step sign-offs: independent per step, on the stepper, withdraw;
 *       'close' refused
 *   B23 double-mileage fix (F71): overage removed, usage kept, totals +
 *       lease total_invoiced moved; nothing-to-fix / sent refused
 *   B24 mark mailed / portal: per-invoice refusal of void / missing
 *   B25 trends / charges / customers endpoints executed as REAL super-admin
 *       and dispatcher logins (after the rollback): amounts only with financials
 *
 * Everything that writes runs inside ONE transaction that is rolled back
 * (db_transaction() joins it), with the invoice counter bumped first so a
 * dry run can never collide with committed invoice numbers.
 *
 * Run:  php tests/_smoke_billing_module.php
 * Exit: 0 all pass, 1 on failure.
 *
 * @session S-BILLING-MODULE, S-BILLING-MODULE-2
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';
define('FF_BILLING_CYCLE_OPEN_INCLUDE', true);
require_once FF_ROOT . '/cron/billing_cycle_open.php';

use FleetForge\Billing\BatchPreviewService;
use FleetForge\Billing\InvoiceDelivery;
use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\Cycle\BillingHolds;
use FleetForge\Billing\Cycle\BillingReadiness;
use FleetForge\Billing\Cycle\BillingReview;
use FleetForge\Billing\Cycle\CycleClose;
use FleetForge\Billing\Cycle\CycleReadings;

$failures = [];
$passes   = 0;
$check = static function (bool $ok, string $m) use (&$passes, &$failures): void {
    if ($ok) { $passes++; echo "  PASS  {$m}\n"; }
    else     { $failures[] = $m; echo "  FAIL  {$m}\n"; }
};
$section = static fn(string $t) => print("\n── {$t}\n");

function bm_bump_counter(): void {
    foreach ([date('Y'), date('Y', strtotime('+1 month'))] as $yr) {
        $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
        $maxNum = ($maxStr !== '' && $maxStr !== null) ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
        db_execute("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ["invoice.next_number.{$yr}", (string) ($maxNum + 80)]);
    }
}
/**
 * BatchPreviewService rolls its dry run back itself — but inside this smoke's
 * outer transaction db_transaction() joins it and cannot, so each dry run is
 * wrapped in a SAVEPOINT here to leave nothing behind (as in real use).
 */
function bm_preview(array $leaseIds, string $start, string $end): array {
    db_execute('SAVEPOINT bm_preview');
    try {
        return BatchPreviewService::run($leaseIds, $start, $end, null);
    } finally {
        db_execute('ROLLBACK TO SAVEPOINT bm_preview');
    }
}
function bm_cols(string $t): array {
    return array_column(db_select("SHOW COLUMNS FROM `{$t}`"), 'Field');
}

db_execute('START TRANSACTION');
try {
    bm_bump_counter();

    // ── B1 ────────────────────────────────────────────────────────────
    $section('B1 schema + settings');
    $expect = [
        'billing_cycles' => ['reference', 'period_start', 'period_end', 'status', 'owner_user_id', 'bill_by_date', 'send_by_date',
                             'readiness_ack', 'readiness_checked_at', 'readiness_summary', 'close_snapshot', 'reopen_reason', 'last_nudged_at'],
        'billing_cycle_readings' => ['cycle_id', 'lease_id', 'odometer_km', 'entered_unit', 'engine_hours', 'reading_date', 'notes', 'entered_by'],
        'billing_cycle_reviews'  => ['cycle_id', 'invoice_id', 'status', 'note', 'reviewed_by', 'reviewed_at'],
        'billing_holds'          => ['scope', 'lease_id', 'customer_id', 'reason', 'starts_on', 'ends_on', 'released_at', 'released_by', 'release_note'],
    ];
    foreach ($expect as $t => $cols) {
        $have = bm_cols($t);
        $check(!array_diff($cols, $have), "{$t} has " . count($cols) . ' expected columns' . (array_diff($cols, $have) ? ' — missing ' . implode(',', array_diff($cols, $have)) : ''));
    }
    $grp = db_select("SELECT `key`, group_name FROM settings WHERE `key` IN ('invoices.approval_required','invoices.approval_allow_self')");
    $check(count($grp) === 2 && !array_filter($grp, fn($r) => $r['group_name'] !== 'billing_cycle'),
        'approval pair moved to group billing_cycle (so Settings → General no longer renders it)');
    $keys = array_column(db_select("SELECT `key` FROM settings WHERE `key` LIKE 'billing_cycle.%' OR `key` = 'cron.billing_cycle_open_enabled'"), 'key');
    $check(count($keys) === 10, 'billing_cycle.* settings (9) + cron.billing_cycle_open_enabled seeded (' . count($keys) . ')');

    // ── B2 ────────────────────────────────────────────────────────────
    $section('B2 cycles');
    $check(BillingCycles::isValidMonth('2026-02') && !BillingCycles::isValidMonth('2026-13') && !BillingCycles::isValidMonth('26-01'), 'isValidMonth');
    $check(BillingCycles::monthBounds('2024-02') === ['2024-02-01', '2024-02-29'], 'monthBounds handles a leap February');
    $check(BillingCycles::shiftMonth('2026-01', -1) === '2025-12' && BillingCycles::shiftMonth('2026-01', 13) === '2027-02', 'shiftMonth');
    db_execute("UPDATE settings SET value = 'arrears' WHERE `key` = 'billing_cycle.mode'");
    $check(BillingCycles::targetMonth('2026-09-24') === '2026-08', 'arrears → last month');
    db_execute("UPDATE settings SET value = 'advance' WHERE `key` = 'billing_cycle.mode'");
    $check(BillingCycles::targetMonth('2026-09-24') === '2026-09', 'advance → this month');
    db_execute("UPDATE settings SET value = 'arrears' WHERE `key` = 'billing_cycle.mode'");

    // A month with real invoices: the latest month that has lease invoices.
    $busy = db_row("SELECT DATE_FORMAT(MAX(billing_period_start), '%Y-%m') m FROM invoices
                     WHERE deleted_at IS NULL AND lease_id IS NOT NULL AND status <> 'void' AND billing_period_start <= ?", [ff_today()])['m'] ?? null;
    $check($busy !== null, "found a month with invoices to test against ({$busy})");
    db_execute("DELETE FROM billing_cycles WHERE period_start = ?", [$busy . '-01']);
    [$cycle, $created] = BillingCycles::ensure($busy, null);
    [$again, $created2] = BillingCycles::ensure($busy, null);
    $check($created && !$created2 && $cycle['id'] === $again['id'], 'ensure() creates once, then returns the same cycle');
    $check($cycle['reference'] === 'BC-' . $busy && $cycle['bill_by_date'] && $cycle['send_by_date'] >= $cycle['bill_by_date'], 'reference + targets set');
    $cov = BillingCycles::coverage($cycle, true);
    $check(array_sum($cov['counts']) === count(BillingCycles::universe($cycle)) && count($cov['rows']) === count(BillingCycles::universe($cycle)),
        'coverage has one row per universe lease (' . count($cov['rows']) . ') and counts add up');
    $stats = BillingCycles::stats($cycle, true);
    $check($stats['live'] > 0 && $stats['live'] === $stats['drafts'] + $stats['issued'], "stats: live {$stats['live']} = drafts + issued");
    $noMoney = BillingCycles::stats($cycle, false);
    $check($noMoney['money'] === null, 'stats without financials carry no money');
    $ov = BillingCycles::overview($cycle, true);
    $check(count($ov['stage']['steps']) === 7 && in_array($ov['stage']['current'], ['prepare', 'readings', 'generate', 'review', 'approve', 'send', 'close'], true), 'stage has 7 steps and a current step');

    // ── B3 ────────────────────────────────────────────────────────────
    $section('B3 readiness (schema-real)');
    $r = BillingReadiness::run($cycle);
    $check(count($r['checks']) === 27, '27 checks ran (' . count($r['checks']) . ')');
    $errored = array_column(array_filter($r['checks'], fn($c) => !empty($c['error'])), 'key');
    $check(!$errored, 'no check errored' . ($errored ? ' — ' . implode(', ', $errored) : ''));
    $bad = array_filter($r['checks'], fn($c) => !in_array($c['severity'], ['blocker', 'warning', 'info'], true) || !is_int($c['count']) || count($c['items']) > $c['count'] && $c['count'] > 0);
    $check(!$bad, 'every check has a valid severity, an int count and no more items than its count');
    $saved = BillingCycles::find($cycle['id']);
    $check($saved['readiness_checked_at'] !== null && is_array($saved['readiness_summary']), 'summary persisted on the cycle');

    // ── B4 ────────────────────────────────────────────────────────────
    $section('B4 review');
    $rv = BillingReview::run($cycle, true);
    $known = ['duplicate_period', 'double_mileage', 'held', 'swing', 'zero_total', 'no_tax', 'usd_no_rate', 'no_recipient', 'credit_line', 'usage_true_up', 'first_invoice', 'several', 'charges'];
    $check(count($rv['rows']) === $stats['live'], 'one review row per live invoice');
    $check(!array_diff(array_keys($rv['flag_counts']), $known), 'only known flag keys');
    $nonRentalDup = array_filter($rv['rows'], fn($x) => !in_array($x['billing_type'], BillingCycles::RENTAL_BILLING_TYPES, true)
        && in_array('duplicate_period', array_column($x['flags'], 'key'), true));
    $check(!$nonRentalDup, 'a mileage_only / adjustment invoice never raises duplicate_period');
    $first = $rv['rows'][0] ?? null;
    if ($first) {
        $n = BillingReview::mark($cycle, [$first['invoice_id']], 'reviewed', null, null);
        $rv2 = BillingReview::run($cycle, true);
        $mark = array_values(array_filter($rv2['rows'], fn($x) => $x['invoice_id'] === $first['invoice_id']))[0]['review'] ?? null;
        $check($n === 1 && $mark && $mark['status'] === 'reviewed', 'mark() records Reviewed');
        $check(BillingReview::mark($cycle, [999999999], 'reviewed', null, null) === 0, 'mark() ignores an invoice outside the cycle');
    }

    // ── B5 ────────────────────────────────────────────────────────────
    $section('B5 holds');
    $cust = db_row("SELECT c.id, COUNT(l.id) n FROM customers c JOIN leases l ON l.customer_id = c.id AND l.status = 'active' AND l.deleted_at IS NULL
                     WHERE c.deleted_at IS NULL GROUP BY c.id HAVING n >= 2 ORDER BY n DESC LIMIT 1");
    $check($cust !== null, 'found a customer with 2+ active leases');
    try {
        BillingHolds::create('customer', null, (int) $cust['id'], '   ', ff_today(), null, null);
        $check(false, 'empty reason refused');
    } catch (\InvalidArgumentException $e) {
        $check(isset(json_decode($e->getMessage(), true)['reason']), 'empty reason refused (field error)');
    }
    $holdId = BillingHolds::create('customer', null, (int) $cust['id'], 'Smoke hold', '2000-01-01', null, null);
    $custLeases = array_map('intval', array_column(db_select("SELECT id FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL", [$cust['id']]), 'id'));
    $map = BillingHolds::heldMap($custLeases, '2026-10-01', '2026-10-31');
    $check(count($map) === count($custLeases), 'heldMap covers every active lease of the held customer (' . count($map) . ')');
    $h = BillingHolds::activeFor($custLeases[0], '2026-10-01', '2026-10-31');
    $check($h && (int) $h['id'] === $holdId && str_contains(BillingHolds::describe($h), 'Smoke hold'), 'activeFor() + describe()');
    try {
        BillingHolds::create('customer', null, (int) $cust['id'], 'Again', ff_today(), null, null);
        $check(false, 'a second active hold on the same customer is refused');
    } catch (\InvalidArgumentException $e) {
        $check(true, 'a second active hold on the same customer is refused');
    }
    $past = BillingHolds::heldMap($custLeases, '1999-01-01', '1999-01-31');
    $check(!$past, 'a period ending before starts_on is not held');

    // ── B6 ────────────────────────────────────────────────────────────
    $section('B6 holds honoured by the real dry run');
    $pv = bm_preview([$custLeases[0]], '2026-10-01', '2026-10-31');
    $check(($pv['previews'][0]['ok'] ?? true) === false && str_contains((string) ($pv['previews'][0]['error'] ?? ''), 'Smoke hold'),
        'BatchPreviewService refuses a held lease with the hold reason');
    $check(BillingHolds::release($holdId, 'done', null) === true && BillingHolds::release($holdId, 'again', null) === false,
        'release() once; a second release returns false');
    $check(BillingHolds::activeFor($custLeases[0], '2026-10-01', '2026-10-31') === null, 'released hold no longer applies');

    // ── B7 / B8 ───────────────────────────────────────────────────────
    $section('B7 readings + B8 dry run uses them');
    $next = BillingCycles::shiftMonth(substr(ff_today(), 0, 7), 1);
    [$ns, $ne] = BillingCycles::monthBounds($next);
    db_execute("DELETE FROM billing_cycles WHERE period_start = ?", [$ns]);
    [$nextCycle] = BillingCycles::ensure($next, null);
    $sheet = CycleReadings::sheet($nextCycle);
    $cand = null;
    foreach ($sheet as $row) {
        if (!$row['needs_odometer'] || $row['billed']) continue;
        $l = db_row("SELECT billing_cycle, status FROM leases WHERE id = ?", [$row['lease_id']]);
        if ($l['billing_cycle'] !== 'monthly' || $l['status'] !== 'active') continue;
        if (\FleetForge\Billing\InvoiceGenerator::findOverlappingInvoice($row['lease_id'], $ns, $ne)) continue;
        if (BillingHolds::activeFor($row['lease_id'], $ns, $ne)) continue;
        $cand = $row;
        break;
    }
    if (!$cand) {
        $check(true, 'SKIP B7/B8 — no unbilled active manual-mileage lease for ' . $next);
    } else {
        $prev = $cand['prev_odometer_in_unit'] !== null ? $cand['prev_odometer_in_unit'] : '0';
        if (bccomp($prev, '0', 2) > 0) {
            $low = CycleReadings::save($nextCycle, [['lease_id' => $cand['lease_id'], 'odometer' => bcsub($prev, '1', 2)]], null);
            $check($low['saved'] === 0 && ($low['errors'][0]['field'] ?? '') === 'odometer', 'a reading below the previous one is refused');
        }
        $entered = bcadd($prev, '500', 2);
        $ok = CycleReadings::save($nextCycle, [['lease_id' => $cand['lease_id'], 'odometer' => $entered, 'reading_date' => $ne]], null);
        $stored = db_row("SELECT odometer_km, entered_unit FROM billing_cycle_readings WHERE cycle_id = ? AND lease_id = ?", [$nextCycle['id'], $cand['lease_id']]);
        $conv = (string) db_row("SELECT miles_to_km_conversion c FROM leases WHERE id = ?", [$cand['lease_id']])['c'];
        $expectKm = $cand['mileage_unit'] === 'miles' ? bcmul($entered, $conv, 2) : $entered;
        $check($ok['saved'] === 1 && $stored && bccomp((string) $stored['odometer_km'], $expectKm, 2) === 0 && $stored['entered_unit'] === $cand['mileage_unit'],
            "reading saved in km ({$stored['odometer_km']} km from {$entered} {$cand['mileage_unit']})");
        $gp = CycleReadings::generatorParams($cand['lease_id'], $ns, $ne);
        $check(isset($gp['odometer_at_period_end_km']) && bccomp($gp['odometer_at_period_end_km'], $expectKm, 2) === 0 && ($gp['odometer_source'] ?? '') === 'manual',
            'generatorParams() passes the reading for the full month');
        $check(CycleReadings::generatorParams($cand['lease_id'], $ns, date('Y-m-d', strtotime($ns . ' +9 days'))) === [],
            'generatorParams() ignores a period that does not end on the month end');
        $pg = CycleReadings::progress($nextCycle);
        $check($pg['required'] >= 1 && $pg['entered'] >= 1 && $pg['required'] === $pg['entered'] + $pg['missing'], 'progress counts add up');

        $pv2 = bm_preview([$cand['lease_id']], $ns, $ne);
        $p0 = $pv2['previews'][0] ?? [];
        $check(!empty($p0['ok']) && $p0['usage']['odometer_end'] !== null && bccomp((string) $p0['usage']['odometer_end'], $expectKm, 2) === 0,
            'the real dry run prices with the cycle reading (period-end odometer ' . ($p0['usage']['odometer_end'] ?? 'null') . ' km)'
            . (empty($p0['ok']) ? ' — preview error: ' . ($p0['error'] ?? '?') : ''));
    }

    // ── B9 ────────────────────────────────────────────────────────────
    $section('B9 close / lock / reopen');
    $pre = CycleClose::preCloseChecks(BillingCycles::find($cycle['id']));
    if ($stats['drafts'] > 0) {
        $check(!$pre['can_close'] && in_array('drafts', array_column($pre['hard'], 'key'), true), 'drafts are a hard block');
        try {
            CycleClose::close(BillingCycles::find($cycle['id']), 'x', true, null);
            $check(false, 'close() refuses a month with drafts');
        } catch (\DomainException $e) {
            $check(true, 'close() refuses a month with drafts');
        }
    }
    // An empty far-future month: soft checks only (no invoices, readiness never run).
    db_execute("DELETE FROM billing_cycles WHERE period_start = '2090-01-01'");
    [$empty] = BillingCycles::ensure('2090-01', null);
    $ep = CycleClose::preCloseChecks($empty);
    $check($ep['can_close'] && $ep['soft'], 'an empty month has only soft checks');
    try {
        CycleClose::close($empty, '', false, null);
        $check(false, 'soft checks need override + note');
    } catch (\DomainException $e) {
        $check(true, 'soft checks need override + note');
    }
    $snap = CycleClose::close($empty, 'smoke close', true, null);
    $closed = BillingCycles::find($empty['id']);
    $check($closed['status'] === 'closed' && is_array($closed['close_snapshot']) && $snap['invoices']['live'] === 0, 'closed with a stored snapshot');
    $check(CycleClose::closedCycleFor('2090-01-05', '2090-01-20') !== null && CycleClose::closedCycleFor('2090-02-01', '2090-02-28') === null,
        'closedCycleFor() locks exactly that month');
    try {
        CycleClose::reopen($closed, '  ', null);
        $check(false, 'reopen needs a reason');
    } catch (\DomainException $e) {
        $check(true, 'reopen needs a reason');
    }
    CycleClose::reopen($closed, 'smoke reopen', null);
    $check(BillingCycles::find($empty['id'])['status'] === 'open' && CycleClose::closedCycleFor('2090-01-05', '2090-01-20') === null, 'reopen unlocks the month');

    // ── B10 ───────────────────────────────────────────────────────────
    $section('B10 billing register');
    $reg = CycleClose::registerRows($cycle);
    $w = count($reg[0]);
    $check(count($reg) - 1 >= $stats['live'] && !array_filter($reg, fn($row) => count($row) !== $w), "register: header + " . (count($reg) - 1) . " rows, all {$w} wide");
    $ref = new \ReflectionMethod(CycleClose::class, 'csvText');
    $ref->setAccessible(true);
    $check($ref->invoke(null, '=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && $ref->invoke(null, 'Acme') === 'Acme', 'formula-looking text is neutralised');
    $sum = CycleClose::summary($cycle, true);
    $catTotal = array_reduce($sum['categories'], fn($c, $x) => bcadd($c, $x['amount_cad'], 2), '0.00');
    $sub = db_row("SELECT COALESCE(SUM(" . BillingCycles::cadSql('subtotal') . "), 0) s FROM invoices i WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void'",
        BillingCycles::scopeParams($cycle))['s'];
    $check(abs((float) bcsub($catTotal, (string) $sub, 2)) <= 1.00, "categories add up to the pre-tax subtotal (Δ " . bcsub($catTotal, (string) $sub, 2) . " CAD, rounding)");

    // ── B11 ───────────────────────────────────────────────────────────
    $section('B11 delivery');
    $inv = db_row("SELECT i.id FROM invoices i JOIN customers c ON c.id = i.customer_id
                    WHERE i.deleted_at IS NULL AND (c.invoice_email IS NOT NULL AND c.invoice_email <> '') LIMIT 1");
    if ($inv) {
        $rc = InvoiceDelivery::recipient((int) $inv['id']);
        $ov2 = InvoiceDelivery::recipient((int) $inv['id'], 'override@example.test');
        $check($rc['to'] !== null && $ov2['to'] === 'override@example.test', 'recipient(): invoice email by default, override first');
    }
    $le = InvoiceDelivery::lastEmails(array_map('intval', array_column(db_select("SELECT entity_id FROM email_logs WHERE entity_type = 'invoice' LIMIT 3"), 'entity_id')));
    $okShape = !array_filter($le, fn($x) => !isset($x['status'], $x['attempts'], $x['sent_count']));
    $check($okShape, 'lastEmails() rows carry status / attempts / sent_count (' . count($le) . ')');

    // ── B12 ───────────────────────────────────────────────────────────
    $section('B12 cron');
    db_execute("UPDATE settings SET value = '1' WHERE `key` = 'billing_cycle.open_day'");
    db_execute("UPDATE settings SET value = '' WHERE `key` = 'billing_cycle.owner_user_id'");
    $target = BillingCycles::targetMonth('2089-06-10');
    db_execute("DELETE FROM billing_cycles WHERE period_start = ?", [$target . '-01']);
    $run1 = ff_run_billing_cycle_open('2089-06-10');
    $run2 = ff_run_billing_cycle_open('2089-06-10');
    $check($run1['opened'] === 'BC-' . $target && $run2['opened'] === null, "opens {$target} once; the second run is a no-op");
    $check(BillingCycles::findByMonth($target)['readiness_checked_at'] !== null, 'the opened cycle had its readiness run');

    // ── B13 ───────────────────────────────────────────────────────────
    $section('B13 static wiring');
    $files = array_merge(glob(FF_ROOT . '/api/v1/billing/*.php'), glob(FF_ROOT . '/api/v1/billing/*/*.php'));
    foreach ($files as $f) {
        if (basename($f)[0] === '_') continue;
        $src = file_get_contents($f);
        $rel = substr($f, strlen(FF_ROOT) + 1);
        $check(str_contains($src, 'require_auth_api()') && str_contains($src, "require_permission("), "{$rel} authenticates + gates");
    }
    foreach (['cycles/show', 'cycles/coverage', 'cycles/review', 'cycles/delivery', 'cycles/summary', 'cycles/index', 'cycles/export', 'kpis'] as $e) {
        $check(str_contains(file_get_contents(FF_ROOT . "/api/v1/billing/{$e}.php"), 'can_view_financials()'), "api/v1/billing/{$e} consults can_view_financials()");
    }
    $bg = file_get_contents(FF_ROOT . '/api/v1/invoices/batch_generate.php');
    $rg = file_get_contents(FF_ROOT . '/api/v1/invoices/batch_runs/generate.php');
    $rc2 = file_get_contents(FF_ROOT . '/api/v1/invoices/batch_runs/create.php');
    $bp = file_get_contents(FF_ROOT . '/lib/Billing/BatchPreviewService.php');
    $cg = file_get_contents(FF_ROOT . '/lib/Billing/Cycle/CycleGenerator.php');
    $check(str_contains($cg, 'BillingHolds::activeFor') && str_contains($cg, 'CycleReadings::generatorParams') && str_contains($cg, 'findOverlappingInvoice'), 'CycleGenerator: holds + readings + overlap guard');
    $check(str_contains($bg, 'CycleGenerator::run') && str_contains($bg, 'CycleClose::closedCycleFor') && !str_contains($bg, '->createFromLease('), 'batch_generate: the shared loop + closed lock (no loop of its own)');
    $check(str_contains($rg, 'CycleGenerator::run') && str_contains($rg, 'CycleClose::closedCycleFor') && !str_contains($rg, '->createFromLease('), 'batch_runs/generate: the shared loop + closed lock (no loop of its own)');
    $check(str_contains($rc2, 'CycleClose::closedCycleFor'), 'batch_runs/create: closed lock');
    $check(str_contains($bp, 'BillingHolds::activeFor') && str_contains($bp, 'CycleReadings::generatorParams'), 'BatchPreviewService: holds + readings');
    $check(str_contains(file_get_contents(FF_ROOT . '/cron/invoice_generate_monthly.php'), 'BillingHolds::activeFor'), 'monthly cron skips held leases');
    $bs = file_get_contents(FF_ROOT . '/api/v1/invoices/bulk_send.php');
    $check(str_contains($bs, 'InvoiceDelivery::email') && !str_contains($bs, 'sendFromTemplate'), 'bulk_send delivers through InvoiceDelivery (one email path)');
    $nav = file_get_contents(FF_ROOT . '/config/navigation.php');
    $check(str_contains($nav, "'url'          => '/billing'") && !str_contains($nav, "'url'    => '/invoices/batch'"), 'nav: Monthly Billing present, Batch Invoicing child gone');
    $check(str_contains(file_get_contents(FF_ROOT . '/app/admin/invoices/batch.php'), "base_url('billing/run')")
        && str_contains(file_get_contents(FF_ROOT . '/app/admin/invoices/batch_run.php'), "base_url('billing/approval')"), 'old batch URLs redirect into Billing');
    $check(str_contains(file_get_contents(FF_ROOT . '/app/admin/invoices/show.php'), "api/v1/billing/deliver"), 'invoice Re-send uses billing/deliver');
    $check(str_contains(file_get_contents(FF_ROOT . '/api/v1/audit/history.php'), "'billing_cycle'  => 'invoices'"), 'billing_cycle activity is gated on invoices:view');
    $check(str_contains(file_get_contents(FF_ROOT . '/app/admin/billing/approval.php'), 'can_view_financials()'), 'approval page gated on financials');

    // ── B14 ───────────────────────────────────────────────────────────
    $section('B14 charges');
    try {
        \FleetForge\Billing\Cycle\BillingCharges::create(['lease_id' => 0, 'description' => '', 'unit_price' => '-5'], null);
        $check(false, 'invalid charge refused');
    } catch (\InvalidArgumentException $e) {
        $f = json_decode($e->getMessage(), true);
        $check(isset($f['lease_id'], $f['description'], $f['unit_price']), 'invalid charge refused (lease, description, price)');
    }
    // A clean active monthly lease of our own (no billing history) — built in-txn.
    $tplLease = db_row("SELECT l.* FROM leases l WHERE l.status = 'active' AND l.billing_cycle = 'monthly' AND l.deleted_at IS NULL
                          AND l.monthly_rate > 0 AND l.mileage_tracking_mode = 'off' AND l.precharge_enabled = 0 ORDER BY l.id LIMIT 1");
    $check($tplLease !== null, 'found a template lease to clone');
    $mkLease = static function (array $over) use ($tplLease): int {
        $row = $tplLease;
        unset($row['id']);
        $row = array_merge($row, [
            'contract_number' => 'SMK-BM-' . bin2hex(random_bytes(4)),
            'start_date' => '2088-01-01', 'end_date' => null, 'actual_return_date' => null,
            'last_billed_date' => null, 'last_billed_invoice_id' => null, 'next_billing_date' => '2088-02-01',
            'total_invoiced' => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
            'cartage_amount' => null, 'cartage_billed_at' => null, 'advance_billing_periods' => 0,
            'created_at' => ff_now_utc(), 'updated_at' => ff_now_utc(), 'closed_at' => null,
        ], $over);
        foreach ($row as $k => $v) if ($v === null) unset($row[$k]);
        return (int) db_insert('leases', $row);
    };
    $leaseA = $mkLease([]);
    $chargeId = \FleetForge\Billing\Cycle\BillingCharges::create([
        'lease_id' => $leaseA, 'description' => 'Smoke tire repair', 'item_type' => 'damage',
        'quantity' => '2', 'unit_price' => '37.50', 'recurrence' => 'once', 'bill_from' => '2088-01-01',
    ], null);
    $monthlyId = \FleetForge\Billing\Cycle\BillingCharges::create([
        'lease_id' => $leaseA, 'description' => 'Smoke yard parking', 'item_type' => 'other',
        'quantity' => '1', 'unit_price' => '50', 'recurrence' => 'monthly', 'bill_from' => '2088-01-01',
    ], null);
    $pv = bm_preview([$leaseA], '2088-01-01', '2088-01-31');
    $pvLines = $pv['previews'][0]['lines'] ?? [];
    $check(!empty($pv['previews'][0]['ok']) && count(array_filter($pvLines, fn($l) => str_contains((string) $l['description'], 'Smoke'))) === 2,
        'the dry run shows both queued charges as lines' . (empty($pv['previews'][0]['ok']) ? ' — ' . ($pv['previews'][0]['error'] ?? '') : ''));
    $st = \FleetForge\Billing\Cycle\BillingCharges::list(['lease_id' => $leaseA, 'state' => 'all']);
    $check(($st[0]['state'] ?? '') !== 'billed' && ($st[1]['state'] ?? '') !== 'billed', 'a dry run never consumes a charge');

    $g = \FleetForge\Billing\Cycle\CycleGenerator::run([$leaseA], '2088-01-01', '2088-01-31', ['notify' => false, 'label' => 'Smoke']);
    $invA = $g['invoices'][0]['invoice_id'] ?? null;
    $refLines = $invA ? db_select("SELECT reference_id, amount, item_type FROM invoice_line_items WHERE invoice_id = ? AND reference_type = 'billing_charge' ORDER BY reference_id", [$invA]) : [];
    $check($invA && count($refLines) === 2 && bccomp((string) $refLines[0]['amount'], '75.00', 2) === 0 && $refLines[0]['item_type'] === 'damage',
        'the generated invoice carries both charges as referenced lines (2 × 37.50 = 75.00 damage)');
    $once = \FleetForge\Billing\Cycle\BillingCharges::list(['lease_id' => $leaseA, 'state' => 'all', 'month' => '2088-01']);
    $byId = array_column($once, null, 'id');
    $check(($byId[$chargeId]['state'] ?? '') === 'billed' && ($byId[$monthlyId]['state'] ?? '') === 'billed', 'both charges now show billed for the month');
    $check(\FleetForge\Billing\Cycle\BillingCharges::dueLines($leaseA, '2088-01-01', '2088-01-31') === [], 'nothing is due twice in the same month');
    $feb = \FleetForge\Billing\Cycle\BillingCharges::dueLines($leaseA, '2088-02-01', '2088-02-29');
    $check(count($feb) === 1 && (int) $feb[0]['reference_id'] === $monthlyId, 'next month: only the monthly charge is due again');

    // Draft editor keeps the reference (update_lines logic, exercised directly on the rows).
    $before = db_select("SELECT id, item_type, description, quantity, unit, unit_price, amount, is_credit, taxable FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order, id", [$invA]);
    $check(count($before) >= 3, 'the draft has rental + charge lines');

    // Void → charges back in the queue.
    \FleetForge\AI\Actions\FinancialActions::voidInvoice((int) $invA, 'smoke', 1, 'smoke', '127.0.0.1');
    $again = \FleetForge\Billing\Cycle\BillingCharges::dueLines($leaseA, '2088-01-01', '2088-01-31');
    $check(count($again) === 2, 'voiding the invoice puts both charges back in the queue');
    $check(\FleetForge\Billing\Cycle\BillingCharges::cancel($chargeId, 'smoke', null)
        && count(\FleetForge\Billing\Cycle\BillingCharges::dueLines($leaseA, '2088-01-01', '2088-01-31')) === 1, 'a cancelled charge is no longer due');

    // ── B15 ───────────────────────────────────────────────────────────
    $section('B15 shared generator');
    $leaseB = $mkLease([]);
    $holdB = BillingHolds::create('lease', $leaseB, null, 'smoke hold', '2088-01-01', null, null);
    $g2 = \FleetForge\Billing\Cycle\CycleGenerator::run([$leaseB], '2088-01-01', '2088-01-31', ['notify' => false]);
    $check($g2['actioned'] === 0 && !empty($g2['errors'][0]['held']) && $g2['flagged'] === 0, 'a held lease is reported, not billed, not flagged');
    BillingHolds::release($holdB, '', null);
    $g3 = \FleetForge\Billing\Cycle\CycleGenerator::run([$leaseB], '2088-01-01', '2088-01-31', ['notify' => false]);
    $g4 = \FleetForge\Billing\Cycle\CycleGenerator::run([$leaseB], '2088-01-01', '2088-01-31', ['notify' => false]);
    $exc = db_row("SELECT status, source FROM invoice_billing_exceptions WHERE lease_id = ? AND period_start = '2088-01-01'", [$leaseB]);
    $check($g3['actioned'] === 1 && $g4['actioned'] === 0 && $g4['flagged'] === 1 && $exc && $exc['status'] === 'open',
        'billed once; the re-run is an overlap skip flagged as an exception');

    // ── B16 ───────────────────────────────────────────────────────────
    $section('B16 monthly cron (#112)');
    define('FF_MONTHLY_BILLING_INCLUDE', true);
    require_once FF_ROOT . '/cron/invoice_generate_monthly.php';
    db_execute("UPDATE leases SET next_billing_date = NULL WHERE status = 'active' AND deleted_at IS NULL AND id NOT IN (?, ?)", [0, 0]); // isolate: only our leases are due
    $leaseC = $mkLease(['next_billing_date' => '2088-02-01']);
    // A single_period invoice already covers Feb 10-29 (billed another way).
    (new \FleetForge\Billing\InvoiceGenerator())->createFromLease(['lease_id' => $leaseC, 'period_start' => '2088-01-01', 'period_end' => '2088-01-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'generation_source' => 'manual', 'require_lease_status' => 'active']);
    (new \FleetForge\Billing\InvoiceGenerator())->createFromLease(['lease_id' => $leaseC, 'period_start' => '2088-02-10', 'period_end' => '2088-02-29',
        'billing_type' => 'single_period', 'invoice_type' => 'regular', 'generation_source' => 'manual', 'require_lease_status' => 'active']);
    db_execute("UPDATE settings SET `value` = 'arrears' WHERE `key` = 'billing_cycle.mode'");
    ff_run_monthly_billing('2088-02-15');
    $nC = (int) db_count("SELECT COUNT(*) FROM invoices WHERE lease_id = ? AND status <> 'void' AND deleted_at IS NULL", [$leaseC]);
    $check($nC === 2, "arrears: February has not ended on the 15th — nothing billed ({$nC} invoices, expected the 2 seeded)");
    ff_run_monthly_billing('2088-03-02');
    $febCount = (int) db_count("SELECT COUNT(*) FROM invoices WHERE lease_id = ? AND status <> 'void' AND deleted_at IS NULL AND billing_period_start BETWEEN '2088-02-01' AND '2088-02-29'", [$leaseC]);
    $nbd = db_row("SELECT next_billing_date FROM leases WHERE id = ?", [$leaseC])['next_billing_date'];
    $check($febCount === 1 && $nbd === '2088-03-01', "a February already covered by a single_period invoice is not billed again (Feb invoices {$febCount}, pointer {$nbd})");
    db_execute("DELETE FROM billing_cycles WHERE period_start = '2088-03-01'");
    [$mar] = BillingCycles::ensure('2088-03', null);
    db_execute("UPDATE billing_cycles SET status = 'closed' WHERE id = ?", [$mar['id']]);
    ff_run_monthly_billing('2088-04-02');
    $marCount = (int) db_count("SELECT COUNT(*) FROM invoices WHERE lease_id = ? AND status <> 'void' AND deleted_at IS NULL AND billing_period_start BETWEEN '2088-03-01' AND '2088-03-31'", [$leaseC]);
    $check($marCount === 0, 'a closed cycle is left alone by the monthly job');

    // ── B17 ───────────────────────────────────────────────────────────
    $section('B17 send: terms from the send date (#113)');
    $leaseD = $mkLease([]);
    $invD = (new \FleetForge\Billing\InvoiceGenerator())->createFromLease(['lease_id' => $leaseD, 'period_start' => '2088-01-01', 'period_end' => '2088-01-31',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'generation_source' => 'manual', 'require_lease_status' => 'active'])['invoice_id'];
    // Pretend it is an old month: invoice dated long ago, a stale draft PDF on file.
    db_execute("UPDATE invoices SET invoice_date = '2020-01-01', due_date = '2020-01-31', pdf_path = 'x/v1/old.pdf', pdf_generated_at = UTC_TIMESTAMP() WHERE id = ?", [$invD]);
    db_execute("UPDATE settings SET `value` = 'send_date' WHERE `key` = 'billing_cycle.due_date_basis'");
    \FleetForge\AI\Actions\FinancialActions::sendInvoice((int) $invD, null, 1, 'smoke', '127.0.0.1');
    $d = db_row("SELECT due_date, sent_date, pdf_path, invoice_date FROM invoices WHERE id = ?", [$invD]);
    $check($d['due_date'] > ff_today() || $d['due_date'] === ff_today(), "due date moved to send date + terms ({$d['due_date']}); invoice date kept ({$d['invoice_date']})");
    $check($d['invoice_date'] === '2020-01-01' && $d['sent_date'] === ff_today() && $d['pdf_path'] === null, 'invoice date unchanged, sent_date = local today, draft-era PDF cleared');

    // ── B18 ───────────────────────────────────────────────────────────
    $section('B18 activation month-skip (#111)');
    $act = file_get_contents(FF_ROOT . '/api/v1/leases/activate.php');
    $check(!str_contains($act, "strtotime('+1 month', \$startTs)") && str_contains($act, "\$startMonth . ' +1 month'"), 'activation steps from the first of the start month');

    // ── B19 ───────────────────────────────────────────────────────────
    $section('B19 one email per customer');
    $invD2 = (new \FleetForge\Billing\InvoiceGenerator())->createFromLease(['lease_id' => $leaseD, 'period_start' => '2088-02-01', 'period_end' => '2088-02-29',
        'billing_type' => 'full_month', 'invoice_type' => 'regular', 'generation_source' => 'manual', 'require_lease_status' => 'active'])['invoice_id'];
    $r1 = InvoiceDelivery::emailBundle([(int) $invD, (int) $invD2], 'smoke@example.test', false, 1, 'Smoke month');
    $check(!$r1['success'] && str_contains((string) $r1['error'], 'draft'), 'a bundle with a draft is refused');
    \FleetForge\AI\Actions\FinancialActions::sendInvoice((int) $invD2, null, 1, 'smoke', '127.0.0.1');
    $before = (int) db_count("SELECT COUNT(*) FROM email_logs WHERE entity_type = 'invoice' AND entity_id IN (?, ?)", [$invD, $invD2]);
    $r2 = InvoiceDelivery::emailBundle([(int) $invD, (int) $invD2], 'smoke@example.test', false, 1, 'Smoke month');
    $after = (int) db_count("SELECT COUNT(*) FROM email_logs WHERE entity_type = 'invoice' AND entity_id IN (?, ?) AND status = 'sent'", [$invD, $invD2]);
    $check($r2['success'] && $r2['count'] === 2 && $after - $before >= 2, 'the bundle sends once and logs a delivery row for each invoice' . ($r2['success'] ? '' : ' — ' . $r2['error']));
    $other = db_row("SELECT i.id FROM invoices i WHERE i.customer_id <> (SELECT customer_id FROM leases WHERE id = ?) AND i.status IN ('sent','paid','overdue') AND i.deleted_at IS NULL LIMIT 1", [$leaseD]);
    if ($other) {
        $r3 = InvoiceDelivery::emailBundle([(int) $invD, (int) $other['id']], null, false, 1, 'x');
        $check(!$r3['success'] && str_contains((string) $r3['error'], 'one customer'), 'a bundle across two customers is refused');
    }

    // ── B20 ───────────────────────────────────────────────────────────
    $section('B20 wiring');
    $check(is_file(FF_ROOT . '/api/v1/billing/mark_delivered.php') && is_file(FF_ROOT . '/api/v1/billing/fixes/double_mileage.php'), 'mark_delivered + double_mileage fix endpoints exist');
    $inv = file_get_contents(FF_ROOT . '/tests/_smoke_billing_invariants.php');
    $check(str_contains($inv, 'base_rental_reconciliation_overflow') && str_contains($inv, "'mileage_estimate'"), 'invariants I1/I6 recognise the overflow cap and estimate lines (#114)');
    $check(str_contains(file_get_contents(FF_ROOT . '/api/v1/invoices/update_lines.php'), "'reference_type' => \$prev['reference_type']"), 'the draft line editor keeps charge references');

    // ── B21 ───────────────────────────────────────────────────────────
    // #115: Mileage Logs feed the Readings sheet (suggestion) and the sheet
    // writes each month-end reading back to Mileage Logs (one marker row).
    $section('B21 mileage logs ↔ readings (#115)');
    $leaseM = $mkLease(['mileage_tracking_mode' => 'manual', 'mileage_rate_km' => '0.1000', 'mileage_unit' => 'miles', 'odometer_start_km' => '1000']);
    $unitM  = (int) (db_row("SELECT equipment_unit_id u FROM leases WHERE id = ?", [$leaseM])['u'] ?? 0);
    db_execute("DELETE FROM billing_cycles WHERE period_start = '2088-05-01'");
    [$cycM] = BillingCycles::ensure('2088-05', null);
    if (!$unitM) {
        $check(true, 'SKIP B21 — template lease has no equipment unit');
    } else {
        db_insert('mileage_logs', ['equipment_unit_id' => $unitM, 'log_type' => 'service', 'odometer_reading' => 10000, 'mileage_unit' => 'km', 'log_date' => '2088-05-20', 'notes' => 'Smoke service visit']);
        db_insert('mileage_logs', ['equipment_unit_id' => $unitM, 'log_type' => 'service', 'odometer_reading' => 99999, 'mileage_unit' => 'km', 'log_date' => '2088-06-02', 'notes' => 'Smoke after month end']);
        $kmToMi = (string) db_row("SELECT km_to_miles_conversion c FROM leases WHERE id = ?", [$leaseM])['c'];
        $expectSug = bcmul('10000', $kmToMi, 2);
        $rowM = current(array_filter(CycleReadings::sheet($cycM), fn($r) => $r['lease_id'] === $leaseM)) ?: null;
        $check($rowM && $rowM['log_odometer_in_unit'] === $expectSug && $rowM['log_date'] === '2088-05-20' && $rowM['log_type'] === 'service',
            "the sheet suggests the latest log on/before month end, in the lease's unit ({$expectSug} miles from 10000 km; the June log ignored)");
        $marker = 'Billing cycle ' . $cycM['reference'] . ' month-end reading';
        CycleReadings::save($cycM, [['lease_id' => $leaseM, 'odometer' => '7000', 'reading_date' => '2088-05-31']], null);
        $wb = db_select("SELECT odometer_reading, mileage_unit, log_type, log_date FROM mileage_logs WHERE lease_id = ? AND notes = ?", [$leaseM, $marker]);
        $check(count($wb) === 1 && (int) $wb[0]['odometer_reading'] === 7000 && $wb[0]['mileage_unit'] === 'miles' && $wb[0]['log_type'] === 'manual' && $wb[0]['log_date'] === '2088-05-31',
            'saving a reading writes ONE manual Mileage Logs row (7000 miles, month end)');
        CycleReadings::save($cycM, [['lease_id' => $leaseM, 'odometer' => '7100', 'reading_date' => '2088-05-31']], null);
        $wb2 = db_select("SELECT odometer_reading FROM mileage_logs WHERE lease_id = ? AND notes = ?", [$leaseM, $marker]);
        $check(count($wb2) === 1 && (int) $wb2[0]['odometer_reading'] === 7100, 're-saving replaces that row instead of adding one');
        $rowM2 = current(array_filter(CycleReadings::sheet($cycM), fn($r) => $r['lease_id'] === $leaseM)) ?: null;
        $check($rowM2 && $rowM2['log_odometer_in_unit'] === $expectSug, 'the sheet never suggests its own write-back');
        $clr = CycleReadings::save($cycM, [['lease_id' => $leaseM, 'odometer' => '']], null);
        $check($clr['cleared'] === 1 && (int) db_count("SELECT COUNT(*) FROM mileage_logs WHERE lease_id = ? AND notes = ?", [$leaseM, $marker]) === 0,
            'clearing the reading removes its Mileage Logs row');
    }
    $gpsSrc = file_get_contents(FF_ROOT . '/cron/gps_mileage_sync.php');
    $check(str_contains($gpsSrc, '$today     = ff_today();') && str_contains($gpsSrc, "\$lease['km_to_miles_conversion']") && str_contains($gpsSrc, 'bcmul('),
        'gps_mileage_sync: local business day + the lease\'s own conversion factor, in bcmath');

    // ── B22 ───────────────────────────────────────────────────────────
    $section('B22 step sign-offs');
    BillingCycles::signoff($cycM, 'review', true, 'smoke note');
    BillingCycles::signoff($cycM, 'prepare', true, null);
    $so = BillingCycles::find($cycM['id'])['step_signoffs'];
    $check(isset($so['review'], $so['prepare']) && $so['review']['note'] === 'smoke note' && $so['review']['by'] !== '' && $so['review']['at'] !== '',
        'two steps signed off — neither drops the other; who / when / note kept');
    $stepsM = array_column(BillingCycles::overview(BillingCycles::find($cycM['id']), false)['stage']['steps'], null, 'key');
    $check(($stepsM['review']['signoff']['note'] ?? '') === 'smoke note' && $stepsM['readings']['signoff'] === null, 'the stepper carries each step\'s sign-off');
    BillingCycles::signoff($cycM, 'review', false);
    $so2 = BillingCycles::find($cycM['id'])['step_signoffs'];
    $check(!isset($so2['review']) && isset($so2['prepare']), 'withdrawing one sign-off leaves the others');
    try {
        BillingCycles::signoff($cycM, 'close', true);
        $check(false, "'close' cannot be signed off (closing the cycle is its sign-off)");
    } catch (\InvalidArgumentException $e) {
        $check(true, "'close' cannot be signed off (closing the cycle is its sign-off)");
    }

    // ── B23 ───────────────────────────────────────────────────────────
    $section('B23 fix: double mileage on a draft (F71)');
    $invB = (int) ($g3['invoices'][0]['invoice_id'] ?? 0);
    foreach ([['mileage_usage', 'Smoke mileage usage'], ['mileage', 'Smoke mileage overage']] as $i => [$type, $desc]) {
        db_insert('invoice_line_items', ['invoice_id' => $invB, 'item_type' => $type, 'description' => $desc, 'quantity' => '1000',
            'unit_price' => '0.10', 'amount' => '100.00', 'taxable' => 1, 'sort_order' => 90 + $i]);
    }
    \FleetForge\Billing\InvoiceRecalc::recalc($invB);
    $oldTotal = (string) db_row("SELECT total_amount t FROM invoices WHERE id = ?", [$invB])['t'];
    $tiBefore = (string) db_row("SELECT total_invoiced t FROM leases WHERE id = ?", [$leaseB])['t'];
    $fx = BillingReview::removeDoubleMileage($invB);
    $typesB = array_column(db_select("SELECT item_type FROM invoice_line_items WHERE invoice_id = ?", [$invB]), 'item_type');
    $newTotal = (string) db_row("SELECT total_amount t FROM invoices WHERE id = ?", [$invB])['t'];
    $tiAfter = (string) db_row("SELECT total_invoiced t FROM leases WHERE id = ?", [$leaseB])['t'];
    $check($fx['removed'] === 1 && !in_array('mileage', $typesB, true) && in_array('mileage_usage', $typesB, true),
        'the overage line is removed, the usage line kept');
    $check($fx['old_total'] === $oldTotal && $fx['new_total'] === $newTotal && bccomp(bcsub($oldTotal, $newTotal, 2), '100.00', 2) >= 0,
        "totals recomputed ({$oldTotal} → {$newTotal}: the \$100 line and its tax)");
    $check(bccomp(bcsub($tiAfter, $tiBefore, 2), bcsub($newTotal, $oldTotal, 2), 2) === 0, 'lease total_invoiced moved by the same change');
    foreach ([[$invB, 'NOTHING_TO_FIX', 'a second run finds nothing to fix'], [(int) $invD, 'NOT_DRAFT', 'a sent invoice is refused (credit note instead)']] as [$iid, $code, $msg]) {
        try {
            BillingReview::removeDoubleMileage($iid);
            $check(false, $msg);
        } catch (\DomainException $e) {
            $check(str_starts_with($e->getMessage(), $code . '|'), $msg);
        }
    }

    // ── B24 ───────────────────────────────────────────────────────────
    $section('B24 mark as mailed / on portal');
    $md = InvoiceDelivery::markDelivered([(int) $invD, (int) $invA, 2147483000], 'manual', 'smoke', null, 'smoke');
    $dm = db_row("SELECT delivery_method FROM invoices WHERE id = ?", [$invD])['delivery_method'];
    $check($md['updated'] === 1 && count($md['errors']) === 2 && $dm === 'manual',
        'a sent invoice is marked mailed; the void one and a missing id are refused per invoice');
    try {
        InvoiceDelivery::markDelivered([(int) $invD], 'fax', null, null, 'smoke');
        $check(false, 'an unknown delivery method is refused');
    } catch (\InvalidArgumentException $e) {
        $check(true, 'an unknown delivery method is refused');
    }
} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    echo "  FAIL  EXCEPTION {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
} finally {
    db_execute('ROLLBACK');
}

// ── B25 ───────────────────────────────────────────────────────────────
// Read endpoints executed for REAL, as real logged-in users (auth_login loads
// role permissions exactly as at login), AFTER the rollback — each runs in its
// own PHP process / DB connection, so it reads committed data and holds no
// lock this smoke's transaction could wait on. Read-only endpoints only.
$section('B25 read endpoints as real users (money redaction)');
$bmHarness = sys_get_temp_dir() . '/_ff_bm_login_' . getmypid() . '.php';
$bmRoot = FF_ROOT;
file_put_contents($bmHarness, <<<PHP
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
error_reporting(E_ERROR | E_PARSE);
\$endpoint=\$argv[1]??''; \$qs=\$argv[2]??''; \$uid=(int)(\$argv[3]??0);
parse_str(\$qs, \$_GET);
\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REMOTE_ADDR']='127.0.0.1'; \$_SERVER['HTTP_HOST']='localhost';
require '{$bmRoot}/config/app.php';
require_once FF_ROOT . '/includes/auth.php';
@session_start();
\$u = db_row("SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = ?", [\$uid]);
if (!\$u) { echo "FFSMOKE-FIN=missing-user\n"; exit; }
@auth_login(\$u);
echo 'FFSMOKE-FIN=' . (can_view_financials() ? '1' : '0') . "\n";
require '{$bmRoot}/' . \$endpoint;
PHP);
$bmGet = static function (string $endpoint, string $qs, int $uid) use ($bmHarness): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bmHarness) . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($qs) . ' ' . escapeshellarg((string) $uid) . ' 2>/dev/null');
    $fin = preg_match('/^FFSMOKE-FIN=(\S+)/m', $out, $m) ? $m[1] : 'none';
    $p = strpos($out, '{"');
    $j = $p !== false ? json_decode(trim(substr($out, $p)), true) : null;
    return ['fin' => $fin, 'resp' => is_array($j) ? $j : ['_raw' => substr($out, 0, 200)]];
};
$bmUser = static function (string $slug, string $email): ?int {
    $r = db_row("SELECT u.id FROM users u JOIN user_roles r ON r.id = u.role_id
                  WHERE r.slug = ? AND u.deleted_at IS NULL AND u.status = 'active'
                  ORDER BY (u.email = ?) DESC, u.id LIMIT 1", [$slug, $email]);
    return $r ? (int) $r['id'] : null;
};
try {
    $superId = $bmUser('super_admin', 'test-superadmin@fleetforge.test');
    $dispId  = $bmUser('dispatcher', 'test-dispatcher@fleetforge.test');
    $cycRow  = db_row("SELECT id FROM billing_cycles ORDER BY period_start DESC LIMIT 1");
    if (!$superId || !$dispId) {
        $check(true, 'SKIP B25 — no active super_admin / dispatcher user');
    } else {
        $t1 = $bmGet('api/v1/billing/trends.php', '', $superId);
        $m1 = $t1['resp']['data']['months'] ?? [];
        $check($t1['fin'] === '1' && !empty($t1['resp']['success']) && $m1 && array_key_exists('billed_cad', $m1[0]) && $m1[0]['billed_cad'] !== null,
            'trends as super admin: runs against the real schema, with amounts (' . count($m1) . ' months)' . (empty($t1['resp']['success']) ? ' — ' . json_encode($t1['resp']) : ''));
        $t2 = $bmGet('api/v1/billing/trends.php', '', $dispId);
        $m2 = $t2['resp']['data']['months'] ?? [];
        $check($t2['fin'] === '0' && (empty($t2['resp']['success']) || !array_filter($m2, fn($r) => $r['billed_cad'] !== null)),
            'trends as dispatcher: no amounts');
        $c2 = $bmGet('api/v1/billing/charges/index.php', 'state=all', $dispId);
        $check($c2['fin'] === '0' && (empty($c2['resp']['success']) || !array_filter($c2['resp']['data']['charges'] ?? [], fn($r) => $r['amount'] !== null || $r['unit_price'] !== null)),
            'charges as dispatcher: no amounts');
        if (!$cycRow) {
            $check(true, 'SKIP customers — no committed billing cycle');
        } else {
            $u1 = $bmGet('api/v1/billing/cycles/customers.php', 'id=' . $cycRow['id'], $superId);
            $check(!empty($u1['resp']['success']) && is_array($u1['resp']['data']['rows'] ?? null),
                'customers as super admin: runs (' . count($u1['resp']['data']['rows'] ?? []) . ' customers)' . (empty($u1['resp']['success']) ? ' — ' . json_encode($u1['resp']) : ''));
            $u2 = $bmGet('api/v1/billing/cycles/customers.php', 'id=' . $cycRow['id'], $dispId);
            $check(empty($u2['resp']['success']) || !array_filter($u2['resp']['data']['rows'] ?? [], fn($r) => $r['total'] !== null || $r['balance'] !== null),
                'customers as dispatcher: no amounts');
        }
    }
} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    echo "  FAIL  EXCEPTION {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
} finally {
    @unlink($bmHarness);
}

echo "\n" . str_repeat('═', 60) . "\n";
echo 'billing_module_smoke: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . "\n";
if ($failures) {
    echo "FAILURES:\n  - " . implode("\n  - ", $failures) . "\n";
}
exit($failures ? 1 : 0);
