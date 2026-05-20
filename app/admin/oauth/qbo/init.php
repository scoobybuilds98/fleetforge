<?php
declare(strict_types=1);

/**
 * app/admin/oauth/qbo/init.php
 *
 * Step 1 of the Intuit OAuth 2.0 authorization-code grant. Mints a
 * per-request OAuth state token via StateManager (DB-backed,
 * 10-min TTL, single-use), then 302-redirects the operator to the
 * Intuit authorize URL.
 *
 * Operator lands back on app/admin/oauth/qbo/callback.php with
 * ?code=...&state=...&realmId=... once Intuit's UI completes.
 *
 * URL: /fleetforge/oauth/qbo/init.php (routed via public/index.php
 * catchall → this file). Authenticated operators only; the
 * edit_credentials check blocks anyone who can view but not write
 * the QBO settings. (init is user-initiated, so require_auth is
 * appropriate here; the callback is auth-context-free — see
 * callback.php docblock + D-QBO-OAUTH-FIX-2.)
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1 step 1-2.
 * Session:  S-QBO-1 (initial), S-QBO-OAUTH-FIX (DB-backed state)
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\OAuth\StateManager;

require_auth();
require_permission('quickbooks', 'edit_credentials');

$clientId    = (string) settings_get('quickbooks.client_id', '');
$environment = (string) settings_get('quickbooks.environment', 'sandbox');

if ($clientId === '') {
    // No client_id configured — bounce back with a friendly flash.
    // Without client_id the Intuit redirect would silently 400.
    header('Location: ' . base_url('quickbooks/settings') . '?flash_error=' . rawurlencode('Set QBO Client ID + Client Secret before connecting.'));
    exit;
}

// ── Build the redirect_uri ─────────────────────────────────────
// Production: hard-coded canonical URL (must match Intuit Developer
// dashboard exactly — any mismatch produces redirect_uri_mismatch).
// Sandbox: read the operator-supplied ngrok URL from settings so a
// dev environment can point at a tunnel without code changes.
if ($environment === 'production') {
    $redirectUri = 'https://mainlandrentals.com/fleetforge/oauth/qbo/callback.php';
} else {
    $redirectUri = (string) settings_get('quickbooks.sandbox_redirect_uri', '');
    if ($redirectUri === '') {
        header('Location: ' . base_url('quickbooks/settings') . '?flash_error=' . rawurlencode('Set quickbooks.sandbox_redirect_uri (your ngrok URL) before connecting in sandbox mode.'));
        exit;
    }
}

// ── OAuth state token (DB-backed, K-22 Trap #59) ───────────────
// Persisted to acc_oauth_states with 10-min TTL + single-use
// enforcement. Replaces the S-QBO-1 $_SESSION pattern which fails
// under ngrok: the callback arrives on a different origin than
// init, so the session cookie isn't present at callback time and
// state verification always failed. The DB row also captures the
// initiating user_id so callback can attribute the audit_log row
// without needing an active session (D-QBO-OAUTH-FIX-4).
$state = StateManager::generate(
    'quickbooks',
    StateManager::DEFAULT_TTL_SECONDS,
    current_user_id(),
    $_SERVER['REMOTE_ADDR'] ?? null
);

// ── Build the authorize URL ────────────────────────────────────
// Scopes per spec §5.1: accounting (mandatory) + payment (for the
// QBO Payments embed in S-QBO-15). The payment scope is harmless if
// QBO Payments isn't enabled on the realm — Intuit just hides the
// payment-related entities.
$authorizeUrl = 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query([
    'client_id'     => $clientId,
    'response_type' => 'code',
    'scope'         => 'com.intuit.quickbooks.accounting com.intuit.quickbooks.payment',
    'redirect_uri'  => $redirectUri,
    'state'         => $state,
]);

header('Location: ' . $authorizeUrl);
exit;
