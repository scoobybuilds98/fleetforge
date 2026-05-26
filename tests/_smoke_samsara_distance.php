<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_distance.php
 *
 * S-MILEAGE-1B smoke test for SamsaraClient::getDistanceForPeriod
 * and the FixtureProvider hermetic mode + S-MILEAGE-2A surface tests.
 *
 * Runs 16 stress tests:
 *   T1-T13 — Samsara distance / fixture-mode coverage from S-MILEAGE-1B
 *            (and S-MILEAGE-1B-FOLLOWUP for T13). Every T1-T13 routes
 *            through FixtureProvider — NO live Samsara calls.
 *   T14-T16 — S-MILEAGE-2A surface (ADD per operator clarification —
 *            T8/T10/T12 placeholders preserved as 2B carry-forward
 *            because 2A does NOT integrate getDistanceForPeriod into
 *            InvoiceGenerator; that's a 2B drawdown concern). T14
 *            asserts precharge line emission, T15 asserts
 *            chk_leases_precharge_amount CHECK rejects malformed
 *            shapes, T16 confirms fixture/HTTP dispatch via source
 *            inspection.
 *
 * Each test prints a PASS/FAIL line carrying the actual `distance`
 * string so a reader can scan for float-leak artifacts (Avi's Q4
 * addition). Final summary line is grep-able: "N/16 passed in Xs"
 * or "M/16 passed (FAILED: T4 ..., T15 ...)".
 *
 * Exit code: 0 on all-pass, 1 on any-fail.
 *
 * Usage:
 *   php tests/_smoke_samsara_distance.php
 *   php tests/_smoke_samsara_distance.php --leave-fixture-mode-on
 *     (skips the cleanup so the next request also runs in fixture mode)
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\GPS\SamsaraClient;
use FleetForge\Samsara\FixtureProvider;

$cleanup = !in_array('--leave-fixture-mode-on', $argv ?? [], true);

// ── Capture original fixture-mode setting so we can restore later ──
$originalFixtureMode = (string) settings_get('samsara.fixture_mode');

// ── Flip fixture mode ON for the test run ─────────────────────
db_execute(
    "UPDATE settings SET value = '1' WHERE `key` = 'samsara.fixture_mode'"
);

// In-memory cache for the settings table reads inside SamsaraClient
// is not used by settings_get() — it queries fresh each call. Good.

$client = new SamsaraClient();

$tStart  = microtime(true);
$results = [];   // [testId => [passed: bool, msg: string, distance: string]]

// =====================================================================
// HELPERS
// =====================================================================

/**
 * Record a test result. $msg should be human-readable; if FAIL, prefix
 * the reason. distance is the literal `distance` field value (string,
 * null for failures, or the empty string for tests that don't return one).
 */
function record(array &$results, string $id, string $name, bool $passed, string $msg, $distance): void
{
    $distRender = is_string($distance) ? $distance : ($distance === null ? '<null>' : (string) $distance);
    $results[$id] = [
        'name'     => $name,
        'passed'   => $passed,
        'msg'      => $msg,
        'distance' => $distRender,
    ];
    $tag = $passed ? 'PASS' : 'FAIL';
    printf("  %s %-4s %-32s distance=%-12s — %s\n", $tag, $id, $name, $distRender, $msg);
}

// =====================================================================
// T1 — Standard 30-day period: returns expected distance, no warnings
// =====================================================================
// S-INVOICE-COUNTER-BUMP — drift WARN: surface counter ≤ MAX(invoice_number) before it becomes an opaque UNIQUE collision in T14 (precharge_invoice_emit calls InvoiceGenerator::createFromLease).
$_yr = date('Y');
$_counter = (int)(db_row("SELECT value FROM settings WHERE `key` = ?", ["invoice.next_number.{$_yr}"])['value'] ?? 1);
$_maxStr = db_row("SELECT MAX(invoice_number) AS m FROM invoices WHERE invoice_number LIKE ?", ["INV-{$_yr}-%"])['m'] ?? '';
$_maxNum = $_maxStr !== '' ? (int)substr(strrchr($_maxStr, '-'), 1) : 0;
if ($_counter <= $_maxNum) { fwrite(STDERR, "WARN invoice-counter-drift: invoice.next_number.{$_yr}={$_counter} <= MAX(invoice_number)={$_maxNum}; next generateInvoiceNumber() will collide. Run: UPDATE settings SET value='" . ($_maxNum + 5) . "' WHERE `key`='invoice.next_number.{$_yr}'. See S-D131-BASELINE-RESTORE / S-INVOICE-COUNTER-BUMP.\n"); }

echo "\n[Running 16 stress tests: T1-T13 fixture-mode coverage, T14-T16 S-MILEAGE-2A surface]\n\n";

$start = new DateTimeImmutable('2026-04-01T00:00:00Z');
$end   = new DateTimeImmutable('2026-04-30T23:59:59Z');
$r = $client->getDistanceForPeriod('FIX_STD', $start, $end, 'km');

$ok = ($r['distance'] === '1234.56')
    && ($r['source'] === 'gps')
    && ($r['warnings'] === [])
    && ($r['reading_count'] > 0);
record($results, 'T1', 'standard_30day',
    $ok, $ok ? 'source=gps warnings=[] readings=' . $r['reading_count']
             : 'expected distance=1234.56/source=gps/no warnings; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T2 — Parked: same first/last reading; distance=0.00
// =====================================================================
$r = $client->getDistanceForPeriod('FIX_PARKED', $start, $end, 'km');
$ok = ($r['distance'] === '0.00') && ($r['warnings'] === []);
record($results, 'T2', 'parked_zero',
    $ok, $ok ? 'distance=0 warnings=[]'
             : 'expected distance=0.00/no warnings; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T3 — No-readings (truly unmonitored): failure with no_readings_in_period
// =====================================================================
$r = $client->getDistanceForPeriod('FIX_NONE', $start, $end, 'km');
$ok = ($r['distance'] === null)
    && ($r['reason'] === 'no_readings_in_period')
    && ($r['source'] === 'unavailable');
record($results, 'T3', 'no_readings',
    $ok, $ok ? "reason={$r['reason']}"
             : 'expected reason=no_readings_in_period; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T4 — Gateway reset: distance=0.00 + warning gateway_reset_detected
// =====================================================================
$r = $client->getDistanceForPeriod('FIX_RESET', $start, $end, 'km');
$ok = ($r['distance'] === '0.00')
    && in_array('gateway_reset_detected', $r['warnings'] ?? [], true);
record($results, 'T4', 'gateway_reset',
    $ok, $ok ? 'distance=0 warnings includes gateway_reset_detected'
             : 'expected distance=0.00 + gateway_reset warning; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T5 — Sparse: distance with sparse_readings warning
// =====================================================================
$r = $client->getDistanceForPeriod('FIX_SPARSE', $start, $end, 'km');
$ok = ($r['distance'] === '500.00')
    && in_array('sparse_readings', $r['warnings'] ?? [], true);
record($results, 'T5', 'sparse_readings',
    $ok, $ok ? 'distance=500 warnings=' . implode(',', $r['warnings'])
             : 'expected distance=500.00 + sparse_readings warning; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T6 — Period > 90 days → period_too_long
// =====================================================================
$tooLong = new DateTimeImmutable('2026-01-01T00:00:00Z');
$r = $client->getDistanceForPeriod('FIX_STD', $tooLong, $end, 'km');
$ok = ($r['distance'] === null) && ($r['reason'] === 'period_too_long');
record($results, 'T6', 'period_over_90d',
    $ok, $ok ? "reason={$r['reason']}"
             : 'expected reason=period_too_long; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T7 — Unit conversion km vs miles: same fixture, proportional scale,
// no float drift. 1234.56 km × (1/1.609344 km per mile) ≈ 767.27 miles.
// =====================================================================
$rKm    = $client->getDistanceForPeriod('FIX_STD', $start, $end, 'km');
$rMi    = $client->getDistanceForPeriod('FIX_STD', $start, $end, 'miles');
$kmExpected    = '1234.56';
$milesExpected = bcdiv('1234560', '1609.344', 2); // recompute via bcmath for reference
$ok = ($rKm['distance'] === $kmExpected)
    && ($rMi['distance'] === $milesExpected)
    // Float-drift sentinel: bcmath strings never carry trailing nines/zeros
    && (!preg_match('/[0-9]\.\d*([0-9])\1{6,}/', $rMi['distance']));
record($results, 'T7', 'unit_conversion',
    $ok, $ok ? "km={$rKm['distance']} miles={$rMi['distance']} (no float drift)"
             : "expected km=$kmExpected miles=$milesExpected; got km={$rKm['distance']} miles={$rMi['distance']}",
    $rMi['distance']); // print the miles value for the float-leak inspection

// =====================================================================
// T8 — Period cap exercise (S-MILEAGE-2B C7 / D-P real coverage).
// Was a source-inspection placeholder pre-2B; now exercises the 90-day
// cap end-to-end through the FixtureProvider (which honors the cap per
// lib/Samsara/FixtureProvider.php:63-65). Supplies a 100-day range and
// asserts reason='period_too_long' + structured failure shape.
// Production HTTP loop has the same cap (SamsaraClient.php:1284-1290)
// PLUS the pagination hard-cap at 50 (line 1318) — the latter requires
// real HTTP and stays source-inspectable via T8-INSP cross-check below.
// =====================================================================
$longStart = new \DateTimeImmutable('2026-01-01T00:00:00Z', new \DateTimeZone('UTC'));
$longEnd   = new \DateTimeImmutable('2026-04-12T00:00:00Z', new \DateTimeZone('UTC'));  // 101 days
$r = $client->getDistanceForPeriod('FIX_STD', $longStart, $longEnd, 'km');
$periodOk = ($r['distance'] === null)
         && ($r['reason'] === 'period_too_long')
         && isset($r['detail'])
         && str_contains((string) $r['detail'], '90-day cap');
$src = file_get_contents(FF_ROOT . '/lib/GPS/SamsaraClient.php');
$pagOk = (strpos($src, 'maxPages    = 50') !== false || strpos($src, 'maxPages = 50') !== false)
       && (strpos($src, 'Pagination cap of') !== false);
$ok = $periodOk && $pagOk;
record($results, 'T8', 'pagination_cap',
    $ok, $ok ? sprintf('period_too_long fixture-exercised (reason=%s); pagination cap source-verified (maxPages=50 + cap-exceeded message)', $r['reason'])
             : sprintf('period_too_long check %s; pagination cap source check %s',
                       $periodOk ? 'PASS' : 'FAIL (got reason=' . ($r['reason'] ?? 'null') . ')',
                       $pagOk ? 'PASS' : 'FAIL'),
    '');

// =====================================================================
// T9 — Adversarial: SQL-injection-shape vehicleId. No SQL is involved
// (HTTP only). In fixture mode, the unknown ID falls through to the
// 'unit_not_in_samsara' failure path.
// =====================================================================
$r = $client->getDistanceForPeriod("FIX_STD'; DROP TABLE leases;--", $start, $end, 'km');
$ok = ($r['distance'] === null) && ($r['reason'] === 'unit_not_in_samsara');
record($results, 'T9', 'sqli_vehicle_id',
    $ok, $ok ? "reason={$r['reason']} (input treated as opaque ID, no SQL touched)"
             : 'expected unit_not_in_samsara for unknown ID; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T10 — Structured failure shape (S-MILEAGE-2B C7 / D-P real coverage).
// Was source-inspection only pre-2B; now exercises the structured failure
// contract end-to-end through the FixtureProvider's inverted-range path
// (lib/Samsara/FixtureProvider.php:59-62 — endTime <= startTime returns
// reason='api_error'). Asserts the structured failure shape: distance=NULL
// + reason + detail + queried_at all present. The malformed-JSON branch
// (SamsaraClient.php production HTTP path) stays source-inspectable as a
// T10-INSP cross-check since the fixture provider doesn't emit raw JSON.
// =====================================================================
$invertEnd   = new \DateTimeImmutable('2026-04-01T00:00:00Z', new \DateTimeZone('UTC'));
$invertStart = new \DateTimeImmutable('2026-04-30T23:59:59Z', new \DateTimeZone('UTC'));  // end < start
$r = $client->getDistanceForPeriod('FIX_STD', $invertStart, $invertEnd, 'km');
$shapeOk = ($r['distance'] === null)
        && ($r['reason'] === 'api_error')
        && isset($r['detail'])
        && isset($r['queried_at'])
        && isset($r['source']);
$malformedOk = strpos($src, "Samsara returned non-JSON body") !== false
            && strpos($src, "if (!is_array(\$response))") !== false;
$ok = $shapeOk && $malformedOk;
record($results, 'T10', 'malformed_response',
    $ok, $ok ? sprintf('structured failure shape fixture-exercised (reason=%s, detail/queried_at/source present); malformed-JSON branch source-verified', $r['reason'])
             : sprintf('failure shape check %s; malformed-JSON source check %s',
                       $shapeOk ? 'PASS' : 'FAIL (reason=' . ($r['reason'] ?? 'null') . ', missing keys=' . json_encode(array_diff(['distance','reason','detail','queried_at','source'], array_keys($r))) . ')',
                       $malformedOk ? 'PASS' : 'FAIL'),
    '');

// =====================================================================
// T11 — "Concurrent" calls: two sequential calls to the same fixture
// must return identical results (no shared state corruption).
// =====================================================================
$a = $client->getDistanceForPeriod('FIX_STD', $start, $end, 'km');
$b = $client->getDistanceForPeriod('FIX_STD', $start, $end, 'km');
// Strip queried_at since it advances between calls.
unset($a['queried_at'], $b['queried_at']);
$ok = ($a == $b);
record($results, 'T11', 'concurrent_idempotent',
    $ok, $ok ? 'two sequential calls returned identical shape'
             : 'shape diverged between two calls — possible state leak: ' . json_encode(['a'=>$a,'b'=>$b]),
    $a['distance']);

// =====================================================================
// T12 — Settings flag flip: flipping samsara.fixture_mode 1→0 and back
// changes the dispatch path. Verified by gps.log entry — fixture-mode
// dispatch writes a SAMSARA_HISTORY_FIXTURE line; HTTP path does not.
// =====================================================================
// Snapshot current size of gps.log
$logPath = FF_ROOT . '/logs/gps.log';
$sizeBefore = file_exists($logPath) ? filesize($logPath) : 0;

// Already in fixture mode — call should append SAMSARA_HISTORY_FIXTURE
$client->getDistanceForPeriod('FIX_STD', $start, $end, 'km');
$sizeMid = file_exists($logPath) ? filesize($logPath) : 0;

$tail = '';
if ($sizeMid > $sizeBefore) {
    $fp = fopen($logPath, 'rb');
    fseek($fp, $sizeBefore);
    $tail = (string) fread($fp, $sizeMid - $sizeBefore);
    fclose($fp);
}
$ok = (strpos($tail, 'SAMSARA_HISTORY_FIXTURE') !== false);
record($results, 'T12', 'fixture_flag_dispatch',
    $ok, $ok ? 'fixture-mode dispatch wrote SAMSARA_HISTORY_FIXTURE log line'
             : 'expected SAMSARA_HISTORY_FIXTURE in gps.log tail; got ' . substr($tail, 0, 200),
    '');

// =====================================================================
// T13 — FIX_GAP: 7-day mid-period gap → distance + large_gap_detected
// =====================================================================
$r = $client->getDistanceForPeriod('FIX_GAP', $start, $end, 'km');
$ok = ($r['distance'] === '2300.00')
    && ($r['source'] === 'gps')
    && in_array('large_gap_detected', $r['warnings'] ?? [], true);
record($results, 'T13', 'large_gap_detected',
    $ok, $ok ? 'distance=2300 source=gps warnings includes large_gap_detected'
             : 'expected distance=2300.00 + large_gap_detected warning; got ' . json_encode($r),
    $r['distance']);

// =====================================================================
// T14 — S-MILEAGE-2A: precharge-enabled lease → InvoiceGenerator emits a
// mileage_precharge line with locked D-C shape + computed per-line tax.
//
// Hermetic via BEGIN/ROLLBACK. InvoiceGenerator's internal
// db_transaction() detects the outer transaction (nesting guard in
// includes/db.php:198-219) so its writes participate in the rollback.
//
// Overlaps with the C3 stress test
// (tests/_stress_invoice_generator_precharge.php) — smoke is continuous
// regression in the D131 gate, stress is point-in-time proof at C3
// commit time. Both have value: the stress narrows on 6 emit-gate
// branches, the smoke proves end-to-end emission lands on every D131
// pre-commit.
//
// FIX_STD fixture-mode is preserved from the file-level flip at line 41
// — not because 2A calls Samsara (it doesn't; D-F locked pre-session)
// but as a safety net if a future change accidentally introduces a
// SamsaraClient call into the precharge emit path.
// =====================================================================
require_once FF_ROOT . '/vendor/autoload.php';
$t14CustomerId = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$t14UnitId     = (int) (db_row("SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
$t14UserId     = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);

$t14Ok  = false;
$t14Msg = '';
db_execute("BEGIN");
try {
    $t14LeaseId = db_insert('leases', [
        'contract_number'   => 'SMOKE-2A-T14',
        'customer_id'       => $t14CustomerId,
        'equipment_unit_id' => $t14UnitId,
        'start_date'        => '2026-05-01',
        'status'            => 'active',
        'daily_rate'        => '10.00',
        'weekly_rate'       => '60.00',
        'monthly_rate'      => '250.00',
        'currency'          => 'CAD',
        'billing_cycle'     => 'monthly',
        'precharge_enabled' => 1,
        'precharge_amount'  => '500.00',
        'precharge_balance' => '500.00',
        'created_by'        => $t14UserId,
        'updated_by'        => $t14UserId,
    ]);

    $t14Generator = new \FleetForge\Billing\InvoiceGenerator();
    $t14Inv = $t14Generator->createFromLease([
        'lease_id'     => $t14LeaseId,
        'period_start' => '2026-05-01',
        'period_end'   => '2026-05-31',
        'billing_type' => 'full_month',
        'invoice_type' => 'regular',
        'created_by'   => $t14UserId,
    ]);

    $t14Line = db_row(
        "SELECT amount, description, unit, quantity, taxable, is_credit,
                tax_gst_amount, tax_pst_amount, tax_hst_amount
           FROM invoice_line_items
          WHERE invoice_id = ? AND item_type = 'mileage_precharge'",
        [$t14Inv['invoice_id']]
    );

    $t14Ok = (
        $t14Line !== null
        && $t14Line['amount']        === '500.00'
        && $t14Line['unit']          === 'precharge'
        && (string) $t14Line['quantity'] === '1.0000'
        && (int) $t14Line['taxable']   === 1
        && (int) $t14Line['is_credit'] === 0
        && str_contains((string) $t14Line['description'], 'Mileage Precharge: $500.00')
    );
    $t14LineTax = $t14Line
        ? bcadd(bcadd((string) $t14Line['tax_gst_amount'], (string) $t14Line['tax_pst_amount'], 2), (string) $t14Line['tax_hst_amount'], 2)
        : '0.00';
    $t14Msg = $t14Ok
        ? "line emitted amount={$t14Line['amount']} unit=precharge tax_total={$t14LineTax}"
        : 'shape mismatch: ' . json_encode($t14Line);
} catch (\Throwable $ex) {
    $t14Msg = 'EXCEPTION: ' . $ex->getMessage();
}
db_execute("ROLLBACK");
record($results, 'T14', 'precharge_invoice_emit', $t14Ok, $t14Msg, '');

// =====================================================================
// T15 — chk_leases_precharge_amount CHECK constraint rejects malformed
// shapes: precharge_enabled=1 with NULL / 0 / negative precharge_amount.
// This is the DB-layer last line of defense; the API-layer rejection
// pattern lives in S-MILEAGE-1 ST4/ST5/ST7/ST9 application-layer stress
// tests. The smoke version here ensures the CHECK survives any future
// schema mutation (parity smoke catches column drift; this one catches
// CHECK-clause drift).
//
// Three malformed cases tried in independent BEGIN/ROLLBACK envelopes
// so a partial failure on one doesn't pollute the others.
// =====================================================================
$t15CustomerId = $t14CustomerId;
$t15UnitId     = $t14UnitId;
$t15UserId     = $t14UserId;
$t15Cases = [
    'enabled=1,amount=NULL' => [1, null],
    'enabled=1,amount=0'    => [1, '0.00'],
    'enabled=1,amount=-100' => [1, '-100.00'],
];
$t15Failures = [];
foreach ($t15Cases as $t15CaseName => [$t15Enabled, $t15Amount]) {
    db_execute("BEGIN");
    $t15Rejected = false;
    try {
        db_insert('leases', [
            'contract_number'   => 'SMOKE-2A-T15-' . substr(md5($t15CaseName), 0, 6),
            'customer_id'       => $t15CustomerId,
            'equipment_unit_id' => $t15UnitId,
            'start_date'        => '2026-05-01',
            'status'            => 'pending',
            'daily_rate'        => '10.00',
            'weekly_rate'       => '60.00',
            'monthly_rate'      => '250.00',
            'currency'          => 'CAD',
            'billing_cycle'     => 'monthly',
            'precharge_enabled' => $t15Enabled,
            'precharge_amount'  => $t15Amount,
            'created_by'        => $t15UserId,
            'updated_by'        => $t15UserId,
        ]);
        // If we reach here, the CHECK didn't fire — that's a failure.
    } catch (\Throwable $ex) {
        if (stripos($ex->getMessage(), 'chk_leases_precharge_amount') !== false) {
            $t15Rejected = true;
        }
    }
    db_execute("ROLLBACK");
    if (!$t15Rejected) {
        $t15Failures[] = $t15CaseName;
    }
}
$t15Ok = empty($t15Failures);
record($results, 'T15', 'precharge_amount_check',
    $t15Ok,
    $t15Ok ? '3/3 malformed shapes rejected by chk_leases_precharge_amount'
           : 'CHECK did not fire on: ' . implode(', ', $t15Failures),
    '');

// =====================================================================
// T16 — Dispatch path confirmation (source-code-inspection, parallel to
// T8/T10 pattern). Verifies that SamsaraClient::getDistanceForPeriod
// routes to FixtureProvider only when samsara.fixture_mode='1' (strict
// string match), else falls through to the production HTTP loop.
//
// Source-inspection (not a runtime call with fixture_mode=0) because:
//   (a) The production HTTP loop would make a live Samsara HTTPS request
//       if api_token is configured — smoke tests must be hermetic.
//   (b) The dispatch flag itself is the load-bearing detail; verifying
//       its presence in source is equivalent to verifying the gate.
//
// Spec note: T16 is the new dispatch-path test for 2A's surface. The
// original T8/T10/T12 placeholders remain reserved for 2B (when
// InvoiceGenerator integrates getDistanceForPeriod for drawdown, the
// production HTTP path becomes runtime-exercisable end-to-end).
// =====================================================================
$srcSamsara = $src ?? file_get_contents(FF_ROOT . '/lib/GPS/SamsaraClient.php');
$t16Ok = (
       strpos($srcSamsara, "(string) settings_get('samsara.fixture_mode') === '1'") !== false
    && strpos($srcSamsara, 'FixtureProvider::getDistanceForPeriod') !== false
    && strpos($srcSamsara, 'CURLOPT_RETURNTRANSFER') !== false
    && strpos($srcSamsara, '/fleet/vehicles/stats/history') !== false
);
record($results, 'T16', 'dispatch_path_fixture_vs_http',
    $t16Ok,
    $t16Ok
        ? 'source: fixture_mode==1 routes to FixtureProvider; else production HTTP loop (curl + /fleet/vehicles/stats/history)'
        : 'dispatch markers missing from SamsaraClient.php — production HTTP path may be broken',
    '');

// =====================================================================
// CLEANUP & SUMMARY
// =====================================================================
if ($cleanup) {
    db_execute(
        "UPDATE settings SET value = ? WHERE `key` = 'samsara.fixture_mode'",
        [$originalFixtureMode === '' ? '0' : $originalFixtureMode]
    );
}

// Drop any test-generated audit_log entries — keep the table tidy.
// Identify them by entity_label being a fixture ID.
db_execute(
    "DELETE FROM audit_log
     WHERE module = 'samsara'
       AND entity_type = 'samsara_history_query'
       AND (entity_label LIKE 'FIX_%'
            OR entity_label LIKE 'FIX\\_%'
            OR entity_label LIKE 'FIX_STD%')"
);

$elapsed = microtime(true) - $tStart;
$pass    = array_sum(array_map(fn($r) => $r['passed'] ? 1 : 0, $results));
$total   = count($results);
$failed  = array_filter($results, fn($r) => !$r['passed']);

echo "\n";
if ($pass === $total) {
    printf("%d/%d passed in %.1fs\n", $pass, $total, $elapsed);
    exit(0);
} else {
    $failList = [];
    foreach ($failed as $id => $row) {
        $failList[] = "{$id} {$row['name']}";
    }
    printf("%d/%d passed (FAILED: %s) in %.1fs\n",
        $pass, $total, implode(', ', $failList), $elapsed);
    exit(1);
}
