<?php
declare(strict_types=1);

/**
 * cron/backup_dropbox.php
 *
 * Mirrors the latest S3 backup artifact to Dropbox (D-BACKUP-4).
 * Does NOT create a new dump — only mirrors what backup_db.php or
 * backup_storage.php already wrote to S3.
 *
 * Called with --type db (default) or --type storage.
 *
 *   Crontab (production):
 *     0 3 * * *   /usr/bin/php /var/www/fleetforge/cron/backup_dropbox.php --type db       >> /var/www/fleetforge/logs/cron.log 2>&1
 *     0 4 * * 0   /usr/bin/php /var/www/fleetforge/cron/backup_dropbox.php --type storage  >> /var/www/fleetforge/logs/cron.log 2>&1
 *
 *   Local test:  php cron/backup_dropbox.php --type db
 *
 * Exit 0 cleanly (no error row) if:
 *   - dropbox.enabled != '1'
 *   - dropbox.refresh_token is empty
 *   - no source S3 artifact found (writes a 'no source artifact' failed row instead)
 *
 * Retention (D-BACKUP-5):
 *   DB:      keep last DROPBOX_DB_RETENTION_DAYS days (default 30)
 *   Storage: keep last DROPBOX_STORAGE_RETENTION_COPIES copies (default 12)
 *
 * Session: S-BACKUP-2
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Backup\BackupRun;
use FleetForge\Backup\DropboxClient;
use FleetForge\Storage\StorageClient;

// ── Parse --type argument ─────────────────────────────────────────────────
$type = 'db';
foreach ($argv as $arg) {
    if (preg_match('/^--type=(db|storage)$/', (string) $arg, $m)) {
        $type = $m[1];
        break;
    }
}

// ── Enabled / configured check — exit 0 cleanly if not set up ────────────
if (settings_get('dropbox.enabled') !== '1') {
    exit(0);
}
$rawRefreshToken = (string) settings_get('dropbox.refresh_token', '');
if ($rawRefreshToken === '') {
    exit(0);
}

// ── Advisory lock — prevents overlapping cron runs per type ──────────────
$lockName = 'ff_cron_backup_dropbox_' . $type;
$lockResult = db_value('SELECT GET_LOCK(?, 0)', [$lockName]);
if (!$lockResult) {
    error_log("[CRON backup_dropbox:{$type}] Already running (lock held). Skipping.");
    exit(0);
}

// ── Find latest successful S3 artifact ────────────────────────────────────
$artifact = BackupRun::lastSuccess('s3', $type);

if (!$artifact || empty($artifact['file_key'])) {
    $runId = BackupRun::start('dropbox', $type);
    BackupRun::fail($runId, 'no source artifact');
    error_log("[CRON backup_dropbox:{$type}] No source S3 artifact found. Wrote failed row and exiting.");
    db_execute('SELECT RELEASE_LOCK(?)', [$lockName]);
    exit(0);
}

$s3Key = (string) $artifact['file_key'];
error_log("[CRON backup_dropbox:{$type}] Mirroring S3 artifact: {$s3Key}");

// ── Start backup run ───────────────────────────────────────────────────────
$runId = BackupRun::start('dropbox', $type);

// ── Download from S3 to temp file ─────────────────────────────────────────
$tempFile = sys_get_temp_dir() . '/ff_dropbox_' . $type . '_' . time() . '_' . getmypid() . '.tmp';

try {
    StorageClient::download($s3Key, $tempFile);
} catch (\Throwable $e) {
    $msg = 'S3 download failed: ' . $e->getMessage();
    error_log("[CRON backup_dropbox:{$type}] {$msg}");
    BackupRun::fail($runId, $msg);
    db_execute('SELECT RELEASE_LOCK(?)', [$lockName]);
    exit(1);
}

$localSize = (int) filesize($tempFile);

// ── Build Dropbox destination path ────────────────────────────────────────
// app-folder mode: paths are relative to the app folder (D-BACKUP-5).
// Sub-directory is /db or /storage; filename matches the S3 artifact.
$folderBase  = rtrim((string) settings_get('dropbox.folder_path', ''), '/');
$dropboxPath = $folderBase . '/' . $type . '/' . basename($s3Key);

// ── Upload to Dropbox ──────────────────────────────────────────────────────
try {
    $client = new DropboxClient();
    $client->upload($tempFile, $dropboxPath);
} catch (\Throwable $e) {
    $msg = 'Dropbox upload failed: ' . $e->getMessage();
    error_log("[CRON backup_dropbox:{$type}] {$msg}");
    @unlink($tempFile);
    BackupRun::fail($runId, $msg);
    db_execute('SELECT RELEASE_LOCK(?)', [$lockName]);
    exit(1);
}

@unlink($tempFile);
error_log("[CRON backup_dropbox:{$type}] Uploaded to Dropbox: {$dropboxPath} ({$localSize} bytes)");

// ── Record success ─────────────────────────────────────────────────────────
BackupRun::success($runId, $dropboxPath, $localSize);

// ── Retention cleanup ──────────────────────────────────────────────────────
ff_dropbox_retention($client, $type, $folderBase);

db_execute('SELECT RELEASE_LOCK(?)', [$lockName]);
exit(0);

// ──────────────────────────────────────────────────────────────────────────

/**
 * Deletes Dropbox files beyond the configured retention limit.
 *
 * DB:      DROPBOX_DB_RETENTION_DAYS (default 30) — delete files older than N days.
 * Storage: DROPBOX_STORAGE_RETENTION_COPIES (default 12) — keep the N most recent copies.
 */
function ff_dropbox_retention(DropboxClient $client, string $type, string $folderBase): void
{
    try {
        $folderPath = $folderBase . '/' . $type;
        $files      = $client->listFolder($folderPath);

        if (empty($files)) {
            return;
        }

        if ($type === 'db') {
            $retentionDays = max(1, (int) env('DROPBOX_DB_RETENTION_DAYS', 30));
            $cutoffTs      = time() - ($retentionDays * 86400);
            foreach ($files as $file) {
                $modTs = strtotime($file['modified']);
                if ($modTs !== false && $modTs < $cutoffTs) {
                    $client->deletePath($file['path']);
                    error_log("[CRON backup_dropbox:db] Retention: deleted {$file['path']}");
                }
            }
        } else {
            $retentionCopies = max(1, (int) env('DROPBOX_STORAGE_RETENTION_COPIES', 12));
            // Sort newest first; delete the tail beyond the retention limit.
            usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
            $toDelete = array_slice($files, $retentionCopies);
            foreach ($toDelete as $file) {
                $client->deletePath($file['path']);
                error_log("[CRON backup_dropbox:storage] Retention: deleted {$file['path']}");
            }
        }
    } catch (\Throwable $e) {
        // Retention failure must never abort the main backup success.
        error_log("[CRON backup_dropbox:{$type}] Retention cleanup failed: " . $e->getMessage());
    }
}
