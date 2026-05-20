<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/disconnect.php
 *
 * POST endpoint. Clears the active QBO connection: wipes access +
 * refresh tokens, blanks expiry timestamps, flips connection_status
 * to 'disconnected', and forces sync_enabled='0' as a safety belt
 * so no orphaned cron job pushes after disconnect.
 *
 * Per spec §5.5: does NOT wipe any acc_qbo_*_map tables — those are
 * preserved so a re-connect to the same realm resumes cleanly.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'disconnect')
 * @returns 200 { success: true }
 *          403 FORBIDDEN | 405 METHOD_NOT_ALLOWED | 500 INTERNAL_ERROR
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.5
 * Session:  S-QBO-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'disconnect');

$user = current_user();

db_transaction(function () use ($user): void {
    QuickBooksClient::settings_write_qbo('access_token',              '');
    QuickBooksClient::settings_write_qbo('refresh_token',             '');
    QuickBooksClient::settings_write_qbo('realm_id',                  '');
    QuickBooksClient::settings_write_qbo('access_token_expires_at',   '');
    QuickBooksClient::settings_write_qbo('refresh_token_expires_at',  '');
    QuickBooksClient::settings_write_qbo('connection_status',         'disconnected');
    QuickBooksClient::settings_write_qbo('connection_error',          '');
    QuickBooksClient::settings_write_qbo('sync_enabled',              '0');

    db_insert('audit_log', [
        'user_id'     => $user['id'] ?? null,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'delete',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_oauth_connection',
        'notes'       => 'QBO disconnected by operator. Tokens cleared; sync_enabled forced to 0. Mapping tables preserved.',
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['disconnected' => true]);
