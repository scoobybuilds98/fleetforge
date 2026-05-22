<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/brief_content.php
 *
 * View the full content + metadata of one cached brief (F3). Powers
 * the "click a recent run row" modal in the Intelligence tab.
 *
 * Query params:
 *   cache_id     — specific report_cache row to load (preferred)
 *   date         — YYYY-MM-DD to find the latest brief generated that day
 *                  (used when clicking a "recent runs" row)
 *
 * @method  GET
 * @auth    require_permission('settings', 'view')
 * @session S-INTEL-V2 Phase B
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

$cacheId = isset($_GET['cache_id']) && (int) $_GET['cache_id'] > 0 ? (int) $_GET['cache_id'] : null;
$date    = trim((string) ($_GET['date'] ?? ''));

try {
    if ($cacheId !== null) {
        $row = db_row(
            "SELECT id, cache_key, result_data, expires_at, created_at
               FROM report_cache
              WHERE id = ? AND report_type = 'ai_fleet_brief'",
            [$cacheId]
        );
    } elseif ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $row = db_row(
            "SELECT id, cache_key, result_data, expires_at, created_at
               FROM report_cache
              WHERE report_type = 'ai_fleet_brief'
                AND DATE(created_at) = ?
              ORDER BY id DESC LIMIT 1",
            [$date]
        );
    } else {
        // Latest available.
        $row = db_row(
            "SELECT id, cache_key, result_data, expires_at, created_at
               FROM report_cache
              WHERE report_type = 'ai_fleet_brief'
              ORDER BY id DESC LIMIT 1"
        );
    }

    if (!$row) {
        json_error('NOT_FOUND', 'No brief found for the given criteria.', 404);
    }

    $payload = json_decode((string) $row['result_data'], true);
    if (!is_array($payload)) { $payload = []; }

    // Recipients dispatched for the matching day (if any).
    $recipientsDay = date('Y-m-d', strtotime((string) $row['created_at']));
    $recipients = db_select(
        "SELECT recipient, status, sent_at
           FROM notification_log
          WHERE notification_type = 'morning_digest'
            AND DATE(COALESCE(sent_at, created_at)) = ?
          ORDER BY id DESC
          LIMIT 100",
        [$recipientsDay]
    );

    json_success([
        'cache_id'     => (int) $row['id'],
        'cache_key'    => (string) $row['cache_key'],
        'created_at'   => (string) $row['created_at'],
        'expires_at'   => (string) $row['expires_at'],
        'generated_at' => (string) ($payload['generated_at'] ?? $row['created_at']),
        'model'        => (string) ($payload['model'] ?? ''),
        'manual'       => (bool)   ($payload['manual'] ?? false),
        'manual_by'    => isset($payload['manual_by']) ? (int) $payload['manual_by'] : null,
        'brief'        => (string) ($payload['brief'] ?? ''),
        'metrics'      => $payload['metrics'] ?? null,
        'recipients'   => $recipients,
        'recipient_count' => count($recipients),
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Brief content load failed: ' . $e->getMessage(), 500);
}
