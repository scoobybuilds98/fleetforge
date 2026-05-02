<?php
declare(strict_types=1);

/**
 * scripts/restore_db.php
 *
 * One-shot admin-run database recovery script. NOT a cron — run manually only.
 *
 * Usage:
 *   php scripts/restore_db.php --list
 *     → List all available backups in storage with dates and sizes
 *
 *   php scripts/restore_db.php --restore=<storage-key> [--target-db=fleetforge_restore_test] [--dry-run]
 *     → Download, verify, and restore a backup to a test database (safe default)
 *
 *   php scripts/restore_db.php --restore=<storage-key> --target-db=fleetforge --confirm-prod
 *     → Restore to the LIVE database. Requires --confirm-prod flag AND interactive
 *       confirmation string "yes I want to wipe production" typed at the prompt.
 *
 * The storage key is the path shown by --list (e.g.
 *   backups/db/2026-05-02/18/fleetforge_20260502180000.sql.gz)
 * or a full s3://bucket/... URI (bucket prefix will be stripped automatically).
 *
 * Safety rules:
 *   - Default target is fleetforge_restore_test, NEVER the live DB
 *   - Live DB restore requires BOTH --confirm-prod AND exact confirmation string
 *   - Download → verify integrity → restore (never restore a corrupt dump)
 *   - Every step is audit_logged
 *
 * Decisions: D9 (StorageClient), D21 (no advisory lock — admin-triggered only)
 * Audit findings resolved: #22 (no restore drill / runbook)
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Storage\StorageClient;

// ── CLI argument parsing ──────────────────────────────────────────────────────
$opts       = getopt('', ['list', 'restore:', 'target-db:', 'confirm-prod', 'dry-run']);
$doList     = isset($opts['list']);
$restoreKey = isset($opts['restore'])     ? (string) $opts['restore']     : '';
$targetDb   = isset($opts['target-db'])   ? (string) $opts['target-db']   : 'fleetforge_restore_test';
$confirmProd = isset($opts['confirm-prod']);
$dryRun     = isset($opts['dry-run']);

// ── Validate target DB name — alphanumeric + underscores only (injection guard)
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $targetDb)) {
    fwrite(STDERR, "Error: invalid target database name '{$targetDb}'.\n");
    exit(1);
}

// ── Route to command ─────────────────────────────────────────────────────────
if ($doList) {
    cmd_list();
    exit(0);
}

if ($restoreKey !== '') {
    cmd_restore($restoreKey, $targetDb, $confirmProd, $dryRun);
    exit(0);
}

// No valid mode — show usage
fwrite(STDERR, <<<USAGE
FleetForge DB Restore Script
Usage:
  php scripts/restore_db.php --list
  php scripts/restore_db.php --restore=<key> [--target-db=<db>] [--dry-run]
  php scripts/restore_db.php --restore=<key> --target-db=fleetforge --confirm-prod

Options:
  --list             List available backups with dates and sizes
  --restore=KEY      Storage key from --list output (or s3://bucket/... URI)
  --target-db=DB     Target database name (default: fleetforge_restore_test)
  --confirm-prod     Required when --target-db matches the live production database
  --dry-run          Download and verify backup without actually restoring

USAGE);
exit(1);

// ─────────────────────────────────────────────────────────────────────────────
// cmd_list — display all available DB backups with dates and sizes
// ─────────────────────────────────────────────────────────────────────────────
function cmd_list(): void
{
    $files = StorageClient::listByPrefix('backups/db/');

    if (empty($files)) {
        echo "No DB backups found in storage under backups/db/\n";
        echo "Run: php cron/backup_db.php to create the first backup.\n";
        return;
    }

    // Sort newest first
    usort($files, static fn($a, $b) => strcmp((string) $b['key'], (string) $a['key']));

    $driver = env('STORAGE_DRIVER', 'local');
    echo "\nFleetForge DB Backups (STORAGE_DRIVER={$driver})\n";
    echo str_repeat('=', 72) . "\n";
    printf("  %-3s  %-55s  %8s  %s\n", '#', 'KEY', 'SIZE', 'DATE (UTC)');
    echo str_repeat('-', 72) . "\n";

    foreach ($files as $i => $file) {
        $sizeMb  = $file['size'] >= 1048576
            ? round($file['size'] / 1048576, 2) . 'MB'
            : round($file['size'] / 1024, 1) . 'KB';
        $keyShort = strlen($file['key']) > 55 ? '...' . substr($file['key'], -52) : $file['key'];
        printf("  %-3d  %-55s  %8s  %s\n", $i + 1, $keyShort, $sizeMb, $file['last_modified']);
    }

    echo str_repeat('-', 72) . "\n";
    echo "  Total: " . count($files) . " backup(s)\n\n";
    echo "Restore with:\n  php scripts/restore_db.php --restore=<KEY> [--target-db=<db>]\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// cmd_restore — download, verify, and restore a backup
// ─────────────────────────────────────────────────────────────────────────────
function cmd_restore(string $restoreKey, string $targetDb, bool $confirmProd, bool $dryRun): void
{
    $restoreStart = time();
    $tmpGz  = '';
    $tmpSql = '';

    // Strip s3://bucket/ prefix if the user passed a full S3 URI
    if (str_starts_with($restoreKey, 's3://')) {
        $restoreKey = (string) preg_replace('#^s3://[^/]+/#', '', $restoreKey);
    }

    // Validate the key looks like a backup path
    if (!preg_match('#^backups/db/#', $restoreKey)) {
        fwrite(STDERR, "Error: restore key must start with 'backups/db/' — got: {$restoreKey}\n");
        fwrite(STDERR, "Run --list to see available backups.\n");
        exit(1);
    }

    // ── Live DB safety gate ───────────────────────────────────────────────────
    $liveDb = FF_DB_NAME;
    if ($targetDb === $liveDb) {
        if (!$confirmProd) {
            fwrite(STDERR, "ERROR: Refusing to restore to the live database '{$liveDb}' without --confirm-prod.\n");
            fwrite(STDERR, "Re-run with --confirm-prod to enable the interactive confirmation prompt.\n");
            fwrite(STDERR, "RECOMMENDED: restore to a test database first (--target-db=fleetforge_restore_test).\n");
            exit(1);
        }

        // Interactive double-confirmation for live DB restore
        echo "\n";
        echo "╔═══════════════════════════════════════════════════════════════╗\n";
        echo "║  ⚠  PRODUCTION DATABASE RESTORE — DO NOT RUN UNLESS APPROVED  ║\n";
        echo "╚═══════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "You are about to WIPE AND REPLACE the live database: {$liveDb}\n";
        echo "Backup to restore: {$restoreKey}\n";
        echo "\n";
        echo "Type exactly: yes I want to wipe production\n";
        echo "> ";

        $confirmation = trim((string) fgets(STDIN));
        if ($confirmation !== 'yes I want to wipe production') {
            fwrite(STDERR, "Confirmation text did not match. Aborting.\n");
            exit(1);
        }
        echo "\n";
    }

    try {
        // ── Audit: restore initiated ─────────────────────────────────────────
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'restore',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'restore_db',
            'notes'        => "Restore initiated: source={$restoreKey}, target={$targetDb}"
                . ($dryRun ? ' (dry-run)' : '')
                . ($targetDb === $liveDb ? ' [PRODUCTION]' : ''),
            'ip_address'   => '127.0.0.1',
        ]);

        // ── Step 1: Verify backup exists ─────────────────────────────────────
        echo "Step 1/6: Checking backup exists in storage...\n";
        if (!StorageClient::exists($restoreKey)) {
            throw new \RuntimeException("Backup not found at storage key: {$restoreKey}");
        }
        $backupSize = StorageClient::fileSize($restoreKey);
        $sizeMb     = round($backupSize / 1048576, 2);
        echo "  Found: {$restoreKey} ({$sizeMb}MB)\n";

        // ── Step 2: Download to /tmp ──────────────────────────────────────────
        echo "Step 2/6: Downloading backup to temp directory...\n";
        $ts     = date('YmdHis');
        $tmpGz  = sys_get_temp_dir() . "/ff_restore_{$ts}.sql.gz";
        $tmpSql = sys_get_temp_dir() . "/ff_restore_{$ts}.sql";

        $downloadStart = microtime(true);

        // StorageClient::url() returns a signed URL (or local path URL).
        // For download we use the local driver path directly, or for S3 we
        // stream via file_get_contents on the pre-signed URL.
        if (env('STORAGE_DRIVER', 'local') === 'local') {
            $localPath = FF_ROOT . '/storage/' . $restoreKey;
            if (!copy($localPath, $tmpGz)) {
                throw new \RuntimeException("Failed to copy local backup to temp: {$localPath}");
            }
        } else {
            // S3: download via signed URL with stream context (avoids loading whole file in memory)
            $signedUrl = StorageClient::url($restoreKey, 3600);
            $ctx = stream_context_create(['http' => ['timeout' => 300]]);
            $fh = fopen($tmpGz, 'wb');
            if (!$fh) {
                throw new \RuntimeException("Cannot create temp file: {$tmpGz}");
            }
            $src = fopen($signedUrl, 'rb', false, $ctx);
            if (!$src) {
                fclose($fh);
                throw new \RuntimeException("Cannot open download stream for: {$restoreKey}");
            }
            while (!feof($src)) {
                $chunk = fread($src, 65536);
                if ($chunk !== false) fwrite($fh, $chunk);
            }
            fclose($src);
            fclose($fh);
        }

        $downloadSec = round(microtime(true) - $downloadStart, 1);
        echo "  Downloaded in {$downloadSec}s\n";

        // ── Step 3: Decompress and verify integrity ───────────────────────────
        echo "Step 3/6: Decompressing and verifying backup integrity...\n";

        $decompressStart = microtime(true);
        $gz  = gzopen($tmpGz, 'rb');
        $fhOut = fopen($tmpSql, 'wb');
        if ($gz === false || $fhOut === false) {
            throw new \RuntimeException("Failed to open gzip file for decompression: {$tmpGz}");
        }

        $bytesWritten = 0;
        $headerBuf    = '';
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 65536);
            if ($chunk === false) break;
            if ($bytesWritten < 200) {
                $headerBuf .= $chunk;
            }
            fwrite($fhOut, $chunk);
            $bytesWritten += strlen($chunk);
        }
        gzclose($gz);
        fclose($fhOut);

        $decompressSec = round(microtime(true) - $decompressStart, 1);

        // Verify MySQL dump header
        if (!str_contains($headerBuf, '-- MySQL dump') && !str_contains($headerBuf, '-- MariaDB dump')) {
            @unlink($tmpGz);
            @unlink($tmpSql);
            throw new \RuntimeException(
                "Integrity check FAILED — not a valid MySQL dump. Header: " . json_encode(substr($headerBuf, 0, 80))
            );
        }

        $sqlSizeMb = round($bytesWritten / 1048576, 2);
        echo "  Integrity OK: {$sqlSizeMb}MB uncompressed, decompressed in {$decompressSec}s\n";

        // Clean up the .gz now that we have the .sql
        @unlink($tmpGz);
        $tmpGz = '';

        if ($dryRun) {
            @unlink($tmpSql);
            echo "\nDRY-RUN complete — backup downloaded and verified successfully.\n";
            echo "Remove --dry-run to actually restore to: {$targetDb}\n";

            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'restore',
                'module'       => 'backups',
                'entity_type'  => 'cron',
                'entity_id'    => null,
                'entity_label' => 'restore_db',
                'notes'        => "Restore dry-run complete: backup verified OK, not restored (dry-run mode)",
                'ip_address'   => '127.0.0.1',
            ]);
            return;
        }

        // ── Step 4: Create target DB if it doesn't exist ─────────────────────
        echo "Step 4/6: Ensuring target database '{$targetDb}' exists...\n";
        // $targetDb already validated as safe identifier (alphanumeric + underscore regex above)
        db_execute(
            "CREATE DATABASE IF NOT EXISTS `{$targetDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            []
        );
        echo "  Database ready: {$targetDb}\n";

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'restore',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'restore_db',
            'notes'        => "Restore Step 4/6: target DB '{$targetDb}' created/confirmed",
            'ip_address'   => '127.0.0.1',
        ]);

        // ── Step 5: Restore with mysql binary ────────────────────────────────
        echo "Step 5/6: Restoring to '{$targetDb}' via mysql...\n";

        $mysqlCmd = [
            'mysql',
            '--host=' . FF_DB_HOST,
            '--port=' . FF_DB_PORT,
            '--user=' . FF_DB_USER,
            '--password=' . FF_DB_PASS,
            $targetDb,
        ];

        $restoreProcStart = microtime(true);

        $mysqlProc = proc_open($mysqlCmd, [
            0 => ['file', $tmpSql, 'r'], // stdin from decompressed SQL file
            1 => ['pipe', 'w'],          // stdout
            2 => ['pipe', 'w'],          // stderr
        ], $mysqlPipes);

        if (!is_resource($mysqlProc)) {
            throw new \RuntimeException('proc_open: failed to start mysql');
        }

        $mysqlOut  = (string) stream_get_contents($mysqlPipes[1]);
        $mysqlErr  = (string) stream_get_contents($mysqlPipes[2]);
        fclose($mysqlPipes[1]);
        fclose($mysqlPipes[2]);
        $mysqlExit = proc_close($mysqlProc);

        @unlink($tmpSql);
        $tmpSql = '';

        if ($mysqlExit !== 0) {
            throw new \RuntimeException("mysql restore exited {$mysqlExit}: {$mysqlErr}");
        }

        $restoreSec = round(microtime(true) - $restoreProcStart, 1);
        echo "  Restore complete in {$restoreSec}s\n";

        // ── Step 6: Verify sample row counts ─────────────────────────────────
        echo "Step 6/6: Verifying restored data via sample queries...\n";

        // Connect to the restored database directly via PDO for verification
        $verifyPdo = new \PDO(
            "mysql:host=" . FF_DB_HOST . ";port=" . FF_DB_PORT . ";dbname={$targetDb};charset=utf8mb4",
            FF_DB_USER,
            FF_DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $verifyTables = ['customers', 'invoices', 'leases'];
        $verifyCounts = [];
        foreach ($verifyTables as $tbl) {
            $stmt = $verifyPdo->query("SELECT COUNT(*) AS cnt FROM `{$tbl}`");
            $row  = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
            $verifyCounts[$tbl] = $row ? (int) $row['cnt'] : -1;
            printf("  %-20s %d rows\n", $tbl . ':', $verifyCounts[$tbl]);
        }
        $verifyPdo = null;

        // Also show counts from the source database for comparison
        echo "\n  Source DB ({$liveDb}) counts for comparison:\n";
        foreach ($verifyTables as $tbl) {
            $srcCount = db_count("SELECT COUNT(*) FROM `{$tbl}`", []);
            printf("  %-20s %d rows\n", '  ' . $tbl . ':', $srcCount);
        }

        $totalSec = time() - $restoreStart;
        echo "\n  Restore finished — total RTO: {$totalSec}s (" . round($totalSec / 60, 1) . " min)\n\n";

        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'system',
            'action'       => 'restore',
            'module'       => 'backups',
            'entity_type'  => 'cron',
            'entity_id'    => null,
            'entity_label' => 'restore_db',
            'notes'        => "Restore complete: source={$restoreKey}, target={$targetDb}"
                . " (download={$downloadSec}s, decompress={$decompressSec}s, restore={$restoreSec}s, total={$totalSec}s)"
                . " — customers={$verifyCounts['customers']}, invoices={$verifyCounts['invoices']}, leases={$verifyCounts['leases']}",
            'ip_address'   => '127.0.0.1',
        ]);

        if ($targetDb === $liveDb) {
            echo "WARNING: Live DB was replaced. Verify application behaviour before resuming operations.\n";
        } else {
            echo "Test DB '{$targetDb}' restored. Drop when done:\n";
            echo "  mysql -u " . FF_DB_USER . " -p -e 'DROP DATABASE IF EXISTS {$targetDb};'\n\n";
        }

    } catch (\Throwable $e) {
        if ($tmpGz !== '' && file_exists($tmpGz)) @unlink($tmpGz);
        if ($tmpSql !== '' && file_exists($tmpSql)) @unlink($tmpSql);

        fwrite(STDERR, "\nRestore FAILED: " . $e->getMessage() . "\n");

        try {
            db_insert('audit_log', [
                'user_id'      => null,
                'user_name'    => 'system',
                'action'       => 'restore',
                'module'       => 'backups',
                'entity_type'  => 'cron',
                'entity_id'    => null,
                'entity_label' => 'restore_db',
                'notes'        => "Restore FAILED: source={$restoreKey}, target={$targetDb}: " . $e->getMessage(),
                'ip_address'   => '127.0.0.1',
            ]);
        } catch (\Throwable) {}

        exit(1);
    }
}
