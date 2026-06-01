<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/qbo_demo_seed.php
 *
 * Offline demo-seed action endpoint (S-QBO-OFFLINE-TESTBED). Drives
 * QboDemoSeed::load() / wipe() — the harness that populates every
 * /quickbooks/* admin surface with realistic data via the fixture
 * HTTP layer, WITHOUT a live Intuit connection.
 *
 * Two actions:
 *   • load — flip fixture_mode + seed FF entities + run the real
 *            Pusher pipeline → real worker dispatch → real Pullers,
 *            then DriftChecker.runCheck(forceLive=true) on top. The
 *            production guard inside QboDemoSeed::load() refuses to
 *            execute in production OR with a real Intuit realm
 *            connected (D-QBO-FIXTURE-2).
 *   • wipe — delete ONLY rows tagged as fixture-originated
 *            (realm_id='FIXTURE-DEMO' OR ff_*_id in [999000,999989]
 *            OR qbo_*_id LIKE 'QBO-FIX-%'). Snapshots real-row counts
 *            before+after and returns the diff.
 *
 * Permission: `quickbooks/force_full_resync` (same control gate as
 * the bulk reconciliation actions on manual_sync.php — this is an
 * operator-only tool, not a public API).
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'force_full_resync').
 * @body    JSON: { action: 'load'|'wipe' }
 * @returns 200 load → { action, pushed, failed, skipped, pulled, drift_events, queue_drained }
 *        | 200 wipe → { action, deleted, real_row_diff }
 *        | 400 BAD_REQUEST       (unknown action)
 *        | 403 FORBIDDEN
 *        | 422 INVALID_STATE     (production guard refuses)
 *        | 500 INTERNAL_ERROR
 *
 * @session  S-QBO-OFFLINE-TESTBED
 * @decision D-QBO-FIXTURE-2 (production + real-realm hard refuse),
 *           D-QBO-FIXTURE-4 (real-pipeline demo-seed)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'force_full_resync');

use FleetForge\QboDemoSeed;

$body   = json_body();
$action = isset($body['action']) ? strtolower(trim((string) $body['action'])) : '';

if (!in_array($action, ['load', 'wipe'], true)) {
    json_error('BAD_REQUEST', "Unknown action '{$action}'. Allowed: load, wipe.", 400);
}

try {
    if ($action === 'load') {
        $summary = QboDemoSeed::load();

        $user = current_user();
        db_insert('audit_log', [
            'user_id'      => $user['id'] ?? null,
            'user_name'    => $user['name'] ?? 'system',
            'action'       => 'create',
            'module'       => 'quickbooks',
            'entity_type'  => 'qbo_demo_seed',
            'entity_id'    => null,
            'entity_label' => 'load',
            'notes'        => 'Offline demo-seed loaded: ' . json_encode($summary),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        json_success(['action' => 'load'] + $summary);
    }

    // action === 'wipe'
    $result = QboDemoSeed::wipe();

    $user = current_user();
    db_insert('audit_log', [
        'user_id'      => $user['id'] ?? null,
        'user_name'    => $user['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'quickbooks',
        'entity_type'  => 'qbo_demo_seed',
        'entity_id'    => null,
        'entity_label' => 'wipe',
        'notes'        => 'Offline demo-seed wiped: ' . json_encode($result),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    json_success(['action' => 'wipe'] + $result);
} catch (\RuntimeException $e) {
    // Production / real-realm guard refused — surface 422 so the UI
    // shows the explanatory message instead of a 500.
    if (stripos($e->getMessage(), 'refused') !== false) {
        json_error('INVALID_STATE', $e->getMessage(), 422);
    }
    json_error('INTERNAL_ERROR', $e->getMessage(), 500);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Demo-seed action failed: ' . $e->getMessage(), 500);
}
