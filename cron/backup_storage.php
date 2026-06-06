<?php
declare(strict_types=1);

/**
 * cron/backup_storage.php
 *
 * Weekly storage tarball cron — runs Sunday 03:00 UTC.
 * Gathers ALL user content through the StorageClient abstraction (NOT a
 * hardcoded local dir), tars it into a gzipped archive, and uploads via
 * StorageClient (S3 in production, local storage/backups/storage/ in dev).
 *
 * D-BACKUP-6 (driver-agnostic gather): content is listed + downloaded via
 * StorageClient::listByPrefix('') + ::download(), so dev (local driver) and
 * prod (s3 driver) run the SAME code path. There is NO STORAGE_DRIVER branch.
 * The previous version tarred the LOCAL storage/uploads/ dir, which is EMPTY
 * on prod (real uploads live in S3) — so the backup silently captured nothing.
 *
 * Crontab (production): 0 3 * * 0  /usr/bin/php /var/www/fleetforge/cron/backup_storage.php >> /var/www/fleetforge/logs/cron.log 2>&1
 * Local test:           php /Users/avi/Documents/fleetforge/cron/backup_storage.php
 *
 * Backup path: backups/storage/{YYYY-MM}/storage_{YYYY-MM-DD}.tar.gz
 *
 * Retention: 12 months of weekly tarballs (configurable via
 *            BACKUP_STORAGE_RETENTION_MONTHS env, default 12).
 *
 * Decisions: D9 (StorageClient abstraction), D21 (advisory lock), D-BACKUP-6
 * Audit findings resolved: #9 (no storage backup cron)
 */

require_once dirname(__DIR__) . '/config/app.php';
\FleetForge\Observability\Sentry::init();

use FleetForge\Storage\StorageClient;
use FleetForge\Backup\BackupRun;

// When required by a test harness purely to access the pure helpers below
// (ff_storage_backup_should_include), define the functions but DO NOT run the
// backup. Top-level function declarations are hoisted, so the early return
// still leaves every helper in this file callable.
if (defined('FF_BACKUP_STORAGE_LIB_ONLY')) {
    return;
}

// ── Advisory lock (D21) ───────────────────────────────────────────────────────
$lock = db_row("SELECT GET_LOCK('ff_cron_backup_storage', 0) AS ok", []);
if (!$lock || (int)$lock['ok'] !== 1) {
    exit(0);
}

$tmpFile     = '';
$stageDir    = '';
$start       = time();
$backupRunId = BackupRun::start('s3', 'storage');

try {
    // ── Log cron start ───────────────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'backups',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'backup_storage',
        'notes'        => 'Storage tarball backup started',
        'ip_address'   => '127.0.0.1',
    ]);

    // ── Generate backup paths ────────────────────────────────────────────────
    $now      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $monthDir = $now->format('Y-m');
    $dateStr  = $now->format('Y-m-d');
    $s3Key    = "backups/storage/{$monthDir}/storage_{$dateStr}.tar.gz";
    $tmpFile  = sys_get_temp_dir() . "/ff_storage_{$now->format('YmdHis')}.tar.gz";

    // ── Gather user content via StorageClient (D-BACKUP-6, driver-agnostic) ───
    // List EVERY key under the storage root, then keep only user content —
    // excluding any backups so the storage backup never tars itself. This runs
    // identically under the local (dev) and s3 (prod) drivers.
    $allEntries  = StorageClient::listByPrefix('');
    $contentKeys = [];
    foreach ($allEntries as $entry) {
        $key = (string) ($entry['key'] ?? '');
        if ($key !== '' && ff_storage_backup_should_include($key)) {
            $contentKeys[] = $key;
        }
    }

    // ── Stage content into a temp dir, preserving relative key paths ─────────
    // Each key becomes a sub-path under the staging dir so the tarball mirrors
    // the storage layout (uploads/x.pdf, customers/42/contract.pdf, ...).
    $stageDir = sys_get_temp_dir() . '/ff_storage_backup_' . getmypid() . '_' . $now->format('YmdHis');
    if (!is_dir($stageDir) && !mkdir($stageDir, 0700, true) && !is_dir($stageDir)) {
        throw new \RuntimeException("Could not create staging dir: {$stageDir}");
    }
    foreach ($contentKeys as $key) {
        $dest    = $stageDir . '/' . $key;
        $destDir = dirname($dest);
        if (!is_dir($destDir) && !mkdir($destDir, 0700, true) && !is_dir($destDir)) {
            throw new \RuntimeException("Could not create staging subdir: {$destDir}");
        }
        StorageClient::download($key, $dest);
    }

    // ── Create tar.gz via proc_open array form (no shell — no injection risk) ─
    // -C changes to the staging dir so paths inside the tarball are relative.
    // '.' archives the whole staged tree (empty content set → a valid, near-empty
    // tarball, which is a SUCCESS, not an error — see the size guard below).
    $tarCmd = [
        'tar',
        '-czf', $tmpFile,
        '-C', $stageDir,
        '.',
    ];

    $tarProc = proc_open($tarCmd, [
        0 => ['pipe', 'r'],  // stdin  (not used)
        1 => ['pipe', 'w'],  // stdout (tar doesn't write to stdout with -f)
        2 => ['pipe', 'w'],  // stderr → checked for errors
    ], $tarPipes);

    if (!is_resource($tarProc)) {
        throw new \RuntimeException('proc_open: failed to start tar');
    }
    fclose($tarPipes[0]);

    $tarStdout = (string) stream_get_contents($tarPipes[1]);
    $tarStderr = (string) stream_get_contents($tarPipes[2]);
    fclose($tarPipes[1]);
    fclose($tarPipes[2]);
    $tarExit = proc_close($tarProc);

    // tar exit 1 = warnings (e.g. file changed during archive) — tolerated
    // tar exit 2 = fatal error — abort
    if ($tarExit === 2) {
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException("tar fatal error (exit 2): {$tarStderr}");
    }
    if ($tarExit !== 0 && $tarExit !== 1) {
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException("tar exited {$tarExit}: {$tarStderr}");
    }

    // ── Verify tarball was produced and is structurally valid ────────────────
    // A tarball is always expected (even for 0 content keys → a near-empty but
    // valid archive). The "suspiciously small" floor only applies when there IS
    // content to capture — 0 content keys legitimately produces a tiny tarball.
    clearstatcache();
    $rawSize = file_exists($tmpFile) ? (int) filesize($tmpFile) : 0;
    if ($rawSize === 0) {
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException('Tarball was not created (0 bytes)');
    }
    if (count($contentKeys) > 0 && $rawSize < 32) {
        @unlink($tmpFile);
        $tmpFile = '';
        throw new \RuntimeException("Tarball suspiciously small ({$rawSize} bytes) for " . count($contentKeys) . ' content keys');
    }

    // Quick integrity check — list tarball contents (SC8)
    $listProc = proc_open(['tar', '-tzf', $tmpFile], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $listPipes);

    if (is_resource($listProc)) {
        fclose($listPipes[0]);
        $listOut = (string) stream_get_contents($listPipes[1]);
        $listErr = (string) stream_get_contents($listPipes[2]);
        fclose($listPipes[1]);
        fclose($listPipes[2]);
        $listExit = proc_close($listProc);

        if ($listExit !== 0) {
            @unlink($tmpFile);
            $tmpFile = '';
            throw new \RuntimeException("Tarball integrity check failed (tar -tzf exit {$listExit}): {$listErr}");
        }

        $fileCount = max(0, substr_count(rtrim($listOut), "\n"));
    } else {
        $fileCount = -1; // tar list check unavailable
    }

    $sizeMb = round($rawSize / 1048576, 2);

    // ── Upload via StorageClient ─────────────────────────────────────────────
    StorageClient::upload($tmpFile, $s3Key);
    $tmpFile = ''; // StorageClient::upload() deletes tmpFile on success

    // ── Verify upload ────────────────────────────────────────────────────────
    if (!StorageClient::exists($s3Key)) {
        throw new \RuntimeException("Post-upload verification failed — not found at: {$s3Key}");
    }

    $duration     = time() - $start;
    $contentCount = count($contentKeys); // actual user-content files (tar -tzf count includes dir entries)

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'backups',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'backup_storage',
        'notes'        => "Storage backup successful: {$s3Key} ({$sizeMb}MB, {$contentCount} files archived, {$duration}s)",
        'ip_address'   => '127.0.0.1',
    ]);

    error_log("[CRON backup_storage] Backup complete: {$s3Key} ({$sizeMb}MB, {$contentCount} files, {$duration}s)");
    BackupRun::success($backupRunId, $s3Key, $rawSize);

    // ── Retention cleanup ────────────────────────────────────────────────────
    ff_backup_storage_retention();

} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    if ($tmpFile !== '' && file_exists($tmpFile)) {
        @unlink($tmpFile);
    }

    $msg = $e->getMessage();
    error_log("[CRON backup_storage] FAILED: {$msg}");
    BackupRun::fail($backupRunId, $msg);

    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'system',
        'action'       => 'cron',
        'module'       => 'backups',
        'entity_type'  => 'cron',
        'entity_id'    => null,
        'entity_label' => 'backup_storage',
        'notes'        => "Storage backup failed: {$msg}",
        'ip_address'   => '127.0.0.1',
    ]);

    exit(1);

} finally {
    // Always remove the staging tree, even on failure.
    if ($stageDir !== '' && is_dir($stageDir)) {
        ff_rrmdir($stageDir);
    }
    db_execute("SELECT RELEASE_LOCK('ff_cron_backup_storage')", []);
}

// ── Pure include/exclude filter (unit-tested by the smoke; D-BACKUP-6) ───────
// Decides whether a storage key belongs in the storage backup. Excludes any
// backup artifacts so the storage backup never recursively captures backups.
// Pure: no I/O, no globals — safe to call in isolation.
function ff_storage_backup_should_include(string $key): bool
{
    if (str_starts_with($key, 'backups/') || str_starts_with($key, 'db-backups/')) {
        return false;
    }
    return true;
}

// ── Recursive directory removal (staging-dir teardown) ───────────────────────
function ff_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

// ── 12-month retention cleanup for storage tarballs ──────────────────────────
function ff_backup_storage_retention(): void
{
    $retentionMonths = max(1, (int) env('BACKUP_STORAGE_RETENTION_MONTHS', 12));
    $cutoffTs        = strtotime("-{$retentionMonths} months");
    if ($cutoffTs === false) {
        error_log("[CRON backup_storage] Could not compute retention cutoff");
        return;
    }
    $cutoff = new \DateTimeImmutable('@' . $cutoffTs, new \DateTimeZone('UTC'));

    try {
        $allFiles = StorageClient::listByPrefix('backups/storage/');
    } catch (\Throwable $e) {
        error_log("[CRON backup_storage] Retention list failed: " . $e->getMessage());
        return;
    }

    $toDelete = [];
    $toKeep   = [];

    foreach ($allFiles as $file) {
        // Parse date from path: backups/storage/{YYYY-MM}/storage_{YYYY-MM-DD}.tar.gz
        if (!preg_match('#^backups/storage/\d{4}-\d{2}/storage_(\d{4}-\d{2}-\d{2})\.tar\.gz$#', (string) $file['key'], $m)) {
            $toKeep[] = $file;
            continue;
        }

        try {
            $fileDate = new \DateTimeImmutable($m[1], new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            $toKeep[] = $file;
            continue;
        }

        if ($fileDate >= $cutoff) {
            $toKeep[] = $file;
        } else {
            $toDelete[] = $file;
        }
    }

    $deletedCount = 0;
    $deleteErrors = 0;

    foreach ($toDelete as $file) {
        try {
            StorageClient::delete((string) $file['key']);
            $deletedCount++;
        } catch (\Throwable $e) {
            $deleteErrors++;
            error_log("[CRON backup_storage] Retention delete failed for {$file['key']}: " . $e->getMessage());
        }
    }

    $notes = "Storage retention cleanup: kept=" . count($toKeep)
        . ", deleted={$deletedCount}"
        . ($deleteErrors > 0 ? ", errors={$deleteErrors}" : '')
        . " (policy: {$retentionMonths} months)";

    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'cron',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'backup_storage_retention',
            'notes'        => $notes,
            'ip_address'   => '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        error_log("[CRON backup_storage] Retention audit_log failed: " . $e->getMessage());
    }
}
