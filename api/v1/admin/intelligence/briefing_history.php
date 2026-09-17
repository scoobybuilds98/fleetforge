<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/briefing_history.php
 *
 * Returns last 10 dispatch runs of the morning briefing, grouped by
 * run date with sent/skipped/errored/total counts. Powers the
 * "Recent runs" widget in the Intelligence settings tab.
 *
 * Data source: notification_log rows with notification_type='morning_digest'
 * (the cron-issued sends). Test-send rows are notification_type=
 * 'morning_digest_test' and are excluded from this widget so the
 * operator sees only real cron history.
 *
 * @method  GET
 * @auth    require_permission('settings', 'view')
 * @returns 200 { success: true, runs: [{date, sent, failed, total}], generated_at_brief, briefing_enabled }
 *
 * @session  S-INTEL-TAB
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

try {
    // Group sends by date (the cron's per-run window is < 1 hour, so all rows
    // from one cron run share the same day).
    // S-LOCAL-DAY-TS: sent_at/created_at are UTC, but run_date is a business-
    // LOCAL day (it is shown to the operator and fed back to brief_content.php
    // ?date=). DATE() on the UTC value put an evening run (after 5pm/4pm
    // Pacific) on tomorrow's row. Named MySQL zones are not loaded, so SQL
    // aggregates per UTC minute (exact for any whole/half-hour offset) and PHP
    // folds those into local days.
    $slots = db_select(
        "SELECT DATE_FORMAT(COALESCE(sent_at, created_at), '%Y-%m-%d %H:%i:00') AS utc_slot,
                SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total,
                MAX(COALESCE(sent_at, created_at)) AS latest_at
           FROM notification_log
          WHERE notification_type = 'morning_digest'
          GROUP BY utc_slot
          ORDER BY utc_slot DESC
          LIMIT 5000"
    );

    $byDay = [];
    foreach ($slots as $slot) {
        $day = ff_utc_to_local((string) $slot['utc_slot'], 'Y-m-d');
        if (!isset($byDay[$day])) {
            // Slots arrive newest-first; stop once the 10 most recent local days are
            // collected (preserves the old LIMIT 10).
            if (count($byDay) >= 10) break;
            $byDay[$day] = ['run_date' => $day, 'sent' => 0, 'failed' => 0, 'total' => 0, 'latest_at' => null];
        }
        $byDay[$day]['sent']   += (int) $slot['sent'];
        $byDay[$day]['failed'] += (int) $slot['failed'];
        $byDay[$day]['total']  += (int) $slot['total'];
        if ($byDay[$day]['latest_at'] === null) {
            // First (newest) slot of the day holds its MAX; ISO-8601 with offset
            // so the UTC instant is unambiguous to the client.
            $byDay[$day]['latest_at'] = gmdate('c', strtotime((string) $slot['latest_at'] . ' UTC'));
        }
    }
    $runs = array_values($byDay);

    // Plus: the most recent cached brief generation timestamp, so the
    // UI can show "last generated at <ts>" alongside the dispatch
    // history.
    // report_cache schema: id, report_type, parameters_hash, parameters,
    // result_data, generated_at (NOT created_at), expires_at, generated_by.
    $cacheRow = db_row(
        "SELECT result_data, generated_at, expires_at FROM report_cache
          WHERE report_type = 'ai_fleet_brief'
          ORDER BY id DESC LIMIT 1"
    );
    $generatedAt = null;
    $cacheActive = false;
    if ($cacheRow) {
        // S-LOCAL-DAY-TS: expires_at is a UTC DATETIME — a bare strtotime()
        // parses it as Pacific and reported the cache active 7-8h too long.
        $cacheActive = strtotime((string) $cacheRow['expires_at'] . ' UTC') > time();
        $payload = json_decode((string) $cacheRow['result_data'], true);
        // Payload generated_at is ISO-8601 with offset (writers since
        // S-LOCAL-DAY-TS), so plain strtotime() is exact; the column fallback is
        // UTC. Emit ISO-8601 with offset — formatTs() in the settings page does
        // new Date(), which would read a bare string as browser-local time.
        $genTs = is_array($payload) && !empty($payload['generated_at'])
            ? strtotime((string) $payload['generated_at'])
            : strtotime((string) $cacheRow['generated_at'] . ' UTC');
        $generatedAt = $genTs !== false ? gmdate('c', $genTs) : null;
    }

    // Recipient roll-up — how many users currently match the three-gate
    // filter (master enabled + role allow list + per-user opt_in).
    $rolesJson = (string) settings_get('ai.briefing_recipient_roles', '["super_admin","manager","accountant"]');
    $roles = json_decode($rolesJson, true);
    if (!is_array($roles) || empty($roles)) {
        $roles = ['super_admin', 'manager', 'accountant'];
    }
    $roles = array_values(array_filter($roles, 'is_string'));
    $placeholders = $roles ? implode(',', array_fill(0, count($roles), '?')) : "''";
    $currentRecipientCount = $roles
        ? (int) db_count(
            "SELECT COUNT(*) FROM users u
             JOIN user_roles ur ON ur.id = u.role_id
             WHERE u.deleted_at IS NULL AND u.status = 'active'
               AND u.morning_briefing_opt_in = 1
               AND ur.slug IN ({$placeholders})",
            $roles
        )
        : 0;

    json_success([
        'runs'                    => $runs,
        'briefing_enabled'        => (string) settings_get('ai.briefing_enabled', '1') === '1',
        'ai_enabled'              => (string) settings_get('ai.enabled', '1') === '1',
        'last_brief_generated_at' => $generatedAt,
        'cache_active'            => $cacheActive,
        'current_recipient_count' => $currentRecipientCount,
        'configured_roles'        => $roles,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Briefing history failed: ' . $e->getMessage(), 500);
}
