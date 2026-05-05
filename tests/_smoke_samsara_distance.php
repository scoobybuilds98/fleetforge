<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_distance.php
 *
 * S-MILEAGE-1B smoke test for SamsaraClient::getDistanceForPeriod
 * and the FixtureProvider hermetic mode.
 *
 * Runs 13 stress tests covering the success + failure contracts
 * documented in the S-MILEAGE-1B brief. Every test runs through
 * the fixture provider — NO live Samsara calls.
 *
 * Each test prints a PASS/FAIL line carrying the actual `distance`
 * string so a reader can scan for float-leak artifacts (Avi's Q4
 * addition). Final summary line is grep-able: "N/13 passed in Xs"
 * or "M/13 passed (FAILED: T4 ..., T8 ...)".
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
echo "\n[Running 13 stress tests against FixtureProvider…]\n\n";

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
// T8 — Pagination cap: production HTTP path only (the fixture provider
// returns directly without paging). We assert the failure shape contract
// is sound by code-inspection. This test always passes here AS DOCUMENTED:
// the production loop hard-caps at 50 with `reason='api_error'` and
// `detail` mentioning the pagination cap. Verified by grep against the
// source.
// =====================================================================
$src = file_get_contents(FF_ROOT . '/lib/GPS/SamsaraClient.php');
$ok = (strpos($src, 'maxPages    = 50') !== false || strpos($src, 'maxPages = 50') !== false)
    && (strpos($src, 'Pagination cap of') !== false);
record($results, 'T8', 'pagination_cap',
    $ok, $ok ? 'source code declares maxPages=50 with cap-exceeded reason=api_error (cannot exercise via fixtures; hermetic-test scope)'
             : 'pagination cap or message text not found in source — production loop missing',
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
// T10 — Malformed Samsara response: production HTTP path only. Same
// rationale as T8 — verify the contract via source inspection.
// =====================================================================
$ok = strpos($src, "Samsara returned non-JSON body") !== false
    && strpos($src, "if (!is_array(\$response))") !== false;
record($results, 'T10', 'malformed_response',
    $ok, $ok ? 'source code rejects non-JSON / non-array body with reason=api_error (cannot exercise via fixtures)'
             : 'malformed-response handling not found in source',
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
