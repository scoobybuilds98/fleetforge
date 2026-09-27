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
// bin/deploy.sh's health gate relies on that default (curl -f).
//
// STRICT MODE (S-PERF-3) — GET /fleetforge/api/v1/health?strict=1
// Same body, but HTTP 503 whenever status !== "ok". This exists for
// UPTIME MONITORS ONLY (Sentry uptime treats non-2xx as down, and it
// never parses JSON). Before this, the monitor polled the login page,
// which renders 200 even with MySQL down (settings_get() falls back to
// defaults), so the known [2002] outage mode was invisible to it.
// Never point the deploy gate or a load balancer at strict mode.
// See docs/runbooks/deploy.md "Uptime monitor (strict health)".
// ============================================================

// S-PERF-3: anonymous callers (the uptime bot, 1,440×/day) get no session
// file and no Set-Cookie. Only skipped when the request has NO ff_session /
// ff_remember cookie — see _ff_session_start() in includes/auth.php — so an
// authenticated caller still gets version / disk / schema.missing below.
if (!defined('FF_SKIP_ANON_SESSION')) {
    define('FF_SKIP_ANON_SESSION', true);
}

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

// ── Migration state (D-DEPLOY-2) ─────────────────────────────
// Pending-migration count makes a SCHEMA LAG a one-curl check the deploy
// gates on — the 2026-06-05 outage was a migration that never reached the DB.
// Light path: listFiles ∖ listApplied (no per-file sha256 / drift work). The
// Runner is constructed with a placeholder mysql binary because health.php
// never APPLIES migrations — it only reads the plan — so the mysql-binary
// resolution shell-out is skipped on this hot endpoint. Any failure here
// (e.g. schema_migrations itself missing) degrades, never 500s the check.
$migPending = null;
$migOk      = false;
// S-PERF-3: with the DB already known to be down, skip the Runner entirely —
// listApplied() would just run the whole connect-retry cycle a second time
// (2.8 s → 1.4 s for a DB-down check). The result is exactly what the catch
// below produced before: pending unknown (null), ok false.
if ($dbOk) {
    try {
        $runner   = new \FleetForge\Migrations\Runner(null, 'health-noop-never-applies');
        $files    = $runner->listFiles();
        $applied  = $runner->listApplied();
        $migPending = 0;
        foreach ($files as $f) {
            if (!isset($applied[$f])) {
                $migPending++;
            }
        }
        $migOk = ($migPending === 0);
    } catch (Throwable $e) {
        error_log('[Health] migrate-state check failed: ' . $e->getMessage());
        $migPending = null;   // unknown
        $migOk      = false;
    }
}

// ── Critical-table presence (D-DEPLOY-2) ─────────────────────
// The load-bearing tables whose absence breaks the app site-wide. A missing
// one (e.g. role_permission_overrides, the 2026-06-05 culprit) = UNHEALTHY.
$criticalTables = [
    'users', 'user_roles', 'role_permission_overrides', 'user_permission_overrides',
    'settings', 'schema_migrations', 'customers', 'leases', 'invoices',
];
$missingTables = [];
$tablesOk      = false;
if ($dbOk) {
    try {
        $rows    = db_select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?",
            [FF_DB_NAME]
        );
        $present = array_map('strtolower', array_column($rows, 'TABLE_NAME'));
        foreach ($criticalTables as $t) {
            if (!in_array($t, $present, true)) {
                $missingTables[] = $t;
            }
        }
        $tablesOk = empty($missingTables);
    } catch (Throwable $e) {
        error_log('[Health] critical-table check failed: ' . $e->getMessage());
        $tablesOk = false;
    }
}

// ── Overall status ───────────────────────────────────────────
// pending>0 or a missing critical table = UNHEALTHY (D-DEPLOY-2) — the deploy
// gate aborts on this before serving traffic.
$status = ($dbOk && $diskOk && $migOk && $tablesOk) ? 'ok' : 'degraded';

// ── Build response ───────────────────────────────────────────
// FIX #39: unauthenticated callers (load balancers, uptime monitors) only need
// status / db / time. Version string and disk metrics are omitted unless the
// request comes from an authenticated session — leaking them to the public
// can aid fingerprinting and targeted exploits.
$isAuthed = (bool) current_user_id();

$data = [
    'status' => $status,
    'db'     => $dbOk,
    // migrate-state + schema presence are PUBLIC (just counts/booleans, not
    // sensitive) so the deploy gate can curl this unauthenticated (D-DEPLOY-2).
    'migrations' => [
        'pending' => $migPending,   // int, or null if the check itself failed
        'ok'      => $migOk,        // false when pending>0 OR check failed
    ],
    'schema' => [
        'ok' => $tablesOk,          // false when a critical table is missing
    ],
    'time'   => date('c'),  // ISO 8601 with timezone offset
];

if ($isAuthed) {
    $data['version'] = FF_VERSION;
    $data['disk']    = [
        'free_gb'  => $diskFreeGb,
        'total_gb' => $diskTotalGb,
        'ok'       => $diskOk,
    ];
    // Detailed missing-table list only to authenticated callers — the public
    // payload exposes only schema.ok to avoid fingerprinting which table is gone.
    $data['schema']['missing'] = $missingTables;
    // Include cache status only when applicable
    if ($cacheOk !== null) {
        $data['cache'] = ['ok' => $cacheOk];
    }
}

// ── Strict mode (S-PERF-3) ──────────────────────────────────
// Opt-in only (exactly `strict=1`): 503 + the SAME body when not "ok".
// The default stays 200 in every state — bin/deploy.sh:356-380 curls
// without strict and must keep getting a parseable 200.
$strict = (($_GET['strict'] ?? '') === '1');

json_success($data, ($strict && $status !== 'ok') ? 503 : 200);
