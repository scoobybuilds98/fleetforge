<?php
declare(strict_types=1);

/**
 * cron/archive_old_data.php
 *
 * Monthly data archiver — runs 1st of month at 04:00 UTC.
 *
 * Moves old audit_log and notification_log rows to archive tables so the source
 * tables stay fast for writes and the admin UI stays responsive.  Archived rows
 * are NOT deleted — they remain queryable in the archive tables indefinitely.
 *
 * Crontab (production): 0 4 1 * *  /usr/bin/php /var/www/fleetforge/cron/archive_old_data.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/archive_old_data.php
 *
 * Archive tables:
 *   audit_log_archive        — exists (created by FLEETFORGE_DATABASE_MASTER.sql)
 *   notification_log_archive — created on first run if it doesn't exist
 *
 * Retention defaults (configurable in settings table):
 *   archive.audit_log_retention_days        — default 365 (1 year)
 *   archive.notification_log_retention_days — default 90  (3 months)
 *
 * CRA compliance note: If your jurisdiction requires 6+ years of audit_log
 * retention, set archive.audit_log_retention_days=2190 (6 years) in the
 * settings table.  The default 365 days covers operational recovery only.
 *
 * Chunked archival: processes ARCHIVE_CHUNK_SIZE rows per batch so the source
 * table is not held with a huge transaction lock (important for large prod DBs).
 * Each chunk is its own INSERT + DELETE transaction — atomically moved.
 *
 * Decisions: D21 (advisory lock)
 * Audit findings resolved: part of #3 (missing archive cron)
 */

require_once dirname(__DIR__) . '/config/app.php';

const ARCHIVE_CHUNK_SIZE = 500;

// ── Advisory lock (D21) ──────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_archive_old_data', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$start = time();

try {
    // ── Log cron start ───────────────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'archive',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'archive_old_data',
        'notes'        => 'Data archive started',
        'ip_address'   => '127.0.0.1',
    ]);

    // ── Ensure notification_log_archive exists ───────────────────────────────
    // notification_log_archive is not in the baseline schema — created on first
    // run here so the archive cron is self-bootstrapping.
    db_execute(
        "CREATE TABLE IF NOT EXISTS `notification_log_archive` (
          `id`                int unsigned      NOT NULL AUTO_INCREMENT,
          `rule_id`           int unsigned      DEFAULT NULL,
          `channel`           enum('email','sms','in_app','webhook')
                                COLLATE utf8mb4_unicode_ci NOT NULL,
          `recipient`         varchar(255)      COLLATE utf8mb4_unicode_ci NOT NULL,
          `subject`           varchar(500)      COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `body`              text              COLLATE utf8mb4_unicode_ci,
          `entity_type`       varchar(100)      COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `entity_id`         int unsigned      DEFAULT NULL,
          `notification_type` varchar(100)      COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `status`            enum('queued','sent','delivered','failed','bounced')
                                COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
          `error_message`     text              COLLATE utf8mb4_unicode_ci,
          `sent_at`           datetime          DEFAULT NULL,
          `created_at`        datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_status`  (`status`),
          KEY `idx_created` (`created_at`),
          KEY `idx_entity`  (`entity_type`,`entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        []
    );

    // ── Retention thresholds ─────────────────────────────────────────────────
    $auditRetentionDays  = max(30, (int) settings_get('archive.audit_log_retention_days', '365'));
    $notifRetentionDays  = max(7,  (int) settings_get('archive.notification_log_retention_days', '90'));
    $auditCutoff         = date('Y-m-d H:i:s', strtotime("-{$auditRetentionDays} days"));
    $notifCutoff         = date('Y-m-d H:i:s', strtotime("-{$notifRetentionDays} days"));

    // ── Helper: archive one table in chunks ──────────────────────────────────
    // Returns [archived_count, error_count]
    $archiveTable = static function (
        string $sourceTable,
        string $archiveTable,
        string $insertColumns,  // comma-separated column list (excluding `id`)
        string $cutoff
    ): array {
        $archived = 0;
        $errors   = 0;
        $chunkSize = ARCHIVE_CHUNK_SIZE;

        do {
            // Collect the next chunk of IDs to archive (ORDER BY created_at ASC = oldest first)
            $rows = db_select(
                "SELECT id FROM `{$sourceTable}` WHERE created_at < ? ORDER BY created_at ASC LIMIT {$chunkSize}",
                [$cutoff]
            );

            if (empty($rows)) {
                break;
            }

            $ids    = array_column($rows, 'id');
            $phs    = implode(',', array_fill(0, count($ids), '?'));

            try {
                db_transaction(function () use (
                    $sourceTable, $archiveTable, $insertColumns, $ids, $phs, &$archived
                ) {
                    // Move rows into archive table (preserve original id so cross-reference works)
                    db_execute(
                        "INSERT IGNORE INTO `{$archiveTable}` (id, {$insertColumns})
                         SELECT id, {$insertColumns} FROM `{$sourceTable}` WHERE id IN ({$phs})",
                        $ids
                    );
                    // Remove from source only after successful archive insert
                    $deleted = db_execute(
                        "DELETE FROM `{$sourceTable}` WHERE id IN ({$phs})",
                        $ids
                    );
                    $archived += $deleted;
                });
            } catch (\Throwable $chunkErr) {
                $errors++;
                error_log("[CRON archive_old_data] Chunk error on {$sourceTable}: " . $chunkErr->getMessage());
                break; // stop this table's archival on chunk error
            }

        } while (count($rows) === $chunkSize); // loop while there may be more rows

        return [$archived, $errors];
    };

    // ── Archive audit_log ────────────────────────────────────────────────────
    $auditCols = 'user_id, user_name, action, module, entity_type, entity_id, entity_label,'
        . ' old_values, new_values, ip_address, user_agent, notes, created_at';

    [$auditArchived, $auditErrors] = $archiveTable(
        'audit_log',
        'audit_log_archive',
        $auditCols,
        $auditCutoff
    );

    // ── Archive notification_log ─────────────────────────────────────────────
    $notifCols = 'rule_id, channel, recipient, subject, body, entity_type, entity_id,'
        . ' notification_type, status, error_message, sent_at, created_at';

    [$notifArchived, $notifErrors] = $archiveTable(
        'notification_log',
        'notification_log_archive',
        $notifCols,
        $notifCutoff
    );

    // ── Summary audit log ────────────────────────────────────────────────────
    $duration   = time() - $start;
    $totalErrors = $auditErrors + $notifErrors;
    $notes = "Archived {$auditArchived} audit_log rows (cutoff {$auditRetentionDays}d),"
        . " {$notifArchived} notification_log rows (cutoff {$notifRetentionDays}d)"
        . ($totalErrors > 0 ? ", errors={$totalErrors}" : '')
        . " ({$duration}s)";

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'archive',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'archive_old_data',
        'notes'        => $notes,
        'ip_address'   => '127.0.0.1',
    ]);

    error_log("[CRON archive_old_data] {$notes}");

} catch (\Throwable $e) {
    $msg = $e->getMessage();
    error_log("[CRON archive_old_data] FAILED: {$msg}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'archive',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'archive_old_data',
        'notes'        => "Archive cron FAILED: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_archive_old_data')", []);
}
