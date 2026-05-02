<?php
declare(strict_types=1);

/**
 * api/v1/account/mfa/setup_verify.php
 *
 * Step 2 of MFA setup: validate the user's first TOTP code against the
 * session secret, then commit MFA to the DB and issue backup codes.
 *
 * POST /api/v1/account/mfa/setup_verify
 *   Body: { code: "123456" }
 *   → { backup_codes: ["XXXX...", ...] }      on success
 *   → 422 INVALID_CODE | SESSION_EXPIRED       on failure
 *
 * Setup session expires after 10 minutes of inactivity.
 *
 * @session S-PROD-1A
 */

require_once __DIR__ . '/../../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

define('FF_API_CONTEXT', true);
require_method('POST');
require_auth_api();

$userId = current_user_id();

use FleetForge\Auth\MfaService;

// ── Validate setup session ────────────────────────────────────────────────────
$setup = $_SESSION['ff_mfa_setup'] ?? null;

if (!$setup || !isset($setup['secret'], $setup['started_at'])) {
    json_error('SESSION_EXPIRED', 'MFA setup session not found. Please start setup again.', 422);
}

if ((time() - (int) $setup['started_at']) > 600) {
    unset($_SESSION['ff_mfa_setup']);
    json_error('SESSION_EXPIRED', 'MFA setup session expired (10-minute limit). Please start again.', 422);
}

// ── Parse + validate input ───────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$code = preg_replace('/\D/', '', (string) ($body['code'] ?? ''));

if (strlen($code) !== 6) {
    json_error('VALIDATION_ERROR', 'Code must be 6 digits.', 422);
}

// ── Enable MFA (verifies code, encrypts secret, issues backup codes) ─────────
$result = MfaService::enableMfa($userId, $setup['secret'], $code);

if (!$result['success']) {
    // Log failed setup attempt but do NOT clear the session — user can retry
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? '',
        'action'       => 'update',
        'module'       => 'auth',
        'entity_type'  => 'user',
        'entity_id'    => $userId,
        'entity_label' => current_user()['email'] ?? '',
        'notes'        => 'MFA setup failed — invalid TOTP code',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    json_error('INVALID_CODE', 'The code is incorrect. Check your authenticator app and try again.', 422);
}

// ── Clean up session ──────────────────────────────────────────────────────────
unset($_SESSION['ff_mfa_setup']);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? '',
    'action'       => 'update',
    'module'       => 'auth',
    'entity_type'  => 'user',
    'entity_id'    => $userId,
    'entity_label' => current_user()['email'] ?? '',
    'notes'        => 'MFA enabled — ' . count($result['backup_codes']) . ' backup codes issued',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
]);

// Update session to reflect MFA-enabled state so UI reacts immediately
if (isset($_SESSION['ff_user'])) {
    $_SESSION['ff_user']['mfa_enabled'] = 1;
}

json_success(['backup_codes' => $result['backup_codes']]);
