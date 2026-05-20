<?php
declare(strict_types=1);

/**
 * app/admin/oauth/qbo/callback.php
 *
 * Step 2 of the Intuit OAuth 2.0 authorization-code grant. Receives
 * the redirect from Intuit with ?code=...&state=...&realmId=...,
 * verifies the state CSRF token, exchanges the auth code for the
 * access + refresh token pair, and persists everything into the
 * settings table.
 *
 * URL: /fleetforge/oauth/qbo/callback.php (must match the redirect_uri
 * registered with Intuit Developer for the active environment).
 *
 * On success: flash_success → redirect to /quickbooks/settings.
 * On failure: connection_status flips to 'error',
 * connection_error captures the message, flash_error redirect.
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1 steps 4-8, §5.2.
 * Session:  S-QBO-1 (initial), hotfix 2026-05-20 (K-22 Trap #55:
 *           current_user_name() → current_user()['name']).
 *
 * Known constraint: during local dev with ngrok, the OAuth callback
 * arrives on a different origin (ngrok-free.dev) than the browser
 * session origin (fleetforge.test), so session-based state and auth
 * fail. Proper fix tracked as S-QBO-OAUTH-FIX (DB-backed state +
 * auth-context-free callback). Until that ships, sandbox OAuth setup
 * via ngrok requires temporary auth bypass (see commit history).
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'edit_credentials');

use FleetForge\QuickBooksClient;

/**
 * ff_qbo_redirect_to_settings — emit a Location header to the
 * settings page with a flash query-string parameter and exit.
 * Local helper — single call surface, no need to globalise.
 */
function ff_qbo_redirect_to_settings(string $flashType, string $message): never
{
    $param = $flashType === 'success' ? 'flash_success' : 'flash_error';
    header('Location: ' . base_url('quickbooks/settings') . '?' . $param . '=' . rawurlencode($message));
    exit;
}

// ── CSRF state check ───────────────────────────────────────────
// hash_equals() to defeat timing-attack snooping. A missing or
// mismatched state means either a forged redirect or a stale tab —
// either way: 400 + bounce to settings.
$state         = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['qbo_oauth_state'] ?? '');
unset($_SESSION['qbo_oauth_state']); // single-use

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    http_response_code(400);
    ff_qbo_redirect_to_settings('error', 'OAuth state mismatch — please retry the connect flow.');
}

// Intuit can hand us back an explicit error param if the operator
// cancels or the app config is wrong. Treat as a hard fail.
if (!empty($_GET['error'])) {
    $errDescription = (string) ($_GET['error_description'] ?? $_GET['error']);
    QuickBooksClient::settings_write_qbo('connection_status', 'error');
    QuickBooksClient::settings_write_qbo('connection_error',  QuickBooksClient::truncateError('OAuth denied: ' . $errDescription));
    ff_qbo_redirect_to_settings('error', 'Intuit returned an error: ' . $errDescription);
}

$code    = (string) ($_GET['code'] ?? '');
$realmId = (string) ($_GET['realmId'] ?? '');

if ($code === '' || $realmId === '') {
    http_response_code(400);
    ff_qbo_redirect_to_settings('error', 'OAuth callback missing code or realmId — please retry.');
}

// ── Determine redirect_uri for the token-exchange POST ─────────
// Must match the redirect_uri that init.php sent verbatim or
// Intuit returns invalid_grant. Same rules as init.php.
$environment = (string) settings_get('quickbooks.environment', 'sandbox');
if ($environment === 'production') {
    $redirectUri = 'https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php';
} else {
    $redirectUri = (string) settings_get('quickbooks.sandbox_redirect_uri', '');
}

$clientId     = (string) settings_get('quickbooks.client_id', '');
$clientSecret = (string) settings_get('quickbooks.client_secret', '');

// ── Exchange the auth code for tokens ──────────────────────────
$ch = curl_init('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $redirectUri,
    ]),
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Failure path ───────────────────────────────────────────────
if ($body === false || $httpCode >= 400) {
    $errSummary = $curlErr !== '' ? $curlErr : (string) $body;
    $msg = "OAuth token exchange failed (HTTP {$httpCode}): {$errSummary}";

    QuickBooksClient::settings_write_qbo('connection_status', 'error');
    QuickBooksClient::settings_write_qbo('connection_error',  QuickBooksClient::truncateError($msg));

    // Audit: action='update' because audit_log.action ENUM does
    // not include 'edit' — 'update' is the closest semantic match.
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'user_name'   => current_user()['name'] ?? 'system',
        'action'      => 'update',
        'module'      => 'quickbooks',
        'entity_type' => 'qbo_oauth_connection',
        'notes'       => 'OAuth connection failed: ' . QuickBooksClient::truncateError($msg),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    ff_qbo_redirect_to_settings('error', 'OAuth token exchange failed — check Settings for details.');
}

$decoded = json_decode((string) $body, true);
if (!is_array($decoded) || empty($decoded['access_token']) || empty($decoded['refresh_token'])) {
    QuickBooksClient::settings_write_qbo('connection_status', 'error');
    QuickBooksClient::settings_write_qbo('connection_error',  'OAuth token exchange returned malformed body.');
    ff_qbo_redirect_to_settings('error', 'OAuth token exchange returned malformed body.');
}

// ── Success path ───────────────────────────────────────────────
$now           = time();
$accessExpiry  = $now + (int) ($decoded['expires_in'] ?? 3600);
$refreshExpiry = $now + (int) ($decoded['x_refresh_token_expires_in'] ?? 8726400);

QuickBooksClient::settings_write_qbo('access_token',             (string) $decoded['access_token']);
QuickBooksClient::settings_write_qbo('refresh_token',            (string) $decoded['refresh_token']);
QuickBooksClient::settings_write_qbo('access_token_expires_at',  date('Y-m-d H:i:s', $accessExpiry));
QuickBooksClient::settings_write_qbo('refresh_token_expires_at', date('Y-m-d H:i:s', $refreshExpiry));
QuickBooksClient::settings_write_qbo('realm_id',                 $realmId);
QuickBooksClient::settings_write_qbo('last_connected_at',        date('Y-m-d H:i:s', $now));
QuickBooksClient::settings_write_qbo('connection_status',        'connected');
QuickBooksClient::settings_write_qbo('connection_error',         '');

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'quickbooks',
    'entity_type'  => 'qbo_oauth_connection',
    'entity_label' => 'Realm ' . $realmId,
    'notes'        => 'OAuth connection established for environment=' . $environment,
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

ff_qbo_redirect_to_settings('success', 'Connected to QuickBooks (realm ' . $realmId . ').');