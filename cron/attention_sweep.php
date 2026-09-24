<?php
declare(strict_types=1);

/**
 * cron/attention_sweep.php
 *
 * Hourly "Needs attention" upkeep (S-ATTENTION-INBOX):
 *   1. Re-checks every kind the system can verify (overdue customers, unit
 *      documents, credit applications, customer requests, QuickBooks…):
 *      opens items for new problems, updates existing ones in place (never a
 *      duplicate), and closes items whose problem is gone. This is the
 *      safety net behind the instant re-checks that events trigger — e.g. a
 *      compliance date edited on a screen that fires no event closes within
 *      the hour.
 *   2. Resolves event-only items that stopped recurring (staleAfterDays).
 *   3. Wakes snoozed items whose date has come.
 *   4. Escalates urgent items nobody has taken for
 *      notifications.escalate_after_hours (default 24) — once per item.
 *   5. (S-ATTENTION-WHATSAPP) Queues each person's WhatsApp morning summary
 *      when it's their summary hour.
 *
 * Infrastructure, like the notification digest: deliberately NOT in
 * config/cron_jobs.php (not switchable from Settings) — turning it off would
 * leave the list silently stale, which is the failure this whole feature
 * exists to prevent.
 *
 * Crontab (production): 5 * * * *  /usr/bin/php /var/www/fleetforge/cron/attention_sweep.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/attention_sweep.php
 *
 * Decisions: D21 (advisory lock)
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Attention\AttentionService;

// ── Advisory lock (D21) ──────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_attention_sweep', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    exit(0);
}

$start = microtime(true);

try {
    $stats = AttentionService::sweepAll();

    // S-ATTENTION-WHATSAPP: queue the morning summary for everyone whose
    // summary hour (their timezone) is now. Send-once per person per day is
    // enforced by the delivery dedupe key, so a re-run in the same hour is a
    // no-op. cron/whatsapp_dispatch.php sends them.
    try {
        $stats['summaries'] = \FleetForge\Notifications\WhatsApp\WhatsAppDeliveries::enqueueSummaries();
    } catch (\Throwable $e) {
        $stats['errors'][] = 'summaries: ' . $e->getMessage();
    }

    $changed = 0;
    $cleared = 0;
    $open    = 0;
    foreach ($stats['kinds'] as $k) {
        $open    += (int) ($k['open'] ?? 0);
        $changed += (int) ($k['changed'] ?? 0);
        $cleared += (int) ($k['cleared'] ?? 0) + (int) ($k['expired'] ?? 0);
    }
    $ms    = (int) round((microtime(true) - $start) * 1000);
    $notes = "Attention sweep: {$open} open problem(s), {$changed} new/worse, {$cleared} closed, "
           . ($stats['woke'] ?? 0) . ' woke, ' . ($stats['escalated'] ?? 0) . ' escalated, '
           . ($stats['summaries'] ?? 0) . " WhatsApp summaries queued ({$ms}ms)"
           . ($stats['errors'] ? ' — ERRORS: ' . implode(' | ', $stats['errors']) : '');

    // Hourly cron: only write the audit trail / log when something happened,
    // so the audit log isn't 24 identical rows a day.
    if ($changed + $cleared + (int) ($stats['woke'] ?? 0) + (int) ($stats['escalated'] ?? 0) + (int) ($stats['summaries'] ?? 0) > 0 || $stats['errors']) {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'notifications',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'attention_sweep',
            'notes'        => mb_substr($notes, 0, 2000),
            'ip_address'   => '127.0.0.1',
        ]);
        error_log("[CRON attention_sweep] {$notes}");
    }

    if ($stats['errors']) {
        exit(1);
    }
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    error_log('[CRON attention_sweep] FAILED: ' . $e->getMessage());
    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'notifications',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'attention_sweep',
            'notes'        => 'Attention sweep FAILED: ' . mb_substr($e->getMessage(), 0, 1500),
            'ip_address'   => '127.0.0.1',
        ]);
    } catch (\Throwable) {
        // nothing more to do
    }
    exit(1);
} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_attention_sweep')", []);
}
