<?php
declare(strict_types=1);

/**
 * tests/_smoke_backup_dropbox.php
 *
 * Offline smoke test for the S-BACKUP-2 Dropbox backup engine.
 * No live Dropbox connection required — all checks are DB, reflection, or
 * syntax-level. Run before committing.
 *
 * Usage:  php tests/_smoke_backup_dropbox.php
 * Exit:   0 = all pass, 1 = any failure
 *
 * Session: S-BACKUP-2
 */

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Backup\DropboxClient;
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

echo "=== _smoke_backup_dropbox ===\n\n";

// ── C1: DropboxClient class is autoloadable ──────────────────────────────
echo "C1: DropboxClient class is autoloadable\n";
if (class_exists(DropboxClient::class)) {
    ok('class FleetForge\\Backup\\DropboxClient exists');
} else {
    ko('class FleetForge\\Backup\\DropboxClient not found');
}

// ── C2: encrypt / decrypt round-trip ────────────────────────────────────
echo "\nC2: encrypt/decrypt round-trip\n";
try {
    $plain = 'test-secret-value-' . bin2hex(random_bytes(8));
    $enc   = DropboxClient::encrypt($plain);
    if (!str_starts_with($enc, 'ENC:')) {
        ko('encrypt() output does not start with ENC:');
    } else {
        ok("encrypt() produces ENC: prefix");
    }
    $dec = DropboxClient::decrypt($enc);
    if ($dec === $plain) {
        ok('decrypt(encrypt(plain)) === plain');
    } else {
        ko('round-trip mismatch', "expected '{$plain}', got '" . ($dec ?? 'null') . "'");
    }
    // Two encryptions of the same plaintext must differ (different IV)
    $enc2 = DropboxClient::encrypt($plain);
    if ($enc !== $enc2) {
        ok('each encrypt() call produces a distinct ciphertext (IV randomness)');
    } else {
        ko('encrypt() produced identical ciphertext on second call (no IV randomness)');
    }
} catch (\Throwable $e) {
    ko('encrypt/decrypt threw exception', $e->getMessage());
}

// ── C3: decrypt() safety — null, non-ENC:, garbage ──────────────────────
echo "\nC3: decrypt() safety (null / non-ENC: / garbage)\n";
try {
    if (DropboxClient::decrypt(null) === null) {
        ok("decrypt(null) === null");
    } else {
        ko("decrypt(null) should return null");
    }
    if (DropboxClient::decrypt('plaintext') === null) {
        ok("decrypt('plaintext') === null (no ENC: prefix)");
    } else {
        ko("decrypt('plaintext') should return null");
    }
    if (DropboxClient::decrypt('ENC:notbase64!!!') === null) {
        ok("decrypt('ENC:notbase64!!!') === null (malformed payload)");
    } else {
        ko("decrypt('ENC:notbase64!!!') should return null");
    }
} catch (\Throwable $e) {
    ko('decrypt() safety threw exception', $e->getMessage());
}

// ── C4: DropboxClient constructor throws on missing app_key ─────────────
echo "\nC4: constructor throws on missing app_key\n";
try {
    // Temporarily store empty app_key in settings to trigger the guard.
    // We read the current value and restore it after the test.
    $origKey = (string) settings_get('dropbox.app_key', '');
    db_execute("UPDATE settings SET `value`='' WHERE `key`='dropbox.app_key'");

    $threw = false;
    try {
        new DropboxClient();
    } catch (\RuntimeException $ex) {
        $threw = true;
        ok("constructor throws RuntimeException when app_key is empty: " . $ex->getMessage());
    }
    if (!$threw) {
        ko('constructor should throw when app_key is empty');
    }

    // Restore original value
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.app_key'", [$origKey]);
} catch (\Throwable $e) {
    ko('C4 threw unexpectedly', $e->getMessage());
}

// ── C5: StorageClient::download() method exists ─────────────────────────
echo "\nC5: StorageClient::download() method exists\n";
if (method_exists(StorageClient::class, 'download')) {
    ok('StorageClient::download() is defined');
    $ref = new ReflectionMethod(StorageClient::class, 'download');
    if ($ref->isPublic() && $ref->isStatic()) {
        ok('StorageClient::download() is public static');
    } else {
        ko('StorageClient::download() is not public static');
    }
} else {
    ko('StorageClient::download() not found');
}

// ── C6: acc_oauth_states.provider ENUM includes 'dropbox' (migration 99) ─
echo "\nC6: acc_oauth_states.provider ENUM includes 'dropbox'\n";
try {
    $col = db_row("SHOW COLUMNS FROM `acc_oauth_states` LIKE 'provider'", []);
    if ($col) {
        $typeStr = strtolower((string) ($col['Type'] ?? ''));
        if (str_contains($typeStr, "'dropbox'")) {
            ok("provider ENUM contains 'dropbox': {$typeStr}");
        } else {
            ko("provider ENUM missing 'dropbox'", "found: {$typeStr}");
        }
        if (str_contains($typeStr, "'quickbooks'")) {
            ok("provider ENUM still contains 'quickbooks' (not broken by migration)");
        } else {
            ko("provider ENUM lost 'quickbooks'", "found: {$typeStr}");
        }
    } else {
        ko("acc_oauth_states.provider column not found");
    }
} catch (\Throwable $e) {
    ko('C6 DB check threw', $e->getMessage());
}

// ── C7: schema-real settings-write coverage (regression guard) ──────────────
// S-FIX-BACKUP-COLS: dropbox_configure.php fataled on prod with
// "Unknown column 'type'" — the settings table columns are `value_type`
// and `group_name`, not `type`/`group`. The original C7 only exercised
// encrypt/decrypt, never the real settings INSERT, so the typo escaped to
// prod. These sub-checks assert that EVERY column identifier in the
// configure CLI's INSERTs and the OAuth callback's settings writes actually
// exists in `SHOW COLUMNS FROM settings`, AND that the configure writes
// execute end-to-end (write → read back → decrypt).
echo "\nC7: schema-real settings-write coverage (S-FIX-BACKUP-COLS regression guard)\n";

// cron syntax check retained
$cronFile = dirname(__DIR__) . '/cron/backup_dropbox.php';
$out = shell_exec('php -l ' . escapeshellarg($cronFile) . ' 2>&1');
if (str_contains((string) $out, 'No syntax errors')) {
    ok("backup_dropbox.php: php -l PASS");
} else {
    ko("backup_dropbox.php: php -l FAIL", trim((string) $out));
}

/**
 * Extract backtick-quoted column identifiers from the column-list of every
 * `INSERT INTO settings (...)` and from the `SET ... WHERE` of every
 * `UPDATE settings ...` statement in a PHP source file. Returns a flat,
 * de-duplicated list of column names referenced as real table columns.
 *
 * VALUES(`col`) references and the `key`= in WHERE clauses are columns too,
 * so a naive backtick scan over the matched statements is correct — every
 * identifier must be a real column.
 */
function ff_extract_settings_columns(string $file): array
{
    $src  = (string) file_get_contents($file);
    $cols = [];

    // INSERT INTO settings ( ... )  — capture the column list inside parens
    if (preg_match_all('/INSERT\s+INTO\s+settings\s*\(([^)]*)\)/i', $src, $m)) {
        foreach ($m[1] as $list) {
            if (preg_match_all('/`([a-z_]+)`/i', $list, $cm)) {
                foreach ($cm[1] as $c) { $cols[$c] = true; }
            }
        }
    }

    // UPDATE settings SET `col`=... WHERE `col`=...  — the SQL lives inside a
    // double-quoted PHP string, so capture everything up to the closing ".
    if (preg_match_all('/UPDATE\s+settings\s+SET\s+([^"]*)"/i', $src, $um)) {
        foreach ($um[1] as $stmt) {
            if (preg_match_all('/`([a-z_]+)`/i', $stmt, $cm)) {
                foreach ($cm[1] as $c) { $cols[$c] = true; }
            }
        }
    }

    return array_keys($cols);
}

try {
    $colRows = db_select("SHOW COLUMNS FROM settings", []);
    $realCols = array_map(fn($r) => (string) $r['Field'], $colRows);

    // C7a: configure CLI column validity
    $cfgFile = dirname(__DIR__) . '/scripts/dropbox_configure.php';
    $cfgCols = ff_extract_settings_columns($cfgFile);
    if (empty($cfgCols)) {
        ko('could not extract any settings columns from dropbox_configure.php');
    } else {
        $badCfg = array_diff($cfgCols, $realCols);
        if (empty($badCfg)) {
            ok('dropbox_configure.php INSERT columns all exist in settings: ' . implode(', ', $cfgCols));
        } else {
            ko('dropbox_configure.php references nonexistent settings columns', implode(', ', $badCfg));
        }
    }

    // C7b: callback column validity
    $cbFile = dirname(__DIR__) . '/app/admin/oauth/dropbox/callback.php';
    $cbCols = ff_extract_settings_columns($cbFile);
    if (empty($cbCols)) {
        ko('could not extract any settings columns from callback.php');
    } else {
        $badCb = array_diff($cbCols, $realCols);
        if (empty($badCb)) {
            ok('callback.php settings-write columns all exist in settings: ' . implode(', ', $cbCols));
        } else {
            ko('callback.php references nonexistent settings columns', implode(', ', $badCb));
        }
    }

    // C7c: execute the REAL configure writes end-to-end, read back, decrypt.
    // ON DUPLICATE KEY UPDATE only touches `value`, so we save + restore the
    // pre-test values to keep the dev DB clean.
    $origKey    = (string) settings_get('dropbox.app_key', '');
    $origSecret = (string) settings_get('dropbox.app_secret', '');

    $testKey    = 'test_app_key_' . bin2hex(random_bytes(4));
    $testSecret = 'test_app_secret_' . bin2hex(random_bytes(8));

    $cfgOut = shell_exec(
        'php ' . escapeshellarg($cfgFile) . ' ' .
        escapeshellarg($testKey) . ' ' . escapeshellarg($testSecret) . ' 2>&1'
    );

    if (str_contains((string) $cfgOut, 'Unknown column')) {
        ko('dropbox_configure.php fataled on a settings column', trim((string) $cfgOut));
    } else {
        // Read back the freshly-written rows (bypass any static settings cache
        // by querying the table directly).
        $keyRow    = db_row("SELECT `value` FROM settings WHERE `key`='dropbox.app_key'", []);
        $secretRow = db_row("SELECT `value` FROM settings WHERE `key`='dropbox.app_secret'", []);

        if (($keyRow['value'] ?? null) === $testKey) {
            ok('configure write round-trip: dropbox.app_key persisted + read back correctly');
        } else {
            ko('dropbox.app_key did not round-trip', 'got: ' . ($keyRow['value'] ?? 'null'));
        }

        $storedSecret = (string) ($secretRow['value'] ?? '');
        if (str_starts_with($storedSecret, 'ENC:')) {
            ok('configure write round-trip: dropbox.app_secret stored ENC:-encrypted');
        } else {
            ko('dropbox.app_secret not ENC:-encrypted', 'got prefix: ' . substr($storedSecret, 0, 8));
        }
        if (DropboxClient::decrypt($storedSecret) === $testSecret) {
            ok('configure write round-trip: dropbox.app_secret decrypts back to the input value');
        } else {
            ko('dropbox.app_secret did not decrypt back to input');
        }
    }

    // Restore original values
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.app_key'", [$origKey]);
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.app_secret'", [$origSecret]);
} catch (\Throwable $e) {
    ko('C7 threw unexpectedly', $e->getMessage());
}

// ── C8: 6 required dropbox.* settings keys present with correct sensitivity
echo "\nC8: 6 dropbox.* settings keys with correct is_sensitive values\n";
$expected = [
    'dropbox.enabled'           => 0,
    'dropbox.app_key'           => 0,
    'dropbox.app_secret'        => 1,
    'dropbox.refresh_token'     => 1,
    'dropbox.folder_path'       => 0,
    'dropbox.connected_account' => 0,
];
try {
    $rows = db_select(
        "SELECT `key`, `is_sensitive`, `label` FROM settings WHERE `key` LIKE 'dropbox.%'",
        []
    );
    $found = [];
    foreach ($rows as $row) {
        $found[(string) $row['key']] = (int) $row['is_sensitive'];
    }
    foreach ($expected as $key => $expectedSensitive) {
        if (!array_key_exists($key, $found)) {
            ko("settings key '{$key}' not found");
        } elseif ($found[$key] !== $expectedSensitive) {
            ko("{$key} is_sensitive={$found[$key]}, expected {$expectedSensitive}");
        } else {
            ok("{$key}: is_sensitive={$expectedSensitive} ✓");
        }
    }
    // All label=NULL (D196 filter)
    $labelled = array_filter($rows, fn($r) => $r['label'] !== null);
    if (empty($labelled)) {
        ok('all dropbox.* rows have label=NULL (D196 — excluded from settings index UI)');
    } else {
        $keys = implode(', ', array_column($labelled, 'key'));
        ko("dropbox.* rows with non-NULL label (violates D196): {$keys}");
    }
} catch (\Throwable $e) {
    ko('C8 DB check threw', $e->getMessage());
}

// ── C9: cron executes end-to-end down the fail-soft path (regression guard) ──
// S-FIX-DROPBOX-CRON-DBVALUE: cron/backup_dropbox.php called the undefined
// db_value() at the GET_LOCK read and fataled on prod. php -l does NOT catch
// undefined-function calls, and the prior smoke never EXECUTED the cron, so it
// shipped. This sub-check runs the REAL cron as a subprocess against the real
// db.php + schema, driven down a deterministic, network-free path:
//   enabled+token gate passes → lock acquired (exercises the FIXED line) →
//   BackupRun::lastSuccess('s3', type) returns null → fail-soft "no source
//   artifact" branch → dropbox failed row written → exit 0.
// Non-vacuous: if line 57 still calls db_value, the subprocess fatals BEFORE
// reaching the no-artifact branch — exit != 0, "undefined function" in output,
// and no failed row created — so all three assertions fail.
echo "\nC9: cron/backup_dropbox.php executes end-to-end (S-FIX-DROPBOX-CRON-DBVALUE regression guard)\n";
$cronType = 'db';
$cronPath = dirname(__DIR__) . '/cron/backup_dropbox.php';

// Save fixture state so the dev DB is left exactly as found.
$c9OrigEnabled = (string) settings_get('dropbox.enabled', '0');
$c9OrigToken   = (string) settings_get('dropbox.refresh_token', '');

// Capture the s3 success rows for the test type — we hide them (destination
// → 'manual') so lastSuccess('s3', type) returns null for the cron run, then
// restore them in finally.
$c9HiddenIds = array_map(
    'intval',
    array_column(
        db_select(
            "SELECT id FROM backup_runs WHERE destination='s3' AND backup_type=? AND status='success'",
            [$cronType]
        ),
        'id'
    )
);

// High-water mark to identify + clean up exactly the rows this test creates.
$c9MaxIdBefore = (int) (db_row("SELECT COALESCE(MAX(id),0) AS m FROM backup_runs", [])['m'] ?? 0);

try {
    // Fixtures: enabled + non-empty token so the cron passes the gate checks.
    db_execute("UPDATE settings SET `value`='1' WHERE `key`='dropbox.enabled'");
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.refresh_token'", ['smoke-dummy-refresh-token']);

    // Hide existing s3 successes for this type → lastSuccess() returns null.
    if (!empty($c9HiddenIds)) {
        $in = implode(',', $c9HiddenIds);
        db_execute("UPDATE backup_runs SET destination='manual' WHERE id IN ({$in})");
    }

    // Execute the REAL cron (subprocess). 2>&1 so PHP fatals land in $cronOut.
    $outLines = [];
    $exitCode = null;
    exec('php ' . escapeshellarg($cronPath) . ' --type=' . $cronType . ' 2>&1', $outLines, $exitCode);
    $cronOut = implode("\n", $outLines);

    // Assertion 1: no undefined-function / fatal (this is what db_value emitted).
    if (stripos($cronOut, 'undefined function') === false && stripos($cronOut, 'Fatal') === false) {
        ok('cron subprocess: no "undefined function"/"Fatal" in output');
    } else {
        ko('cron subprocess emitted a fatal', trim($cronOut));
    }

    // Assertion 2: clean exit 0 (fail-soft "no source artifact" path).
    if ($exitCode === 0) {
        ok('cron subprocess exited 0 (fail-soft path reached + lock line executed)');
    } else {
        ko('cron subprocess exit code', 'expected 0, got ' . var_export($exitCode, true) . ' — output: ' . trim($cronOut));
    }

    // Assertion 3: a dropbox/<type> failed row was written with the no-artifact message.
    $testRow = db_row(
        "SELECT id, status, error_message FROM backup_runs
         WHERE id > ? AND destination='dropbox' AND backup_type=?
         ORDER BY id DESC LIMIT 1",
        [$c9MaxIdBefore, $cronType]
    );
    if ($testRow && $testRow['status'] === 'failed' && $testRow['error_message'] === 'no source artifact') {
        ok("cron wrote a dropbox/{$cronType} 'failed' row with error_message='no source artifact'");
    } else {
        ko('expected dropbox failed row not found', 'got: ' . json_encode($testRow));
    }
} catch (\Throwable $e) {
    ko('C9 threw unexpectedly', $e->getMessage());
} finally {
    // Restore ALL fixture state — leave the dev DB exactly as found.
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.enabled'", [$c9OrigEnabled]);
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.refresh_token'", [$c9OrigToken]);
    if (!empty($c9HiddenIds)) {
        $in = implode(',', $c9HiddenIds);
        db_execute("UPDATE backup_runs SET destination='s3' WHERE id IN ({$in})");
    }
    // Delete only the rows this test created (id past the high-water mark).
    db_execute("DELETE FROM backup_runs WHERE id > ? AND destination='dropbox'", [$c9MaxIdBefore]);
}

// ── C10: --type argument resolution (S-FIX-DROPBOX-ARGS regression guard) ────
// The parser must accept BOTH `--type=storage` and `--type storage` (space
// form — which the cron's own docblock + crontab use), default to 'db' only
// when no --type token is present, and FAIL LOUD (exit 1, no row) on an
// unrecognized value — never silently default to 'db'.
//
// Observability: with enabled+token set and BOTH s3 success types hidden,
// lastSuccess('s3', <type>) returns null → the cron hits the fail-soft branch
// and writes a dropbox/<resolved-type> 'failed' row. The row's backup_type IS
// the resolved type, so it's directly assertable without a live upload.
//
// Non-vacuous: the old equals-only parser resolved `--type storage` (space) to
// 'db', so the space-form case below FAILS against it.
echo "\nC10: --type resolution (accept space + equals forms; fail loud on invalid)\n";
$cronPath10 = dirname(__DIR__) . '/cron/backup_dropbox.php';

$c10OrigEnabled = (string) settings_get('dropbox.enabled', '0');
$c10OrigToken   = (string) settings_get('dropbox.refresh_token', '');

// Hide s3 successes for BOTH types so lastSuccess() returns null whichever type resolves.
$c10HiddenIds = array_map(
    'intval',
    array_column(
        db_select(
            "SELECT id FROM backup_runs WHERE destination='s3' AND backup_type IN ('db','storage') AND status='success'",
            []
        ),
        'id'
    )
);
$c10MaxIdBefore = (int) (db_row("SELECT COALESCE(MAX(id),0) AS m FROM backup_runs", [])['m'] ?? 0);

/**
 * Run the cron with the given raw argument string; return the dropbox
 * backup_type the run resolved to (from the failed row it wrote), plus exit
 * code and whether a new dropbox row was created.
 */
$c10Run = function (string $argStr) use ($cronPath10): array {
    $mark = (int) (db_row("SELECT COALESCE(MAX(id),0) AS m FROM backup_runs", [])['m'] ?? 0);
    $out  = [];
    $code = null;
    exec('php ' . escapeshellarg($cronPath10) . ' ' . $argStr . ' 2>&1', $out, $code);
    $row = db_row(
        "SELECT backup_type, status FROM backup_runs
         WHERE id > ? AND destination='dropbox' ORDER BY id DESC LIMIT 1",
        [$mark]
    );
    return [
        'exit'        => $code,
        'output'      => implode("\n", $out),
        'backup_type' => $row['backup_type'] ?? null,
        'row_created' => $row !== null,
    ];
};

try {
    db_execute("UPDATE settings SET `value`='1' WHERE `key`='dropbox.enabled'");
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.refresh_token'", ['smoke-dummy-refresh-token']);
    if (!empty($c10HiddenIds)) {
        $in = implode(',', $c10HiddenIds);
        db_execute("UPDATE backup_runs SET destination='manual' WHERE id IN ({$in})");
    }

    // (1) SPACE form `--type storage` → MUST resolve to storage (regression guard).
    $r = $c10Run('--type storage');
    if ($r['exit'] === 0 && $r['backup_type'] === 'storage') {
        ok("`--type storage` (space form) resolves to 'storage'");
    } else {
        ko('`--type storage` did not resolve to storage', 'exit=' . var_export($r['exit'], true) . ' type=' . var_export($r['backup_type'], true) . ' out=' . trim($r['output']));
    }

    // (2) EQUALS form `--type=storage` → storage.
    $r = $c10Run('--type=storage');
    if ($r['exit'] === 0 && $r['backup_type'] === 'storage') {
        ok("`--type=storage` (equals form) resolves to 'storage'");
    } else {
        ko('`--type=storage` did not resolve to storage', 'exit=' . var_export($r['exit'], true) . ' type=' . var_export($r['backup_type'], true));
    }

    // (3) SPACE form `--type db` → db.
    $r = $c10Run('--type db');
    if ($r['exit'] === 0 && $r['backup_type'] === 'db') {
        ok("`--type db` (space form) resolves to 'db'");
    } else {
        ko('`--type db` did not resolve to db', 'exit=' . var_export($r['exit'], true) . ' type=' . var_export($r['backup_type'], true));
    }

    // (4) No --type token → default db.
    $r = $c10Run('');
    if ($r['exit'] === 0 && $r['backup_type'] === 'db') {
        ok("no --type token defaults to 'db'");
    } else {
        ko('no-arg did not default to db', 'exit=' . var_export($r['exit'], true) . ' type=' . var_export($r['backup_type'], true));
    }

    // (5) Invalid value → exit 1, STDERR, NO row written (never silent-default).
    $r = $c10Run('--type bogus');
    if ($r['exit'] === 1 && !$r['row_created'] && stripos($r['output'], "invalid --type") !== false) {
        ok("`--type bogus` fails loud (exit 1, error on STDERR, no backup_runs row)");
    } else {
        ko('`--type bogus` did not fail loud', 'exit=' . var_export($r['exit'], true) . ' row_created=' . var_export($r['row_created'], true) . ' out=' . trim($r['output']));
    }
} catch (\Throwable $e) {
    ko('C10 threw unexpectedly', $e->getMessage());
} finally {
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.enabled'", [$c10OrigEnabled]);
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.refresh_token'", [$c10OrigToken]);
    if (!empty($c10HiddenIds)) {
        $in = implode(',', $c10HiddenIds);
        db_execute("UPDATE backup_runs SET destination='s3' WHERE id IN ({$in})");
    }
    db_execute("DELETE FROM backup_runs WHERE id > ? AND destination='dropbox'", [$c10MaxIdBefore]);
}

// ── Summary ───────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n=== {$pass}/{$total} PASS" . ($fail > 0 ? ", {$fail} FAIL" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
