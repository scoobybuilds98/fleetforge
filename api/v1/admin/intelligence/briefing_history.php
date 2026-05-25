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
    // Group sends by date (the cron's per-run window is < 1 hour, so
    // bucketing by DATE(sent_at) is sufficient — all rows from one cron
    // run share the same day).
    $runs = db_select(
        "SELECT DATE(COALESCE(sent_at, created_at)) AS run_date,
                SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total,
                MAX(COALESCE(sent_at, created_at)) AS latest_at
           FROM notification_log
          WHERE notification_type = 'morning_digest'
          GROUP BY DATE(COALESCE(sent_at, created_at))
          ORDER BY run_date DESC
          LIMIT 10"
    );

    // Coerce to ints for clean JSON output.
    foreach ($runs as &$r) {
        $r['sent']   = (int) $r['sent'];
        $r['failed'] = (int) $r['failed'];
        $r['total']  = (int) $r['total'];
    }
    unset($r);

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
        $cacheActive = strtotime((string) $cacheRow['expires_at']) > time();
        $payload = json_decode((string) $cacheRow['result_data'], true);
        $generatedAt = is_array($payload) && !empty($payload['generated_at'])
            ? (string) $payload['generated_at']
            : (string) $cacheRow['generated_at'];
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
