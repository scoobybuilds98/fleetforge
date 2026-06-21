<?php
declare(strict_types=1);

// ============================================================
// tests/_smoke_gps_mileage_sync.php
//
// Regression smoke for the trailer-aware GPS odometer/mileage
// fixes from the leases audit (CLAUDE_2026-06-19_leases.md):
//
//   L05 — cron/gps_mileage_sync.php was a no-op (filtered on the
//         dead gps_device_id column, never sync'd a single unit)
//         and trailer-blind.
//   L06 — SamsaraClient::getMileageForLease hit the per-vehicle
//         PATH form (`/fleet/vehicles/{id}/stats/history`) which
//         404s in the live API, and mis-parsed the response → null
//         for EVERY unit.
//   L07 — getMileageForLease + getOdometerReading were trailer-
//         blind (OBD-only; trailers expose gpsOdometerMeters under
//         /fleet/trailers).
//
// The fix routes both legacy methods through the already-verified
// entity-aware engines (getEntityStats / getDistanceForPeriod) and
// repoints the cron + api/v1/gps/mileage.php to samsara_vehicle_id
// + samsara_entity_type.
//
// Test tiers:
//   PART A (T1-T5)  — source wiring, always run, hermetic.
//   PART B (T6)     — fixture-mode functional delegation, hermetic.
//   PART C (T7-T9)  — LIVE Samsara reads + a REAL cron subprocess
//                     run against a real trailer unit. Auto-SKIPs
//                     (never fails) when no API key is configured,
//                     fixture mode is forced on, --hermetic is
//                     passed, or no live trailer is reachable.
//
// All seeded rows are committed only for the duration of the real
// cron run and are hard-deleted afterwards (try/finally + a
// register_shutdown_function backstop keyed on a unique contract
// number). The chosen unit's mileage is captured and restored.
//
// Usage:
//   php tests/_smoke_gps_mileage_sync.php            # hermetic + live (if key present)
//   php tests/_smoke_gps_mileage_sync.php --hermetic # skip all live/cron tests
//
// Final line is grep-able: "N/M passed ..." Exit 0 all-pass, 1 on any fail.
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/functions.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\GPS\SamsaraClient;

$hermeticOnly = in_array('--hermetic', $argv ?? [], true);

$results = []; // id => [name, passed|null(skip), msg]

function record(array &$results, string $id, string $name, ?bool $passed, string $msg): void
{
    $results[$id] = ['name' => $name, 'passed' => $passed, 'msg' => $msg];
    $tag = $passed === null ? 'SKIP' : ($passed ? 'PASS' : 'FAIL');
    printf("  %s %-4s %-30s — %s\n", $tag, $id, $name, $msg);
}

$samsaraSrc = file_get_contents(FF_ROOT . '/lib/GPS/SamsaraClient.php');
$cronSrc    = file_get_contents(FF_ROOT . '/cron/gps_mileage_sync.php');
$mileageSrc = file_get_contents(FF_ROOT . '/api/v1/gps/mileage.php');

/** Extract a method body by name (signature → matching close), best-effort. */
function method_body(string $src, string $method): string
{
    $pos = strpos($src, "function {$method}(");
    if ($pos === false) return '';
    // Grab a generous window forward — enough to cover the whole method.
    return substr($src, $pos, 4000);
}

echo "\n[GPS mileage-sync trailer fix smoke — Part A/B hermetic, Part C "
   . ($hermeticOnly ? 'SKIPPED (--hermetic)' : 'live') . "]\n\n";

// =====================================================================
// PART A — SOURCE WIRING
// =====================================================================

// T1 — getMileageForLease: entity-aware + delegates to getDistanceForPeriod
$mfl = method_body($samsaraSrc, 'getMileageForLease');
$t1 = (new ReflectionMethod(SamsaraClient::class, 'getMileageForLease'));
$t1params = $t1->getParameters();
$t1HasEntity = count($t1params) === 4
    && $t1params[3]->getName() === 'entityType'
    && $t1params[3]->isDefaultValueAvailable()
    && $t1params[3]->getDefaultValue() === 'vehicle';
$t1Delegates = strpos($mfl, 'getDistanceForPeriod(') !== false;
$t1NoDeadUrl = strpos($mfl, 'engineTotalIdleMilliseconds') === false
            && strpos($mfl, "/stats/history?types=") === false;
$ok = $t1HasEntity && $t1Delegates && $t1NoDeadUrl;
record($results, 'T1', 'getMileageForLease_wiring', $ok,
    $ok ? '4th param $entityType=vehicle; delegates to getDistanceForPeriod; dead 404 path-form removed'
        : sprintf('entityParam=%s delegates=%s deadUrlGone=%s', var_export($t1HasEntity,true), var_export($t1Delegates,true), var_export($t1NoDeadUrl,true)));

// T2 — getOdometerReading: entity-aware + delegates to getEntityStats
$gor = method_body($samsaraSrc, 'getOdometerReading');
$t2 = (new ReflectionMethod(SamsaraClient::class, 'getOdometerReading'));
$t2params = $t2->getParameters();
$t2HasEntity = count($t2params) === 2
    && $t2params[1]->getName() === 'entityType'
    && $t2params[1]->isDefaultValueAvailable()
    && $t2params[1]->getDefaultValue() === 'vehicle';
$t2Delegates = strpos($gor, 'getEntityStats(') !== false;
// Old broken per-vehicle path form: "/fleet/vehicles/' . urlencode($vehicleId) . '/stats?types=obdOdometerMeters"
$t2NoDeadUrl = strpos($gor, "/stats?types=obdOdometerMeters") === false;
$ok = $t2HasEntity && $t2Delegates && $t2NoDeadUrl;
record($results, 'T2', 'getOdometerReading_wiring', $ok,
    $ok ? '2nd param $entityType=vehicle; delegates to getEntityStats; dead 404 path-form removed'
        : sprintf('entityParam=%s delegates=%s deadUrlGone=%s', var_export($t2HasEntity,true), var_export($t2Delegates,true), var_export($t2NoDeadUrl,true)));

// T3 — cron repointed to samsara_vehicle_id + samsara_entity_type, gate preserved
$t3 = strpos($cronSrc, 'eu.samsara_vehicle_id') !== false
   && strpos($cronSrc, 'eu.samsara_entity_type') !== false
   && strpos($cronSrc, "eu.samsara_vehicle_id != ''") !== false
   && strpos($cronSrc, 'eu.gps_device_id') === false
   && strpos($cronSrc, 'getOdometerReading($vehicleId, $entityType)') !== false
   && strpos($cronSrc, "l.mileage_tracking_mode = 'samsara'") !== false;
record($results, 'T3', 'cron_query_repointed', $t3,
    $t3 ? 'cron keys off samsara_vehicle_id+entity_type, passes $entityType, mileage_tracking_mode gate preserved, gps_device_id gone'
        : 'cron query not fully repointed — inspect cron/gps_mileage_sync.php');

// T4 — mileage.php repointed + passes entityType
$t4 = strpos($mileageSrc, 'eu.samsara_vehicle_id') !== false
   && strpos($mileageSrc, 'eu.samsara_entity_type') !== false
   && strpos($mileageSrc, 'getMileageForLease($vehicleId, $startDate, $endDate, $entityType)') !== false
   && strpos($mileageSrc, 'eu.gps_device_id') === false;
record($results, 'T4', 'mileage_endpoint_repointed', $t4,
    $t4 ? 'mileage.php keys off samsara_vehicle_id+entity_type and passes $entityType; gps_device_id gone'
        : 'mileage.php not fully repointed — inspect api/v1/gps/mileage.php');

// T5 — trailer endpoint markers still present in the delegated engines
$t5 = strpos($samsaraSrc, '/fleet/trailers/stats/history') !== false
   && strpos($samsaraSrc, "entityType === 'trailer'") !== false
   && strpos($samsaraSrc, 'gpsOdometerMeters') !== false
   && strpos($samsaraSrc, '/fleet/vehicles/stats/history') !== false; // fleet form (not path form)
record($results, 'T5', 'engine_endpoints_intact', $t5,
    $t5 ? 'getDistanceForPeriod fleet-form + trailer dispatch + gpsOdometerMeters intact'
        : 'delegated engine endpoints missing — trailer billing path at risk');

// =====================================================================
// PART B — FIXTURE-MODE FUNCTIONAL DELEGATION (hermetic)
// =====================================================================
$origFixture = (string) settings_get('samsara.fixture_mode');
db_execute("UPDATE settings SET value = '1' WHERE `key` = 'samsara.fixture_mode'");
try {
    $client = new SamsaraClient();
    $s = '2026-04-01';
    $e = '2026-04-30';
    $trailerKm = $client->getMileageForLease('FIX_TRAILER_STD', $s, $e, 'trailer');
    $vehicleKm = $client->getMileageForLease('FIX_STD',         $s, $e);            // default vehicle
    $noneKm    = $client->getMileageForLease('FIX_NONE',        $s, $e, 'trailer'); // null path
    $t6 = (abs((float)$trailerKm - 1234.56) < 0.001)
       && (abs((float)$vehicleKm - 1234.56) < 0.001)
       && ($noneKm === null);
    record($results, 'T6', 'fixture_delegation', $t6,
        $t6 ? "trailer=$trailerKm vehicle=$vehicleKm none=NULL (delegation + entityType plumbing proven hermetically)"
            : "expected 1234.56/1234.56/NULL; got " . var_export($trailerKm,true) . '/' . var_export($vehicleKm,true) . '/' . var_export($noneKm,true));
} catch (\Throwable $ex) {
    record($results, 'T6', 'fixture_delegation', false, 'EXCEPTION: ' . $ex->getMessage());
} finally {
    db_execute("UPDATE settings SET value = ? WHERE `key` = 'samsara.fixture_mode'", [$origFixture === '' ? '0' : $origFixture]);
}

// =====================================================================
// PART C — LIVE + REAL CRON (auto-skip when not runnable)
// =====================================================================
$apiKey       = (string) (settings_get('samsara.api_token') ?: settings_get('gps.samsara_api_key') ?: '');
$fixtureForced = (string) settings_get('samsara.fixture_mode') === '1';
$liveRunnable = !$hermeticOnly && $apiKey !== '' && !$fixtureForced;

if (!$liveRunnable) {
    $why = $hermeticOnly ? '--hermetic flag' : ($apiKey === '' ? 'no Samsara API key configured' : 'fixture_mode forced on');
    record($results, 'T7', 'live_trailer_odometer', null, "skipped ($why)");
    record($results, 'T8', 'live_trailer_distance', null, "skipped ($why)");
    record($results, 'T9', 'real_cron_end_to_end',  null, "skipped ($why)");
} else {
    $live = new SamsaraClient();

    // Pick up to a few free trailers (not on an active lease) with a real
    // live odometer AND no gps_sync row today (so the cron won't dedup-skip).
    $today = date('Y-m-d');
    $cands = db_select(
        "SELECT id, unit_number, samsara_vehicle_id
           FROM equipment_units
          WHERE deleted_at IS NULL AND samsara_entity_type='trailer'
            AND samsara_vehicle_id IS NOT NULL AND samsara_vehicle_id<>''
            AND id NOT IN (SELECT equipment_unit_id FROM leases WHERE status='active' AND deleted_at IS NULL)
          ORDER BY id LIMIT 25", []);

    $chosen = null; $chosenOdo = null;
    foreach ($cands as $u) {
        $hasLog = db_exists('mileage_logs', "equipment_unit_id = ? AND log_type='gps_sync' AND log_date = ?", [$u['id'], $today]);
        if ($hasLog) continue;
        $odo = $live->getOdometerReading((string)$u['samsara_vehicle_id'], 'trailer');
        if ($odo !== null && $odo > 0) { $chosen = $u; $chosenOdo = $odo; break; }
    }

    // T7 — live trailer odometer
    if ($chosen === null) {
        record($results, 'T7', 'live_trailer_odometer', null, 'no reachable free trailer with live odometer');
        record($results, 'T8', 'live_trailer_distance', null, 'no reachable free trailer');
        record($results, 'T9', 'real_cron_end_to_end',  null, 'no reachable free trailer');
    } else {
        record($results, 'T7', 'live_trailer_odometer', true,
            "unit#{$chosen['unit_number']} (samsara_id={$chosen['samsara_vehicle_id']}) live trailer odometer = {$chosenOdo} km (>0)");

        // T8 — live trailer distance over last 30d (parked → 0.0 is valid; null only on no readings)
        $s30 = (new DateTimeImmutable('-30 days'))->format('Y-m-d');
        $e30 = $today;
        $dist = $live->getMileageForLease((string)$chosen['samsara_vehicle_id'], $s30, $e30, 'trailer');
        $t8 = is_float($dist) || $dist === null; // type contract; report value
        // Strengthen: at least confirm it's not throwing and is a sane non-negative number when present
        if (is_float($dist) && $dist < 0) $t8 = false;
        record($results, 'T8', 'live_trailer_distance', $t8,
            $t8 ? 'getMileageForLease(trailer,30d) = ' . var_export($dist, true) . ' km (float|null contract held)'
                : 'unexpected return: ' . var_export($dist, true));

        // T9 — REAL cron subprocess against a seeded active samsara-mode trailer lease
        $unitId   = (int) $chosen['id'];
        $contract = 'SMOKE-GPS-' . substr(md5(uniqid('', true)), 0, 8);
        $custId   = (int) (db_row("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0);
        $userId   = (int) (db_row("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
        $origMileage = db_row("SELECT mileage FROM equipment_units WHERE id = ?", [$unitId])['mileage'] ?? null;

        // Backstop cleanup: even if this process dies, drop any row carrying our
        // unique contract number + restore the unit mileage.
        register_shutdown_function(function () use ($contract, $unitId, $origMileage) {
            try {
                $lid = db_row("SELECT id FROM leases WHERE contract_number = ?", [$contract])['id'] ?? null;
                if ($lid !== null) {
                    db_execute("DELETE FROM mileage_logs WHERE lease_id = ?", [$lid]);
                    db_execute("DELETE FROM leases WHERE id = ?", [$lid]);
                }
                db_execute("UPDATE equipment_units SET mileage = ? WHERE id = ?", [$origMileage, $unitId]);
            } catch (\Throwable) { /* best effort */ }
        });

        $leaseId = null; $t9 = false; $t9Msg = '';
        try {
            if ($custId === 0 || $userId === 0) {
                throw new \RuntimeException('no seed customer/user available');
            }
            $leaseId = db_insert('leases', [
                'contract_number'       => $contract,
                'customer_id'           => $custId,
                'equipment_unit_id'     => $unitId,
                'start_date'            => (new DateTimeImmutable('-5 days'))->format('Y-m-d'),
                'status'                => 'active',
                'currency'              => 'CAD',
                'billing_cycle'         => 'monthly',
                'daily_rate'            => '10.00',
                'weekly_rate'           => '60.00',
                'monthly_rate'          => '250.00',
                'mileage_tracking_mode' => 'samsara',   // the gate under test
                'mileage_unit'          => 'km',
                'created_by'            => $userId,
                'updated_by'            => $userId,
            ]);

            // Run the REAL cron as a subprocess (real schema + live Samsara).
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(FF_ROOT . '/cron/gps_mileage_sync.php') . ' 2>&1';
            $out = []; $rc = 0;
            exec($cmd, $out, $rc);
            $outStr = implode("\n", $out);

            // Assert: clean exit + a gps_sync row landed for OUR unit/lease today
            $row = db_row(
                "SELECT odometer_reading, mileage_unit, lease_id FROM mileage_logs
                  WHERE equipment_unit_id = ? AND log_type='gps_sync' AND log_date = ?
                  ORDER BY id DESC LIMIT 1",
                [$unitId, $today]
            );
            $odoStored = $row ? (int) $row['odometer_reading'] : null;
            // Live odometer may tick a few km between the T7 read and the cron run.
            $within = $odoStored !== null && abs($odoStored - (int)$chosenOdo) <= 50;

            $t9 = ($rc === 0)
               && ($row !== null)
               && ((int) $row['lease_id'] === (int) $leaseId)
               && ($within);
            $t9Msg = $t9
                ? "cron exit=0; mileage_logs gps_sync row written for unit#{$chosen['unit_number']}: odometer_reading={$odoStored} km (live≈{$chosenOdo}), lease_id={$leaseId}"
                : sprintf('rc=%d rowWritten=%s lease_match=%s stored=%s live=%s | out="%s"',
                    $rc, var_export($row !== null, true),
                    var_export($row !== null && (int)$row['lease_id'] === (int)$leaseId, true),
                    var_export($odoStored, true), var_export($chosenOdo, true),
                    substr(str_replace("\n", ' ', $outStr), 0, 240));
        } catch (\Throwable $ex) {
            $t9Msg = 'EXCEPTION: ' . $ex->getMessage();
        } finally {
            // Explicit cleanup (the shutdown backstop is a safety net).
            try {
                if ($leaseId !== null) {
                    db_execute("DELETE FROM mileage_logs WHERE lease_id = ?", [$leaseId]);
                    db_execute("DELETE FROM leases WHERE id = ?", [$leaseId]);
                }
                db_execute("UPDATE equipment_units SET mileage = ? WHERE id = ?", [$origMileage, $unitId]);
            } catch (\Throwable) { /* best effort — shutdown backstop will retry */ }
        }
        record($results, 'T9', 'real_cron_end_to_end', $t9, $t9Msg);
    }
}

// =====================================================================
// SUMMARY
// =====================================================================
echo "\n";
$pass    = 0; $fail = 0; $skip = 0; $failList = [];
foreach ($results as $id => $r) {
    if ($r['passed'] === null) { $skip++; }
    elseif ($r['passed'])      { $pass++; }
    else                       { $fail++; $failList[] = "{$id} {$r['name']}"; }
}
$total = count($results);
if ($fail === 0) {
    printf("%d/%d passed (%d skipped)\n", $pass, $total, $skip);
    exit(0);
}
printf("%d/%d passed, %d failed (%s), %d skipped\n", $pass, $total, $fail, implode(', ', $failList), $skip);
exit(1);
