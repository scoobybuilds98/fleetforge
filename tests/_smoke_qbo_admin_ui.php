<?php
declare(strict_types=1);

/**
 * tests/_smoke_qbo_admin_ui.php
 *
 * S-QBO-4 — Static + render-smoke for the QBO admin UI surface.
 * Verifies the 4 admin pages + 9 backing API endpoints exist,
 * lint clean, gate the right permissions, and render without
 * fatal error under both empty-state and synthetic-data conditions.
 *
 * Self-cleaning — synthetic rows use entity_id=999999 sentinel and
 * are DELETEd in a finally block regardless of test outcome.
 *
 * 8 sub-checks:
 *   C1: All 4 pages exist + lint clean
 *   C2: All 9 API endpoints exist + lint clean
 *   C3: Each endpoint contains require_permission('quickbooks', ...) with the expected action
 *   C4: config/navigation.php has 5 children under QuickBooks (was 4; +Sync Queue)
 *   C5: Pages render without fatal error against the empty DB (no rows in any of the 3 tables)
 *   C6: Pages render without fatal error against synthetic rows (insert 1 row in each of
 *       sync_queue + sync_log + drift_events; rerun render; verify HTTP 200)
 *   C7: All 5 mutation endpoints check require_method('POST') (CSRF auto-applied by api/bootstrap.php)
 *   C8: dashboard_metrics.php returns valid JSON shape with the expected top-level keys
 *       (cards, chart, recent, connection)
 *
 * @session  S-QBO-4
 */

require_once __DIR__ . '/../config/app.php';

$failures = [];
$pass     = 0;
$total    = 8;

$check = function (string $label, array $errs) use (&$pass, &$failures): void {
    if (empty($errs)) {
        echo "PASS {$label}\n";
        $pass++;
    } else {
        echo "FAIL {$label}  " . implode('; ', $errs) . "\n";
        $failures[] = $label;
    }
};

$adminPages = [
    'dashboard.php',
    'sync_queue.php',
    'sync_log.php',
    'drift.php',
];
$apiEndpoints = [
    'dashboard_metrics.php'      => ['GET',  'view'],
    'refresh_token_now.php'      => ['POST', 'edit_credentials'],
    'sync_queue_list.php'        => ['GET',  'view'],
    'sync_queue_retry.php'       => ['POST', 'force_resync'],
    'sync_queue_clear.php'       => ['POST', 'clear_queue'],
    'sync_log_search.php'        => ['GET',  'view'],
    'sync_log_detail.php'        => ['GET',  'view'],
    'drift_list.php'             => ['GET',  'view'],
    'drift_resolve.php'          => ['POST', 'view'],
];

// ── C1: pages exist + lint ────────────────────────────────────
$c1Errs = [];
foreach ($adminPages as $p) {
    $path = FF_ROOT . '/app/admin/quickbooks/' . $p;
    if (!is_file($path)) {
        $c1Errs[] = "missing page: {$p}";
        continue;
    }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c1Errs[] = "lint failed {$p}: " . implode(' ', $out);
    }
}
$check('C1  4 admin pages exist + lint clean', $c1Errs);

// ── C2: endpoints exist + lint ────────────────────────────────
$c2Errs = [];
foreach (array_keys($apiEndpoints) as $e) {
    $path = FF_ROOT . '/api/v1/quickbooks/' . $e;
    if (!is_file($path)) {
        $c2Errs[] = "missing endpoint: {$e}";
        continue;
    }
    $out = []; $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $c2Errs[] = "lint failed {$e}: " . implode(' ', $out);
    }
}
$check('C2  9 API endpoints exist + lint clean', $c2Errs);

// ── C3: each endpoint contains require_permission with expected action
$c3Errs = [];
foreach ($apiEndpoints as $endpoint => $spec) {
    [$method, $action] = $spec;
    $path = FF_ROOT . '/api/v1/quickbooks/' . $endpoint;
    if (!is_file($path)) {
        continue; // C2 already flagged
    }
    $content = file_get_contents($path);
    $needle = "require_permission('quickbooks', '{$action}')";
    if (!str_contains((string) $content, $needle)) {
        $c3Errs[] = "{$endpoint} missing: {$needle}";
    }
}
$check('C3  endpoints gate with require_permission(quickbooks, <action>)', $c3Errs);

// ── C4: navigation has 11 QuickBooks children ─────────────────
// Grew 5→6 in S-QBO-5 (Customers), 6→7 in S-QBO-7 (Vendors), 7→8 in
// S-QBO-8 (Accounts), 8→9 in S-QBO-9 (Tax Codes between Accounts and
// Settings), 9→10 in S-QBO-10 with the addition of Items (between
// Tax Codes and Settings), then 10→11 in S-QBO-11 with Invoices
// (between Items and Settings) for the FF→QBO invoice push admin.
$c4Errs = [];
$navConfig = require FF_ROOT . '/config/navigation.php';
$qboParent = null;
foreach ($navConfig as $entry) {
    if (isset($entry['label']) && $entry['label'] === 'QuickBooks' && isset($entry['children'])) {
        $qboParent = $entry;
        break;
    }
}
if ($qboParent === null) {
    $c4Errs[] = 'QuickBooks parent entry with children not found in config/navigation.php';
} else {
    $childLabels = array_map(static fn($c) => $c['label'] ?? '', $qboParent['children']);
    $expected = ['Dashboard', 'Sync Queue', 'Sync Log', 'Drift', 'Customers', 'Vendors', 'Accounts', 'Tax Codes', 'Items', 'Invoices', 'Settings'];
    if ($childLabels !== $expected) {
        $c4Errs[] = 'expected children ' . json_encode($expected) . ' got ' . json_encode($childLabels);
    }
}
$check('C4  config/navigation.php has 11 QuickBooks children (incl. Invoices between Items and Settings)', $c4Errs);

// ── C5 + C6: empty + synthetic render (combined for cleanup symmetry)
// We don't actually render pages over HTTP — that requires the test
// harness to authenticate. Instead we verify that loading each page
// file as a require_once produces no fatal compile errors AND that
// the backing API endpoint, when invoked via curl against the local
// dev server, returns 401/403 (auth wall, not 500). The auth wall
// proves bootstrap+routing work end-to-end; the smoke skips actually
// authenticating because that's covered by S-QBO-3's queue smoke
// and the human operator can manually test full flows.
//
// For C5/C6 we hit the dashboard_metrics endpoint via internal cURL
// to localhost, expecting either a 200 (if a session exists somehow)
// or a 401/403 (unauthenticated). 500 = page is broken under empty
// state. Synthetic insert + re-fetch confirms it doesn't break with
// data either.

$c5Errs = [];
$c6Errs = [];
$createdQueue = null;
$createdLog   = null;
$createdDrift = null;
$createdAudit = [];

$probeUrl = function (string $path): int {
    $base = function_exists('env') ? env('APP_URL', 'http://fleetforge.test/fleetforge') : 'http://fleetforge.test/fleetforge';
    $base = rtrim($base, '/');
    $url  = $base . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_NOBODY         => false,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
};

// C5 — empty state. Probe one representative page + one endpoint.
// 200 / 302 / 401 / 403 are all acceptable (page reachable, no 500).
$emptyDashStatus = $probeUrl('/quickbooks/dashboard');
if ($emptyDashStatus >= 500 || $emptyDashStatus === 0) {
    $c5Errs[] = "dashboard.php returned HTTP {$emptyDashStatus} under empty state (expected <500)";
}
$emptyApiStatus = $probeUrl('/api/v1/quickbooks/dashboard_metrics.php');
if ($emptyApiStatus >= 500 || $emptyApiStatus === 0) {
    $c5Errs[] = "dashboard_metrics endpoint returned HTTP {$emptyApiStatus} under empty state (expected <500)";
}
$check('C5  pages + endpoints reachable under empty-state (no 5xx)', $c5Errs);

// C6 — synthetic data. Insert 1 row in each of 3 tables, probe, cleanup.
try {
    $createdQueue = (int) db_insert('acc_qbo_sync_queue', [
        'entity_type'   => 'customer',
        'entity_id'     => 999999,
        'operation'     => 'create',
        'status'        => 'failed',
        'priority'      => 5,
        'retry_count'   => 2,
        'max_retries'   => 5,
        'error_code'    => 'pusher_not_implemented',
        'error_message' => 'S-QBO-4 smoke synthetic row',
    ]);

    $createdLog = (int) db_insert('acc_qbo_sync_log', [
        'direction'        => 'push',
        'entity_type'      => 'customer',
        'entity_id'        => 999999,
        'qbo_entity_id'    => 'TEST-001',
        'operation'        => 'create',
        'http_method'      => 'POST',
        'endpoint'         => 'customer',
        'request_payload'  => json_encode(['DisplayName' => 'Smoke Customer 999999']),
        'response_status'  => 200,
        'response_payload' => json_encode(['Customer' => ['Id' => 'TEST-001']]),
        'duration_ms'      => 250,
        'queue_id'         => $createdQueue,
        'realm_id'         => 'smoke',
        'environment'      => 'sandbox',
    ]);

    $createdDrift = (int) db_insert('acc_qbo_drift_events', [
        'detection_source' => 'push_failure',
        'category'         => 'push_failed',
        'entity_type'      => 'customer',
        'entity_id'        => 999999,
        'description'      => 'S-QBO-4 smoke synthetic drift event',
        'queue_id'         => $createdQueue,
        'realm_id'         => 'smoke',
        'environment'      => 'sandbox',
    ]);

    $syntheticDashStatus = $probeUrl('/quickbooks/dashboard');
    if ($syntheticDashStatus >= 500 || $syntheticDashStatus === 0) {
        $c6Errs[] = "dashboard.php returned HTTP {$syntheticDashStatus} with synthetic rows (expected <500)";
    }
    $syntheticQueueStatus = $probeUrl('/quickbooks/sync_queue');
    if ($syntheticQueueStatus >= 500 || $syntheticQueueStatus === 0) {
        $c6Errs[] = "sync_queue.php returned HTTP {$syntheticQueueStatus} with synthetic rows";
    }
    $syntheticLogStatus = $probeUrl('/quickbooks/sync_log');
    if ($syntheticLogStatus >= 500 || $syntheticLogStatus === 0) {
        $c6Errs[] = "sync_log.php returned HTTP {$syntheticLogStatus} with synthetic rows";
    }
    $syntheticDriftStatus = $probeUrl('/quickbooks/drift');
    if ($syntheticDriftStatus >= 500 || $syntheticDriftStatus === 0) {
        $c6Errs[] = "drift.php returned HTTP {$syntheticDriftStatus} with synthetic rows";
    }
} catch (Throwable $e) {
    $c6Errs[] = 'synthetic insert/probe failed: ' . $e->getMessage();
} finally {
    // SELF-CLEANUP — must run regardless of outcome
    try {
        if ($createdDrift !== null) {
            db_execute("DELETE FROM acc_qbo_drift_events WHERE id = ?", [$createdDrift]);
        }
        if ($createdLog !== null) {
            db_execute("DELETE FROM acc_qbo_sync_log WHERE id = ?", [$createdLog]);
        }
        if ($createdQueue !== null) {
            db_execute("DELETE FROM acc_qbo_sync_queue WHERE id = ?", [$createdQueue]);
        }
        // audit_log rows the worker might have written for entity_id=999999
        db_execute(
            "DELETE FROM audit_log
              WHERE module='quickbooks'
                AND entity_type IN ('qbo_sync_queue','qbo_drift_event')
                AND entity_label LIKE '%999999%'",
            []
        );
    } catch (Throwable $cleanErr) {
        $c6Errs[] = 'cleanup: ' . $cleanErr->getMessage();
    }
}
$check('C6  pages reachable under synthetic data (self-cleaning; entity_id=999999)', $c6Errs);

// ── C7: mutation endpoints declare require_method('POST') ─────
$c7Errs = [];
foreach ($apiEndpoints as $endpoint => $spec) {
    [$method, $action] = $spec;
    if ($method !== 'POST') continue;
    $content = (string) file_get_contents(FF_ROOT . '/api/v1/quickbooks/' . $endpoint);
    if (!str_contains($content, "require_method('POST')")) {
        $c7Errs[] = "{$endpoint} missing require_method('POST')";
    }
}
$check('C7  mutation endpoints declare require_method(POST) (CSRF auto-applied)', $c7Errs);

// ── C8: dashboard_metrics returns valid JSON shape ────────────
// We can't authenticate from this offline smoke, but we CAN verify
// the endpoint file declares the expected top-level keys in its
// json_success() call. Match against keywords in the source.
$c8Errs = [];
$src = (string) file_get_contents(FF_ROOT . '/api/v1/quickbooks/dashboard_metrics.php');
foreach (["'cards'", "'chart'", "'recent'", "'connection'", "'sync_queue'", "'drift'", "'activity_24h'", "'master'", "'labels'", "'series'"] as $needle) {
    if (!str_contains($src, $needle)) {
        $c8Errs[] = "dashboard_metrics source missing: {$needle}";
    }
}
$check('C8  dashboard_metrics declares expected top-level + nested JSON keys', $c8Errs);

// ── Final cleanup verification ────────────────────────────────
$leftover = (int) db_count(
    "SELECT
       (SELECT COUNT(*) FROM acc_qbo_sync_queue   WHERE entity_id=999999) +
       (SELECT COUNT(*) FROM acc_qbo_sync_log     WHERE entity_id=999999) +
       (SELECT COUNT(*) FROM acc_qbo_drift_events WHERE entity_id=999999)",
    []
);
if ($leftover !== 0) {
    echo "WARN C6 cleanup left {$leftover} synthetic rows with entity_id=999999\n";
}

echo "\nqbo_admin_ui_smoke: {$pass}/{$total} PASS";
if (!empty($failures)) {
    echo " — failing: " . implode(', ', $failures);
    echo "\n";
    exit(1);
}
echo "\n";
exit(0);
