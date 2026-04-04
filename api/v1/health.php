<?php
declare(strict_types=1);

// ============================================================
// FleetForge — Health Check  [PASS-15:I1]
//
// GET /fleetforge/api/v1/health
//
// Unauthenticated endpoint — no require_auth_api() call.
// Used by uptime monitors, load balancers, and deployment
// pipelines to verify the application is operational.
//
// Response shape:
//   {
//     "success": true,
//     "data": {
//       "status":  "ok" | "degraded",
//       "version": "x.y.z",
//       "db":      true | false,
//       "disk": {
//         "free_gb":  <float|null>,
//         "total_gb": <float|null>,
//         "ok":       true | false
//       },
//       "time": "<ISO-8601>"
//     }
//   }
//
// HTTP 200 is returned in both "ok" and "degraded" states so
// that load balancers do not route traffic away from degraded
// nodes — the caller decides how to interpret "degraded".
// ============================================================

require_once dirname(__DIR__) . '/bootstrap.php';

require_method('GET');

// ── DB check ────────────────────────────────────────────────
$dbOk = false;
try {
    // Lightweight query — verifies PDO connection and DB server
    db_pdo()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    error_log('[Health] DB check failed: ' . $e->getMessage());
    $dbOk = false;
}

// ── Disk check ───────────────────────────────────────────────
// Check free space on the storage partition.
// Falls back to FF_ROOT if the storage directory doesn't exist yet.
$diskPath = is_dir(FF_ROOT . '/storage')
    ? FF_ROOT . '/storage'
    : FF_ROOT;

$rawFree  = disk_free_space($diskPath);
$rawTotal = disk_total_space($diskPath);

$diskFreeGb  = ($rawFree  !== false) ? round($rawFree  / 1_073_741_824, 2) : null;
$diskTotalGb = ($rawTotal !== false) ? round($rawTotal / 1_073_741_824, 2) : null;

// Degraded if free space cannot be determined or drops below 500 MB
$diskOk = ($diskFreeGb !== null) && ($diskFreeGb >= 0.5);

// ── Cache check (optional, non-critical) ────────────────────
// Only checked if a cache directory is configured — silently
// omitted from the response if not in use.
$cacheOk  = null;
$cacheDir = FF_ROOT . '/cache';
if (is_dir($cacheDir)) {
    $cacheOk = is_writable($cacheDir);
}

// ── Overall status ───────────────────────────────────────────
$status = ($dbOk && $diskOk) ? 'ok' : 'degraded';

// ── Build response ───────────────────────────────────────────
// FIX #39: unauthenticated callers (load balancers, uptime monitors) only need
// status / db / time. Version string and disk metrics are omitted unless the
// request comes from an authenticated session — leaking them to the public
// can aid fingerprinting and targeted exploits.
$isAuthed = (bool) current_user_id();

$data = [
    'status' => $status,
    'db'     => $dbOk,
    'time'   => date('c'),  // ISO 8601 with timezone offset
];

if ($isAuthed) {
    $data['version'] = FF_VERSION;
    $data['disk']    = [
        'free_gb'  => $diskFreeGb,
        'total_gb' => $diskTotalGb,
        'ok'       => $diskOk,
    ];
    // Include cache status only when applicable
    if ($cacheOk !== null) {
        $data['cache'] = ['ok' => $cacheOk];
    }
}

json_success($data);
