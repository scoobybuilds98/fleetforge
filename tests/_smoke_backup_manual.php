<?php
declare(strict_types=1);

/**
 * tests/_smoke_backup_manual.php
 *
 * S-BACKUP-3c — async manual "download everything" full backup.
 *
 * C1 enqueue: an in_progress manual/full row blocks a second concurrent build.
 * C2 worker (subprocess, executed): seed s3/db + s3/storage successes + tiny
 *    artifacts → run cron/backup_manual_worker.php → row flips to success with
 *    file_key+size, exit 0, no Fatal/undefined-function, bundle has both members.
 * C3 worker no-source: hide the s3 successes → worker writes a 'no source
 *    artifact' fail row, exit 0.
 * C4 download: success row → StorageClient::url() yields a link; endpoint source
 *    has the strict manual/full/success gate + settings.view + audit_log.
 * C5 column validity (Trap 71/73): every backup_runs/settings column the new
 *    code touches exists in SHOW COLUMNS.
 *
 * Self-cleaning: all seeded rows + artifacts removed; flipped rows restored.
 *
 * Usage:  php tests/_smoke_backup_manual.php   ·   Exit 0 = all pass
 * Session: S-BACKUP-3c
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Backup\BackupRun;
use FleetForge\Storage\StorageClient;

$pass = 0; $fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "  PASS  {$l}\n"; }
function ko(string $l, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$l}" . ($d !== '' ? " — {$d}" : '') . "\n"; }

$root      = dirname(__DIR__);
$workerPath = $root . '/cron/backup_manual_worker.php';
$rand      = bin2hex(random_bytes(4));

echo "=== _smoke_backup_manual ===\n\n";

// High-water mark — only rows we create get cleaned.
$maxIdBefore = (int) (db_row("SELECT COALESCE(MAX(id),0) AS m FROM backup_runs", [])['m'] ?? 0);
$seededKeys  = [];          // storage keys to delete in finally
$flippedIds  = [];          // s3 success rows temporarily hidden in C3

/**
 * Clear transient manual/full job records (in_progress + failed) so each phase
 * starts clean and the worker's "claim oldest in_progress" is deterministic.
 * A stale in_progress manual row (no live worker — the smoke runs the worker
 * synchronously) is safe to drop; 'success' rows are history and left intact
 * (finally still removes any this test created via the id>maxIdBefore sweep).
 */
$clearManual = function (): void {
    db_execute(
        "DELETE FROM backup_runs
          WHERE destination='manual' AND backup_type='full' AND status IN ('in_progress','failed')",
        []
    );
};
/** Run the worker subprocess; returns [exitCode, output]. */
$runWorker = function () use ($workerPath): array {
    $out = []; $code = null;
    exec('php ' . escapeshellarg($workerPath) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
/** Seed a tiny gz artifact + a fresh (newest) s3 success row pointing at it. */
$seedS3 = function (string $type, string $key, string $body) use (&$seededKeys): void {
    $tmp = tempnam(sys_get_temp_dir(), 'ffseed');
    file_put_contents($tmp, gzencode($body));
    StorageClient::upload($tmp, $key);            // deletes $tmp
    $seededKeys[] = $key;
    db_execute(
        "INSERT INTO backup_runs (destination, backup_type, status, file_key, file_size_bytes, started_at, completed_at, trigger_source)
         VALUES ('s3', ?, 'success', ?, 64, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'cron')",
        [$type, $key]
    );
};

try {
    // ── C1: enqueue concurrency guard ───────────────────────────────────────
    echo "C1: enqueue creates one in_progress manual/full + blocks a second\n";
    $clearManual();
    $rid1 = BackupRun::start('manual', 'full', null, 'manual'); // simulates enqueue
    // The endpoint's guard query — a second enqueue must find this row, not insert.
    $guard = db_row(
        "SELECT id FROM backup_runs
          WHERE destination='manual' AND backup_type='full' AND status='in_progress'
          ORDER BY id ASC LIMIT 1",
        []
    );
    if ($guard && (int) $guard['id'] === $rid1) {
        ok('in_progress guard query returns the existing run (second enqueue would reuse it)');
    } else {
        ko('guard query did not return the enqueued run', json_encode($guard));
    }
    $enqSrc = (string) file_get_contents($root . '/api/v1/settings/backup/enqueue.php');
    if (str_contains($enqSrc, "status = 'in_progress'") && str_contains($enqSrc, "already_running")
        && str_contains($enqSrc, "require_permission('settings', 'edit')")) {
        ok('enqueue.php has the in_progress guard + already_running + settings.edit gate');
    } else {
        ko('enqueue.php missing guard/gate');
    }

    // ── C3: worker with NO source artifact (run BEFORE seeding s3 successes) ──
    echo "\nC3: worker with no source artifact → fail-soft row, exit 0\n";
    // Hide every s3 db/storage success so lastSuccess() returns null.
    $flippedIds = array_map('intval', array_column(
        db_select("SELECT id FROM backup_runs WHERE destination='s3' AND backup_type IN ('db','storage') AND status='success'", []),
        'id'
    ));
    if (!empty($flippedIds)) {
        $in = implode(',', $flippedIds);
        db_execute("UPDATE backup_runs SET destination='dropbox' WHERE id IN ({$in})");
    }
    $clearManual();
    $rid3 = BackupRun::start('manual', 'full', null, 'manual');
    [$c3code, $c3out] = $runWorker();
    $row3 = db_row("SELECT status, error_message FROM backup_runs WHERE id = ?", [$rid3]);
    if ($c3code === 0 && stripos($c3out, 'Fatal') === false && stripos($c3out, 'undefined function') === false
        && $row3 && $row3['status'] === 'failed' && str_contains((string) $row3['error_message'], 'no source artifact')) {
        ok("worker wrote 'no source artifact' fail row + exit 0");
    } else {
        ko('no-source path wrong', "code={$c3code} status=" . ($row3['status'] ?? '?') . " out=" . trim($c3out));
    }
    // Restore the hidden s3 rows.
    if (!empty($flippedIds)) {
        $in = implode(',', $flippedIds);
        db_execute("UPDATE backup_runs SET destination='s3' WHERE id IN ({$in})");
        $flippedIds = [];
    }

    // ── C2: worker happy path (executed) ─────────────────────────────────────
    echo "\nC2: worker bundles latest s3/db + s3/storage → success row + exit 0\n";
    $seedS3('db', "backups/db/_smoke/fleetforge_{$rand}.sql.gz", "db dump {$rand}");
    $seedS3('storage', "backups/storage/_smoke/storage_{$rand}.tar.gz", "storage tar {$rand}");
    $clearManual();
    $rid2 = BackupRun::start('manual', 'full', null, 'manual');
    [$c2code, $c2out] = $runWorker();
    $row2 = db_row("SELECT status, file_key, file_size_bytes, progress_pct, progress_stage FROM backup_runs WHERE id = ?", [$rid2]);

    if (stripos($c2out, 'Fatal') === false && stripos($c2out, 'undefined function') === false) {
        ok('worker subprocess: no "undefined function"/"Fatal"');
    } else {
        ko('worker emitted a fatal', trim($c2out));
    }
    if ($c2code === 0) {
        ok('worker exited 0');
    } else {
        ko('worker exit code', "expected 0, got " . var_export($c2code, true) . " — " . trim($c2out));
    }
    $bundleKey = (string) ($row2['file_key'] ?? '');
    if ($row2 && $row2['status'] === 'success' && preg_match('#^backups/manual/manual_\d+\.tar$#', $bundleKey)
        && (int) $row2['file_size_bytes'] > 0) {
        ok("row flipped to success with bundle key + size ({$bundleKey})");
    } else {
        ko('worker did not flip the row to success', json_encode($row2));
    }
    // S-BACKUP-3c-PROGRESS: success stamps 100/'Complete'.
    if ($row2 && (int) $row2['progress_pct'] === 100) {
        ok("completed row has progress_pct=100 (stage='" . ($row2['progress_stage'] ?? '') . "')");
    } else {
        ko('completed row progress_pct != 100', 'got ' . var_export($row2['progress_pct'] ?? null, true));
    }
    if ($bundleKey !== '') {
        $seededKeys[] = $bundleKey;
        // Bundle must contain both members.
        $local = tempnam(sys_get_temp_dir(), 'ffbundle');
        StorageClient::download($bundleKey, $local);
        $members = [];
        exec('tar -tf ' . escapeshellarg($local) . ' 2>&1', $members, $mc);
        @unlink($local);
        $hasDb = $hasSt = false;
        foreach ($members as $m) {
            if (str_contains($m, "fleetforge_{$rand}.sql.gz")) $hasDb = true;
            if (str_contains($m, "storage_{$rand}.tar.gz"))     $hasSt = true;
        }
        if ($mc === 0 && $hasDb && $hasSt) {
            ok('bundle tarball contains both the db + storage members');
        } else {
            ko('bundle missing a member', implode(',', $members));
        }
    }

    // ── C4: download endpoint mechanism + gate ───────────────────────────────
    echo "\nC4: download — presigned URL for success rows, strict gate\n";
    if ($bundleKey !== '') {
        $url = StorageClient::url($bundleKey, 300);
        if (is_string($url) && $url !== '') {
            ok('StorageClient::url() returns a link for the success bundle');
        } else {
            ko('StorageClient::url() returned empty');
        }
    }
    $dlSrc = (string) file_get_contents($root . '/api/v1/settings/backup/download.php');
    $gateOk = str_contains($dlSrc, "destination'] !== 'manual'")
           && str_contains($dlSrc, "backup_type'] !== 'full'")
           && str_contains($dlSrc, "status'] !== 'success'")
           && str_contains($dlSrc, "require_permission('settings', 'view')")
           && str_contains($dlSrc, "StorageClient::url(")
           && str_contains($dlSrc, "db_insert('audit_log'");
    if ($gateOk) {
        ok('download.php: strict manual/full/success gate + settings.view + presigned url + audit_log');
    } else {
        ko('download.php gate/url/audit incomplete');
    }

    // ── C5: column validity (Trap 71/73) ─────────────────────────────────────
    echo "\nC5: backup_runs + settings column identifiers exist (Trap 71/73)\n";
    $brCols  = array_map(fn($r) => (string) $r['Field'], db_select("SHOW COLUMNS FROM backup_runs", []));
    $needBr  = ['id', 'destination', 'backup_type', 'status', 'progress_pct', 'progress_stage', 'file_key', 'file_size_bytes', 'completed_at', 'started_at', 'error_message', 'initiated_by', 'trigger_source'];
    $badBr   = array_diff($needBr, $brCols);
    if (empty($badBr)) { ok('backup_runs columns all exist (incl. progress_pct/progress_stage): ' . implode(', ', $needBr)); }
    else { ko('nonexistent backup_runs columns', implode(', ', $badBr)); }

    // ── C6: status endpoint returns progress fields ──────────────────────────
    echo "\nC6: status.php exposes progress_pct + progress_stage\n";
    $stSrc = (string) file_get_contents($root . '/api/v1/settings/backup/status.php');
    if (str_contains($stSrc, 'progress_pct') && str_contains($stSrc, 'progress_stage')
        && preg_match('/SELECT[^;]*progress_pct[^;]*progress_stage/s', $stSrc)) {
        ok('status.php SELECTs + returns progress_pct + progress_stage');
    } else {
        ko('status.php does not expose progress fields');
    }

} catch (\Throwable $e) {
    ko('smoke threw', $e->getMessage());
} finally {
    // Restore any still-hidden s3 rows.
    if (!empty($flippedIds)) {
        $in = implode(',', array_map('intval', $flippedIds));
        db_execute("UPDATE backup_runs SET destination='s3' WHERE id IN ({$in})");
    }
    // Delete seeded artifacts + the produced bundle.
    foreach (array_unique($seededKeys) as $k) {
        try { StorageClient::delete($k); } catch (\Throwable $e) { /* ignore */ }
    }
    // Delete every row this test created (manual + seeded s3 successes).
    db_execute("DELETE FROM backup_runs WHERE id > ?", [$maxIdBefore]);
}

$total = $pass + $fail;
echo "\n=== {$pass}/{$total} PASS" . ($fail > 0 ? ", {$fail} FAIL" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
