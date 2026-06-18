<?php
/**
 * tests/_smoke_legacy_close_overshoot.php
 *
 * S-CLOSE-OVERSHOOT regression — proves that closing a lease NEVER leaves a
 * non-advance rental invoice billed past the lease's billable extent.
 *
 * THE BUG (production, lease MTTS66 / INV-2026-00130): a lease that STARTS
 * mid-month gets an activation `partial_start` Invoice 1 billed start_date →
 * end-of-month (date('Y-m-t')). When the lease was later RETURNED inside that
 * same month, nothing shortened or credited that invoice — the customer was
 * over-billed for the days between the return and month-end. Root cause:
 * close.php's legacy_handle_existing_full_month_draft() only ever matched
 * `full_month` invoices, and the partial_end fallback was suppressed by the
 * coverage guard because month-end coverage already reached past the return.
 *
 * THE FIX: api/v1/leases/close.php::reconcile_overshoot_invoices() runs on every
 * close and clamps each non-advance rental invoice whose billing_period_end
 * exceeds the lease's billable extent — draft → void+regenerate-shortened,
 * sent/paid → prorated credit_note. The legacy partial_end block now compares
 * against / bills to the same time-of-day-adjusted $extentEnd.
 *
 * This drives the REAL close endpoint over HTTP (like _smoke_session4_regression)
 * and creates the activation invoice the SAME way activate.php's legacy path does
 * (InvoiceGenerator::createFromLease, partial_start → end of start month).
 *
 * USAGE: php tests/_smoke_legacy_close_overshoot.php
 * EXIT:  0 = all pass, 1 = any failure.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

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

// ── Session bootstrap for HTTP (super_admin) ───────────────────────────────
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = '/var/tmp/sess_' . $sessId;
file_put_contents($sessFile,
    'ff_user|' . serialize([
        'id' => 1, 'name' => 'Overshoot Bot', 'email' => 'overshoot@fleetforge.test',
        'role_id' => 1, 'role_slug' => 'super_admin', 'permissions' => [], 'theme' => 'dark',
    ]) .
    'ff_last_activity|' . serialize(time()) .
    'csrf_token|' . serialize($csrf)
);
chmod($sessFile, 0600);
register_shutdown_function(static function () use ($sessFile) {
    if (is_file($sessFile)) @unlink($sessFile);
});

$baseUrl = 'http://fleetforge.test/fleetforge';

function http_post(string $url, array $body, string $sessId, string $csrf): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
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
    return [
        'http_code' => (int) $code,
        'body'      => is_string($resp) ? $resp : '',
        'json'      => is_string($resp) ? json_decode($resp, true) : null,
    ];
}

function customer_ob(int $custId): string {
    return (string) (db_row("SELECT outstanding_balance FROM customers WHERE id = ?", [$custId])['outstanding_balance'] ?? 'NA');
}

function live_invoices(int $leaseId): array {
    return db_select(
        "SELECT id, status, billing_type, billing_period_start, billing_period_end, billing_period_days, total_amount, generation_source
           FROM invoices WHERE lease_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY id",
        [$leaseId]
    );
}

// ── Shared fixtures ────────────────────────────────────────────────────────
$unitRow = db_row("SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1", []);
if (!$unitRow) {
    fwrite(STDERR, "No equipment_units available in dev DB — cannot run smoke.\n");
    exit(1);
}
$unitId     = (int) $unitRow['id'];
$unitNumber = (string) $unitRow['unit_number'];
$prefix     = 'SMOKE-OVS-' . date('YmdHis');

$customerId = db_insert('customers', [
    'company_name' => $prefix . ' Co', 'contact_name' => 'Overshoot',
    'email' => strtolower($prefix) . '@example.invalid', 'phone' => '555-0300',
    'province' => 'BC', 'currency' => 'CAD',
    'gst_exempt' => 0, 'pst_exempt' => 0, 'tax_exempt' => 0, 'outstanding_balance' => '0.00',
]);

/**
 * Create an ACTIVE non-advance lease + its activation Invoice 1, exactly the way
 * api/v1/leases/activate.php's legacy path does: partial_start (or full_month)
 * billed start_date → last day of the start month.
 *
 * @return array{0:int,1:int,2:string} [leaseId, invoiceId, monthEnd]
 */
function make_active_lease(
    int $customerId, int $unitId, string $unitNumber, string $prefix, string $tag,
    string $startDate, ?string $startTime
): int {
    return db_insert('leases', [
        'contract_number'         => $prefix . '-' . $tag,
        'customer_id'             => $customerId,
        'equipment_unit_id'       => $unitId,
        'unit_number_snapshot'    => $unitNumber,
        'company_name_snapshot'   => $prefix . ' Co',
        'customer_name_snapshot'  => 'Overshoot',
        'status'                  => 'active',
        'start_date'              => $startDate,
        'start_time'              => $startTime,
        'monthly_rate'            => '1000.00',
        'daily_rate'              => '40.00',
        'weekly_rate'             => '250.00',
        'currency'                => 'CAD',
        'billing_cycle'           => 'monthly',
        'advance_billing_periods' => 0,
        'gps_opt_in'              => 0,
        'gst_exempt'              => 0,
        'pst_exempt'              => 0,
        'tax_exempt'              => 0,
        'discount_type'           => 'none',
        'discount_value'          => '0.0000',
        'mileage_rate'            => '0.0000',
        'mileage_unit'            => 'km',
        'total_invoiced'          => '0.00',
        'total_paid'              => '0.00',
        'outstanding_balance'     => '0.00',
        'next_billing_date'       => (new DateTimeImmutable($startDate))->modify('first day of next month')->format('Y-m-d'),
    ]);
}

function make_active_lease_with_invoice1(
    int $customerId, int $unitId, string $unitNumber, string $prefix, string $tag,
    string $startDate, ?string $startTime
): array {
    $leaseId = make_active_lease($customerId, $unitId, $unitNumber, $prefix, $tag, $startDate, $startTime);

    $monthEnd  = date('Y-m-t', strtotime($startDate));
    $firstDay  = date('Y-m-01', strtotime($startDate));
    $type      = ($startDate === $firstDay) ? 'full_month' : 'partial_start';

    $gen = new InvoiceGenerator();
    $inv = $gen->createFromLease([
        'lease_id'          => $leaseId,
        'period_start'      => $startDate,
        'period_end'        => $monthEnd,        // activate.php legacy path: bills to month-end
        'billing_type'      => $type,
        'invoice_type'      => 'regular',
        'created_by'        => 1,
        'auto_generated'    => 1,
        'generation_source' => 'manual',
    ]);
    return [$leaseId, (int) $inv['invoice_id'], $monthEnd];
}

echo "S-CLOSE-OVERSHOOT — legacy close billing-period clamp regression\n";
echo str_repeat('=', 78) . "\n\n";

// Use a fixed reference month with 31 days, in the past, so cron timing and
// month-length are irrelevant and the lease is unambiguously in one month.
$refMonthStart = (new DateTimeImmutable('first day of last month'))->format('Y-m-d'); // 1st of last month
$day  = static fn(int $d) => (new DateTimeImmutable($refMonthStart))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
$monthEnd = date('Y-m-t', strtotime($refMonthStart));

try {

// ══════════════════════════════════════════════════════════════════════════
// CASE A — DRAFT partial_start, mid-month return (date-only). THE BUG.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE A — draft partial_start, returned mid-month (date-only)\n";
[$leaseA, $invA, $meA] = make_active_lease_with_invoice1($customerId, $unitId, $unitNumber, $prefix, 'A', $day(5), null);

$inv1 = db_row("SELECT billing_period_start, billing_period_end, billing_type, status, total_amount FROM invoices WHERE id = ?", [$invA]);
$origTotalA = (string) $inv1['total_amount'];
ok('A.0 Invoice 1 is partial_start',                    'partial_start', (string) $inv1['billing_type']);
ok('A.1 Invoice 1 billed to month-end (the over-bill)', $monthEnd,       (string) $inv1['billing_period_end']);
ok('A.2 customer OB unchanged (draft excluded, Path B)','0.00',          customer_ob($customerId));

$returnA = $day(15);
$resp = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseA, 'actual_return_date' => $returnA, 'close_notes' => 'overshoot smoke A',
], $sessId, $csrf);
ok('A.3 close returned 200', 200, $resp['http_code']);
if ($resp['http_code'] !== 200) { echo "    body: {$resp['body']}\n"; throw new RuntimeException('close A failed'); }
$dataA = $resp['json']['data'] ?? $resp['json'];

$ovA = $dataA['overshoot_actions'] ?? [];
ok('A.4 exactly one overshoot action', 1, count($ovA));
ok('A.5 overshoot action = replaced (draft straddle → void+regenerate)', 'replaced', (string) ($ovA[0]['action'] ?? ''));
ok('A.6 overshoot classified as straddles', 'straddles', (string) ($ovA[0]['overshoot'] ?? ''));

$invsA   = live_invoices($leaseA);
$voided  = db_row("SELECT status FROM invoices WHERE id = ?", [$invA]);
ok('A.7 original Invoice 1 is now void', 'void', (string) $voided['status']);
ok('A.8 exactly one LIVE (non-void) invoice remains', 1, count($invsA));

$repl = $invsA[0];
ok('A.9 replacement period_start = lease start', $day(5),  (string) $repl['billing_period_start']);
ok('A.10 replacement period_end = return date (clamped)', $returnA, (string) $repl['billing_period_end']);
ok('A.11 replacement billed days = 11 (day 5..15 inclusive)', '11', (string) $repl['billing_period_days']);
ok('A.12 replacement billing_type = partial_start', 'partial_start', (string) $repl['billing_type']);
okTrue('A.13 replacement amount < original over-billed amount',
    bccomp((string) $repl['total_amount'], $origTotalA, 2) < 0);
ok('A.14 no spurious partial_end final invoice (final invoice_id null)', '', (string) ($dataA['invoice_id'] ?? ''));
ok('A.15 customer OB still 0 (all drafts, Path B)', '0.00', customer_ob($customerId));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE B — SENT partial_start, mid-month return → prorated credit_note.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE B — sent partial_start, returned mid-month → credit note\n";
[$leaseB, $invB, $meB] = make_active_lease_with_invoice1($customerId, $unitId, $unitNumber, $prefix, 'B', $day(5), null);

$sendResp = http_post("$baseUrl/api/v1/invoices/send", ['id' => $invB], $sessId, $csrf);
ok('B.0 send Invoice 1 returned 200', 200, $sendResp['http_code']);
$invBrow = db_row("SELECT total_amount, billing_period_end, status, balance_due FROM invoices WHERE id = ?", [$invB]);
ok('B.1 Invoice 1 now sent', 'sent', (string) $invBrow['status']);
$obAfterSend = customer_ob($customerId);
okTrue('B.2 customer OB increased after send (Path B sent → OB += balance_due)',
    bccomp($obAfterSend, '0.00', 2) > 0);

$returnB = $day(15);
$respB = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseB, 'actual_return_date' => $returnB, 'close_notes' => 'overshoot smoke B',
], $sessId, $csrf);
ok('B.3 close returned 200', 200, $respB['http_code']);
if ($respB['http_code'] !== 200) { echo "    body: {$respB['body']}\n"; throw new RuntimeException('close B failed'); }
$dataB = $respB['json']['data'] ?? $respB['json'];
$ovB = $dataB['overshoot_actions'] ?? [];
ok('B.4 exactly one overshoot action', 1, count($ovB));
ok('B.5 overshoot action = credit_note_issued (sent → credit)', 'credit_note_issued', (string) ($ovB[0]['action'] ?? ''));

$invBafter = db_row("SELECT status, billing_period_end FROM invoices WHERE id = ?", [$invB]);
ok('B.6 sent invoice stays sent (immutable)', 'sent', (string) $invBafter['status']);
ok('B.7 sent invoice period_end unchanged (immutable record)', (string) $invBrow['billing_period_end'], (string) $invBafter['billing_period_end']);

$cn = db_row("SELECT amount, source, status FROM credit_notes WHERE source_invoice_id = ? AND deleted_at IS NULL", [$invB]);
okTrue('B.8 credit_note issued for unused tail (amount > 0)', $cn && bccomp((string) $cn['amount'], '0.00', 2) > 0);
// unused tail = days 16..monthEnd of a [5..monthEnd] period. Fraction = unused/total.
$totalDays  = (int) ((new DateTimeImmutable($meB))->diff(new DateTimeImmutable($day(5)))->days) + 1;
$usedDays   = (int) ((new DateTimeImmutable($returnB))->diff(new DateTimeImmutable($day(5)))->days) + 1;
$unusedDays = $totalDays - $usedDays;
$expectedCn = bcround(bcmul((string) $invBrow['total_amount'], bcdiv((string) $unusedDays, (string) $totalDays, 6), 6), 2);
ok('B.9 credit_note amount = prorated unused tail', $expectedCn, (string) ($cn['amount'] ?? '0.00'));

// B.10/B.11 — idempotency for the SENT case: reopen + reclose must NOT issue a
// second credit note (the guard skips invoices already carrying an adjustment CN).
$reopenB = http_post("$baseUrl/api/v1/leases/reopen", ['id' => $leaseB, 'reopen_reason' => 'overshoot smoke B idempotency'], $sessId, $csrf);
ok('B.10 reopen returned 200', 200, $reopenB['http_code']);
$recloseB = http_post("$baseUrl/api/v1/leases/close", ['id' => $leaseB, 'actual_return_date' => $returnB, 'close_notes' => 'overshoot smoke B reclose'], $sessId, $csrf);
ok('B.11 reclose returned 200', 200, $recloseB['http_code']);
$cnCount = (int) (db_row("SELECT COUNT(*) c FROM credit_notes WHERE source_invoice_id = ? AND source='invoice_adjustment' AND status <> 'void' AND deleted_at IS NULL", [$invB])['c'] ?? 0);
ok('B.12 still exactly ONE adjustment credit note (no double-credit on reclose)', 1, $cnCount);
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE C — DRAFT partial_start, returned on LAST day of month → NO clamp.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE C — returned on last day of month → no clamp (strict > boundary)\n";
[$leaseC, $invC, $meC] = make_active_lease_with_invoice1($customerId, $unitId, $unitNumber, $prefix, 'C', $day(5), null);

$respC = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseC, 'actual_return_date' => $meC, 'close_notes' => 'overshoot smoke C',
], $sessId, $csrf);
ok('C.1 close returned 200', 200, $respC['http_code']);
if ($respC['http_code'] !== 200) { echo "    body: {$respC['body']}\n"; throw new RuntimeException('close C failed'); }
$dataC = $respC['json']['data'] ?? $respC['json'];
ok('C.2 no overshoot actions (period_end == extent, not >)', 0, count($dataC['overshoot_actions'] ?? []));
$invCafter = db_row("SELECT status, billing_period_end FROM invoices WHERE id = ?", [$invC]);
ok('C.3 Invoice 1 untouched (still draft)', 'draft', (string) $invCafter['status']);
ok('C.4 Invoice 1 period_end unchanged (= month end)', $meC, (string) $invCafter['billing_period_end']);
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE D — TIME-OF-DAY: return before pickup time → extent = return-1.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE D — time-of-day rule: returned before pickup time-of-day\n";
[$leaseD, $invD, $meD] = make_active_lease_with_invoice1($customerId, $unitId, $unitNumber, $prefix, 'D', $day(5), '17:00:00');

$returnD = $day(15);
$respD = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseD, 'actual_return_date' => $returnD,
    'actual_return_time' => '16:00:00',  // before pickup 17:00 → return day not billed
    'close_notes' => 'overshoot smoke D',
], $sessId, $csrf);
ok('D.1 close returned 200', 200, $respD['http_code']);
if ($respD['http_code'] !== 200) { echo "    body: {$respD['body']}\n"; throw new RuntimeException('close D failed'); }
$dataD = $respD['json']['data'] ?? $respD['json'];
$ovD = $dataD['overshoot_actions'] ?? [];
ok('D.2 one overshoot action (replaced)', 'replaced', (string) ($ovD[0]['action'] ?? ''));

$invsD = live_invoices($leaseD);
$replD = $invsD[0] ?? [];
$expectedExtentD = $day(14); // return 15th, returned before pickup time → extent = 14th
ok('D.3 replacement period_end = return date - 1 (time-of-day rule)', $expectedExtentD, (string) ($replD['billing_period_end'] ?? ''));
ok('D.4 replacement billed days = 10 (day 5..14 inclusive)', '10', (string) ($replD['billing_period_days'] ?? ''));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE E — IDEMPOTENCY: reopen + reclose at the same return date → no-op.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE E — idempotency on reopen + reclose (same return date)\n";
// Reuse lease A (already closed at day 15). Reopen, then reclose at day 15.
$reopenResp = http_post("$baseUrl/api/v1/leases/reopen", [
    'id' => $leaseA, 'reopen_reason' => 'overshoot smoke E idempotency check',
], $sessId, $csrf);
ok('E.1 reopen returned 200', 200, $reopenResp['http_code']);
if ($reopenResp['http_code'] !== 200) { echo "    body: {$reopenResp['body']}\n"; throw new RuntimeException('reopen failed'); }

$recloseResp = http_post("$baseUrl/api/v1/leases/close", [
    'id' => $leaseA, 'actual_return_date' => $returnA, 'close_notes' => 'overshoot smoke E reclose',
], $sessId, $csrf);
ok('E.2 reclose returned 200', 200, $recloseResp['http_code']);
$dataE = $recloseResp['json']['data'] ?? $recloseResp['json'];
ok('E.3 reclose finds nothing to reconcile (already clamped to extent)', 0, count($dataE['overshoot_actions'] ?? []));
ok('E.4 still exactly one live invoice (no duplicate clamp/credit)', 1, count(live_invoices($leaseA)));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE F — bulk_close applies the SAME clamp via the shared helper.
// Adaptive to the server's real "today" (bulk_close uses date('Y-m-d')).
// ══════════════════════════════════════════════════════════════════════════
echo "CASE F — bulk_close clamps an overshoot invoice (shared reconciliation)\n";
$todayStr = date('Y-m-d');
$dom = (int) date('j');
$dim = (int) date('t');
if ($dom < 4 || $dom >= $dim) {
    echo "  (skipped: today is day {$dom}/{$dim}; need day 4.." . ($dim - 1) . " for a mid-month overshoot)\n\n";
} else {
    $startF = date('Y-m-02'); // 2nd of current month → partial_start billed to month-end
    [$leaseF, $invF, $meF] = make_active_lease_with_invoice1($customerId, $unitId, $unitNumber, $prefix, 'F', $startF, null);
    $invFrow = db_row("SELECT billing_period_end FROM invoices WHERE id = ?", [$invF]);
    ok('F.0 Invoice 1 partial_start billed to month-end', $meF, (string) $invFrow['billing_period_end']);

    $bresp = http_post("$baseUrl/api/v1/leases/bulk_close", ['ids' => [$leaseF]], $sessId, $csrf);
    ok('F.1 bulk_close returned 200', 200, $bresp['http_code']);
    if ($bresp['http_code'] !== 200) { echo "    body: {$bresp['body']}\n"; throw new RuntimeException('bulk_close F failed'); }

    $leaseFrow = db_row("SELECT status, actual_return_date FROM leases WHERE id = ?", [$leaseF]);
    ok('F.2 lease completed via bulk_close', 'completed', (string) $leaseFrow['status']);
    ok('F.3 actual_return_date = today', $todayStr, (string) $leaseFrow['actual_return_date']);

    $voidedF = db_row("SELECT status FROM invoices WHERE id = ?", [$invF]);
    ok('F.4 original overshoot invoice voided by bulk_close', 'void', (string) $voidedF['status']);

    $liveF = live_invoices($leaseF);
    ok('F.5 exactly one live invoice (clamped replacement)', 1, count($liveF));
    ok('F.6 replacement period_end = today (clamped to bulk extent)', $todayStr, (string) ($liveF[0]['billing_period_end'] ?? ''));
    echo "\n";
}

// ══════════════════════════════════════════════════════════════════════════
// CASE G — future-month wholly-past invoice voided AND real tail still billed
// (review Finding 1 + 3): a stray future full_month must not (a) survive as a
// dangling over-bill, nor (b) inflate the coverage anchor and suppress the
// partial_end for the genuinely-rented tail.
// ══════════════════════════════════════════════════════════════════════════
echo "CASE G — future full_month voided + real tail still billed\n";
$leaseG = make_active_lease($customerId, $unitId, $unitNumber, $prefix, 'G', $day(5), null);
$genG = new InvoiceGenerator();
// Real partial coverage [day5..day10] — does NOT overshoot the day-20 return.
$genG->createFromLease(['lease_id'=>$leaseG,'period_start'=>$day(5),'period_end'=>$day(10),
    'billing_type'=>'partial_start','invoice_type'=>'regular','created_by'=>1,'auto_generated'=>1,'generation_source'=>'manual']);
// Stray future-month full_month (wholly past the return) — the Finding 3 trigger.
$nmStart = (new DateTimeImmutable($refMonthStart))->modify('first day of next month')->format('Y-m-d');
$nmEnd   = date('Y-m-t', strtotime($nmStart));
$invFutG = $genG->createFromLease(['lease_id'=>$leaseG,'period_start'=>$nmStart,'period_end'=>$nmEnd,
    'billing_type'=>'full_month','invoice_type'=>'regular','created_by'=>1,'auto_generated'=>1,'generation_source'=>'manual']);

$returnG = $day(20);
$respG = http_post("$baseUrl/api/v1/leases/close", ['id'=>$leaseG,'actual_return_date'=>$returnG,'close_notes'=>'overshoot smoke G'], $sessId, $csrf);
ok('G.1 close returned 200', 200, $respG['http_code']);
if ($respG['http_code'] !== 200) { echo "    body: {$respG['body']}\n"; throw new RuntimeException('close G failed'); }
$dataG = $respG['json']['data'] ?? $respG['json'];

$futStatus = (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invFutG['invoice_id']])['status'];
ok('G.2 future full_month (wholly past) voided', 'void', $futStatus);
$ovG = $dataG['overshoot_actions'] ?? [];
ok('G.3 one overshoot action (the future full_month)', 1, count($ovG));
ok('G.4 future full_month classified wholly_past', 'wholly_past', (string) ($ovG[0]['overshoot'] ?? ''));

// Fix A: coverage anchor excludes the future invoice → real tail [day11..day20] billed.
ok('G.5 partial_end final invoice generated for the real tail', true, !empty($dataG['invoice_id']));
$tailG = db_row("SELECT billing_period_start, billing_period_end, billing_type FROM invoices WHERE id = ?", [$dataG['invoice_id']]);
ok('G.6 tail period_start = day 11 (day after real coverage)', $day(11), (string) ($tailG['billing_period_start'] ?? ''));
ok('G.7 tail period_end = day 20 (the return/extent)', $returnG, (string) ($tailG['billing_period_end'] ?? ''));
echo "\n";

// ══════════════════════════════════════════════════════════════════════════
// CASE H — bulk_close clamps a full_month STRADDLE (it has no legacy_handle).
// ══════════════════════════════════════════════════════════════════════════
echo "CASE H — bulk_close clamps a full_month straddle\n";
if ($dom < 4 || $dom >= $dim) {
    echo "  (skipped: today is day {$dom}/{$dim}; need day 4.." . ($dim - 1) . " for a mid-month straddle)\n\n";
} else {
    $leaseH = make_active_lease($customerId, $unitId, $unitNumber, $prefix, 'H', date('Y-m-01'), null);
    $genH = new InvoiceGenerator();
    $fmEnd = date('Y-m-t');
    $invH = $genH->createFromLease(['lease_id'=>$leaseH,'period_start'=>date('Y-m-01'),'period_end'=>$fmEnd,
        'billing_type'=>'full_month','invoice_type'=>'regular','created_by'=>1,'auto_generated'=>1,'generation_source'=>'manual']);
    $brespH = http_post("$baseUrl/api/v1/leases/bulk_close", ['ids'=>[$leaseH]], $sessId, $csrf);
    ok('H.1 bulk_close returned 200', 200, $brespH['http_code']);
    if ($brespH['http_code'] !== 200) { echo "    body: {$brespH['body']}\n"; throw new RuntimeException('bulk_close H failed'); }
    ok('H.2 original full_month straddle voided by bulk_close', 'void', (string) db_row("SELECT status FROM invoices WHERE id = ?", [$invH['invoice_id']])['status']);
    $liveH = live_invoices($leaseH);
    ok('H.3 exactly one live invoice (clamped replacement)', 1, count($liveH));
    ok('H.4 replacement period_end = today (full_month straddle clamped)', $todayStr, (string) ($liveH[0]['billing_period_end'] ?? ''));
    echo "\n";
}

} catch (\Throwable $e) {
    echo "\nEXCEPTION: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
    $FAILURES++;
}

echo str_repeat('=', 78) . "\n";
printf("%s — %d test(s), %d failure(s)\n", $FAILURES === 0 ? 'ALL PASS' : 'FAILURES', $TESTS, $FAILURES);
exit($FAILURES === 0 ? 0 : 1);
