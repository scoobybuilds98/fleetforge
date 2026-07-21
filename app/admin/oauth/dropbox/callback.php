<?php
declare(strict_types=1);

/**
 * app/admin/oauth/dropbox/callback.php
 *
 * Step 2 of the Dropbox OAuth 2.0 authorization-code grant. Receives
 * ?code=...&state=... from Dropbox, verifies the state token, exchanges
 * the code for access + refresh tokens, encrypts and persists them, then
 * sets dropbox.enabled='1'.
 *
 * URL: base_url('oauth/dropbox/callback.php') — for Mainland that resolves to
 * https://mainlandrentals.com/fleetforge/oauth/dropbox/callback.php.
 * — must match the Redirect URI registered in the Dropbox App Console exactly.
 *
 * On success: flash_success → redirect to admin/settings.
 * On failure: dropbox.connection_status='error' + flash_error redirect.
 *
 * Auth model (D-QBO-OAUTH-FIX-2 principle applied to Dropbox): intentionally
 * public — no require_auth, no require_permission. The state token IS the
 * authentication proof. The initiating user_id is recovered from acc_oauth_states
 * for audit_log attribution without needing an active session.
 *
 * Session: S-BACKUP-2
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Backup\DropboxClient;
use FleetForge\OAuth\StateManager;

/**
 * Redirect to the settings page with a flash message and exit.
 */
function ff_dropbox_redirect(string $flashType, string $message): never
{
    // Real settings route is `settings` (NOT `admin/settings`, which 404s).
    // Land the operator back on the Backup tab. Mirrors the PRG redirect in
    // app/admin/settings/index.php which builds `base_url('settings?tab=...')`.
    $param = $flashType === 'success' ? 'flash_success' : 'flash_error';
    header('Location: ' . base_url('settings?tab=backup') . '&' . $param . '=' . rawurlencode($message));
    exit;
}

/**
 * Resolve a user id to a display name for audit attribution.
 * Returns 'system' when the user is null or no longer exists.
 */
function ff_dropbox_user_name(?int $userId): string
{
    if ($userId === null) {
        return 'system';
    }
    $row = db_row('SELECT name FROM users WHERE id = ?', [$userId]);
    return $row['name'] ?? 'system';
}

// ── State verification (DB-backed, single-use; K-22 Trap #59) ──────────────
$state        = (string) ($_GET['state'] ?? '');
$stateContext = StateManager::verifyAndConsume(
    $state,
    'dropbox',
    $_SERVER['REMOTE_ADDR'] ?? null
);

if ($stateContext === null) {
    http_response_code(400);
    ff_dropbox_redirect('error', 'OAuth state invalid or expired — please retry the connect flow.');
}

$initiatedUserId   = $stateContext['initiated_by_user_id'] ?? null;
$initiatedUserName = ff_dropbox_user_name($initiatedUserId);

// Dropbox sends ?error=access_denied when the operator cancels.
if (!empty($_GET['error'])) {
    $errDescription = (string) ($_GET['error_description'] ?? $_GET['error']);
    ff_dropbox_redirect('error', 'Dropbox returned an error: ' . $errDescription);
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    http_response_code(400);
    ff_dropbox_redirect('error', 'OAuth callback missing code — please retry.');
}

// Derived from APP_URL (S-NORTHLAND-P0). Dropbox requires the token-exchange
// redirect_uri to byte-match the one init.php sent, so both call base_url().
$redirectUri = base_url('oauth/dropbox/callback.php');

// ── Exchange the auth code for tokens ────────────────────────────────────────
try {
    $tokens = DropboxClient::exchangeCode($code, $redirectUri);
} catch (\Throwable $e) {
    $msg = 'OAuth token exchange failed: ' . $e->getMessage();
    db_insert('audit_log', [
        'user_id'     => $initiatedUserId,
        'user_name'   => $initiatedUserName,
        'action'      => 'update',
        'module'      => 'dropbox',
        'entity_type' => 'dropbox_oauth_connection',
        'notes'       => mb_substr($msg, 0, 65535),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    ff_dropbox_redirect('error', 'OAuth token exchange failed — check error logs.');
}

// ── Persist encrypted tokens (D-BACKUP-1) ───────────────────────────────────
$encRefreshToken   = DropboxClient::encrypt((string) ($tokens['refresh_token'] ?? ''));
$accountId         = (string) ($tokens['account_id'] ?? '');

db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.refresh_token'", [$encRefreshToken]);
db_execute("UPDATE settings SET `value`='1' WHERE `key`='dropbox.enabled'");

// Friendly connected-account label (email / display name), NOT the raw
// dbid:... account id. Fail-soft: if the lookup fails, fall back to the
// account id so the connection still succeeds.
$friendly = $accountId;
try {
    $acct = (new DropboxClient())->getCurrentAccount();
    $email   = (string) ($acct['email'] ?? '');
    $display = (string) ($acct['name']['display_name'] ?? '');
    if ($email !== '' && $display !== '') {
        $friendly = $display . ' <' . $email . '>';
    } elseif ($email !== '') {
        $friendly = $email;
    } elseif ($display !== '') {
        $friendly = $display;
    }
} catch (\Throwable $acctErr) {
    error_log('[dropbox callback] getCurrentAccount failed: ' . $acctErr->getMessage());
}
db_execute("UPDATE settings SET `value`=? WHERE `key`='dropbox.connected_account'", [$friendly]);

db_insert('audit_log', [
    'user_id'      => $initiatedUserId,
    'user_name'    => $initiatedUserName,
    'action'       => 'create',
    'module'       => 'dropbox',
    'entity_type'  => 'dropbox_oauth_connection',
    'entity_label' => $friendly !== '' ? $friendly : 'connected',
    'notes'        => 'Dropbox OAuth connection established.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

ff_dropbox_redirect('success', 'Dropbox connected successfully.');
