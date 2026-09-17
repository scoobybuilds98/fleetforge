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
 *                  (used when clicking a "recent runs" row) — a business-LOCAL day
 *
 * Timestamps out: created_at / expires_at are raw UTC DATETIMEs; generated_at
 * and recipients[].sent_at are business-local 'Y-m-d H:i:s' display strings.
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
            "SELECT id, parameters_hash AS cache_key, result_data, expires_at, generated_at AS created_at
               FROM report_cache
              WHERE id = ? AND report_type = 'ai_fleet_brief'",
            [$cacheId]
        );
    } elseif ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        // S-LOCAL-DAY-TS: ?date= is a business-LOCAL day (briefing_history's
        // run_date) but generated_at is UTC — DATE(generated_at) matched the UTC
        // day, missing an evening brief. Window on the local day's UTC bounds.
        // An impossible calendar date (e.g. 2026-13-45) would throw in the date
        // helpers — keep the old "no row → 404" outcome instead of a 500.
        $row = clean_date($date) === null ? null : db_row(
            "SELECT id, parameters_hash AS cache_key, result_data, expires_at, generated_at AS created_at
               FROM report_cache
              WHERE report_type = 'ai_fleet_brief'
                AND generated_at >= ? AND generated_at < ?
              ORDER BY id DESC LIMIT 1",
            [ff_local_day_start_utc($date), ff_local_day_start_utc(ff_local_date_add($date, 1))]
        );
    } else {
        // Latest available.
        $row = db_row(
            "SELECT id, parameters_hash AS cache_key, result_data, expires_at, generated_at AS created_at
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
    // S-LOCAL-DAY-TS: created_at (= report_cache.generated_at) and
    // notification_log.sent_at/created_at are all UTC. Take the brief's LOCAL
    // day and match sends inside that day's UTC bounds (was PHP-local parse of
    // a UTC value vs DATE() of a UTC value — off by a day in the evening).
    $recipientsDay = ff_utc_to_local((string) $row['created_at'], 'Y-m-d');
    $recipients = db_select(
        "SELECT recipient, status, sent_at
           FROM notification_log
          WHERE notification_type = 'morning_digest'
            AND COALESCE(sent_at, created_at) >= ? AND COALESCE(sent_at, created_at) < ?
          ORDER BY id DESC
          LIMIT 100",
        [ff_local_day_start_utc($recipientsDay), ff_local_day_start_utc(ff_local_date_add($recipientsDay, 1))]
    );
    // sent_at is rendered verbatim in the settings modal — show local wall time.
    foreach ($recipients as &$rcp) {
        if (!empty($rcp['sent_at'])) {
            $rcp['sent_at'] = ff_utc_to_local((string) $rcp['sent_at'], 'Y-m-d H:i:s');
        }
    }
    unset($rcp);

    // generated_at is rendered verbatim too. Payload value is ISO-8601 with
    // offset (legacy rows: bare local string — strtotime() reads both right);
    // the column fallback is UTC. Output business-local wall time.
    $genTs = !empty($payload['generated_at'])
        ? strtotime((string) $payload['generated_at'])
        : strtotime((string) $row['created_at'] . ' UTC');
    $generatedLocal = $genTs !== false
        ? ff_utc_to_local(gmdate('Y-m-d H:i:s', $genTs), 'Y-m-d H:i:s')
        : (string) $row['created_at'];

    json_success([
        'cache_id'     => (int) $row['id'],
        'cache_key'    => (string) $row['cache_key'],
        'created_at'   => (string) $row['created_at'],
        'expires_at'   => (string) $row['expires_at'],
        'generated_at' => $generatedLocal,
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
