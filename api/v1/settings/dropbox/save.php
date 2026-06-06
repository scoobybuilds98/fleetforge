<?php
declare(strict_types=1);

/**
 * api/v1/settings/dropbox/save.php
 *
 * POST endpoint — saves the Dropbox App Console credentials the operator
 * pastes from https://www.dropbox.com/developers/apps :
 *   - dropbox.app_key     (plain — public app key)
 *   - dropbox.app_secret  (ENCRYPTED via DropboxClient::encrypt — ENC: prefix)
 *
 * Replaces the scripts/dropbox_configure.php CLI for the white-label UI.
 * After saving keys the operator clicks "Connect Dropbox" → the OAuth init
 * route (app/admin/oauth/dropbox/init.php) handles the authorization flow.
 *
 * Sensitive-value semantics (mirrors api/v1/quickbooks/save_credentials.php):
 * a blank or masked-placeholder app_secret is SKIPPED so the operator can
 * change the app_key without re-typing the secret. The secret value is NEVER
 * echoed back and NEVER written to audit_log (field names only).
 *
 * Columns: settings uses value_type / group_name (NOT type/group) — Trap 71.
 *
 * @method  POST
 * @auth    Session; require_permission('settings', 'edit'); CSRF via bootstrap (X-CSRF-Token)
 * @body    JSON: { app_key?: string, app_secret?: string }
 * @returns 200 { success: true, changed: string[] }
 *
 * Session: S-BACKUP-3b
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

use FleetForge\Backup\DropboxClient;

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$appKey    = isset($body['app_key'])    ? (string) $body['app_key']    : null;
$appSecret = isset($body['app_secret']) ? (string) $body['app_secret'] : null;

// Masked placeholder emitted by the UI (8 bullets) → operator did not retype.
$isMaskedPlaceholder = static function (string $v): bool {
    return $v !== '' && str_starts_with($v, '••••••••');
};

$changed = [];

db_transaction(function () use (&$changed, $appKey, $appSecret, $isMaskedPlaceholder): void {
    // app_key is non-sensitive — persist verbatim whenever a non-empty value
    // was supplied. (A blank app_key would break OAuth, so we don't clear it.)
    if ($appKey !== null && trim($appKey) !== '') {
        db_execute(
            "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = 'dropbox.app_key'",
            [trim($appKey), current_user_id()]
        );
        $changed[] = 'app_key';
    }

    // app_secret is sensitive — only persist a real new value; blank/placeholder
    // leaves the stored (encrypted) secret untouched.
    if ($appSecret !== null && trim($appSecret) !== '' && !$isMaskedPlaceholder(trim($appSecret))) {
        db_execute(
            "UPDATE settings SET `value` = ?, updated_by = ?, updated_at = NOW() WHERE `key` = 'dropbox.app_secret'",
            [DropboxClient::encrypt(trim($appSecret)), current_user_id()]
        );
        $changed[] = 'app_secret';
    }

    if ($changed !== []) {
        $user = current_user();
        db_insert('audit_log', [
            'user_id'     => $user['id'] ?? null,
            'user_name'   => $user['name'] ?? 'system',
            'action'      => 'update',
            'module'      => 'settings',
            'entity_type' => 'dropbox_credentials',
            // WHY: never log the actual credential values — field names only.
            'notes'       => 'Dropbox credentials updated: ' . implode(', ', $changed),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
});

json_success(['changed' => $changed]);
