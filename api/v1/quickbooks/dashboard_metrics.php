<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/dashboard_metrics.php
 *
 * Single-roundtrip JSON endpoint backing the QuickBooks Dashboard
 * page (app/admin/quickbooks/dashboard.php). Returns 4 KPI card
 * payloads + 14-day chart series + the 20 most-recent sync log
 * entries + the connection-status strip data, all in one shape.
 *
 * Empty-state safe — every aggregate returns 0 when the table is
 * empty, the chart always produces 14 buckets even if all are 0,
 * and `recent` is an empty array. The Alpine.js view renders a
 * "no sync activity yet" annotation when activity_24h.total === 0.
 *
 * Performance: 4 simple aggregates + 1 GROUP BY DATE + 1 LIMIT 20 +
 * 1 settings_get for connection. ~6 queries, all trivially indexed
 * (acc_qbo_sync_queue.status + acc_qbo_sync_log.created_at are
 * already keyed per spec §6.4 / §6.5).
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { success: true, cards: {...}, chart: {...}, recent: [...], connection: {...} }
 *
 * Spec ref: spec §6.4 (queue states), §6.5 (sync log), §13.4 (error display)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

try {
    // ── Card 1 — Sync Queue counts by status ──────────────────
    $queueRows = db_select(
        "SELECT status, COUNT(*) AS n FROM acc_qbo_sync_queue GROUP BY status",
        []
    );
    $queue = ['queued' => 0, 'processing' => 0, 'failed' => 0, 'skipped' => 0, 'completed' => 0];
    foreach ($queueRows as $r) {
        $queue[$r['status']] = (int) $r['n'];
    }

    // ── Card 2 — Drift events (unresolved + top category) ────
    $unresolved = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events WHERE resolved_at IS NULL",
        []
    );
    $byCategory = [];
    if ($unresolved > 0) {
        $catRows = db_select(
            "SELECT category, COUNT(*) AS n FROM acc_qbo_drift_events
              WHERE resolved_at IS NULL
              GROUP BY category
              ORDER BY n DESC
              LIMIT 3",
            []
        );
        foreach ($catRows as $r) {
            $byCategory[$r['category']] = (int) $r['n'];
        }
    }

    // ── Card 3 — Last 24h API call counts ─────────────────────
    $last24h = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_log WHERE created_at >= NOW() - INTERVAL 24 HOUR",
        []
    );
    $last24hOk = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_log
          WHERE created_at >= NOW() - INTERVAL 24 HOUR
            AND response_status >= 200 AND response_status < 300",
        []
    );
    $last24hErr = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_log
          WHERE created_at >= NOW() - INTERVAL 24 HOUR
            AND (response_status IS NULL OR response_status < 200 OR response_status >= 300)",
        []
    );

    // ── Card 4 — Master sync status (settings) ────────────────
    $syncEnabled = (string) settings_get('quickbooks.sync_enabled', '0');
    $dryRun      = (string) settings_get('quickbooks.dry_run_mode', '0');
    $environment = (string) settings_get('quickbooks.environment', 'sandbox');

    // ── 14-day chart series ───────────────────────────────────
    // Pre-fill 14 zero buckets so the chart always renders even
    // when there are no rows yet. Then overlay actual counts.
    $labels       = [];
    $completedMap = [];
    $failedMap    = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $labels[]            = $d;
        $completedMap[$d]    = 0;
        $failedMap[$d]       = 0;
    }
    $chartRows = db_select(
        "SELECT DATE(created_at) AS d,
                SUM(response_status >= 200 AND response_status < 300) AS ok,
                SUM(response_status IS NULL OR response_status < 200 OR response_status >= 300) AS err
           FROM acc_qbo_sync_log
          WHERE created_at >= CURDATE() - INTERVAL 13 DAY
          GROUP BY DATE(created_at)",
        []
    );
    foreach ($chartRows as $r) {
        $d = (string) $r['d'];
        if (isset($completedMap[$d])) {
            $completedMap[$d] = (int) $r['ok'];
            $failedMap[$d]    = (int) $r['err'];
        }
    }
    $chart = [
        'labels' => $labels,
        'series' => [
            ['name' => 'Completed', 'data' => array_values($completedMap)],
            ['name' => 'Failed',    'data' => array_values($failedMap)],
        ],
    ];

    // ── Recent activity feed (last 20) ────────────────────────
    $recent = db_select(
        "SELECT id, created_at, direction, entity_type, entity_id, qbo_entity_id,
                operation, http_method, response_status, error_code, duration_ms
           FROM acc_qbo_sync_log
          ORDER BY created_at DESC, id DESC
          LIMIT 20",
        []
    );

    // ── Connection status strip ───────────────────────────────
    // Pull every field the strip needs in one settings sweep so
    // the page doesn't make a second roundtrip just for these.
    $connStatus   = (string) settings_get('quickbooks.connection_status', 'disconnected');
    $realmId      = (string) settings_get('quickbooks.realm_id', '');
    $connectedAt  = (string) settings_get('quickbooks.last_connected_at', '');
    $tokenRefresh = (string) settings_get('quickbooks.last_token_refresh_at', '');
    $refreshExp   = (string) settings_get('quickbooks.refresh_token_expires_at', '');
    $connError    = (string) settings_get('quickbooks.connection_error', '');

    $refreshExpiresInDays = null;
    if ($refreshExp !== '') {
        $expTs = strtotime($refreshExp);
        if ($expTs !== false) {
            $refreshExpiresInDays = (int) floor(($expTs - time()) / 86400);
        }
    }

    json_success([
        'cards' => [
            'sync_queue'   => $queue,
            'drift'        => [
                'unresolved'  => $unresolved,
                'by_category' => $byCategory,
            ],
            'activity_24h' => [
                'total'  => $last24h,
                'ok'     => $last24hOk,
                'errors' => $last24hErr,
            ],
            'master'       => [
                'sync_enabled' => $syncEnabled,
                'dry_run_mode' => $dryRun,
                'environment'  => $environment,
            ],
        ],
        'chart'      => $chart,
        'recent'     => $recent,
        'connection' => [
            'status'                       => $connStatus,
            'environment'                  => $environment,
            'realm_id'                     => $realmId,
            'last_connected_at'            => $connectedAt !== '' ? $connectedAt : null,
            'last_token_refresh_at'        => $tokenRefresh !== '' ? $tokenRefresh : null,
            'refresh_token_expires_in_days'=> $refreshExpiresInDays,
            'connection_error'             => $connError !== '' ? $connError : null,
        ],
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Dashboard metrics fetch failed: ' . $e->getMessage(), 500);
}
