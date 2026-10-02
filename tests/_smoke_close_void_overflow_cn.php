<?php
/**
 * tests/_smoke_close_void_overflow_cn.php
 *
 * S-CLOSE-VOID-OVERFLOW-CN regression — lease close's own invoice void
 * (adv_void_invoice() in api/v1/leases/_close_reconciliation.php) now honours
 * the S-ORPHAN-OVERFLOW-CN contract every other void path already did, and the
 * close-time engine-hours start reading ignores voided / past-extent invoices.
 *
 * THE BUGS:
 *   1. adv_void_invoice() never touched OverflowCreditNotes. Voiding a draft at
 *      close (overshoot clamp, full-month draft, advance voids, reclose) left its
 *      auto-created overflow credit note ACTIVE — an orphan the next mileage/hours
 *      true-up subtracts lease-wide — and an already-APPLIED one did not block.
 *   2. close.php picked the period-start engine hours from the latest invoice of
 *      ANY status ending anywhere: a voided invoice's reading (or one billed past
 *      the return) started the final period from the wrong reading.
 *
 * Drives the REAL close + bulk_close + void endpoints over HTTP against Herd
 * (http://fleetforge.test/fleetforge; session file in /var/tmp), like
 * _smoke_legacy_close_overshoot.php. Overflow CNs are inserted as fixture rows
 * attached to the draft close will void (source='mileage_overpayment',
 * source_invoice_id) with their issue JE posted through AutoEntryBridge —
 * OverflowCreditNotes finds them exactly as it finds engine-made ones.
 * Fixtures (SMOKE-OVCN-*) stay in the dev DB like the overshoot smoke's; the QBO
 * queue rows this run creates are deleted on shutdown (dev is live-configured,
 * so a queued push of a fixture CN must never reach a worker).
 *
 *   C1  close voids a draft with an UNAPPLIED overflow CN → CN void (remaining 0,
 *       voided_at, audit row, issue JE reversed when accounting is on) and its
 *       QBO CreditMemo void queued after commit; the clamped reissue exists.
 *   C2  the overflow CN is already APPLIED → close refused 422
 *       CREDIT_NOTE_APPLIED naming the CN; lease still active, invoice still
 *       draft, CN untouched, nothing queued.
 *   C3  bulk_close: lease 1 voids a draft with an unapplied CN, then hits a draft
 *       whose CN is applied → that lease rolls back (both drafts + the first CN
 *       untouched, nothing queued) and is reported with the CN; lease 2 in the
 *       same batch still closes and its CN void is queued.
 *   H1  a VOIDED invoice carries a later engine-hours reading → the final bills
 *       from the live reading (30 hrs, not 10).
 *   H2  a live draft billed PAST the return carries a later reading → the
 *       clamped reissue bills from the reading inside the extent (30 hrs).
 *   H3  a SENT invoice that runs past the return keeps its reading (close only
 *       credits it; its hours line stands) → no hours re-billed or flagged due.
 *   C4  close.php legacy full_month path (lease started on the 1st, closed
 *       mid-month) voids the draft + its CN, then bills the partial_end.
 *   U   in-process (BEGIN/ROLLBACK): the post-commit queue add/discard/flush,
 *       the drafts-only guard and the I05 status-gated flip.
 * Checks that depend on accounting / QBO sync being on print SKIP when off.
 *
 * USAGE: php tests/_smoke_close_void_overflow_cn.php
 * EXIT:  0 = all pass, 1 = any failure.
 *
 * @session S-CLOSE-VOID-OVERFLOW-CN
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/api/bootstrap.php';
// adv_void_invoice() + the queue helpers for the in-process U block (pure
// function definitions — no top-level execution).
require_once dirname(__DIR__) . '/api/v1/leases/_close_reconciliation.php';

use FleetForge\Billing\InvoiceGenerator;

$FAILURES = 0;
$TESTS    = 0;

function ok(string $label, $expected, $actual): void {
    global $FAILURES, $TESTS;
    $TESTS++;
    $pass = ($expected === $actual);
    if (!$pass) $FAILURES++;
    $e = is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected;
    $a = is_bool($actual)   ? ($actual   ? 'true' : 'false') : (string) $actual;
    printf("  [%s] %s\n", $pass ? 'PASS' : 'FAIL', $label);
    if (!$pass) printf("        expected: %s\n        actual:   %s\n", $e, $a);
}
function okTrue(string $label, bool $cond): void { ok($label, true, $cond); }
/** A check that only means something when $gate is on (accounting / QBO sync) — SKIP, not a vacuous PASS. */
function okGated(string $label, bool $gate, $expected, $actual): void {
    if (!$gate) { printf("  [SKIP] %s (gate off in this DB)\n", $label); return; }
    ok($label, $expected, $actual);
}

// ── Session bootstrap for HTTP (super_admin), same as the overshoot smoke ──
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = '/var/tmp/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Overflow CN Bot', 'email' => 'ovcn@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);

// QBO queue rows created for this run's fixture CNs/invoices — removed on exit
// (dev is live-configured: a queued push of a fixture must never reach a worker).
// The borrowed equipment unit's status is restored too (every close flips it).
$FIXTURE_CN_IDS  = [];
$FIXTURE_INV_IDS = [];
$UNIT_RESTORE    = null;
register_shutdown_function(static function () use ($sessFile) {
    global $FIXTURE_CN_IDS, $FIXTURE_INV_IDS, $UNIT_RESTORE;
    if (is_file($sessFile)) @unlink($sessFile);
    if ($FIXTURE_CN_IDS) {
        $in = implode(',', array_map('intval', $FIXTURE_CN_IDS));
        db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'credit_memo' AND entity_id IN ($in)");
    }
    if ($FIXTURE_INV_IDS) {
        $in = implode(',', array_map('intval', $FIXTURE_INV_IDS));
        db_execute("DELETE FROM acc_qbo_sync_queue WHERE entity_type = 'invoice' AND entity_id IN ($in)");
    }
    if ($UNIT_RESTORE) {
        db_execute("UPDATE equipment_units SET status = ? WHERE id = ?", [$UNIT_RESTORE['status'], (int) $UNIT_RESTORE['id']]);
    }
});

$baseUrl = 'http://fleetforge.test/fleetforge';

function http_post(string $url, array $body, string $sessId, string $csrf): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CSRF-Token: ' . $csrf,
            'Cookie: ff_session=' . $sessId,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => (int) $code, 'body' => (string) $resp, 'json' => json_decode((string) $resp, true)];
}

// ── Fixtures ───────────────────────────────────────────────────────────────
$unitRow = db_row("SELECT id, unit_number, status FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1", []);
if (!$unitRow) { fwrite(STDERR, "No equipment_units in dev DB — cannot run smoke.\n"); exit(1); }
$unitId     = (int) $unitRow['id'];
$unitNumber = (string) $unitRow['unit_number'];
$UNIT_RESTORE = ['id' => $unitId, 'status' => $unitRow['status']];
$prefix     = 'SMOKE-OVCN-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
$accountingOn = in_array(strtolower((string) settings_get('accounting.enabled', '0')), ['1', 'true'], true);
$qboSyncOn    = (string) settings_get('quickbooks.sync_enabled', '0') === '1'
             && !in_array((string) settings_get('quickbooks.sync_mode.credit_memo', 'sync'), ['qbo_to_ff', 'disabled'], true);

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Overflow CN',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0400',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

/** Active non-advance lease (optionally hourly). */
function make_lease(int $customerId, int $unitId, string $unitNumber, string $prefix, string $tag,
                    string $startDate, string $hourlyRate = '0.00', ?string $hoursAtStart = null): int {
    return db_insert('leases', [
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'Overflow CN',
        'status'                  => 'active',
        'start_date'              => $startDate,
        'monthly_rate'            => '1000.00', 'daily_rate' => '40.00', 'weekly_rate' => '250.00',
        'hourly_rate'             => $hourlyRate,
        'engine_hours_at_start'   => $hoursAtStart,
        'currency'                => 'CAD', 'billing_cycle' => 'monthly', 'advance_billing_periods' => 0,
        'gps_opt_in'              => 0, 'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0,
        'discount_type'           => 'none', 'discount_value' => '0.0000',
        'mileage_rate'            => '0.0000', 'mileage_unit' => 'km',
        'total_invoiced'          => '0.00', 'total_paid' => '0.00', 'outstanding_balance' => '0.00',
        'next_billing_date'       => (new DateTimeImmutable($startDate))->modify('first day of next month')->format('Y-m-d'),
    ]);
}

/** createFromLease wrapper → invoice id. */
function make_invoice(int $leaseId, string $start, string $end, string $type, array $extra = []): int {
    global $FIXTURE_INV_IDS;
    $inv = (new InvoiceGenerator())->createFromLease(array_merge([
        'lease_id' => $leaseId, 'period_start' => $start, 'period_end' => $end,
        'billing_type' => $type, 'invoice_type' => 'regular', 'created_by' => 1,
        'auto_generated' => 1, 'generation_source' => 'manual',
    ], $extra));
    $FIXTURE_INV_IDS[] = (int) $inv['invoice_id'];
    return (int) $inv['invoice_id'];
}

/**
 * Overflow CN attached to $invoiceId, issued like the engine does (issue JE via
 * AutoEntryBridge). $remaining < $amount = already applied (a blocker).
 */
function make_overflow_cn(int $invoiceId, string $amount, string $remaining, string $source = 'mileage_overpayment'): array {
    global $FIXTURE_CN_IDS;
    $inv = db_row("SELECT lease_id, customer_id, currency FROM invoices WHERE id = ?", [$invoiceId]);
    // Mint + insert + issue JE atomically: the number counter's lock only holds
    // inside a transaction (parallel smokes share the dev DB).
    [$id, $num] = db_transaction(function () use ($inv, $invoiceId, $amount, $remaining, $source) {
    $num = ff_next_credit_note_number();
    $id  = db_insert('credit_notes', [
        'credit_note_number' => $num,
        'customer_id'        => (int) $inv['customer_id'],
        'lease_id'           => (int) $inv['lease_id'],
        'source'             => $source,
        'source_invoice_id'  => $invoiceId,
        'amount'             => $amount,
        'currency'           => $inv['currency'] ?? 'CAD',
        'amount_remaining'   => $remaining,
        'status'             => bccomp($remaining, $amount, 2) === 0 ? 'active' : 'partially_used',
        'reason'             => 'smoke overflow CN fixture',
        'created_by'         => 1,
    ]);
    \FleetForge\Accounting\AutoEntryBridge::onCreditNoteIssued($id, 1);
    return [$id, $num];
    });
    $FIXTURE_CN_IDS[] = $id;
    return ['id' => $id, 'number' => $num];
}

function cn_row(int $id): array {
    return db_row("SELECT status, amount_remaining, voided_at FROM credit_notes WHERE id = ?", [$id]) ?? [];
}
function cn_void_queued(int $cnId): int {
    return (int) (db_row("SELECT COUNT(*) c FROM acc_qbo_sync_queue WHERE entity_type='credit_memo' AND entity_id=? AND operation='void'", [$cnId])['c'] ?? 0);
}
function issue_je_reversed(int $cnId, string $number): bool {
    $je = db_row("SELECT reversed_by_id FROM acc_journal_entries WHERE source_type='credit_note' AND source_id=? AND reference=? AND is_reversal=0 ORDER BY id LIMIT 1", [$cnId, $number]);
    return $je !== null && $je['reversed_by_id'] !== null;
}
function hourly_qty(int $invoiceId): ?string {
    $ln = db_row("SELECT quantity FROM invoice_line_items WHERE invoice_id = ? AND item_type = 'hourly_usage'", [$invoiceId]);
    return $ln ? bcadd((string) $ln['quantity'], '0', 2) : null;
}
function live_rental(int $leaseId): array {
    return db_select("SELECT id, billing_period_start, billing_period_end FROM invoices
                       WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY billing_period_start, id", [$leaseId]);
}

$refMonthStart = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
$day      = static fn(int $d) => (new DateTimeImmutable($refMonthStart))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
$monthEnd = date('Y-m-t', strtotime($refMonthStart));

echo "S-CLOSE-VOID-OVERFLOW-CN — close voids keep overflow credit notes + hours honest\n";
echo str_repeat('=', 78) . "\n";
echo '  (accounting ' . ($accountingOn ? 'ON' : 'off — JE checks skipped') . '; QBO credit-memo sync ' . ($qboSyncOn ? 'ON' : 'off — queue checks expect 0') . ")\n\n";

try {

// ══════════════════════════════════════════════════════════════════════════
// C1 — unapplied overflow CN on the draft close voids (overshoot straddle)
// ══════════════════════════════════════════════════════════════════════════
echo "C1 — close voids a draft with an UNAPPLIED overflow CN\n";
$leaseC1 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'C1', $day(5));
$invC1   = make_invoice($leaseC1, $day(5), $monthEnd, 'partial_start');       // billed past the return
$cnC1    = make_overflow_cn($invC1, '25.00', '25.00');
$cnC1h   = make_overflow_cn($invC1, '7.50', '7.50', 'hours_overpayment');   // two CNs on one invoice
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseC1, 'actual_return_date' => $day(15), 'close_notes' => 'ovcn C1'], $sessId, $csrf);
ok('C1.1 close returned 200', 200, $r['http_code']);
if ($r['http_code'] !== 200) { echo "    body: {$r['body']}\n"; throw new RuntimeException('close C1 failed'); }
ok('C1.2 overshoot draft voided', 'void', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invC1])['status']);
$c = cn_row($cnC1['id']);
ok('C1.3 its overflow CN voided with the invoice', 'void', (string) ($c['status'] ?? ''));
ok('C1.4 CN amount_remaining zeroed', '0.00', (string) ($c['amount_remaining'] ?? ''));
okTrue('C1.5 CN voided_at stamped', !empty($c['voided_at']));
ok('C1.6 CN void audited', 1, (int) (db_row("SELECT COUNT(*) c FROM audit_log WHERE entity_type='credit_note' AND entity_id=? AND notes LIKE '%voided on lease close%'", [$cnC1['id']])['c'] ?? 0));
okGated('C1.7 CN issue JE reversed', $accountingOn, true, issue_je_reversed($cnC1['id'], $cnC1['number']));
okGated('C1.8 QBO CreditMemo void queued after commit', $qboSyncOn, 1, cn_void_queued($cnC1['id']));
$liveC1 = live_rental($leaseC1);
ok('C1.9 one live clamped reissue ending on the return date', '1|' . $day(15), count($liveC1) . '|' . ($liveC1[0]['billing_period_end'] ?? ''));
ok('C1.10 second (hours) overflow CN on the same invoice voided too', 'void', (string) (cn_row($cnC1h['id'])['status'] ?? ''));
okGated('C1.11 …and queued exactly once', $qboSyncOn, 1, cn_void_queued($cnC1h['id']));
// Ordering: the CNs must be gone BEFORE the reissue is generated (its true-up
// reads live overflow CNs) — audit ids are strictly increasing within the txn.
$cnVoidAudit = (int) (db_row("SELECT MAX(id) m FROM audit_log WHERE entity_type='credit_note' AND entity_id IN (?, ?) AND notes LIKE '%voided on lease close%'", [$cnC1['id'], $cnC1h['id']])['m'] ?? 0);
$reissueAudit = (int) (db_row("SELECT MIN(id) m FROM audit_log WHERE entity_type='invoice' AND entity_id = ? AND action='create'", [(int) ($liveC1[0]['id'] ?? 0)])['m'] ?? 0);
okTrue("C1.12 CNs voided before the reissue was created (audit {$cnVoidAudit} < {$reissueAudit})", $cnVoidAudit > 0 && $reissueAudit > 0 && $cnVoidAudit < $reissueAudit);
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// C2 — APPLIED overflow CN blocks the close
// ══════════════════════════════════════════════════════════════════════════
echo "C2 — the overflow CN is already applied → close refused, nothing changes\n";
$leaseC2 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'C2', $day(5));
$invC2   = make_invoice($leaseC2, $day(5), $monthEnd, 'partial_start');
$cnC2    = make_overflow_cn($invC2, '25.00', '10.00');                         // $15 already spent
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseC2, 'actual_return_date' => $day(15), 'close_notes' => 'ovcn C2'], $sessId, $csrf);
ok('C2.1 close refused with 422', 422, $r['http_code']);
ok('C2.2 error code CREDIT_NOTE_APPLIED', 'CREDIT_NOTE_APPLIED', (string) ($r['json']['error']['code'] ?? ''));
okTrue('C2.3 message names the credit note', str_contains((string) ($r['json']['error']['message'] ?? ''), $cnC2['number']));
ok('C2.3b error extras list the blocking CN', json_encode([$cnC2['number']]), json_encode($r['json']['error']['credit_notes'] ?? null));
ok('C2.4 lease still active', 'active', (string) db_row("SELECT status FROM leases WHERE id = ?", [$leaseC2])['status']);
ok('C2.5 invoice still draft (close rolled back)', 'draft', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invC2])['status']);
$c = cn_row($cnC2['id']);
ok('C2.6 CN untouched', 'partially_used|10.00', ($c['status'] ?? '') . '|' . ($c['amount_remaining'] ?? ''));
okGated('C2.7 nothing queued for QBO', $qboSyncOn, 0, cn_void_queued($cnC2['id']));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// C3 — bulk_close: a blocked lease rolls back alone; the batch carries on
// ══════════════════════════════════════════════════════════════════════════
echo "C3 — bulk_close: blocked lease rolls back, the other lease still closes\n";
$today     = date('Y-m-d');
$nm1Start  = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
$nm1End    = date('Y-m-t', strtotime($nm1Start));
$nm2Start  = (new DateTimeImmutable('first day of next month'))->modify('+1 month')->format('Y-m-d');
$nm2End    = date('Y-m-t', strtotime($nm2Start));
// Lease A: covered to today, then two wholly-future drafts (voided in start order):
// the first carries an UNAPPLIED CN (voided + queued), the second an APPLIED one (blocks).
$leaseA = make_lease($customerId, $unitId, $unitNumber, $prefix, 'C3A', date('Y-m-01'));
make_invoice($leaseA, date('Y-m-01'), $today, 'partial_start');
$invA1 = make_invoice($leaseA, $nm1Start, $nm1End, 'full_month');
$invA2 = make_invoice($leaseA, $nm2Start, $nm2End, 'full_month');
$cnA1  = make_overflow_cn($invA1, '12.00', '12.00');
$cnA2  = make_overflow_cn($invA2, '30.00', '5.00');
// Lease B: covered to today + one future draft with an UNAPPLIED CN.
$leaseB = make_lease($customerId, $unitId, $unitNumber, $prefix, 'C3B', date('Y-m-01'));
make_invoice($leaseB, date('Y-m-01'), $today, 'partial_start');
$invB1 = make_invoice($leaseB, $nm1Start, $nm1End, 'full_month');
$cnB1  = make_overflow_cn($invB1, '18.00', '18.00');
$r = http_post("$baseUrl/api/v1/leases/bulk_close", ['ids' => [$leaseA, $leaseB]], $sessId, $csrf);
ok('C3.1 bulk_close returned 200', 200, $r['http_code']);
$data = $r['json']['data'] ?? [];
ok('C3.2 one actioned, one skipped', '1|1', (string) ($data['actioned'] ?? '') . '|' . (string) ($data['skipped'] ?? ''));
$errA = array_values(array_filter($data['errors'] ?? [], fn($e) => (int) ($e['id'] ?? 0) === $leaseA))[0]['reason'] ?? '';
okTrue('C3.3 lease A reported with the applied CN named', str_contains((string) $errA, $cnA2['number']));
okTrue('C3.3b …through the typed refusal, not the generic DB-error catch',
    str_contains((string) $errA, 'has already been applied') && !str_starts_with((string) $errA, 'Database error'));
ok('C3.4 lease A still active', 'active', (string) db_row("SELECT status FROM leases WHERE id = ?", [$leaseA])['status']);
ok('C3.5 lease A drafts untouched (rolled back)', 'draft|draft',
    db_row("SELECT status FROM invoices WHERE id = ?", [$invA1])['status'] . '|' . db_row("SELECT status FROM invoices WHERE id = ?", [$invA2])['status']);
ok('C3.6 lease A first CN back to active (its in-txn void rolled back)', 'active|12.00', implode('|', array_slice(array_values(cn_row($cnA1['id'])), 0, 2)));
okGated('C3.7 nothing queued for lease A CNs', $qboSyncOn, 0, cn_void_queued($cnA1['id']) + cn_void_queued($cnA2['id']));
ok('C3.8 lease B completed', 'completed', (string) db_row("SELECT status FROM leases WHERE id = ?", [$leaseB])['status']);
ok('C3.9 lease B future draft + its CN voided', 'void|void',
    db_row("SELECT status FROM invoices WHERE id = ?", [$invB1])['status'] . '|' . (cn_row($cnB1['id'])['status'] ?? ''));
okGated('C3.10 lease B CN void queued after its commit', $qboSyncOn, 1, cn_void_queued($cnB1['id']));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// H1 — a VOIDED invoice's engine-hours reading is ignored
// ══════════════════════════════════════════════════════════════════════════
echo "H1 — voided invoice's later hours reading ignored at close\n";
$leaseH1 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'H1', $day(5), '1.50', '100.00');
make_invoice($leaseH1, $day(5), $day(10), 'partial_start',
    ['engine_hours_at_period_start' => '100.00', 'engine_hours_at_period_end' => '120.00']);
$invH1v = make_invoice($leaseH1, $day(11), $day(18), 'partial_start',
    ['engine_hours_at_period_start' => '120.00', 'engine_hours_at_period_end' => '140.00']);
$rv = http_post("$baseUrl/api/v1/invoices/void", ['id' => $invH1v, 'void_reason' => 'ovcn H1 — wrong reading'], $sessId, $csrf);
ok('H1.0 interim invoice voided through the void endpoint', 200, $rv['http_code']);
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseH1, 'actual_return_date' => $day(20),
    'engine_hours_at_close' => '150.00', 'close_notes' => 'ovcn H1'], $sessId, $csrf);
ok('H1.1 close returned 200', 200, $r['http_code']);
if ($r['http_code'] !== 200) { echo "    body: {$r['body']}\n"; throw new RuntimeException('close H1 failed'); }
$finalH1 = (int) ($r['json']['data']['invoice_id'] ?? 0);
okTrue('H1.2 final partial_end invoice created', $finalH1 > 0);
ok('H1.3 final bills 120 → 150 = 30 hrs (not 140 → 150 = 10)', '30.00', hourly_qty($finalH1));
ok('H1.4 final snapshots the live start reading', '120.00',
    (string) (db_row("SELECT engine_hours_at_period_start s FROM invoices WHERE id = ?", [$finalH1])['s'] ?? ''));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// H2 — a live draft billed PAST the return carries a later reading
// ══════════════════════════════════════════════════════════════════════════
echo "H2 — past-extent invoice's hours reading ignored; clamped reissue bills from the in-extent reading\n";
$leaseH2 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'H2', $day(5), '1.50', '100.00');
make_invoice($leaseH2, $day(5), $day(10), 'partial_start',
    ['engine_hours_at_period_start' => '100.00', 'engine_hours_at_period_end' => '120.00']);
$invH2o = make_invoice($leaseH2, $day(11), $monthEnd, 'partial_start',
    ['engine_hours_at_period_start' => '120.00', 'engine_hours_at_period_end' => '160.00']);
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseH2, 'actual_return_date' => $day(20),
    'engine_hours_at_close' => '150.00', 'close_notes' => 'ovcn H2'], $sessId, $csrf);
ok('H2.1 close returned 200', 200, $r['http_code']);
if ($r['http_code'] !== 200) { echo "    body: {$r['body']}\n"; throw new RuntimeException('close H2 failed'); }
ok('H2.2 past-extent draft voided by the overshoot clamp', 'void', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invH2o])['status']);
$reH2 = db_row("SELECT id FROM invoices WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' AND billing_period_start = ?", [$leaseH2, $day(11)]);
okTrue('H2.3 clamped reissue exists', $reH2 !== null);
ok('H2.4 reissue bills 120 → 150 = 30 hrs (not from the 160 past-extent reading)', '30.00', $reH2 ? hourly_qty((int) $reH2['id']) : null);
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// C4 — close.php legacy full_month path (legacy_handle_existing_full_month_draft)
// ══════════════════════════════════════════════════════════════════════════
echo "C4 — full_month draft (lease started on the 1st) voided mid-month with its CN\n";
$leaseC4 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'C4', $day(1));
$invC4   = make_invoice($leaseC4, $day(1), $monthEnd, 'full_month');
$cnC4    = make_overflow_cn($invC4, '9.00', '9.00');
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseC4, 'actual_return_date' => $day(15), 'close_notes' => 'ovcn C4'], $sessId, $csrf);
ok('C4.1 close returned 200', 200, $r['http_code']);
if ($r['http_code'] !== 200) { echo "    body: {$r['body']}\n"; throw new RuntimeException('close C4 failed'); }
ok('C4.2 voided by legacy_handle (not the overshoot pass)', 'voided_for_replacement', (string) ($r['json']['data']['advance_actions'][0]['action'] ?? ''));
ok('C4.3 full_month draft + its CN voided', 'void|void',
    db_row("SELECT status FROM invoices WHERE id = ?", [$invC4])['status'] . '|' . (cn_row($cnC4['id'])['status'] ?? ''));
okGated('C4.4 CN void queued after commit', $qboSyncOn, 1, cn_void_queued($cnC4['id']));
$liveC4 = live_rental($leaseC4);
ok('C4.5 partial_end final billed to the return date', '1|' . $day(1) . '|' . $day(15),
    count($liveC4) . '|' . ($liveC4[0]['billing_period_start'] ?? '') . '|' . ($liveC4[0]['billing_period_end'] ?? ''));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// H3 — a SENT invoice running past the return keeps its hours reading
// ══════════════════════════════════════════════════════════════════════════
echo "H3 — sent invoice past the return: its reading still counts (no hours re-billed)\n";
$leaseH3 = make_lease($customerId, $unitId, $unitNumber, $prefix, 'H3', $day(5), '1.50', '100.00');
$invH3s = make_invoice($leaseH3, $day(5), $monthEnd, 'partial_start',
    ['engine_hours_at_period_start' => '100.00', 'engine_hours_at_period_end' => '160.00']);
db_execute("UPDATE invoices SET status = 'sent' WHERE id = ?", [$invH3s]);
$r = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseH3, 'actual_return_date' => $day(20),
    'engine_hours_at_close' => '160.00', 'close_notes' => 'ovcn H3'], $sessId, $csrf);
ok('H3.1 close returned 200', 200, $r['http_code']);
if ($r['http_code'] !== 200) { echo "    body: {$r['body']}\n"; throw new RuntimeException('close H3 failed'); }
$hrsActions = array_values(array_filter($r['json']['data']['advance_actions'] ?? [],
    fn($a) => in_array($a['action'] ?? '', ['hours_unbilled_no_draft', 'hours_folded_onto_final'], true)));
ok('H3.2 no hours flagged due or folded (the sent invoice billed them)', '[]', json_encode($hrsActions));
ok('H3.3 still exactly one hourly line on the lease', 1, (int) (db_row(
    "SELECT COUNT(*) c FROM invoices i JOIN invoice_line_items li ON li.invoice_id = i.id AND li.item_type = 'hourly_usage'
      WHERE i.lease_id = ? AND i.deleted_at IS NULL AND i.status <> 'void'", [$leaseH3])['c'] ?? 0));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// U — in-process: queue semantics + adv_void_invoice() guards (BEGIN/ROLLBACK)
// ══════════════════════════════════════════════════════════════════════════
echo "U — queue add/discard/flush + drafts-only + I05 guards (rolled back)\n";
adv_cn_void_queue_discard();
adv_cn_void_queue_add([['id' => 901], ['id' => 902]]);
ok('U.1 queue holds the added CN ids', '[901,902]', json_encode(adv_cn_void_queue_ref()));
adv_cn_void_queue_discard();
ok('U.2 discard empties it', '[]', json_encode(adv_cn_void_queue_ref()));
adv_cn_void_queue_add([['id' => 999999999]]);
adv_cn_void_queue_flush();   // unknown id → enqueuer gate 0 refuses; the queue still drains
ok('U.3 flush drains the queue', '[]', json_encode(adv_cn_void_queue_ref()));

$pdo = db_pdo();
$pdo->beginTransaction();
try {
    $yr = date('Y');
    $maxStr = db_row("SELECT MAX(invoice_number) m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$yr}-%"])['m'] ?? '';
    $maxNum = $maxStr ? (int) substr(strrchr($maxStr, '-'), 1) : 0;
    db_execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
        ["invoice.next_number.{$yr}", (string) ($maxNum + 50)]);   // mbcron_bump_counter pattern
    $leaseU = make_lease($customerId, $unitId, $unitNumber, $prefix, 'U', $day(5));
    $invU   = make_invoice($leaseU, $day(5), $day(9), 'partial_start');
    $rowU   = db_row("SELECT id, invoice_number, status, total_amount, balance_due FROM invoices WHERE id = ?", [$invU]);
    $leaseRowU = db_row("SELECT id, customer_id FROM leases WHERE id = ?", [$leaseU]);
    $tiBefore  = (string) db_row("SELECT total_invoiced t FROM leases WHERE id = ?", [$leaseU])['t'];

    $threw = '';
    try { adv_void_invoice(array_merge($rowU, ['status' => 'sent']), $leaseRowU, 'smoke U'); }
    catch (\LogicException $e) { $threw = 'LogicException'; }
    ok('U.4 non-draft input refused (drafts only)', 'LogicException', $threw);
    ok('U.5 …before any write (invoice still draft)', 'draft', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invU])['status']);

    db_execute("UPDATE invoices SET status = 'sent' WHERE id = ?", [$invU]);   // changed since the caller read it
    $threw = '';
    try { adv_void_invoice($rowU, $leaseRowU, 'smoke U'); }
    catch (\RuntimeException $e) { $threw = get_class($e); }
    ok('U.6 stale draft row refused by the I05 status gate', 'RuntimeException', $threw);
    ok('U.7 …lease counters untouched', $tiBefore, (string) db_row("SELECT total_invoiced t FROM leases WHERE id = ?", [$leaseU])['t']);
} finally {
    $pdo->rollBack();
}
echo "\n";

} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $FAILURES++;
}

echo str_repeat('=', 78) . "\n";
printf("%s — %d test(s), %d failure(s)\n", $FAILURES === 0 ? 'ALL PASS' : 'FAILURES', $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
