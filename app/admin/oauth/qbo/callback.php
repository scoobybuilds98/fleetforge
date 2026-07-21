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
 * Session:  S-QBO-1 (initial), S-QBO-OAUTH-FIX (2026-05-20: DB-backed
 *           state + auth-context-free per D-QBO-OAUTH-FIX-2; resolves
 *           K-22 Trap #59 — session cookie absent at callback under
 *           ngrok cross-origin redirect).
 *
 * Auth model (D-QBO-OAUTH-FIX-2): this endpoint is intentionally
 * public — no require_auth, no require_permission. The state token
 * IS the authentication proof per OAuth 2.0; gating the callback on
 * an active session is architecturally wrong AND practically broken
 * under cross-origin redirects (the session cookie isn't set). The
 * initiating user_id was captured at init time and is recovered from
 * acc_oauth_states for audit_log attribution (D-QBO-OAUTH-FIX-4).
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\QuickBooksClient;
use FleetForge\OAuth\StateManager;
use FleetForge\QboPushers\CompanyInfoSync;

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

/**
 * ff_qbo_lookup_user_name — resolve a user id to a display name for
 * audit attribution. Used because this callback runs without an
 * active session (D-QBO-OAUTH-FIX-2), so current_user()['name'] is
 * unavailable. The user_id was captured at init time and threaded
 * through acc_oauth_states.initiated_by_user_id.
 *
 * Returns 'system' when the id is null (no initiating user recorded)
 * or when the user has since been deleted — keeps audit_log inserts
 * intact rather than failing on a NOT NULL user_name column.
 */
function ff_qbo_lookup_user_name(?int $userId): string
{
    if ($userId === null) {
        return 'system';
    }
    $row = db_row('SELECT name FROM users WHERE id = ?', [$userId]);
    return $row['name'] ?? 'system';
}

// ── State verification (DB-backed, single-use; K-22 Trap #59) ──
// StateManager::verifyAndConsume atomically validates + marks the
// token used. Returns the initiated_by_user_id captured at init
// time so we can attribute the audit_log row without an active
// session. Any failure (missing / expired / replayed / tampered /
// wrong provider) returns null and is treated identically — we
// don't differentiate the reason to avoid leaking info to an
// attacker probing for valid tokens.
$state = (string) ($_GET['state'] ?? '');
$stateContext = StateManager::verifyAndConsume(
    $state,
    'quickbooks',
    $_SERVER['REMOTE_ADDR'] ?? null
);

if ($stateContext === null) {
    http_response_code(400);
    ff_qbo_redirect_to_settings('error', 'OAuth state invalid or expired — please retry the connect flow.');
}

$initiatedUserId   = $stateContext['initiated_by_user_id'] ?? null;
$initiatedUserName = ff_qbo_lookup_user_name($initiatedUserId);

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
    // base_url() — see init.php. Both sites derive from APP_URL so they
    // cannot drift apart, which is what invalid_grant would look like.
    $redirectUri = base_url('oauth/qbo/callback.php');
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
    // user_id/user_name resolved from acc_oauth_states (the operator
    // who initiated the flow); current_user_*() is unavailable here
    // because the callback runs without an active session.
    db_insert('audit_log', [
        'user_id'     => $initiatedUserId,
        'user_name'   => $initiatedUserName,
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

// user_id/user_name resolved from acc_oauth_states (D-QBO-OAUTH-FIX-4);
// callback runs without an active session so current_user_*() is unusable.
db_insert('audit_log', [
    'user_id'      => $initiatedUserId,
    'user_name'    => $initiatedUserName,
    'action'       => 'create',
    'module'       => 'quickbooks',
    'entity_type'  => 'qbo_oauth_connection',
    'entity_label' => 'Realm ' . $realmId,
    'notes'        => 'OAuth connection established for environment=' . $environment,
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
]);

// D-QBO-FIXPACK-11: Detect + cache CompanyInfo on connect so Pushers
// know whether multi-currency is enabled before any entity is pushed.
// Non-fatal: a failed CompanyInfo query doesn't block OAuth connect.
// The conservative default (multi_currency_enabled='0') stays in effect
// until CompanyInfoSync succeeds on next connect or token refresh.
try {
    $companyInfoClient = new QuickBooksClient();
    CompanyInfoSync::syncFromQbo($companyInfoClient);
} catch (\Throwable $companyInfoErr) {
    error_log('S-QBO-FIXPACK-3: CompanyInfo sync failed at connect: ' . $companyInfoErr->getMessage());
}

ff_qbo_redirect_to_settings('success', 'Connected to QuickBooks (realm ' . $realmId . ').');