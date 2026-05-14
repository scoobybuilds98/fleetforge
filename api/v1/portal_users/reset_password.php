<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/reset_password.php
 *
 * S-USERS-CONSOLIDATE C3 — Admin-initiated password reset for a portal
 * user. Ported from the inline `reset_portal_password` POST handler in
 * pre-S-USERS-CONSOLIDATE app/admin/settings/portal_users.php.
 *
 * Effect: stamps a new password_reset_token + 24h expiry on the portal
 * user row, then logs a single-line mail-log entry with the reset URL.
 * The portal user follows the URL to portal/auth/reset_password.php to
 * set a new password. The old password (if any) remains valid until the
 * token is redeemed — there is intentionally NO password invalidation
 * here, so an admin-initiated reset doesn't accidentally lock out a
 * portal user who never receives the email.
 *
 * @method  POST
 * @body    JSON { id: int }
 * @auth    Session required; require_permission('settings','edit')
 * @returns 200 { id, email, expires_at }
 *          422 VALIDATION_ERROR
 *          404 NOT_FOUND
 *
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_validation_error(['id' => 'Portal user ID is required.']);
}

$target = db_row(
    "SELECT pu.id, pu.name, pu.email, c.company_name
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ?",
    [$id]
);
if (!$target) {
    json_error('NOT_FOUND', 'Portal user not found.', 404);
}

$plainToken = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $plainToken);
$expiry     = date('Y-m-d H:i:s', strtotime('+24 hours'));

db_update('portal_users', [
    'password_reset_token'  => $tokenHash,
    'password_reset_expiry' => $expiry,
], 'id = ?', [$id]);

$resetUrl = base_url('portal/auth/reset_password')
    . '?token=' . $plainToken
    . '&email=' . urlencode($target['email']);

$logDir = FF_ROOT . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents($logDir . '/mail.log', sprintf(
    "[%s] ADMIN-INITIATED PORTAL PASSWORD RESET: To: %s | Name: %s | Company: %s | URL: %s\n",
    date('Y-m-d H:i:s'),
    $target['email'],
    $target['name'],
    $target['company_name'],
    $resetUrl
), FILE_APPEND);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'update',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $id,
    'entity_label' => $target['name'] . ' (' . $target['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => "Admin initiated password reset for portal user {$target['email']}",
]);

json_success([
    'id'         => $id,
    'email'      => $target['email'],
    'expires_at' => $expiry,
]);
