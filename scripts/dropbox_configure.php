<?php
declare(strict_types=1);

/**
 * scripts/dropbox_configure.php
 *
 * One-shot CLI bootstrap to set dropbox.app_key and dropbox.app_secret before
 * the OAuth UI exists. Encrypts app_secret with DropboxClient::encrypt() so the
 * stored value matches what the OAuth callback and DropboxClient constructor expect.
 *
 * USAGE:
 *   php scripts/dropbox_configure.php <app_key> <app_secret>
 *
 * SAFETY:
 *   - app_key is stored as plain text (it is public / non-sensitive).
 *   - app_secret is encrypted (ENC: prefix) before storage (is_sensitive=1 in settings).
 *   - Run once; re-running overwrites both keys safely (idempotent UPDATE ... ON DUPLICATE).
 *
 * After running this script, initiate the OAuth flow at:
 *   https://mainlandrentals.com/fleetforge/oauth/dropbox/init.php
 *
 * The oauth callback (app/admin/oauth/dropbox/callback.php) stores the
 * encrypted refresh_token and flips dropbox.enabled to '1'.
 *
 * Session: S-BACKUP-2
 */

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the CLI.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';

use FleetForge\Backup\DropboxClient;

// ── Argument validation ────────────────────────────────────────────────────
if ($argc < 3 || trim($argv[1]) === '' || trim($argv[2]) === '') {
    fwrite(STDERR, "USAGE: php scripts/dropbox_configure.php <app_key> <app_secret>\n");
    fwrite(STDERR, "  app_key    — Dropbox App Key from the App Console (public)\n");
    fwrite(STDERR, "  app_secret — Dropbox App Secret from the App Console (sensitive)\n");
    exit(1);
}

$appKey    = trim($argv[1]);
$appSecret = trim($argv[2]);

// ── Write settings ──────────────────────────────────────────────────────────
$encSecret = DropboxClient::encrypt($appSecret);

db_execute(
    "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
     VALUES ('dropbox.app_key', ?, 'string', 'dropbox', 0, 0, NULL,
             'Dropbox API app key from the Dropbox App Console. Required to initiate OAuth flow.')
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [$appKey]
);

db_execute(
    "INSERT INTO settings (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
     VALUES ('dropbox.app_secret', ?, 'string', 'dropbox', 0, 1, NULL,
             'Dropbox API app secret. Masked in UI. Required for OAuth token exchange.')
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [$encSecret]
);

echo "dropbox.app_key    set: {$appKey}\n";
echo "dropbox.app_secret set: [ENCRYPTED]\n";
echo "\nNext: initiate the OAuth flow at\n";
echo "  https://mainlandrentals.com/fleetforge/oauth/dropbox/init.php\n";
