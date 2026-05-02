<?php
declare(strict_types=1);

/**
 * api/v1/users/disable_mfa.php
 *
 * Admin endpoint: super_admin disables MFA for another user.
 * Used for emergency recovery when a user loses both their authenticator
 * and all backup codes.
 *
 * D-B: Super_admin cannot disable their own MFA via this endpoint.
 *      Self-disable for mfa_required roles is not allowed.
 *
 * POST /api/v1/users/disable_mfa
 *   Body: { user_id: N }
 *   → { success: true }
 *
 * @session S-PROD-1A
 */

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

define('FF_API_CONTEXT', true);
require_method('POST');
require_auth_api();
require_role('super_admin');

use FleetForge\Auth\MfaService;

$body   = json_decode(file_get_contents('php://input'), true);
$target = clean_int($body['user_id'] ?? null);

if (!$target) {
    json_error('VALIDATION_ERROR', 'user_id is required.', 422);
}

$actorId = current_user_id();

// D-B: cannot disable own MFA through the admin endpoint either
if ($target === $actorId) {
    json_error(
        'FORBIDDEN',
        'You cannot disable your own MFA. Ask another super_admin to do this for you.',
        403
    );
}

$targetUser = db_row(
    "SELECT id, mfa_enabled FROM users WHERE id = ? AND deleted_at IS NULL",
    [$target]
);

if (!$targetUser) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

if (!(int) $targetUser['mfa_enabled']) {
    json_error('MFA_NOT_ENABLED', 'MFA is not enabled for this user.', 422);
}

MfaService::disableMfa($target, $actorId);

json_success(['message' => 'MFA has been disabled for the user.']);
