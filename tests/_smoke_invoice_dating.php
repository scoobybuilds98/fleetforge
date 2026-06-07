<?php
declare(strict_types=1);

/**
 * tests/_smoke_invoice_dating.php
 *
 * S-INVOICE-DATING-FIX — end-to-end smoke. Exercises the REAL generation path
 * (generateForLease → createFromLease → DB) and the REAL GL posting path
 * (AutoEntryBridge::onInvoiceSent → acc_journal_entries) in BEGIN/ROLLBACK.
 *
 *   T1  Fan out two periods → distinct, period-derived issue/due dates;
 *       base rentals unchanged ($93.33 / $700.00).
 *   T2  created_at = real generation time, distinct from invoice_date.
 *   T3  GL posting lands in each invoice's PERIOD, not the send/generation
 *       month (D-GL-REVREC-1): (a) a past-period invoice posts on its
 *       issue_date even when sent later (sent_date IGNORED); (b) a future-period
 *       invoice is guarded to today (never posts into a future period).
 *   T4  AR aging input: each invoice's due_date is its own period-derived value.
 *   T5  base-rental math untouched (covered across T1).
 *
 * Run: php tests/_smoke_invoice_dating.php   (0 = pass, 1 = fail)
 * @session S-INVOICE-DATING-FIX
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
require_once __DIR__ . '/helpers/DbState.php';
require_once __DIR__ . '/helpers/Fixtures.php';

use FleetForge\Billing\InvoiceGenerator;
use FleetForge\Accounting\AutoEntryBridge;
use FleetForge\Tests\DbState;
use FleetForge\Tests\Fixtures;

$pass = 0; $fail = 0;
function ok(bool $c, string $l): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  {$l}\n"; } else { $fail++; echo "FAIL  {$l}\n"; } }
function eqs(string $e, $a, string $l): void { ok((string)$e === (string)$a, "{$l} (exp {$e}, got " . var_export($a, true) . ')'); }

$dueDays = (int) (settings_get('invoice.due_days_default', '30') ?? 30);
$tzName  = (string) (settings_get('company.timezone', APP_TIMEZONE) ?? APP_TIMEZONE);
$bizTz   = new DateTimeZone($tzName);
$today   = (new DateTimeImmutable('now', $bizTz))->format('Y-m-d');
$addDue  = static fn (string $d) => (new DateTimeImmutable($d))->modify("+{$dueDays} days")->format('Y-m-d');

function lease(array $o = []): int {
    $cust = Fixtures::createCustomer(['province' => 'BC']);
    return Fixtures::createLease($cust, array_merge([
        'engine_version' => 'holistic', 'billing_cycle' => 'monthly',
        'daily_rate' => '50.00', 'weekly_rate' => '350.00', 'monthly_rate' => '700.00',
        'gps_opt_in' => 0, 'status' => 'active',
    ], $o));
}
function baseNet(int $id): string {
    $r = db_row("SELECT COALESCE(SUM(CASE WHEN is_credit=1 THEN -amount ELSE amount END),'0.00') s
                   FROM invoice_line_items WHERE invoice_id=? AND item_type IN ('base_rental','base_rental_reconciliation_credit')", [$id]);
    return (string)($r['s'] ?? '0.00');
}
function invRow(int $id): array {
    return db_row("SELECT invoice_date, due_date, created_at, billing_period_start FROM invoices WHERE id=?", [$id]) ?: [];
}

$gen = new InvoiceGenerator();

// ── T1 + T2: fan out two periods (the Coastal Haul repro shape) ──
DbState::inTransaction(function () use ($gen, $addDue) {
    // Mar 28–31 partial + Apr 1–30 complete; monthly $700 → $93.33 + $700.
    $l = lease(['start_date' => '2027-03-28', 'end_date' => '2027-04-30']);
    $batch = $gen->generateForLease([
        'lease_id' => $l, 'period_start' => '2027-03-28', 'period_end' => '2027-03-31',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    eqs('2', $batch['count'], 'T1 fan-out → two invoices');
    $i1 = invRow($batch['invoices'][0]['invoice_id']);
    $i2 = invRow($batch['invoices'][1]['invoice_id']);

    // T1 — issue/due derive from each invoice's OWN period (advance: issue=period_start).
    eqs('2027-03-28', $i1['invoice_date'], 'T1 inv1 issue = period_start (Mar 28)');
    eqs($addDue('2027-03-28'), $i1['due_date'], 'T1 inv1 due = issue + net30 (Apr 27)');
    eqs('2027-04-01', $i2['invoice_date'], 'T1 inv2 issue = period_start (Apr 1)');
    eqs($addDue('2027-04-01'), $i2['due_date'], 'T1 inv2 due = issue + net30 (May 1)');
    ok($i1['invoice_date'] !== $i2['invoice_date'] && $i1['due_date'] !== $i2['due_date'], 'T1 the two invoices have DISTINCT issue/due dates');

    // T5/T1 — base-rental math unchanged.
    eqs('93.33', baseNet($batch['invoices'][0]['invoice_id']), 'T1 inv1 base $93.33 (unchanged)');
    eqs('700.00', baseNet($batch['invoices'][1]['invoice_id']), 'T1 inv2 base $700.00 (unchanged)');

    // T2 — created_at is the real generation time, NOT the (future) issue_date.
    $createdDate = substr((string)$i1['created_at'], 0, 10);
    ok($createdDate !== $i1['invoice_date'], 'T2 created_at distinct from invoice_date');
    ok($createdDate <= (new DateTimeImmutable('now'))->format('Y-m-d') && $createdDate >= '2025-01-01',
       'T2 created_at is a real recent generation timestamp (' . $i1['created_at'] . ')');
});

// ── T4: AR aging input — due_date is per-invoice period-derived ──
DbState::inTransaction(function () use ($gen, $addDue) {
    $l = lease(['start_date' => '2027-03-28', 'end_date' => '2027-04-30']);
    $batch = $gen->generateForLease([
        'lease_id' => $l, 'period_start' => '2027-03-28', 'period_end' => '2027-03-31',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    // AR aging buckets on due_date; the two invoices age into different buckets
    // because each due_date follows its own period (not one shared generation date).
    $d1 = invRow($batch['invoices'][0]['invoice_id'])['due_date'];
    $d2 = invRow($batch['invoices'][1]['invoice_id'])['due_date'];
    eqs($addDue('2027-03-28'), $d1, 'T4 inv1 AR due_date period-derived');
    eqs($addDue('2027-04-01'), $d2, 'T4 inv2 AR due_date period-derived');
    ok($d1 !== $d2, 'T4 distinct due_dates → distinct AR aging buckets');
});

// ── T3a: PAST-period invoice posts to its period even when sent LATER ──
// (sent_date deliberately set to a DIFFERENT month to prove it is ignored.)
DbState::inTransaction(function () use ($gen, $today) {
    if (!class_exists(AutoEntryBridge::class)) { ok(false, 'T3 AutoEntryBridge missing'); return; }
    // Single April-2026 month (open, past) → flat $700, issue = 2026-04-05.
    $l = lease(['start_date' => '2026-04-05', 'end_date' => '2026-04-30']);
    $batch = $gen->generateForLease([
        'lease_id' => $l, 'period_start' => '2026-04-05', 'period_end' => '2026-04-30',
        'billing_type' => 'single_period', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $invId = $batch['invoices'][0]['invoice_id'];
    eqs('2026-04-05', invRow($invId)['invoice_date'], 'T3a issue_date = Apr 5 2026');

    // Mark it "sent" in a DIFFERENT month — the JE must NOT use this.
    db_execute("UPDATE invoices SET status='sent', sent_date=? WHERE id=?", [$today, $invId]);
    AutoEntryBridge::onInvoiceSent($invId, null);

    $je = db_row("SELECT entry_date FROM acc_journal_entries WHERE source_type='invoice' AND source_id=? ORDER BY id DESC LIMIT 1", [$invId]);
    ok($je !== null, 'T3a invoice JE was posted');
    eqs('2026-04-05', $je['entry_date'] ?? '', 'T3a GL entry_date = issue_date (Apr 2026), NOT sent month');
});

// ── T3b: FUTURE-period invoice is guarded to today (never posts to the future) ──
DbState::inTransaction(function () use ($gen, $today) {
    $l = lease(['start_date' => '2027-03-28', 'end_date' => '2027-04-30']);
    $batch = $gen->generateForLease([
        'lease_id' => $l, 'period_start' => '2027-03-28', 'period_end' => '2027-03-31',
        'billing_type' => 'partial_start', 'created_by' => null, 'generation_source' => 'manual',
    ]);
    $invId = $batch['invoices'][0]['invoice_id'];   // issue 2027-03-28 (future)
    db_execute("UPDATE invoices SET status='sent', sent_date=? WHERE id=?", [$today, $invId]);
    AutoEntryBridge::onInvoiceSent($invId, null);

    $je = db_row("SELECT entry_date FROM acc_journal_entries WHERE source_type='invoice' AND source_id=? ORDER BY id DESC LIMIT 1", [$invId]);
    ok($je !== null, 'T3b invoice JE was posted');
    ok(($je['entry_date'] ?? '9999') <= $today, 'T3b future issue guarded — GL entry_date <= today (not 2027)');
    ok(($je['entry_date'] ?? '') !== '2027-03-28', 'T3b GL did NOT post into the future period');
});

echo "\n----------------------------------------------------------------------\n";
echo "TOTAL: {$pass} pass / {$fail} fail   (net terms +{$dueDays}d, today {$today})\n";
echo "----------------------------------------------------------------------\n";
exit($fail === 0 ? 0 : 1);
