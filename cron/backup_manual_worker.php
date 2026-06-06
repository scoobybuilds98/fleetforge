<?php
declare(strict_types=1);

/**
 * cron/backup_manual_worker.php
 *
 * Async worker for manual "download everything" full backups (D-BACKUP-3).
 * Runs every minute. A backup_runs row IS the job record — this worker claims
 * the oldest manual/full in_progress row, builds the bundle, and flips the row
 * to success/failed. The UI enqueues via api/v1/settings/backup/enqueue.php and
 * polls status.php → download.php.
 *
 * D-BACKUP-7: the bundle = latest s3/db artifact + latest s3/storage artifact,
 * downloaded via StorageClient and tarred (NO -z — the members are already
 * gzipped) into backups/manual/manual_<ts>.tar.
 *
 *   Crontab (production): * * * * *  /usr/bin/php /var/www/fleetforge/cron/backup_manual_worker.php >> /var/www/fleetforge/logs/cron.log 2>&1
 *   Local test:           php cron/backup_manual_worker.php
 *
 * Exit 0 cleanly if the advisory lock is held or nothing is pending. A missing
 * source artifact is a fail-soft "no source artifact" row + exit 0 (the bundle
 * needs at least one real backup to exist first).
 *
 * Retention: keep the last MANUAL_BACKUP_RETENTION_COPIES (env, default 5).
 *
 * Decisions: D9 (StorageClient), D21 (advisory lock), D-BACKUP-3, D-BACKUP-7
 * Session: S-BACKUP-3c
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Storage\StorageClient;
use FleetForge\Backup\BackupRun;

// ── Advisory lock (D21) — only one manual build at a time ────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_backup_manual', 0) AS ok", []);
if (!$lock || (int) $lock['ok'] !== 1) {
    exit(0);
}

$tmpDir  = '';
$tmpFile = '';

try {
    // ── Claim the oldest pending manual/full job ─────────────────────────────
    $job = db_row(
        "SELECT id FROM backup_runs
          WHERE destination = 'manual' AND backup_type = 'full' AND status = 'in_progress'
          ORDER BY id ASC LIMIT 1",
        []
    );
    if (!$job) {
        // Nothing pending — clean exit.
        db_execute("SELECT RELEASE_LOCK('ff_cron_backup_manual')", []);
        exit(0);
    }
    $runId = (int) $job['id'];
    BackupRun::progress($runId, 5, 'Starting');

    // ── Locate the source artifacts (D-BACKUP-7) ─────────────────────────────
    $s3Db      = BackupRun::lastSuccess('s3', 'db');
    $s3Storage = BackupRun::lastSuccess('s3', 'storage');

    if (!$s3Db || empty($s3Db['file_key']) || !$s3Storage || empty($s3Storage['file_key'])) {
        BackupRun::fail($runId, 'no source artifact — run a backup first');
        error_log("[CRON backup_manual_worker] run #{$runId}: missing source artifact — failed.");
        db_execute("SELECT RELEASE_LOCK('ff_cron_backup_manual')", []);
        exit(0);
    }

    $dbKey      = (string) $s3Db['file_key'];
    $storageKey = (string) $s3Storage['file_key'];

    // ── Stage both artifacts into a per-pid temp dir ─────────────────────────
    $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $ts     = $now->format('YmdHis');
    $tmpDir = sys_get_temp_dir() . '/ff_manual_backup_' . getmypid() . '_' . $ts;
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new \RuntimeException("Could not create staging dir: {$tmpDir}");
    }

    BackupRun::progress($runId, 15, 'Downloading database');
    StorageClient::download($dbKey, $tmpDir . '/' . basename($dbKey));
    BackupRun::progress($runId, 40, 'Downloading documents');
    StorageClient::download($storageKey, $tmpDir . '/' . basename($storageKey));

    // ── tar the staged members (NO -z — they're already gzipped) ─────────────
    $tmpFile = sys_get_temp_dir() . "/ff_manual_{$ts}.tar";
    $s3Key   = "backups/manual/manual_{$ts}.tar";

    BackupRun::progress($runId, 75, 'Bundling');
    $tarCmd = ['tar', '-cf', $tmpFile, '-C', $tmpDir, '.'];
    $tarProc = proc_open($tarCmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $tarPipes);
    if (!is_resource($tarProc)) {
        throw new \RuntimeException('proc_open: failed to start tar');
    }
    fclose($tarPipes[0]);
    stream_get_contents($tarPipes[1]);
    $tarStderr = (string) stream_get_contents($tarPipes[2]);
    fclose($tarPipes[1]);
    fclose($tarPipes[2]);
    $tarExit = proc_close($tarProc);
    if ($tarExit !== 0 && $tarExit !== 1) { // 1 = warnings (tolerated), 2 = fatal
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException("tar exited {$tarExit}: {$tarStderr}");
    }

    clearstatcache();
    $rawSize = file_exists($tmpFile) ? (int) filesize($tmpFile) : 0;
    if ($rawSize === 0) {
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException('Bundle tarball was not created (0 bytes)');
    }

    // Integrity check.
    $listProc = proc_open(['tar', '-tf', $tmpFile], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $listPipes);
    if (is_resource($listProc)) {
        fclose($listPipes[0]);
        stream_get_contents($listPipes[1]);
        $listErr = (string) stream_get_contents($listPipes[2]);
        fclose($listPipes[1]);
        fclose($listPipes[2]);
        $listExit = proc_close($listProc);
        if ($listExit !== 0) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException("Bundle integrity check failed (tar -tf exit {$listExit}): {$listErr}");
        }
    }

    // ── Upload + verify ──────────────────────────────────────────────────────
    BackupRun::progress($runId, 90, 'Uploading');
    StorageClient::upload($tmpFile, $s3Key);
    $tmpFile = ''; // upload() deletes the local temp on success
    if (!StorageClient::exists($s3Key)) {
        throw new \RuntimeException("Post-upload verification failed — not found at: {$s3Key}");
    }

    BackupRun::success($runId, $s3Key, $rawSize);
    $sizeMb = round($rawSize / 1048576, 2);
    error_log("[CRON backup_manual_worker] run #{$runId}: bundle complete {$s3Key} ({$sizeMb}MB)");

    // ── Retention ────────────────────────────────────────────────────────────
    ff_manual_backup_retention();

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    if ($tmpFile !== '' && file_exists($tmpFile)) {
        @unlink($tmpFile);
    }
    if (isset($runId) && $runId > 0) {
        BackupRun::fail($runId, $e->getMessage());
    }
    error_log("[CRON backup_manual_worker] FAILED: " . $e->getMessage());
    if ($tmpDir !== '' && is_dir($tmpDir)) {
        ff_manual_rrmdir($tmpDir);
    }
    db_execute("SELECT RELEASE_LOCK('ff_cron_backup_manual')", []);
    exit(1);

} finally {
    if ($tmpDir !== '' && is_dir($tmpDir)) {
        ff_manual_rrmdir($tmpDir);
    }
}

db_execute("SELECT RELEASE_LOCK('ff_cron_backup_manual')", []);
exit(0);

// ── Retention: keep the last N manual bundles ────────────────────────────────
function ff_manual_backup_retention(): void
{
    $keep = max(1, (int) env('MANUAL_BACKUP_RETENTION_COPIES', 5));
    try {
        $files = StorageClient::listByPrefix('backups/manual/');
    } catch (\Throwable $e) {
        error_log("[CRON backup_manual_worker] Retention list failed: " . $e->getMessage());
        return;
    }
    // Keep only real bundle artifacts; sort newest first by key (ts-named).
    $bundles = array_values(array_filter(
        $files,
        static fn($f) => (bool) preg_match('#^backups/manual/manual_\d+\.tar$#', (string) $f['key'])
    ));
    usort($bundles, static fn($a, $b) => strcmp((string) $b['key'], (string) $a['key']));
    foreach (array_slice($bundles, $keep) as $old) {
        try {
            StorageClient::delete((string) $old['key']);
            error_log("[CRON backup_manual_worker] Retention: deleted {$old['key']}");
        } catch (\Throwable $e) {
            error_log("[CRON backup_manual_worker] Retention delete failed for {$old['key']}: " . $e->getMessage());
        }
    }
}

// ── Recursive temp-dir teardown ──────────────────────────────────────────────
function ff_manual_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}
