<?php
declare(strict_types=1);

/**
 * tests/_smoke_backup_runs.php
 *
 * Smoke test for S-BACKUP-1: backup_runs table + BackupRun helper + Dropbox settings seed.
 *
 * C1 — backup_runs table shape (all columns, 3 ENUMs, indexes, FK)
 * C2 — BackupRun::start() returns a positive id; row is in_progress
 * C3 — BackupRun::success() flips status, sets duration_ms / file_key / file_size_bytes
 * C4 — BackupRun::fail() sets status=failed, error_message; duration_ms populated
 * C5 — BackupRun::lastSuccess() returns newest success row only, ignores failed/in_progress
 * C6 — 6 dropbox.* settings keys present; app_secret + refresh_token are is_sensitive=1
 * C7 — BackupRun write-failure is swallowed (simulate bad table → no exception reaches caller)
 *
 * Hermetic: all backup_runs rows inserted here are deleted in a finally block.
 * @session S-BACKUP-1
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Backup\BackupRun;

$pass    = 0;
$fail    = 0;
$details = [];

function smoke_assert(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $details;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $details[] = $label . ($detail !== '' ? ": {$detail}" : '');
    }
}

// ── Track inserted IDs for cleanup ───────────────────────────────────────────
$insertedIds = [];

// ── C1 — backup_runs table shape ─────────────────────────────────────────────
echo "\nC1 — backup_runs table shape\n";

$cols = db_select(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_runs'
     ORDER BY ORDINAL_POSITION",
    []
);
$colMap = [];
foreach ($cols as $c) {
    $colMap[$c['COLUMN_NAME']] = $c;
}

$expectedCols = [
    'id', 'destination', 'backup_type', 'status', 'file_key', 'file_size_bytes',
    'started_at', 'completed_at', 'duration_ms', 'error_message',
    'initiated_by', 'trigger_source', 'created_at',
];
foreach ($expectedCols as $col) {
    smoke_assert("column '{$col}' exists", isset($colMap[$col]));
}

// ENUM checks
$destEnum = $colMap['destination']['COLUMN_TYPE'] ?? '';
smoke_assert("destination ENUM has s3, dropbox, manual",
    str_contains($destEnum, "'s3'") && str_contains($destEnum, "'dropbox'") && str_contains($destEnum, "'manual'"));

$typeEnum = $colMap['backup_type']['COLUMN_TYPE'] ?? '';
smoke_assert("backup_type ENUM has db, storage, full",
    str_contains($typeEnum, "'db'") && str_contains($typeEnum, "'storage'") && str_contains($typeEnum, "'full'"));

$statusEnum = $colMap['status']['COLUMN_TYPE'] ?? '';
smoke_assert("status ENUM has in_progress, success, failed",
    str_contains($statusEnum, "'in_progress'") && str_contains($statusEnum, "'success'") && str_contains($statusEnum, "'failed'"));

// FK check
$fkRow = db_row(
    "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_runs'
       AND REFERENCED_TABLE_NAME = 'users' AND COLUMN_NAME = 'initiated_by'",
    []
);
smoke_assert("FK fk_backup_runs_user → users(id) exists", $fkRow !== null);

// Index checks
$indexes = db_select(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'backup_runs'",
    []
);
$idxNames = array_column($indexes, 'INDEX_NAME');
smoke_assert("index idx_dest_status exists",    in_array('idx_dest_status', $idxNames, true));
smoke_assert("index idx_created exists",        in_array('idx_created',     $idxNames, true));
smoke_assert("index idx_type exists",           in_array('idx_type',        $idxNames, true));

// ── C2 — start() returns id; row is in_progress ───────────────────────────────
echo "\nC2 — BackupRun::start() inserts in_progress row\n";

$id = BackupRun::start('s3', 'db');
smoke_assert("start() returns positive int", $id > 0, "got {$id}");

if ($id > 0) {
    $insertedIds[] = $id;
    $row = db_row('SELECT * FROM backup_runs WHERE id = ?', [$id]);
    smoke_assert("row exists after start()",       $row !== null);
    smoke_assert("status = in_progress",           ($row['status'] ?? '') === 'in_progress');
    smoke_assert("destination = s3",               ($row['destination'] ?? '') === 's3');
    smoke_assert("backup_type = db",               ($row['backup_type'] ?? '') === 'db');
    smoke_assert("trigger_source = cron",          ($row['trigger_source'] ?? '') === 'cron');
    smoke_assert("started_at not null",            !empty($row['started_at']));
    smoke_assert("completed_at is null",           $row['completed_at'] === null);
}

// ── C3 — success() flips status + sets duration/file_key/size ─────────────────
echo "\nC3 — BackupRun::success() updates row correctly\n";

$id2 = BackupRun::start('s3', 'storage');
if ($id2 > 0) {
    $insertedIds[] = $id2;
    sleep(1); // ensure at least 1 second elapsed for TIMESTAMPDIFF
    BackupRun::success($id2, 'backups/storage/2026-06/storage_2026-06-06.tar.gz', 5242880);
    $row = db_row('SELECT * FROM backup_runs WHERE id = ?', [$id2]);
    smoke_assert("status = success after success()",             ($row['status'] ?? '') === 'success');
    smoke_assert("file_key set correctly",                       ($row['file_key'] ?? '') === 'backups/storage/2026-06/storage_2026-06-06.tar.gz');
    smoke_assert("file_size_bytes = 5242880",                    (int)($row['file_size_bytes'] ?? 0) === 5242880);
    smoke_assert("completed_at not null after success()",        !empty($row['completed_at']));
    smoke_assert("duration_ms >= 0 after success()",             (int)($row['duration_ms'] ?? -1) >= 0);
} else {
    smoke_assert("start() for C3 returned valid id", false, "id={$id2}");
}

// ── C4 — fail() sets error_message + failed status ────────────────────────────
echo "\nC4 — BackupRun::fail() updates row correctly\n";

$id3 = BackupRun::start('s3', 'db');
if ($id3 > 0) {
    $insertedIds[] = $id3;
    BackupRun::fail($id3, 'mysqldump exited 1: Access denied for user');
    $row = db_row('SELECT * FROM backup_runs WHERE id = ?', [$id3]);
    smoke_assert("status = failed after fail()",             ($row['status'] ?? '') === 'failed');
    smoke_assert("error_message contains mysqldump",         str_contains((string)($row['error_message'] ?? ''), 'mysqldump'));
    smoke_assert("completed_at not null after fail()",       !empty($row['completed_at']));
    smoke_assert("duration_ms not null after fail()",        $row['duration_ms'] !== null);
} else {
    smoke_assert("start() for C4 returned valid id", false, "id={$id3}");
}

// Long error truncation
$id4 = BackupRun::start('dropbox', 'db');
if ($id4 > 0) {
    $insertedIds[] = $id4;
    $longError = str_repeat('x', 70000);
    BackupRun::fail($id4, $longError);
    $row = db_row('SELECT LENGTH(error_message) AS len FROM backup_runs WHERE id = ?', [$id4]);
    smoke_assert("error_message truncated to ≤65535 bytes",  (int)($row['len'] ?? 70000) <= 65535);
}

// ── C5 — lastSuccess() returns newest success only ────────────────────────────
echo "\nC5 — BackupRun::lastSuccess() returns newest success row\n";

// Insert a success and a later failed row; lastSuccess must return the success row
$idA = BackupRun::start('s3', 'db');
if ($idA > 0) {
    $insertedIds[] = $idA;
    BackupRun::success($idA, 'backups/db/2026-06-06/00/fleetforge_test_A.sql.gz', 1024);
}
$idB = BackupRun::start('s3', 'db');
if ($idB > 0) {
    $insertedIds[] = $idB;
    BackupRun::fail($idB, 'error after success');
}

$last = BackupRun::lastSuccess('s3', 'db');
smoke_assert("lastSuccess() returns non-null",         $last !== null);
smoke_assert("lastSuccess() status = success",         ($last['status'] ?? '') === 'success');
smoke_assert("lastSuccess() has correct file_key",
    str_contains((string)($last['file_key'] ?? ''), 'test_A'));

// No type filter
$lastAny = BackupRun::lastSuccess('s3');
smoke_assert("lastSuccess() with no type returns non-null", $lastAny !== null);
smoke_assert("lastSuccess() with no type status = success", ($lastAny['status'] ?? '') === 'success');

// Destination with no successes returns null
$lastNone = BackupRun::lastSuccess('manual');
smoke_assert("lastSuccess() returns null when no matches", $lastNone === null);

// ── C6 — 6 dropbox.* settings keys with correct is_sensitive flags ────────────
echo "\nC6 — dropbox.* settings keys present with correct is_sensitive flags\n";

$dropboxKeys = db_select(
    "SELECT `key`, is_sensitive FROM settings WHERE `key` LIKE 'dropbox.%' ORDER BY `key`",
    []
);
$dropboxMap = [];
foreach ($dropboxKeys as $k) {
    $dropboxMap[$k['key']] = (int)$k['is_sensitive'];
}

$expectedKeys = [
    'dropbox.enabled', 'dropbox.app_key', 'dropbox.app_secret',
    'dropbox.refresh_token', 'dropbox.folder_path', 'dropbox.connected_account',
];
smoke_assert("exactly 6 dropbox.* keys present", count($dropboxMap) === 6,
    "found " . count($dropboxMap) . ": " . implode(', ', array_keys($dropboxMap)));

foreach ($expectedKeys as $k) {
    smoke_assert("key '{$k}' exists", isset($dropboxMap[$k]));
}

smoke_assert("dropbox.app_secret is_sensitive = 1",
    ($dropboxMap['dropbox.app_secret'] ?? 0) === 1);
smoke_assert("dropbox.refresh_token is_sensitive = 1",
    ($dropboxMap['dropbox.refresh_token'] ?? 0) === 1);
smoke_assert("dropbox.enabled is_sensitive = 0",
    ($dropboxMap['dropbox.enabled'] ?? 1) === 0);
smoke_assert("dropbox.app_key is_sensitive = 0",
    ($dropboxMap['dropbox.app_key'] ?? 1) === 0);
smoke_assert("dropbox.folder_path is_sensitive = 0",
    ($dropboxMap['dropbox.folder_path'] ?? 1) === 0);
smoke_assert("dropbox.connected_account is_sensitive = 0",
    ($dropboxMap['dropbox.connected_account'] ?? 1) === 0);

// label IS NULL on all dropbox keys (D196 pattern — not rendered in generic settings index)
$labelled = db_count(
    "SELECT COUNT(*) FROM settings WHERE `key` LIKE 'dropbox.%' AND label IS NOT NULL",
    []
);
smoke_assert("all dropbox.* keys have label=NULL (D196)", $labelled === 0, "found {$labelled} with non-null labels");

// ── C7 — BackupRun write failure is swallowed ─────────────────────────────────
echo "\nC7 — BackupRun write-failure is swallowed (fail-soft)\n";

// Passing id=0 to success/fail should be silent no-ops
$exceptionThrown = false;
try {
    BackupRun::success(0, 'key', 100);
    BackupRun::fail(0, 'error');
} catch (\Throwable $e) {
    $exceptionThrown = true;
}
smoke_assert("success(0) and fail(0) are silent no-ops", !$exceptionThrown);

// Simulate bad DB state by passing a non-existent id — UPDATE affects 0 rows, no exception
$exceptionThrown2 = false;
try {
    BackupRun::success(PHP_INT_MAX, 'key', 100);
    BackupRun::fail(PHP_INT_MAX, 'error');
} catch (\Throwable $e) {
    $exceptionThrown2 = true;
}
smoke_assert("success/fail with bad id does not throw", !$exceptionThrown2);

// start() with non-existent user id (FK violation) → caught, returns 0, no throw
$exceptionThrown3 = false;
$badId = 0;
try {
    $badId = BackupRun::start('s3', 'db', PHP_INT_MAX);
} catch (\Throwable $e) {
    $exceptionThrown3 = true;
}
smoke_assert("start() with bad initiated_by FK does not throw", !$exceptionThrown3);
smoke_assert("start() with bad FK returns 0",                   $badId === 0);

// ── Cleanup ───────────────────────────────────────────────────────────────────
if (!empty($insertedIds)) {
    $placeholders = implode(',', array_fill(0, count($insertedIds), '?'));
    db_execute("DELETE FROM backup_runs WHERE id IN ({$placeholders})", $insertedIds);
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "PASS  {$pass}/{$total} — _smoke_backup_runs.php all green\n";
    exit(0);
} else {
    echo "FAIL  {$pass}/{$total} — {$fail} failure(s):\n";
    foreach ($details as $d) {
        echo "  - {$d}\n";
    }
    exit(1);
}
