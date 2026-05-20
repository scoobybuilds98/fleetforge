<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/refresh_token_now.php
 *
 * Operator-initiated token refresh. Calls
 * QuickBooksClient::refreshAccessToken() — same path the daily
 * cron uses — but on demand from the Dashboard's Quick Actions
 * strip. Used by operators who want to confirm OAuth health
 * without waiting for the 02:00 cron tick.
 *
 * @method  POST
 * @auth    Session required; require_permission('quickbooks', 'edit_credentials')
 * @returns 200 { success: true, new_expires_at: string|null, refresh_expires_at: string|null }
 *        | 200 { success: false, error: string }   (when refresh fails — UI surfaces inline)
 *
 * Spec ref: §5.3 (token refresh)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\QuickBooksClient;

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'edit_credentials');

try {
    $client = new QuickBooksClient();
    $client->refreshAccessToken();

    json_success([
        'message'             => 'Token refreshed successfully.',
        'new_expires_at'      => settings_get('quickbooks.access_token_expires_at'),
        'refresh_expires_at'  => settings_get('quickbooks.refresh_token_expires_at'),
        'last_token_refresh_at' => settings_get('quickbooks.last_token_refresh_at'),
    ]);
} catch (\RuntimeException $e) {
    // refreshAccessToken() throws RuntimeException on transport
    // or auth failures. Return HTTP 200 with success=false so the
    // UI can render inline rather than treat as a transport error.
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    exit;
}
