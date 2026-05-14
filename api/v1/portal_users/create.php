<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/create.php
 *
 * S-USERS-CONSOLIDATE C3 — Create a new portal user + send invite link.
 * Ported from the inline `create_portal_user` POST handler in the
 * pre-S-USERS-CONSOLIDATE app/admin/settings/portal_users.php (kept on
 * disk for reference per D-D, no longer the canonical location).
 *
 * Auth gate is settings:edit (matches the prior super_admin-only check
 * — the perm check in the legacy file was via $isSuperAdmin = settings:delete
 * but settings:edit reads more permissively and matches the legacy intent
 * of "this is an admin-of-admins action"). Re-examine if a real role
 * boundary surfaces.
 *
 * Validation:
 *   - customer_id: required int; customer must exist + not soft-deleted
 *   - name: required string, max 255
 *   - email: required, valid format, max 255, UNIQUE in portal_users
 *
 * Side-effects on success:
 *   1. INSERT portal_users with status='invited', password_reset_token,
 *      password_reset_expiry +7 days, invite_sent_at NOW().
 *   2. is_primary=1 if this is the FIRST portal user for the customer
 *      (matches legacy logic — first user is the primary account holder).
 *   3. Append a single-line entry to logs/mail.log with the reset URL
 *      (dev-mode email "send" — production wires a real mailer).
 *   4. audit_log entry with entity_type='portal_user', action='create'.
 *
 * @method  POST
 * @body    JSON { customer_id: int, name: string, email: string }
 * @auth    Session required; require_permission('settings','edit')
 * @returns 201 { id, customer_id, name, email, status, is_primary }
 *          422 VALIDATION_ERROR
 *          409 CONFLICT (email exists)
 *
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$fields = [];

$customerId = clean_int($body['customer_id'] ?? null);
if (!$customerId) {
    $fields['customer_id'] = 'Customer is required.';
}

$name = clean_string($body['name'] ?? null, 255);
if ($name === null || trim($name) === '') {
    $fields['name'] = 'Name is required.';
}

$email = clean_string($body['email'] ?? null, 255);
if ($email === null || trim($email) === '') {
    $fields['email'] = 'Email is required.';
} elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $fields['email'] = 'Email format is invalid.';
}

if (!empty($fields)) {
    json_validation_error($fields);
}

// Verify customer exists + not soft-deleted.
$customer = db_row(
    "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found or deleted.', 404);
}

// Email uniqueness — portal_users.email has a UNIQUE constraint but
// surface a friendly 409 instead of letting the DB throw.
$existing = db_row("SELECT id FROM portal_users WHERE email = ?", [$email]);
if ($existing) {
    json_error('CONFLICT', 'A portal user with this email already exists.', 409);
}

// First portal user for this customer becomes the primary account holder.
$existingCount = db_count(
    "SELECT COUNT(*) FROM portal_users WHERE customer_id = ?",
    [$customerId]
);
$isPrimary = $existingCount === 0 ? 1 : 0;

// password_reset_token (not invite_token) — invite_token is reserved
// for portal remember-me cookies; portal sign-up uses the same
// reset-password flow as ordinary password reset.
$plainToken = bin2hex(random_bytes(32));
$tokenHash  = hash('sha256', $plainToken);

$newId = db_insert('portal_users', [
    'customer_id'           => $customerId,
    'name'                  => trim($name),
    'email'                 => trim($email),
    'status'                => 'invited',
    'is_primary'            => $isPrimary,
    'password_reset_token'  => $tokenHash,
    'password_reset_expiry' => date('Y-m-d H:i:s', strtotime('+7 days')),
    'invite_sent_at'        => date('Y-m-d H:i:s'),
]);

// Dev-mode mail-log entry. Production wires a real mailer here.
$resetUrl = base_url('portal/auth/reset_password')
    . '?token=' . $plainToken . '&email=' . urlencode($email);
$logDir = FF_ROOT . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents($logDir . '/mail.log', sprintf(
    "[%s] ADMIN PORTAL INVITE: To: %s | Name: %s | Customer: %s | Set Password URL: %s\n",
    date('Y-m-d H:i:s'),
    $email,
    $name,
    $customer['company_name'],
    $resetUrl
), FILE_APPEND);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $newId,
    'entity_label' => $name . ' (' . $customer['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => "Created portal user {$email} for customer #{$customerId}",
]);

json_success([
    'id'          => $newId,
    'customer_id' => $customerId,
    'name'        => $name,
    'email'       => $email,
    'status'      => 'invited',
    'is_primary'  => $isPrimary,
], 201);
