<?php
declare(strict_types=1);

/**
 * api/v1/users/set_password.php
 *
 * Super-admin endpoint to set another user's password directly.
 * No current password required. No email notification is sent.
 *
 * Business rules:
 *   - Caller must be super_admin (403 if not).
 *   - Cannot be used to set your own password — use change_password.php instead (422).
 *   - Target user must exist and not be soft-deleted (404 if not found).
 *   - new_password required, minimum 10 characters (422).
 *   - confirm_password must match new_password (422).
 *   - Password is hashed with bcrypt cost 12.
 *   - Audit log entry written with action='update', notes='Password set by administrator'.
 *
 * @method  POST
 * @body    JSON: id, new_password, confirm_password
 * @auth    Session required; is_super_admin() check
 * @returns 200 { message: 'Password updated.' }
 *          403 FORBIDDEN | 404 NOT_FOUND | 405 METHOD_NOT_ALLOWED | 422 VALIDATION_ERROR
 *
 * @decisions D5 (soft-delete guard), D7 (path resolution), §7 (audit log)
 * @session   S017-B
 */

// dirname(__DIR__, 3): api/v1/users/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

// WHY: this endpoint is restricted exclusively to super_admin; other roles must not access it
if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super admins may set another user\'s password.', 403);
}

// ── 1. Parse body ─────────────────────────────────────────────────────────────
$body           = json_body();
$id             = clean_int($body['id'] ?? null);
$newPassword    = $body['new_password']    ?? '';
$confirmPassword = $body['confirm_password'] ?? '';

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// ── 2. Cannot set your own password via this endpoint ────────────────────────
// WHY: super_admin should use change_password.php (which verifies current password)
//      for their own account to prevent accidental self-lockout bypass
if ($id === current_user_id()) {
    json_error('VALIDATION_ERROR', 'Use change_password.php to update your own password.', 422);
}

// ── 3. Validate new_password ─────────────────────────────────────────────────
if ($newPassword === '') {
    json_error('VALIDATION_ERROR', 'New password is required.', 422);
}

if (strlen($newPassword) < 10) {
    json_error('VALIDATION_ERROR', 'Password must be at least 10 characters.', 422);
}

if ($newPassword !== $confirmPassword) {
    json_error('VALIDATION_ERROR', 'Passwords do not match.', 422);
}

// ── 4. Fetch target user ──────────────────────────────────────────────────────
$user = db_row(
    "SELECT id, email FROM users WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// ── 5. Hash and persist inside a transaction + audit log ─────────────────────
// WHY: bcrypt cost 12 is the project standard across all password-setting flows
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

db_transaction(function () use ($id, $newHash, $user): void {
    db_execute(
        "UPDATE users SET password_hash = ? WHERE id = ?",
        [$newHash, $id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $user['email'],
        'notes'        => 'Password set by administrator',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

json_success(['message' => 'Password updated.']);
