<?php
declare(strict_types=1);

/**
 * api/v1/account/mfa/disable.php
 *
 * Self-service MFA disable. Only allowed for users whose role does NOT
 * require MFA (mfa_required = 0).
 *
 * D-B: users in mfa_required roles cannot disable their own MFA.
 *      A super_admin must use the admin endpoint (api/v1/users/disable_mfa.php)
 *      to disable MFA for another user in those roles.
 *
 * POST /api/v1/account/mfa/disable
 *   → { success: true }  or 403
 *
 * @session S-PROD-1A
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$userId = current_user_id();

use FleetForge\Auth\MfaService;

$me = db_row("SELECT mfa_enabled, mfa_required FROM users WHERE id = ?", [$userId]);

if (!$me || !(int) $me['mfa_enabled']) {
    json_error('MFA_NOT_ENABLED', 'MFA is not enabled on this account.', 422);
}

// D-B enforcement: mfa_required roles cannot self-disable
if ((int) $me['mfa_required']) {
    json_error(
        'FORBIDDEN',
        'Your role requires MFA. An administrator must disable it for you.',
        403
    );
}

MfaService::disableMfa($userId, $userId);

json_success(['message' => 'Two-factor authentication has been disabled.']);
