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
 *   B3  readiness: all 26 checks run against the REAL schema with no check
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
 * Everything that writes runs inside ONE transaction that is rolled back
 * (db_transaction() joins it), with the invoice counter bumped first so a
 * dry run can never collide with committed invoice numbers.
 *
 * Run:  php tests/_smoke_billing_module.php
 * Exit: 0 all pass, 1 on failure.
 *
 * @session S-BILLING-MODULE
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
    $check(count($keys) === 9, 'billing_cycle.* settings (8) + cron.billing_cycle_open_enabled seeded (' . count($keys) . ')');

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
    $check(count($r['checks']) === 26, '26 checks ran (' . count($r['checks']) . ')');
    $errored = array_column(array_filter($r['checks'], fn($c) => !empty($c['error'])), 'key');
    $check(!$errored, 'no check errored' . ($errored ? ' — ' . implode(', ', $errored) : ''));
    $bad = array_filter($r['checks'], fn($c) => !in_array($c['severity'], ['blocker', 'warning', 'info'], true) || !is_int($c['count']) || count($c['items']) > $c['count'] && $c['count'] > 0);
    $check(!$bad, 'every check has a valid severity, an int count and no more items than its count');
    $saved = BillingCycles::find($cycle['id']);
    $check($saved['readiness_checked_at'] !== null && is_array($saved['readiness_summary']), 'summary persisted on the cycle');

    // ── B4 ────────────────────────────────────────────────────────────
    $section('B4 review');
    $rv = BillingReview::run($cycle, true);
    $known = ['duplicate_period', 'double_mileage', 'held', 'swing', 'zero_total', 'no_tax', 'usd_no_rate', 'no_recipient', 'credit_line', 'usage_true_up', 'first_invoice', 'several'];
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
    $pv = BatchPreviewService::run([$custLeases[0]], '2026-10-01', '2026-10-31', null);
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

        $pv2 = BatchPreviewService::run([$cand['lease_id']], $ns, $ne, null);
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
    $check(str_contains($bg, 'BillingHolds::activeFor') && str_contains($bg, 'CycleReadings::generatorParams') && str_contains($bg, 'CycleClose::closedCycleFor'), 'batch_generate: holds + readings + closed lock');
    $check(str_contains($rg, 'BillingHolds::activeFor') && str_contains($rg, 'CycleReadings::generatorParams') && str_contains($rg, 'CycleClose::closedCycleFor'), 'batch_runs/generate: holds + readings + closed lock');
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
} catch (\Throwable $e) {
    $failures[] = 'EXCEPTION ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    echo "  FAIL  EXCEPTION {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
} finally {
    db_execute('ROLLBACK');
}

echo "\n" . str_repeat('═', 60) . "\n";
echo 'billing_module_smoke: ' . $passes . '/' . ($passes + count($failures)) . ' PASS' . "\n";
if ($failures) {
    echo "FAILURES:\n  - " . implode("\n  - ", $failures) . "\n";
}
exit($failures ? 1 : 0);
