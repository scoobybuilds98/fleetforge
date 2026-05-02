<?php
declare(strict_types=1);

/**
 * api/v1/account/mfa/regenerate_codes.php
 *
 * Regenerate backup codes for a user who has MFA enabled.
 * Invalidates all existing unused codes and issues a fresh set.
 *
 * POST /api/v1/account/mfa/regenerate_codes
 *   → { backup_codes: ["XXXX...", ...] }
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

// Must have MFA enabled to regenerate codes
$me = db_row("SELECT mfa_enabled FROM users WHERE id = ?", [$userId]);
if (!$me || !(int) $me['mfa_enabled']) {
    json_error('MFA_NOT_ENABLED', 'MFA must be enabled before generating backup codes.', 422);
}

$count       = (int) settings_get('security.mfa.backup_code_count', 10);
$backupCodes = MfaService::generateBackupCodes($userId, $count);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? '',
    'action'       => 'update',
    'module'       => 'auth',
    'entity_type'  => 'user',
    'entity_id'    => $userId,
    'entity_label' => current_user()['email'] ?? '',
    'notes'        => 'MFA backup codes regenerated — ' . $count . ' new codes issued',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
]);

json_success(['backup_codes' => $backupCodes]);
