<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/briefing_audit_log.php
 *
 * Filtered audit_log view scoped to Intelligence-related changes (F11).
 * Surfaces a "who changed what" feed of:
 *   - AI / briefing settings changes (entity_type='settings_group' AND
 *     entity_label IN ('ai', 'intelligence'))
 *   - Per-user opt-in toggles (entity_type='user_briefing_opt_in')
 *   - Test briefing sends (entity_type='morning_briefing_test')
 *   - Manual brief generation (entity_type='ai_brief_generated_manual')
 *   - Manual "send to all" brief dispatch (entity_type='ai_brief_sent_manual')
 *   - Per-user channel changes (entity_type='user_briefing_channels')
 *   - AI connection tests (entity_type='ai_connection')
 *   - Token budget alerts dispatched (entity_type='cron' AND
 *     entity_label='ai_budget_check')
 *
 * @method  GET
 * @auth    require_permission('settings', 'view')
 * @session S-INTEL-V2 Phase A
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

$limit = max(5, min(100, (int) ($_GET['limit'] ?? 25)));

try {
    $rows = db_select(
        "SELECT id, user_name, action, module, entity_type, entity_label, notes, created_at
           FROM audit_log
          WHERE
            (entity_type = 'settings_group' AND entity_label IN ('ai', 'intelligence', 'notifications'))
            OR entity_type IN (
                'user_briefing_opt_in',
                'morning_briefing_test',
                'ai_brief_generated_manual',
                'ai_brief_sent_manual',
                'user_briefing_preferences',
                'user_briefing_snooze',
                'user_briefing_channels',
                'ai_connection'
            )
            OR (entity_type = 'cron' AND entity_label IN ('ai_budget_check', 'ai_fleet_brief', 'notification_digest'))
          ORDER BY id DESC
          LIMIT {$limit}"
    );
    json_success([ 'rows' => $rows, 'limit' => $limit ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Audit log failed: ' . $e->getMessage(), 500);
}
