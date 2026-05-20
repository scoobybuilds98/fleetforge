<?php
declare(strict_types=1);

/**
 * app/admin/oauth/qbo/init.php
 *
 * Step 1 of the Intuit OAuth 2.0 authorization-code grant. Generates
 * a per-request CSRF state token, stashes it in $_SESSION, then
 * 302-redirects the operator to the Intuit authorize URL.
 *
 * Operator lands back on app/admin/oauth/qbo/callback.php with
 * ?code=...&state=...&realmId=... once Intuit's UI completes.
 *
 * URL: /fleetforge/oauth/qbo/init.php (routed via public/index.php
 * catchall → this file). Authenticated operators only; the
 * edit_credentials check blocks anyone who can view but not write
 * the QBO settings.
 *
 * Spec ref: FLEETFORGE_QUICKBOOKS_SPEC.md §5.1 step 1-2.
 * Session:  S-QBO-1
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

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

// ── CSRF state token ───────────────────────────────────────────
// 32 bytes of entropy is overkill for CSRF but cheap. Stored in
// $_SESSION so the callback can hash_equals() it against the
// returned state without a DB round-trip.
$state = bin2hex(random_bytes(32));
$_SESSION['qbo_oauth_state'] = $state;

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
