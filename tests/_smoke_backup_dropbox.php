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

// ── C7: cron/backup_dropbox.php is PHP syntax-valid ─────────────────────
echo "\nC7: cron/backup_dropbox.php PHP syntax\n";
$cronFile = dirname(__DIR__) . '/cron/backup_dropbox.php';
$out = shell_exec('php -l ' . escapeshellarg($cronFile) . ' 2>&1');
if (str_contains((string) $out, 'No syntax errors')) {
    ok("backup_dropbox.php: php -l PASS");
} else {
    ko("backup_dropbox.php: php -l FAIL", trim((string) $out));
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

// ── Summary ───────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n=== {$pass}/{$total} PASS" . ($fail > 0 ? ", {$fail} FAIL" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
