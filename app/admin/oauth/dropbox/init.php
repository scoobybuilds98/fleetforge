<?php
declare(strict_types=1);

/**
 * app/admin/oauth/dropbox/init.php
 *
 * Step 1 of the Dropbox OAuth 2.0 authorization-code grant. Mints a
 * per-request state token via StateManager (DB-backed, 10-min TTL,
 * single-use), then 302-redirects the operator to the Dropbox
 * authorization URL.
 *
 * Operator lands back on app/admin/oauth/dropbox/callback.php with
 * ?code=...&state=... once the Dropbox UI completes.
 *
 * URL: /fleetforge/oauth/dropbox/init.php (direct file-path routing).
 *
 * Auth model: operator-initiated, so require_auth + require_permission
 * are correct here. The callback is intentionally public (state token IS
 * the auth proof per D-QBO-OAUTH-FIX-2, same principle applied to Dropbox).
 *
 * CANONICAL REDIRECT URI: https://mainlandrentals.com/fleetforge/oauth/dropbox/callback.php
 * — register this exact string in the Dropbox Developer Console (App Console
 *   → your app → OAuth 2 → Redirect URIs).
 *
 * Session: S-BACKUP-2
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\OAuth\StateManager;

require_auth();
require_permission('settings', 'edit');

$appKey = (string) settings_get('dropbox.app_key', '');

if ($appKey === '') {
    header(
        'Location: ' . base_url('admin/settings') .
        '?flash_error=' . rawurlencode('Set Dropbox App Key before connecting. Run scripts/dropbox_configure.php first.')
    );
    exit;
}

// DB-backed state token (K-22 Trap #59): session cookies absent under
// cross-origin redirects; state is persisted to acc_oauth_states instead.
$state = StateManager::generate(
    'dropbox',
    StateManager::DEFAULT_TTL_SECONDS,
    current_user_id(),
    $_SERVER['REMOTE_ADDR'] ?? null
);

// token_access_type=offline requests a long-lived refresh_token (D-BACKUP-1).
$authorizeUrl = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
    'client_id'         => $appKey,
    'response_type'     => 'code',
    'redirect_uri'      => 'https://mainlandrentals.com/fleetforge/oauth/dropbox/callback.php',
    'state'             => $state,
    'token_access_type' => 'offline',
]);

header('Location: ' . $authorizeUrl);
exit;
