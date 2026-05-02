<?php
declare(strict_types=1);

/**
 * cron/backup_db.php
 *
 * Database backup cron — runs every 6 hours (00:00, 06:00, 12:00, 18:00 UTC).
 * Produces a gzipped mysqldump, uploads via StorageClient (S3 in production,
 * local storage/backups/db/ in development), verifies the upload, then runs
 * a tiered retention cleanup pass.
 *
 * Crontab (production): 0 *\/6 * * *  /usr/bin/php /var/www/fleetforge/cron/backup_db.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/backup_db.php
 * Dry-run:              php /Users/avi/Documents/fleetforge/cron/backup_db.php --dry-run
 *
 * Backup path:  backups/db/{YYYY-MM-DD}/{HH}/fleetforge_{YYYYMMDDHHmmss}.sql.gz
 *
 * Retention policy (override via BACKUP_DB_RETENTION_DAYS env var, default 90):
 *   Tier 1 (days 0–7):    keep ALL backups
 *   Tier 2 (days 8–30):   keep ONE per day (newest)
 *   Tier 3 (days 31–N):   keep ONE per ISO week (newest)
 *   Beyond N days:        delete
 *
 * CRA note: 90-day default covers operational recovery. Financial record exports
 * (invoices, payments) satisfy 6-year CRA retention separately. Set
 * BACKUP_DB_RETENTION_DAYS=365 or higher if full-DB 6-year retention is needed.
 *
 * --dry-run: runs retention logic only, prints what would be deleted, writes
 *   a dry-run audit_log entry, and exits without creating or deleting anything.
 *
 * Decisions: D9 (StorageClient abstraction), D21 (advisory lock)
 * Audit findings resolved: #9 (no DB backup cron)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Storage\StorageClient;

// ── Advisory lock (D21) — prevents overlapping runs ──────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_backup_db', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0); // Another instance running — exit silently
}

$dryRun  = in_array('--dry-run', $argv ?? [], true);
$tmpFile = '';
$start   = time();

try {
    // ── Log cron start ───────────────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'backups',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'backup_db',
        'notes'        => 'DB backup started' . ($dryRun ? ' (dry-run — skipping actual dump)' : ''),
        'ip_address'   => '127.0.0.1',
    ]);

    if (!$dryRun) {
        // ── Generate backup paths ────────────────────────────────────────────
        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $datePath  = $now->format('Y-m-d');
        $hourPath  = $now->format('H');
        $timestamp = $now->format('YmdHis');
        $s3Key     = "backups/db/{$datePath}/{$hourPath}/fleetforge_{$timestamp}.sql.gz";
        $tmpFile   = sys_get_temp_dir() . "/ff_backup_{$timestamp}.sql.gz";

        // ── mysqldump via proc_open array form (no shell — no injection risk) ─
        // Credentials read from constants defined in config/app.php (sourced from .env).
        // Array form of proc_open calls execvp() directly — shell never invoked.
        $dumpCmd = [
            'mysqldump',
            '--single-transaction',        // consistent snapshot without table locks (production-safe)
            '--quick',                     // stream rows instead of buffering (memory-safe on large tables)
            '--lock-tables=false',
            '--default-character-set=utf8mb4',
            '--set-gtid-purged=OFF',
            '--host=' . FF_DB_HOST,
            '--port=' . FF_DB_PORT,
            '--user=' . FF_DB_USER,
            '--password=' . FF_DB_PASS,
            FF_DB_NAME,
        ];

        $dumpProc = proc_open($dumpCmd, [
            0 => ['pipe', 'r'],  // stdin  (not used)
            1 => ['pipe', 'w'],  // stdout → piped through gzip
            2 => ['pipe', 'w'],  // stderr → checked for errors
        ], $pipes);

        if (!is_resource($dumpProc)) {
            throw new \RuntimeException('proc_open: failed to start mysqldump');
        }
        fclose($pipes[0]);

        // Stream mysqldump stdout through PHP gzip (requires ext-zlib, standard on PHP 8+)
        $gz = gzopen($tmpFile, 'wb9');
        if ($gz === false) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($dumpProc);
            throw new \RuntimeException("gzopen failed for {$tmpFile} — ext-zlib may not be loaded");
        }

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && $chunk !== '') {
                gzwrite($gz, $chunk);
            }
        }
        gzclose($gz);

        $stderr   = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($dumpProc);

        if ($exitCode !== 0) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException("mysqldump exited {$exitCode}: {$stderr}");
        }

        // ── Verify dump integrity before upload ──────────────────────────────
        clearstatcache();
        $rawSize = file_exists($tmpFile) ? (int) filesize($tmpFile) : 0;
        if ($rawSize < 1024) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException(
                "Dump file too small ({$rawSize} bytes) — likely corrupt or empty database"
            );
        }

        $gz = gzopen($tmpFile, 'rb');
        if ($gz === false) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException("Cannot open dump for header verification");
        }
        $header = (string) gzread($gz, 100);
        gzclose($gz);

        if (!str_contains($header, '-- MySQL dump') && !str_contains($header, '-- MariaDB dump')) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException(
                "Header verification failed — unexpected content: " . json_encode(substr($header, 0, 60))
            );
        }

        $sizeMb = round($rawSize / 1048576, 2);

        // ── Upload (StorageClient routes to S3 or local storage/backups/db/) ─
        StorageClient::upload($tmpFile, $s3Key);
        $tmpFile = ''; // StorageClient::upload() deletes the tmpFile on success

        // ── Verify upload landed ─────────────────────────────────────────────
        if (!StorageClient::exists($s3Key)) {
            throw new \RuntimeException(
                "Post-upload verification failed — file not found at storage key: {$s3Key}"
            );
        }

        $duration = time() - $start;

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'backup_db',
            'notes'        => "DB backup successful: {$s3Key} ({$sizeMb}MB, {$duration}s)",
            'ip_address'   => '127.0.0.1',
        ]);

        error_log("[CRON backup_db] Backup complete: {$s3Key} ({$sizeMb}MB, {$duration}s)");
    }

    // ── Retention cleanup (runs in both normal and dry-run modes) ────────────
    ff_backup_db_retention($dryRun);

} catch (\Throwable $e) {
    if ($tmpFile !== '' && file_exists($tmpFile)) {
        @unlink($tmpFile);
    }

    $msg = $e->getMessage();
    error_log("[CRON backup_db] FAILED: {$msg}");

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'backups',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'backup_db',
        'notes'        => "DB backup failed: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    db_execute("SELECT RELEASE_LOCK('ff_cron_backup_db')", []);
}

// ── Tiered retention cleanup ──────────────────────────────────────────────────
function ff_backup_db_retention(bool $dryRun): void
{
    $retentionDays = max(1, (int) env('BACKUP_DB_RETENTION_DAYS', 90));
    $now           = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    try {
        $allFiles = StorageClient::listByPrefix('backups/db/');
    } catch (\Throwable $e) {
        error_log("[CRON backup_db] Retention list failed: " . $e->getMessage());
        return;
    }

    // Sort newest-key-first so "keep first seen per bucket" keeps the newest copy
    usort($allFiles, static fn($a, $b) => strcmp((string) $b['key'], (string) $a['key']));

    $toDelete   = [];
    $toKeep     = [];
    $dailyKept  = []; // date string (Y-m-d) → true, one entry per day in tier 2
    $weeklyKept = []; // ISO week-year key → true, one entry per week in tier 3

    foreach ($allFiles as $file) {
        // Parse date from path: backups/db/{YYYY-MM-DD}/{HH}/filename.sql.gz
        if (!preg_match('#^backups/db/(\d{4}-\d{2}-\d{2})/#', (string) $file['key'], $m)) {
            $toKeep[] = $file; // Unrecognised path — leave untouched
            continue;
        }

        try {
            $fileDate = new \DateTimeImmutable($m[1], new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            $toKeep[] = $file;
            continue;
        }

        $ageDays = (int) $now->diff($fileDate)->days;

        if ($ageDays <= 7) {
            // Tier 1: keep everything from the last 7 days
            $toKeep[] = $file;

        } elseif ($ageDays <= 30) {
            // Tier 2: keep one per calendar day (newest — enforced by sort order above)
            if (!isset($dailyKept[$m[1]])) {
                $dailyKept[$m[1]] = true;
                $toKeep[] = $file;
            } else {
                $toDelete[] = $file;
            }

        } elseif ($ageDays <= $retentionDays) {
            // Tier 3: keep one per ISO week (newest — enforced by sort order above)
            $weekKey = $fileDate->format('oW'); // ISO year + zero-padded week number
            if (!isset($weeklyKept[$weekKey])) {
                $weeklyKept[$weekKey] = true;
                $toKeep[] = $file;
            } else {
                $toDelete[] = $file;
            }

        } else {
            // Beyond retention window: delete
            $toDelete[] = $file;
        }
    }

    $deletedCount = 0;
    $deleteErrors = 0;

    if ($dryRun && count($toDelete) > 0) {
        echo "DRY-RUN: would delete " . count($toDelete) . " backup file(s):\n";
    }

    foreach ($toDelete as $file) {
        if ($dryRun) {
            $sizeMb = round((int) $file['size'] / 1048576, 2);
            echo "  DELETE {$file['key']} ({$sizeMb}MB, last_modified={$file['last_modified']})\n";
            $deletedCount++;
        } else {
            try {
                StorageClient::delete((string) $file['key']);
                $deletedCount++;
            } catch (\Throwable $e) {
                $deleteErrors++;
                error_log("[CRON backup_db] Retention delete failed for {$file['key']}: " . $e->getMessage());
            }
        }
    }

    if ($dryRun) {
        echo "DRY-RUN: would retain " . count($toKeep) . " backup file(s) (policy: 7d all / 30d daily / {$retentionDays}d weekly)\n";
    }

    $notes = "Retention cleanup" . ($dryRun ? ' (dry-run)' : '')
        . ": kept=" . count($toKeep)
        . ", deleted={$deletedCount}"
        . ($deleteErrors > 0 ? ", errors={$deleteErrors}" : '')
        . " (policy: 7d-all / 30d-daily / {$retentionDays}d-weekly)";

    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'backup_db_retention',
            'notes'        => $notes,
            'ip_address'   => '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        error_log("[CRON backup_db] Retention audit_log failed: " . $e->getMessage());
    }
}
