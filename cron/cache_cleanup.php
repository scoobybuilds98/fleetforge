<?php
declare(strict_types=1);

/**
 * cron/cache_cleanup.php
 *
 * Hourly cache expiry sweep — deletes expired rows from cache-like tables.
 *
 * Crontab (production): 0 * * * *  /usr/bin/php /var/www/fleetforge/cron/cache_cleanup.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/cache_cleanup.php
 *
 * Tables cleaned:
 *   report_cache   — rows where expires_at < NOW()
 *   ai_summaries   — rows where expires_at IS NOT NULL AND expires_at < NOW()
 *                    (ai.cache_summaries setting controls whether summaries are
 *                    cached at all; this cron evicts the expired ones regardless)
 *
 * Tables intentionally skipped:
 *   credit_notes.expires_at — business record (when a credit note expires),
 *     NOT a cache entry. Expired credit notes are a financial state, not stale
 *     data to delete. Their lifecycle is managed by billing logic.
 *
 * Decisions: D21 (advisory lock)
 * Audit findings resolved: part of #3 (missing cache_cleanup cron)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

// ── Advisory lock (D21) ──────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_cache_cleanup', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$start = time();

try {
    // ── Clean report_cache ───────────────────────────────────────────────────
    $reportDeleted = db_execute(
        "DELETE FROM report_cache WHERE expires_at < NOW()",
        []
    );

    // ── Clean ai_summaries (expired cache entries only) ──────────────────────
    // expires_at IS NOT NULL guard: summaries with no expiry are kept forever
    // (they may be manually generated snapshots without TTL).
    $aiDeleted = db_execute(
        "DELETE FROM ai_summaries WHERE expires_at IS NOT NULL AND expires_at < NOW()",
        []
    );

    // ── Clean rate_limit_attempts (S-PROD-1A) ─────────────────────────────────
    // Rows older than 7 days are stale — any active block has long expired.
    $rateLimitDeleted = db_execute(
        "DELETE FROM rate_limit_attempts WHERE window_start < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        []
    );

    $duration = time() - $start;
    $total    = $reportDeleted + $aiDeleted + $rateLimitDeleted;

    $notes = "Cache cleanup: report_cache={$reportDeleted}, ai_summaries={$aiDeleted}, rate_limit_attempts={$rateLimitDeleted} expired rows deleted ({$duration}s)";

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'cache',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'cache_cleanup',
        'notes'        => $notes,
        'ip_address'   => '127.0.0.1',
    ]);

    // Only log to cron.log if we actually deleted something (hourly cron — keep logs quiet)
    if ($total > 0) {
        error_log("[CRON cache_cleanup] {$notes}");
    }

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    $msg = $e->getMessage();
    error_log("[CRON cache_cleanup] FAILED: {$msg}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'cache',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'cache_cleanup',
        'notes'        => "Cache cleanup FAILED: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_cache_cleanup')", []);
}
