<?php
declare(strict_types=1);

/**
 * tests/_smoke_backup_settings_tab.php
 *
 * S-BACKUP-3b — Structural + render smoke for the Backup settings tab.
 *
 * Renders app/admin/settings/backup.php as a fragment in-process (preset
 * $canEdit so the standalone-auth guard is skipped — same approach the other
 * settings-tab smokes use) and verifies the 3 status cards + history table,
 * the index.php tab wiring, the dropbox save/disconnect endpoints (permission
 * gate + CSRF-via-bootstrap), the masked-secret guarantee, and that every
 * settings/backup_runs column the new code touches exists in SHOW COLUMNS
 * (Trap 71/73).
 *
 * Self-cleaning: any settings rows mutated for the masked-secret check are
 * restored. No schema mutations.
 *
 * Usage:  php tests/_smoke_backup_settings_tab.php
 * Exit:   0 = all pass, 1 = any failure
 *
 * Session: S-BACKUP-3b
 */

require_once __DIR__ . '/../config/app.php';

use FleetForge\Backup\BackupRun;

$pass = 0;
$fail = 0;
function ok(string $l): void { global $pass; $pass++; echo "  PASS  {$l}\n"; }
function ko(string $l, string $d = ''): void { global $fail; $fail++; echo "  FAIL  {$l}" . ($d !== '' ? " — {$d}" : '') . "\n"; }

$root = dirname(__DIR__);
echo "=== _smoke_backup_settings_tab ===\n\n";

// ── C1: index.php tab wiring ─────────────────────────────────────────────────
echo "C1: settings/index.php Backup tab wiring\n";
$idx = (string) file_get_contents($root . '/app/admin/settings/index.php');
if (str_contains($idx, "'backup'") && preg_match("/'backup'\s*=>\s*'settings_system'/", $idx)) {
    ok("'backup' => 'settings_system' present in \$tabPermMap");
} else {
    ko("'backup' tab not in \$tabPermMap");
}
$hasContentBlock = str_contains($idx, "x-show=\"activeTab === 'backup'\"");
$hasTabButton    = str_contains($idx, 'Backup<?=');           // tab button label line
if ($hasContentBlock && $hasTabButton) {
    ok('Backup tab button + content block present');
} else {
    ko('Backup tab button/content block missing', "button={$hasTabButton} block={$hasContentBlock}");
}
if (str_contains($idx, "__DIR__ . '/backup.php'")) {
    ok('index.php requires backup.php');
} else {
    ko('index.php does not require backup.php');
}

// ── C2: dropbox endpoints exist, lint, gate + CSRF ───────────────────────────
echo "\nC2: dropbox save/disconnect endpoints (gate + CSRF + lint)\n";
foreach (['save', 'disconnect'] as $ep) {
    $path = $root . "/api/v1/settings/dropbox/{$ep}.php";
    if (!file_exists($path)) { ko("{$ep}.php missing"); continue; }
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (!str_contains((string) $lint, 'No syntax errors')) { ko("{$ep}.php lint fail", trim((string) $lint)); continue; }
    $src = (string) file_get_contents($path);
    $hasGate      = str_contains($src, "require_permission('settings', 'edit')");
    $hasBootstrap = str_contains($src, "/api/bootstrap.php"); // bootstrap enforces X-CSRF-Token
    $hasPost      = str_contains($src, "require_method('POST')");
    if ($hasGate && $hasBootstrap && $hasPost) {
        ok("{$ep}.php: POST + settings.edit gate + CSRF-via-bootstrap");
    } else {
        ko("{$ep}.php missing gate/bootstrap/method", "gate={$hasGate} bootstrap={$hasBootstrap} post={$hasPost}");
    }
}

// ── C3: render backup.php fragment → 3 cards + history table ─────────────────
echo "\nC3: backup.php renders 3 cards + history table\n";
// Preset parent-scope vars so the standalone-auth guard is skipped.
$canEdit      = true;
$isSuperAdmin = true;
$csrfToken    = 'smoke-csrf';
ob_start();
try {
    require $root . '/app/admin/settings/backup.php';
    $html = (string) ob_get_clean();
} catch (\Throwable $e) {
    $html = '';
    ob_end_clean();
    ko('backup.php render threw (column typo / fatal?)', $e->getMessage());
}
if ($html !== '') {
    $needles = [
        'AWS S3'                 => 'AWS S3 card',
        'Dropbox'                => 'Dropbox card',
        'Manual Backup'          => 'Manual card',
        'Recent backup history'  => 'history table header',
        '<table'                 => 'history <table>',
    ];
    foreach ($needles as $needle => $label) {
        if (str_contains($html, $needle)) {
            ok("renders {$label}");
        } else {
            ko("missing {$label} ('{$needle}')");
        }
    }
    // Manual card must NOT carry a generate button this session (S-BACKUP-3c).
    if (stripos($html, 'coming soon') !== false) {
        ok('Manual card is display-only (no generate button)');
    } else {
        ko('Manual card missing the display-only marker');
    }
}

// ── C4: masked secret is NEVER rendered in clear ─────────────────────────────
echo "\nC4: stored Dropbox app_secret never appears in rendered output\n";
$origSecret = (string) (db_row("SELECT `value` FROM settings WHERE `key`='dropbox.app_secret'", [])['value'] ?? '');
$sentinel   = 'SENTINEL_SECRET_' . bin2hex(random_bytes(6));
try {
    // Store a sentinel (encrypted, as the real save path would) and render.
    $encSentinel = \FleetForge\Backup\DropboxClient::encrypt($sentinel);
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.app_secret'", [$encSentinel]);

    $canEdit = true; $isSuperAdmin = true; $csrfToken = 'smoke-csrf';
    ob_start();
    require $root . '/app/admin/settings/backup.php';
    $html2 = (string) ob_get_clean();

    if (!str_contains($html2, $sentinel) && !str_contains($html2, $encSentinel) && !str_contains($html2, 'ENC:')) {
        ok('neither the plaintext nor the ENC: ciphertext of app_secret is in the HTML');
    } else {
        ko('app_secret value leaked into rendered HTML');
    }
} catch (\Throwable $e) {
    ko('C4 threw', $e->getMessage());
} finally {
    db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.app_secret'", [$origSecret]);
}

// ── C5: backup.php reads via BackupRun::lastSuccess + db_select(backup_runs) ──
echo "\nC5: backup.php read path\n";
$bsrc = (string) file_get_contents($root . '/app/admin/settings/backup.php');
if (str_contains($bsrc, 'BackupRun::lastSuccess(') && str_contains($bsrc, 'FROM backup_runs')) {
    ok('backup.php uses BackupRun::lastSuccess + db_select on backup_runs');
} else {
    ko('backup.php read path not found');
}

// ── C6: column validity (Trap 71/73) — every column the code touches exists ──
echo "\nC6: settings + backup_runs column identifiers exist (Trap 71/73)\n";
try {
    $settingsCols = array_map(fn($r) => (string) $r['Field'], db_select("SHOW COLUMNS FROM settings", []));
    $brCols       = array_map(fn($r) => (string) $r['Field'], db_select("SHOW COLUMNS FROM backup_runs", []));

    // settings columns referenced by the endpoints + backup.php
    $needSettings = ['value', 'key', 'value_type', 'group_name', 'updated_by', 'updated_at'];
    $badS = array_diff($needSettings, $settingsCols);
    if (empty($badS)) { ok('settings columns all exist: ' . implode(', ', $needSettings)); }
    else { ko('nonexistent settings columns referenced', implode(', ', $badS)); }

    // backup_runs columns SELECTed by backup.php
    $needBr = ['id', 'destination', 'backup_type', 'status', 'file_size_bytes', 'completed_at', 'started_at', 'created_at', 'initiated_by'];
    $badB = array_diff($needBr, $brCols);
    if (empty($badB)) { ok('backup_runs columns all exist: ' . implode(', ', $needBr)); }
    else { ko('nonexistent backup_runs columns referenced', implode(', ', $badB)); }
} catch (\Throwable $e) {
    ko('C6 column check threw', $e->getMessage());
}

// ── C7: callback redirect target is the real settings route ──────────────────
echo "\nC7: callback redirects to the real settings route (?tab=backup)\n";
$cb = (string) file_get_contents($root . '/app/admin/oauth/dropbox/callback.php');
if (str_contains($cb, "base_url('settings?tab=backup')") && !str_contains($cb, "base_url('admin/settings')")) {
    ok("callback uses base_url('settings?tab=backup') (no hardcoded admin/settings)");
} else {
    ko('callback redirect target not fixed');
}
if (str_contains($cb, 'getCurrentAccount()')) {
    ok('callback resolves a friendly connected_account via getCurrentAccount()');
} else {
    ko('callback does not call getCurrentAccount()');
}

// ── Summary ───────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n=== {$pass}/{$total} PASS" . ($fail > 0 ? ", {$fail} FAIL" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
