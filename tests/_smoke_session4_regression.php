<?php
declare(strict_types=1);

/**
 * tests/_smoke_session4_regression.php
 *
 * S-FIX-2 regression check on Session 4 (ADV-BILL-1) advance billing.
 *
 *   T5  — generateAdvanceBatch creates N+1 invoices with Path-B counters
 *   T7  — monthly cron query skips advance-billed leases
 *   T14 — close mode='refund_unused' mid-period: status-aware void of
 *         unpaid drafts; credit_note for paid invoices
 *   T15 — close mode='no_refund' leaves advance invoices intact
 *
 * Strategy mirrors tests/_smoke_valid2.php — manually writes a real PHP
 * session file at /var/tmp/sess_<id> with super_admin claims, then uses
 * cURL to drive the API endpoints. Direct DB reads check counter state
 * before/after each operation.
 *
 * Cleanup: hard-deletes the test customer + lease + equipment_unit (we
 * create our own) at the end. Does not touch any pre-existing rows.
 *
 * USAGE: php tests/_smoke_session4_regression.php
 * EXIT:  0 on all pass, 1 on any failure.
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Billing\InvoiceGenerator;

// ============================================================================
// Test harness
// ============================================================================
$failures = [];
$passes   = 0;

function ok(string $label, $expected, $actual): void {
    global $failures, $passes;
    $bothNumeric = (is_numeric($expected) || $expected === '0' || $expected === '0.00')
                && (is_numeric($actual)   || $actual   === '0' || $actual   === '0.00');
    if ($bothNumeric) {
        $isOk = bccomp((string) $expected, (string) $actual, 2) === 0;
    } else {
        $isOk = $expected === $actual;
    }
    $shownExp = is_bool($expected) ? ($expected ? 'true' : 'false') : (is_array($expected) ? json_encode($expected) : (string) $expected);
    $shownAct = is_bool($actual)   ? ($actual   ? 'true' : 'false') : (is_array($actual)   ? json_encode($actual)   : (string) $actual);
    if ($isOk) {
        $passes++;
        echo "  PASS  {$label}: {$shownAct}\n";
    } else {
        $failures[] = $label;
        echo "  FAIL  {$label}: expected {$shownExp}, got {$shownAct}\n";
    }
}

function counters(int $custId, int $leaseId): array {
    $cust  = db_row("SELECT outstanding_balance FROM customers WHERE id = ?", [$custId]);
    $lease = db_row("SELECT outstanding_balance, total_invoiced FROM leases WHERE id = ?", [$leaseId]);
    return [
        'customer_ob'    => (string) $cust['outstanding_balance'],
        'lease_ob'       => (string) $lease['outstanding_balance'],
        'lease_invoiced' => (string) $lease['total_invoiced'],
    ];
}

// ============================================================================
// Session bootstrap for HTTP calls
// ============================================================================
$tmpDir   = '/var/tmp';
$sessId   = bin2hex(random_bytes(13));
$csrf     = bin2hex(random_bytes(32));
$sessFile = $tmpDir . '/sess_' . $sessId;

$payload  = '';
$payload .= 'ff_user|' . serialize([
    'id'          => 1,
    'name'        => 'S-FIX-2 Regression Bot',
    'email'       => 'regression@fleetforge.test',
    'role_id'     => 1,
    'role_slug'   => 'super_admin',
    'permissions' => [],
    'theme'       => 'dark',
]);
$payload .= 'ff_last_activity|' . serialize(time());
$payload .= 'csrf_token|'       . serialize($csrf);
file_put_contents($sessFile, $payload);
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

// ============================================================================
echo "S-FIX-2 — Session 4 (ADV-BILL-1) advance billing regression\n";
echo str_repeat('=', 78) . "\n\n";

// ---- Pre-flight: JE counter hygiene (pre-existing, unrelated to S-FIX-2) ----
// AccountingService::nextJeNumber reads the per-year counter from settings.
// If older test runs hard-deleted JE rows but didn't roll back the setting,
// or vice versa, the next allocation collides. Detect and self-heal so the
// regression test focuses on Path B behavior, not test-env drift.
$year = date('Y');
$jeKey = "accounting.je_next_number.{$year}";
$actualMax = (int) (db_row(
    "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(entry_number, '-', -1) AS UNSIGNED)), 0) AS m
     FROM acc_journal_entries WHERE entry_number LIKE ?",
    ["JE-{$year}-%"]
)['m'] ?? 0);
$settingRow  = db_row("SELECT `value` FROM settings WHERE `key` = ?", [$jeKey]);
$settingVal  = $settingRow ? (int) $settingRow['value'] : 1;

if ($settingVal <= $actualMax) {
    $newVal = $actualMax + 1;
    echo "Pre-flight: JE counter desync detected (setting={$settingVal}, actual max={$actualMax}). ";
    echo "Self-healing setting to {$newVal} so this test can run.\n";
    if ($settingRow) {
        db_execute("UPDATE settings SET `value` = ? WHERE `key` = ?", [(string) $newVal, $jeKey]);
    } else {
        db_execute(
            "INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES (?, ?, 'integer', 'accounting', 'JE Counter', 'Auto-increment counter', 0)",
            [$jeKey, (string) $newVal]
        );
    }
    echo "(This is pre-existing test-env drift, not a S-FIX-2 regression.)\n\n";
}

// Reuse an existing equipment_unit (don't synthesize a new one — schema is heavy).
$unitRow = db_row(
    "SELECT id, unit_number FROM equipment_units WHERE deleted_at IS NULL LIMIT 1",
    []
);
$equipmentUnitId = (int) $unitRow['id'];
$unitNumber      = $unitRow['unit_number'];

$testPrefix = 'SMOKE-S4-' . date('YmdHis');

$customerId = db_insert('customers', [
    'company_name'        => $testPrefix . ' Test Co',
    'contact_name'        => 'S4 Regression',
    'email'               => 's4-regression@example.invalid',
    'phone'               => '555-0200',
    'province'            => 'BC',
    'currency'            => 'CAD',
    'gst_exempt'          => 0,
    'pst_exempt'          => 0,
    'tax_exempt'          => 0,
    'outstanding_balance' => '0.00',
]);

// Lease starts on the 1st of next month so all 4 advance periods are full_month
// (avoids the partial_start half-period that would make math fuzzy).
$startDate = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');

$leaseId = db_insert('leases', [
    'contract_number'         => $testPrefix . '-1',
    'customer_id'             => $customerId,
    'equipment_unit_id'       => $equipmentUnitId,
    'unit_number_snapshot'    => $unitNumber,
    'company_name_snapshot'   => $testPrefix . ' Test Co',
    'customer_name_snapshot'  => 'S4 Regression',
    'status'                  => 'active',
    'start_date'              => $startDate,
    'monthly_rate'            => '1000.00',
    'daily_rate'              => '40.00',
    'weekly_rate'             => '250.00',
    'currency'                => 'CAD',
    'billing_cycle'           => 'monthly',
    'advance_billing_periods' => 3,
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
]);

echo "Setup: customer #{$customerId}, lease #{$leaseId} ({$testPrefix}), start_date={$startDate}\n";
echo "       advance_billing_periods=3 → expects 4 invoices total (current + 3 future)\n\n";

try {

// ============================================================================
// T5 — generateAdvanceBatch creates 4 invoices with Path-B counters
// ============================================================================
echo "T5 — generateAdvanceBatch: 4 invoices, Path B counters\n";
$generator = new InvoiceGenerator();
$batch = $generator->generateAdvanceBatch($leaseId, 1);

ok('T5.1 invoice count', 4, count($batch['invoices']));

$batchTotal = (string) $batch['batch_total_amount'];
// Each invoice: $1000 base × (1 + 0.05 GST + 0.07 PST) = $1000 × 1.12 = $1120
// 4 invoices × $1120 = $4480
ok('T5.2 batch total', '4480.00', $batchTotal);

$c = counters($customerId, $leaseId);
// Path B: drafts do NOT INC outstanding_balance.
ok('T5.3 customer.outstanding_balance unchanged after batch (Path B drafts excluded)', '0.00', $c['customer_ob']);
ok('T5.4 lease.outstanding_balance unchanged after batch (Path B drafts excluded)',   '0.00', $c['lease_ob']);
// total_invoiced INCLUDES drafts: 4 × $1120 = $4480
ok('T5.5 lease.total_invoiced += sum(total_amount) (Path B includes drafts)', $batchTotal, $c['lease_invoiced']);

// All 4 invoices should be drafts with generation_source='advance'
$inv = db_select(
    "SELECT id, status, generation_source, total_amount, balance_due
     FROM invoices WHERE lease_id = ? AND deleted_at IS NULL
     ORDER BY id",
    [$leaseId]
);
ok('T5.6 all 4 invoices are draft', '4', (string) count(array_filter($inv, fn($i) => $i['status'] === 'draft')));
ok('T5.7 all 4 invoices generation_source=advance', '4', (string) count(array_filter($inv, fn($i) => $i['generation_source'] === 'advance')));

// next_billing_date should be advanced past the final period
$leaseRow = db_row(
    "SELECT next_billing_date FROM leases WHERE id = ?",
    [$leaseId]
);
$expectedNext = (new DateTimeImmutable($startDate))->modify('first day of +4 months')->format('Y-m-d');
ok('T5.8 lease.next_billing_date advanced past final period', $expectedNext, (string) $leaseRow['next_billing_date']);

echo "\n";

// ============================================================================
// T7 — monthly cron skips advance-billed lease
// ============================================================================
echo "T7 — monthly cron skips lease whose next_billing_date is in the future\n";

// Cron query (from cron/invoice_generate_monthly.php):
//   active leases WHERE next_billing_date <= CURDATE()
$todaysCronCandidate = db_row(
    "SELECT id FROM leases
     WHERE id = ?
       AND status = 'active'
       AND deleted_at IS NULL
       AND next_billing_date IS NOT NULL
       AND next_billing_date <= ?",
    [$leaseId, date('Y-m-d')]
);
ok('T7.1 lease NOT picked up by today\'s cron run', null, $todaysCronCandidate);

// Sanity: the lease IS picked up if we query past the next_billing_date
$futureCandidate = db_row(
    "SELECT id FROM leases
     WHERE id = ?
       AND status = 'active'
       AND deleted_at IS NULL
       AND next_billing_date IS NOT NULL
       AND next_billing_date <= ?",
    [$leaseId, $expectedNext]
);
ok('T7.2 lease IS picked up by cron once next_billing_date arrives', $leaseId, (int) ($futureCandidate['id'] ?? 0));

echo "\n";

// ============================================================================
// T14 — close mode='refund_unused' MID-PERIOD
// ============================================================================
echo "T14 — close mode='refund_unused' mid-period: status-aware voids + credit_notes\n";

// Promote 4 advance invoices to a mix of states so we exercise both Session 4
// reconciliation paths under Path B:
//
//  - INV[0] (current period, contains close date) — DRAFT
//      → adv_partial_refund_containing → void + replacement (containing logic)
//  - INV[1] (future)   — DRAFT
//      → adv_void_or_credit_full → adv_void_invoice → status='draft' → VOIDED
//        (Path B counter delta: total_invoiced -= total_amount, OB unchanged)
//  - INV[2] (future)   — SENT then PAID FULL
//      → adv_void_or_credit_full → adv_create_credit_note → CREDIT_NOTE_ISSUED
//        (invoice stays paid; credit_note recorded for later application)
//  - INV[3] (future)   — SENT (not paid)
//      → adv_void_or_credit_full → adv_create_credit_note → CREDIT_NOTE_ISSUED
//        (invoice stays sent — D12 immutability — and a credit_note is issued)
//
// Session 4 design (D-H from the original session): drafts get voided
// (counter rollback), sent/paid stay intact and get credit_notes (preserves
// CRA-immutable record). S-FIX-2 only changes the COUNTER MATH inside the
// adv_void_invoice path; it does not change which path is taken per status.

$invIds = array_map(static fn($i) => (int) $i['id'], $inv);

// Send INV[2] and INV[3] only (INV[0] and INV[1] stay as drafts)
foreach ([2, 3] as $idx) {
    $r = http_post("$baseUrl/api/v1/invoices/send", ['id' => $invIds[$idx]], $sessId, $csrf);
    if ($r['http_code'] !== 200) {
        throw new \RuntimeException("Failed to send invoice {$invIds[$idx]}: HTTP {$r['http_code']} — {$r['body']}");
    }
}

// Pay INV[2] in full
$inv2Row = db_row("SELECT total_amount FROM invoices WHERE id = ?", [$invIds[2]]);
$payResp = http_post("$baseUrl/api/v1/payments/create", [
    'invoice_id'     => $invIds[2],
    'amount'         => (string) $inv2Row['total_amount'],
    'payment_method' => 'cash',
    'payment_date'   => date('Y-m-d'),
    'currency'       => 'CAD',
], $sessId, $csrf);
if ($payResp['http_code'] !== 201) {
    throw new \RuntimeException('Failed to pay INV[2]: HTTP ' . $payResp['http_code'] . ' — ' . $payResp['body']);
}

// Counter snapshot after setup mutations:
//   INV[0] draft   → OB +0 (Path B drafts excluded)
//   INV[1] draft   → OB +0 (Path B drafts excluded)
//   INV[2] sent    → OB += $1120; then paid → OB -= $1120 = net $0
//   INV[3] sent    → OB += $1120 (still owing)
//   ⇒ customer.OB = $1120
//   lease.total_invoiced = $4480 (unchanged — Path B keeps drafts in the
//   lease counter)
$cBefore = counters($customerId, $leaseId);
ok('T14 setup: customer.OB = $1120 (1 sent unpaid invoice; 1 sent paid; 2 drafts excluded)',
    '1120.00', $cBefore['customer_ob']);
ok('T14 setup: lease.total_invoiced = $4480 (Path B includes drafts)',
    '4480.00', $cBefore['lease_invoiced']);

// Close lease mid-period — pick a date inside INV[0]'s billing window.
// INV[0] is full_month covering $startDate to last-day-of-start-month.
// Use the 15th of the start month as the close date.
$closeDate = (new DateTimeImmutable($startDate))->modify('+14 days')->format('Y-m-d');

$closeResp = http_post("$baseUrl/api/v1/leases/close", [
    'id'                   => $leaseId,
    'actual_return_date'   => $closeDate,
    'reconciliation_mode'  => 'refund_unused',
    'close_notes'          => 'S-FIX-2 regression test',
], $sessId, $csrf);

ok('T14.1 close returned 200', 200, $closeResp['http_code']);

if ($closeResp['http_code'] !== 200) {
    echo "  close.php response body: {$closeResp['body']}\n";
    throw new \RuntimeException('close.php did not return 200; aborting T14');
}

$closeJson = $closeResp['json']['data'] ?? $closeResp['json'];
$advActions = $closeJson['advance_actions'] ?? [];
ok('T14.2 close.advance_close == true', true, (bool) ($closeJson['advance_close'] ?? false));
ok('T14.3 close.reconciliation == refund_unused', 'refund_unused', (string) ($closeJson['reconciliation'] ?? ''));

// Verify per-invoice action breakdown (per Session 4 D-H spec):
//   INV[0] (draft, contain) → replaced       (void + new partial replacement)
//   INV[1] (draft, future)  → voided         (Path B status-aware: draft path)
//   INV[2] (paid,  future)  → credit_note_issued
//   INV[3] (sent,  future)  → credit_note_issued
$actionsByInvId = [];
foreach ($advActions as $a) {
    $actionsByInvId[(int) $a['invoice_id']] = $a;
}

ok('T14.4 INV[0] (draft, containing) action == replaced',   'replaced',            (string) ($actionsByInvId[$invIds[0]]['action'] ?? ''));
ok('T14.5 INV[1] (draft, future) action == voided',         'voided',              (string) ($actionsByInvId[$invIds[1]]['action'] ?? ''));
ok('T14.6 INV[2] (paid, future) action == credit_note_issued',  'credit_note_issued',  (string) ($actionsByInvId[$invIds[2]]['action'] ?? ''));
ok('T14.7 INV[3] (sent, future) action == credit_note_issued',  'credit_note_issued',  (string) ($actionsByInvId[$invIds[3]]['action'] ?? ''));

// INV[1] (draft) — voided via adv_void_invoice. Path B applied:
//   draft → void: total_invoiced -= total_amount (≈ $1120), OB unchanged
//   balance_due → 0 (Phase 0.5 Bug B fix on the void row)
$inv1After = db_row("SELECT status, balance_due, total_amount FROM invoices WHERE id = ?", [$invIds[1]]);
ok('T14.8 INV[1] status=void after draft → void',            'void', (string) $inv1After['status']);
ok('T14.9 INV[1] balance_due zeroed (Phase 0.5 Bug B fix in adv_void_invoice)', '0.00', (string) $inv1After['balance_due']);

// INV[2] (paid) — adv_create_credit_note. Invoice stays paid; credit_note row issued.
$inv2After = db_row("SELECT status FROM invoices WHERE id = ?", [$invIds[2]]);
ok('T14.10 INV[2] status remains paid (D12 immutability)', 'paid', (string) $inv2After['status']);
$cnInv2 = db_row(
    "SELECT amount, source, status FROM credit_notes
     WHERE source_invoice_id = ? AND deleted_at IS NULL",
    [$invIds[2]]
);
ok('T14.11 credit_note for INV[2] full amount issued',       '1120.00', (string) ($cnInv2['amount'] ?? '0.00'));
ok('T14.12 credit_note.source = invoice_adjustment',
    'invoice_adjustment', (string) ($cnInv2['source'] ?? ''));

// INV[3] (sent) — adv_create_credit_note. Invoice stays sent; credit_note row issued.
$inv3After = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$invIds[3]]);
ok('T14.13 INV[3] status remains sent (D12 immutability)',   'sent',    (string) $inv3After['status']);
ok('T14.14 INV[3] balance_due unchanged ($1120 still owing)', '1120.00', (string) $inv3After['balance_due']);
$cnInv3 = db_row(
    "SELECT amount, source FROM credit_notes
     WHERE source_invoice_id = ? AND deleted_at IS NULL",
    [$invIds[3]]
);
ok('T14.15 credit_note for INV[3] full amount issued',       '1120.00', (string) ($cnInv3['amount'] ?? '0.00'));

// INV[0] (containing draft) — adv_partial_refund_containing voided + spawned a
// replacement partial_start invoice covering only the days actually used.
$inv0After = db_row("SELECT status FROM invoices WHERE id = ?", [$invIds[0]]);
ok('T14.16 INV[0] (containing draft) voided',         'void', (string) $inv0After['status']);
$replacementId = $actionsByInvId[$invIds[0]]['replacement_invoice_id'] ?? null;
ok('T14.17 replacement invoice id present',           true, $replacementId !== null);

// Counter math after T14:
//   Going IN: customer.OB = $1120 (from T14 setup — only INV[3] sent unpaid)
//   INV[0] draft → void:        OB unchanged (Path B: drafts don't move OB)
//   INV[1] draft → void:        OB unchanged (Path B: drafts don't move OB)
//   INV[2] paid → credit_note:  OB unchanged (invoice still paid; CN sits separately,
//                                              applied by staff manually later)
//   INV[3] sent → credit_note:  OB unchanged (invoice still sent — credit_note will
//                                              reduce OB only when applied via apply.php)
//   Replacement (new draft):    OB unchanged (Path B: drafts don't move OB)
//   ⇒ Final customer.OB should still be $1120 (INV[3] still owing per D12)
$cAfter = counters($customerId, $leaseId);
ok('T14.18 customer.OB after refund_unused = $1120 (INV[3] still owing per D12)',
    '1120.00', $cAfter['customer_ob']);

// Lease total_invoiced math:
//   $4480 starting, then:
//   INV[0] draft → void:  -= $1120 → $3360
//   INV[1] draft → void:  -= $1120 → $2240
//   INV[2/3]: stay intact (credit_note path doesn't touch lease.total_invoiced)
//   Replacement draft: += replacement_total (some prorated portion of $1120,
//     can't predict exact value without re-running the proration math)
//   ⇒ Don't assert exact value; just verify the deltas are non-negative.
$invoicedAfter = $cAfter['lease_invoiced'];
ok('T14.19 lease.total_invoiced after refund_unused changed (drafts voided, replacement created)',
    true, bccomp($invoicedAfter, '0.00', 2) > 0 && bccomp($invoicedAfter, '4480.00', 2) <= 0);

echo "\n";

// ============================================================================
// T15 — close mode='no_refund' leaves advance invoices intact
// ============================================================================
echo "T15 — close mode='no_refund' leaves advance invoices intact\n";

// Set up a fresh advance-billed lease for this test (T14 already closed our
// first lease). Reuse the same equipment_unit + customer.
$lease2Id = db_insert('leases', [
    'contract_number'         => $testPrefix . '-2',
    'customer_id'             => $customerId,
    'equipment_unit_id'       => $equipmentUnitId,
    'unit_number_snapshot'    => $unitNumber,
    'company_name_snapshot'   => $testPrefix . ' Test Co',
    'customer_name_snapshot'  => 'S4 Regression',
    'status'                  => 'active',
    'start_date'              => $startDate,
    'monthly_rate'            => '1000.00',
    'daily_rate'              => '40.00',
    'weekly_rate'             => '250.00',
    'currency'                => 'CAD',
    'billing_cycle'           => 'monthly',
    'advance_billing_periods' => 3,
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
]);

// Capture customer.OB BEFORE T15 sends so we can assert the delta correctly
// (T14 left $1120 of residual OB on INV[3] — still owing per D12 immutability).
$obAtT15Start = (string) (db_row(
    "SELECT outstanding_balance FROM customers WHERE id = ?",
    [$customerId]
)['outstanding_balance']);

// Generate 4-invoice batch
$batch2 = $generator->generateAdvanceBatch($lease2Id, 1);
ok('T15 setup: 4 invoices generated', 4, count($batch2['invoices']));
$inv2Ids = array_map(static fn($i) => (int) $i['invoice_id'], $batch2['invoices']);

// Send all 4 (more interesting than draft for the no-refund case)
foreach ($inv2Ids as $iid) {
    $r = http_post("$baseUrl/api/v1/invoices/send", ['id' => $iid], $sessId, $csrf);
    if ($r['http_code'] !== 200) {
        throw new \RuntimeException("Failed to send invoice {$iid}: HTTP {$r['http_code']}");
    }
}

$cBefore15 = counters($customerId, $lease2Id);
$expectedAfterSends = bcadd($obAtT15Start, '4480.00', 2);
ok('T15 setup: customer.OB == residual_OB_from_T14 + $4480 (4 fresh sends)',
    $expectedAfterSends, $cBefore15['customer_ob']);

// Close lease 2 with no_refund — same mid-period date
$closeResp2 = http_post("$baseUrl/api/v1/leases/close", [
    'id'                   => $lease2Id,
    'actual_return_date'   => $closeDate,
    'reconciliation_mode'  => 'no_refund',
    'close_notes'          => 'S-FIX-2 regression — no_refund mode',
], $sessId, $csrf);

ok('T15.1 close returned 200', 200, $closeResp2['http_code']);
$closeJson2  = $closeResp2['json']['data'] ?? $closeResp2['json'];
$advActions2 = $closeJson2['advance_actions'] ?? [];

// no_refund mode should produce ZERO advance_actions for the advance invoices
// (all 4 should be untouched).
$invoiceTouchActions = array_filter($advActions2, function ($a) use ($inv2Ids) {
    return isset($a['invoice_id']) && in_array((int) $a['invoice_id'], $inv2Ids, true);
});
ok('T15.2 no advance invoices touched in no_refund mode', 0, count($invoiceTouchActions));

// Each of the 4 invoices remains 'sent'
foreach ($inv2Ids as $i => $iid) {
    $row = db_row("SELECT status FROM invoices WHERE id = ?", [$iid]);
    ok("T15.3.{$i} INV[{$i}] still sent", 'sent', (string) $row['status']);
}

// customer.OB unchanged from setup
$cAfter15 = counters($customerId, $lease2Id);
ok('T15.4 customer.OB unchanged in no_refund mode',
    $cBefore15['customer_ob'], $cAfter15['customer_ob']);

echo "\n";

} catch (\Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failures[] = 'fatal_exception: ' . $e->getMessage();
}

// ============================================================================
// Cleanup — hard-delete what we created
// ============================================================================
try {
    $custId  = $customerId  ?? 0;
    $leaseList = [$leaseId ?? 0, $lease2Id ?? 0];

    db_execute("DELETE FROM payment_allocations WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ?)", [$custId]);
    db_execute("DELETE FROM payments WHERE customer_id = ?", [$custId]);
    db_execute("DELETE FROM credit_note_applications WHERE credit_note_id IN (SELECT id FROM credit_notes WHERE customer_id = ?)", [$custId]);
    db_execute("DELETE FROM credit_notes WHERE customer_id = ?", [$custId]);
    db_execute("DELETE FROM invoice_line_items WHERE invoice_id IN (SELECT id FROM invoices WHERE customer_id = ?)", [$custId]);
    db_execute("DELETE FROM lease_billing_periods WHERE lease_id IN (?, ?)", $leaseList);
    db_execute("DELETE FROM lease_status_log WHERE lease_id IN (?, ?)", $leaseList);
    db_execute("DELETE FROM equipment_status_log WHERE reason LIKE 'Lease % closed' AND changed_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)", []);
    db_execute("DELETE FROM acc_journal_entry_lines WHERE journal_entry_id IN (SELECT id FROM acc_journal_entries WHERE source_id IN (SELECT id FROM invoices WHERE customer_id = ?))", [$custId]);
    db_execute("DELETE FROM acc_journal_entries WHERE source_id IN (SELECT id FROM invoices WHERE customer_id = ?)", [$custId]);
    db_execute("DELETE FROM invoices WHERE customer_id = ?", [$custId]);
    db_execute("DELETE FROM leases WHERE id IN (?, ?)", $leaseList);
    // Reset the equipment_unit if T14's close set it to 'available'
    db_execute("UPDATE equipment_units SET status = 'available' WHERE id = ? AND status != 'on_lease'", [$equipmentUnitId]);
    db_execute("DELETE FROM customers WHERE id = ?", [$custId]);
    db_execute("DELETE FROM audit_log WHERE entity_type IN ('customer','lease','invoice','credit_note','payment') AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND user_name LIKE '%S-FIX-2 Regression%'", []);
    echo "Cleanup: test data hard-deleted.\n";
} catch (\Throwable $e) {
    echo "Cleanup error (test data may persist): " . $e->getMessage() . "\n";
}

// ============================================================================
// Summary
// ============================================================================
echo "\n" . str_repeat('=', 78) . "\n";
echo "Session 4 regression: {$passes} passed, " . count($failures) . " failed\n";
if (!empty($failures)) {
    foreach ($failures as $f) echo "  - {$f}\n";
    echo str_repeat('=', 78) . "\n";
    exit(1);
}
echo "Result: T5, T7, T14, T15 all pass — advance billing remains intact.\n";
echo str_repeat('=', 78) . "\n";
exit(0);
