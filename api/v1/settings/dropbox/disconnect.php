<?php
declare(strict_types=1);

/**
 * api/v1/settings/dropbox/disconnect.php
 *
 * POST endpoint — disconnects the Dropbox backup destination:
 *   - clears dropbox.refresh_token
 *   - clears dropbox.connected_account
 *   - sets   dropbox.enabled = '0'
 *
 * The app_key / app_secret are LEFT intact so the operator can reconnect
 * without re-pasting them. After disconnect the cron's enabled check
 * (dropbox.enabled != '1') short-circuits to a clean exit 0.
 *
 * Columns: settings uses value_type / group_name (NOT type/group) — Trap 71.
 *
 * @method  POST
 * @auth    Session; require_permission('settings', 'edit'); CSRF via bootstrap (X-CSRF-Token)
 * @returns 200 { success: true }
 *
 * Session: S-BACKUP-3b
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

db_transaction(function (): void {
    db_execute("UPDATE settings SET `value` = '', updated_by = ?, updated_at = NOW() WHERE `key` = 'dropbox.refresh_token'", [current_user_id()]);
    db_execute("UPDATE settings SET `value` = '', updated_by = ?, updated_at = NOW() WHERE `key` = 'dropbox.connected_account'", [current_user_id()]);
    db_execute("UPDATE settings SET `value` = '0', updated_by = ?, updated_at = NOW() WHERE `key` = 'dropbox.enabled'", [current_user_id()]);

    $user = current_user();
    db_insert('audit_log', [
        'user_id'     => $user['id'] ?? null,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'settings',
        'entity_type' => 'dropbox_oauth_connection',
        'notes'       => 'Dropbox disconnected (refresh token + account cleared, disabled).',
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['disconnected' => true]);
