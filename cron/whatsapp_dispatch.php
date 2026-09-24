<?php
declare(strict_types=1);

/**
 * cron/whatsapp_dispatch.php
 *
 * Sends queued WhatsApp messages (S-ATTENTION-WHATSAPP) — every minute.
 *
 * Messages are queued in notification_deliveries by the Needs attention
 * engine (urgent items, escalations), the hourly sweep (morning summaries)
 * and NotificationService (updates a person ticked). This job claims the
 * due ones, respects each person's quiet hours, skips alerts whose problem
 * was dealt with before sending, sends through Meta's Cloud API and retries
 * temporary failures (1m, 5m, 30m, 2h; 5 attempts). When WhatsApp is
 * switched off in Settings, anything still queued is dropped (marked
 * skipped) instead of going out stale later.
 *
 * Infrastructure: not switchable in Settings → Scheduled Jobs; WhatsApp's own
 * on/off lives in Settings → Notifications.
 *
 * Crontab (production): * * * * *  /usr/bin/php /var/www/fleetforge/cron/whatsapp_dispatch.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/whatsapp_dispatch.php
 *
 * Decisions: D21 (advisory lock)
 *
 * @session S-ATTENTION-WHATSAPP
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Notifications\WhatsApp\WhatsAppDeliveries;

// ── Advisory lock (D21) — a slow run must not overlap the next minute's ──────
$lock = db_row("SELECT GET_LOCK('ff_cron_whatsapp_dispatch', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    exit(0);
}

try {
    $s = WhatsAppDeliveries::dispatchDue(50);
    // Every-minute job: log only when something happened.
    if (array_sum($s) > 0) {
        error_log(sprintf(
            '[CRON whatsapp_dispatch] sent=%d failed=%d retry=%d deferred=%d skipped=%d',
            $s['sent'], $s['failed'], $s['retry'], $s['deferred'], $s['skipped']
        ));
    }
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON whatsapp_dispatch] FAILED: ' . $e->getMessage());
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_whatsapp_dispatch')", []);
}
