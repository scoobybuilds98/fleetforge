<?php
declare(strict_types=1);

/**
 * cron/ai_budget_check.php
 *
 * Hourly check on AI token budget thresholds (F12). Calls
 * TokenBudgetMonitor::check() which emits dedup'd email alerts to the
 * configured recipient roles when usage crosses the thresholds in
 * settings.ai.budget_alert_thresholds.
 *
 * Crontab (production): 0 * * * * php /var/www/fleetforge/cron/ai_budget_check.php
 *
 * @session  S-INTEL-V2 Phase A
 */

require_once __DIR__ . '/../config/app.php';
\FleetForge\Observability\Sentry::init();

$lock = db_row("SELECT GET_LOCK('ff_cron_ai_budget_check', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) { exit(0); }

try {
    $result = \FleetForge\AI\TokenBudgetMonitor::check();
    $summary = sprintf(
        'AI budget check: %d / %d tokens (%.1f%%) — %d alerts sent, %d skipped.',
        $result['usage_tokens'],
        $result['limit_tokens'],
        $result['percent_used'] * 100,
        count($result['alerts_sent']),
        count($result['skipped_thresholds'])
    );
    error_log('[CRON] ' . $summary);

    if (!empty($result['alerts_sent'])) {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'system',
            'entity_type'  => 'cron',
            'entity_label' => 'ai_budget_check',
            'notes'        => $summary,
            'ip_address'   => '127.0.0.1',
        ]);
    }
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON ai_budget_check] Fatal: ' . $e->getMessage());
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_ai_budget_check')", []);
}
