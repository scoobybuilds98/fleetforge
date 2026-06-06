<?php
declare(strict_types=1);

/**
 * tests/_smoke_backup_storage.php
 *
 * Smoke test for the S-BACKUP-3a driver-agnostic storage backup.
 *
 * The key regression this guards: backup_storage.php must gather user content
 * through the StorageClient abstraction (driver-correct), NOT a hardcoded local
 * dir — otherwise it silently captures NOTHING under the s3 driver. `php -l`
 * does not prove the gather path runs, so C2–C5 EXECUTE the real cron as a
 * subprocess against the dev (local) driver, exactly the path prod exercises.
 *
 * Usage:  php tests/_smoke_backup_storage.php
 * Exit:   0 = all pass, 1 = any failure
 *
 * Session: S-BACKUP-3a
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Storage\StorageClient;

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "  PASS  {$label}\n";
}

function ko(string $label, string $detail = ''): void
{
    global $fail;
    $fail++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== _smoke_backup_storage ===\n\n";

$cronPath    = dirname(__DIR__) . '/cron/backup_storage.php';
$rand        = bin2hex(random_bytes(5));
$uploadsKey  = "uploads/_smoke_{$rand}.txt";
$backupsKey  = "backups/_smoke_{$rand}.txt";   // must be EXCLUDED
$artifactKey = 'backups/storage/' . gmdate('Y-m') . '/storage_' . gmdate('Y-m-d') . '.tar.gz';

// High-water mark so we only ever clean up rows this test creates.
$maxIdBefore = (int) (db_row("SELECT COALESCE(MAX(id),0) AS m FROM backup_runs", [])['m'] ?? 0);

// Track seeded keys for guaranteed cleanup.
$seeded = [];

/** Seed a known file into storage via the real StorageClient::upload(). */
function smoke_seed(string $key, string $body): void
{
    global $seeded;
    $tmp = sys_get_temp_dir() . '/ff_seed_' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, $body);
    StorageClient::upload($tmp, $key); // deletes $tmp on success
    $seeded[] = $key;
}

/** Run the cron as a subprocess; returns [exitCode, combinedOutput]. */
function smoke_run_cron(string $cronPath): array
{
    $out  = [];
    $code = null;
    exec('php ' . escapeshellarg($cronPath) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

/** List the entries inside a produced tarball (downloads it first). */
function smoke_tarball_entries(string $artifactKey): array
{
    if (!StorageClient::exists($artifactKey)) {
        return [];
    }
    $local = sys_get_temp_dir() . '/ff_smoke_artifact_' . bin2hex(random_bytes(4)) . '.tar.gz';
    StorageClient::download($artifactKey, $local);
    $entries = [];
    $code    = null;
    exec('tar -tzf ' . escapeshellarg($local) . ' 2>&1', $entries, $code);
    @unlink($local);
    return $code === 0 ? $entries : [];
}

try {
    // ── C1: pure include/exclude filter (unit-test the real function) ───────
    echo "C1: pure include/exclude filter\n";
    define('FF_BACKUP_STORAGE_LIB_ONLY', true);   // load helpers without running the backup
    require $cronPath;

    if (function_exists('ff_storage_backup_should_include')) {
        ok('ff_storage_backup_should_include() is defined (loaded without running the cron)');

        $cases = [
            ['backups/x.tar.gz', false],
            ['db-backups/y.sql.gz', false],
            ['uploads/a.pdf', true],
            ['branding/b.png', true],
        ];
        $allGood = true;
        foreach ($cases as [$k, $want]) {
            $got = ff_storage_backup_should_include($k);
            if ($got !== $want) {
                $allGood = false;
                ko("filter('{$k}') expected " . var_export($want, true) . ', got ' . var_export($got, true));
            }
        }
        if ($allGood) {
            ok("filter excludes backups/ + db-backups/, includes uploads/ + branding/");
        }
    } else {
        ko('ff_storage_backup_should_include() not defined after require');
    }

    // ── Seed content: one INCLUDED (uploads), one EXCLUDED (backups) ────────
    echo "\nSeeding fixtures: {$uploadsKey} (include) + {$backupsKey} (exclude)\n";
    smoke_seed($uploadsKey, "smoke content {$rand}\n");
    smoke_seed($backupsKey, "should NOT be archived {$rand}\n");

    // ── C2–C4: run the real cron, inspect the produced tarball + row ────────
    echo "\nC2-C4: execute cron subprocess + inspect artifact/row\n";
    [$code, $output] = smoke_run_cron($cronPath);

    // C4 (output health): no fatal / undefined-function in output, exit 0
    if (stripos($output, 'undefined function') === false && stripos($output, 'Fatal') === false) {
        ok('cron subprocess: no "undefined function"/"Fatal" in output');
    } else {
        ko('cron subprocess emitted a fatal', trim($output));
    }
    if ($code === 0) {
        ok('cron subprocess exited 0');
    } else {
        ko('cron subprocess exit code', "expected 0, got " . var_export($code, true) . " — output: " . trim($output));
    }

    $entries = smoke_tarball_entries($artifactKey);

    // C2: seeded uploads file IS present
    $uploadsPresent = false;
    foreach ($entries as $e) {
        if (str_contains($e, $uploadsKey)) { $uploadsPresent = true; break; }
    }
    if ($uploadsPresent) {
        ok("C2: seeded '{$uploadsKey}' IS present in the tarball");
    } else {
        ko("C2: seeded '{$uploadsKey}' missing from tarball", count($entries) . ' entries');
    }

    // C3: seeded backups file is NOT present (exclusion works)
    $backupsPresent = false;
    foreach ($entries as $e) {
        if (str_contains($e, "_smoke_{$rand}.txt") && str_contains($e, 'backups/')) { $backupsPresent = true; break; }
    }
    if (!$backupsPresent) {
        ok("C3: seeded '{$backupsKey}' is NOT in the tarball (exclusion works)");
    } else {
        ko("C3: seeded backups key leaked into the tarball");
    }

    // C4 (DB row): a success s3/storage backup_runs row was written this run
    $row = db_row(
        "SELECT id, status FROM backup_runs
         WHERE id > ? AND destination='s3' AND backup_type='storage'
         ORDER BY id DESC LIMIT 1",
        [$maxIdBefore]
    );
    if ($row && $row['status'] === 'success') {
        ok("C4: backup_runs s3/storage 'success' row written (id {$row['id']})");
    } else {
        ko('C4: expected success backup_runs row not found', 'got: ' . json_encode($row));
    }

    // ── C5: empty content set is SUCCESS, valid (near-empty) tarball ────────
    echo "\nC5: zero-content path produces a valid tarball (success, not error)\n";
    // Remove the seeded keys so they are not re-archived; the cron still runs
    // against the remaining dev store and must exit 0 with a success row.
    foreach ($seeded as $k) { @StorageClient::delete($k); }
    $seeded = [];

    [$code5, $output5] = smoke_run_cron($cronPath);
    if ($code5 === 0 && stripos($output5, 'Fatal') === false && stripos($output5, 'undefined function') === false) {
        ok('C5: cron exits 0 in cleaned state (no fatal)');
    } else {
        ko('C5: cron did not cleanly succeed', "code=" . var_export($code5, true) . " out=" . trim($output5));
    }
    $row5 = db_row(
        "SELECT id, status FROM backup_runs
         WHERE id > ? AND destination='s3' AND backup_type='storage'
         ORDER BY id DESC LIMIT 1",
        [$maxIdBefore]
    );
    if ($row5 && $row5['status'] === 'success') {
        ok('C5: a success backup_runs row was written');
    } else {
        ko('C5: expected success row not found', 'got: ' . json_encode($row5));
    }

    // C5 (literal empty input): the cron's tar form on an EMPTY dir yields a
    // valid, parseable archive — proving 0 files is success, not an error.
    // (Dev storage is never literally empty, so this validates the OS-level
    // assumption the cron's empty-content branch relies on, via the same
    // `tar -czf out -C dir .` invocation.)
    $emptyDir = sys_get_temp_dir() . '/ff_smoke_emptydir_' . bin2hex(random_bytes(4));
    @mkdir($emptyDir, 0700, true);
    $emptyTar = sys_get_temp_dir() . '/ff_smoke_empty_' . bin2hex(random_bytes(4)) . '.tar.gz';
    $cOut = [];
    $cCode = null;
    exec('tar -czf ' . escapeshellarg($emptyTar) . ' -C ' . escapeshellarg($emptyDir) . ' . 2>&1', $cOut, $cCode);
    $tOut = [];
    $tCode = null;
    exec('tar -tzf ' . escapeshellarg($emptyTar) . ' 2>&1', $tOut, $tCode);
    $emptySize = file_exists($emptyTar) ? (int) filesize($emptyTar) : 0;
    if ($cCode === 0 && $tCode === 0 && $emptySize > 0) {
        ok("C5: empty-dir tar yields a valid {$emptySize}-byte archive (0 files = success)");
    } else {
        ko('C5: empty-dir tar did not produce a valid archive', "tar={$cCode} list={$tCode} size={$emptySize}");
    }
    @unlink($emptyTar);
    @rmdir($emptyDir);

} catch (\Throwable $e) {
    ko('smoke threw unexpectedly', $e->getMessage());
} finally {
    // ── Cleanup: seeded keys, produced artifact, test-created rows ──────────
    foreach ($seeded as $k) { @StorageClient::delete($k); }
    if (StorageClient::exists($artifactKey)) {
        @StorageClient::delete($artifactKey);   // dev backup artifact — regenerable
    }
    db_execute(
        "DELETE FROM backup_runs WHERE id > ? AND destination='s3' AND backup_type='storage'",
        [$maxIdBefore]
    );
}

// ── Summary ───────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n=== {$pass}/{$total} PASS" . ($fail > 0 ? ", {$fail} FAIL" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
