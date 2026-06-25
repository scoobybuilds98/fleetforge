<?php
declare(strict_types=1);

/**
 * tests/_smoke_samsara_trailer_gps_distance.php — S-SAMSARA-TRAILER-GPS-FALLBACK
 *
 * Unit-tests the GPS-position distance math used when a trailer's
 * gpsOdometerMeters is frozen/missing (observed prod 2026-06-25: many trailers'
 * odometer stopped accumulating ~2025-07-26 while GPS kept reporting, so the
 * odometer path returned 'unit_not_in_samsara' or "thousands shown as hundreds").
 *
 * Covers the two pure helpers behind SamsaraClient::trailerGpsDistance():
 *   haversineMeters()   — great-circle distance.
 *   sumGpsTrackMeters() — chronological track sum with a 15 m jitter floor and a
 *                         160 km/h teleport filter.
 *
 * Both are private static — invoked via reflection (no network, no DB).
 * The live end-to-end magnitude was validated against the real Samsara API
 * (53D1007 = 2766 km/month, 12TR1323 = 884 km/month) during the fix.
 *
 * Run: php tests/_smoke_samsara_trailer_gps_distance.php
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\GPS\SamsaraClient;

$pass = 0; $fail = 0;
function check(string $id, bool $ok, string $msg): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS $id — $msg\n"; }
    else     { $fail++; echo "  FAIL $id — $msg\n"; }
}
function call_priv(string $method, array $args) {
    $m = new ReflectionMethod(SamsaraClient::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

echo "FleetForge — Samsara trailer GPS-distance smoke (S-SAMSARA-TRAILER-GPS-FALLBACK)\n";
echo str_repeat('=', 72) . "\n";

// ── T1: haversine sanity ──────────────────────────────────────────────
$degLat = call_priv('haversineMeters', [49.0, -122.0, 50.0, -122.0]); // 1° lat
$hop    = call_priv('haversineMeters', [49.061183, -122.696189, 49.062000, -122.697000]);
check('T1', $degLat > 110000 && $degLat < 112000 && $hop > 90 && $hop < 130,
    "1° lat=" . round($degLat / 1000, 1) . " km (expect ~111.2), short hop=" . round($hop) . " m (expect ~108)");

// ~1 km north = 0.009° lat (1000 / 111195).
$KM = 0.009;

// ── T2: normal track + jitter floor ───────────────────────────────────
// A→B = ~1 km @ 60 km/h (summed); B→B2 = ~6 m jitter (dropped); B2→C = ~1 km (summed).
$track = [
    0   => [49.000,         -122.0],
    60  => [49.000 + $KM,   -122.0],            // +1 km
    120 => [49.000 + $KM,   -122.00008],        // ~6 m jitter
    180 => [49.000 + 2*$KM, -122.0],            // +1 km from the jitter point
];
$m2 = call_priv('sumGpsTrackMeters', [$track]);
check('T2', $m2 > 1900 && $m2 < 2100,
    "normal+jitter track = " . round($m2) . " m (expect ~2000; <15 m hop excluded)");

// ── T3: teleport (implausible-speed) filter ───────────────────────────
// A→B = ~1 km @ 60 km/h (summed); B→TELE = ~1223 km in 10 s (dropped).
$track3 = [
    0  => [49.000,       -122.0],
    60 => [49.000 + $KM, -122.0],   // +1 km, summed
    70 => [60.000,       -122.0],   // ~1223 km in 10 s → teleport, dropped
];
$m3 = call_priv('sumGpsTrackMeters', [$track3]);
check('T3', $m3 > 950 && $m3 < 1100,
    "track w/ teleport = " . round($m3) . " m (expect ~1000; >160 km/h segment excluded)");

// ── T4: parked trailer (all jitter) → ~0 ──────────────────────────────
$track4 = [
    0   => [49.06118, -122.69618],
    300 => [49.06119, -122.69619],   // ~1 m
    600 => [49.06118, -122.69620],   // ~1 m
];
$m4 = call_priv('sumGpsTrackMeters', [$track4]);
check('T4', $m4 < 15.0,
    "parked (jitter-only) = " . round($m4, 1) . " m (expect ~0; never inflates a parked trailer)");

// ── T5: single point → 0 (no segments) ────────────────────────────────
$m5 = call_priv('sumGpsTrackMeters', [[0 => [49.0, -122.0]]]);
check('T5', $m5 === 0.0, "single point = {$m5} m (expect 0)");

echo str_repeat('=', 72) . "\n";
echo "TOTAL: {$pass} pass / {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
