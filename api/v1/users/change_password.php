<?php
declare(strict_types=1);

/**
 * api/v1/users/change_password.php
 *
 * Allows any authenticated user to change their own password.
 * No module permission check — every logged-in user may change their own password.
 *
 * Business rules:
 *   - current_password must match the stored bcrypt hash.
 *   - new_password must be at least 10 characters.
 *   - confirm_password must match new_password.
 *   - Password is hashed with bcrypt cost 12.
 *   - Audit log entry written with action='update'.
 *
 * @method  POST
 * @body    form-encoded: current_password, new_password, confirm_password
 * @auth    Session required (any authenticated user)
 * @returns 200 { message }
 *          422 VALIDATION_ERROR (current wrong, too short, mismatch)
 *          404 NOT_FOUND
 *
 * @decisions D5 (soft-delete guard), D7 (path resolution), §7 (audit log)
 * @session   S017-B
 */

// dirname(__DIR__, 3): api/v1/users/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

// ── 1. Read and validate input ────────────────────────────────────────────────
// WHY: support both JSON body (FF_Api.post) and form-encoded POST for flexibility
$body            = json_body();
$currentPassword = $body['current_password']  ?? ($_POST['current_password']  ?? '');
$newPassword     = $body['new_password']       ?? ($_POST['new_password']       ?? '');
$confirmPassword = $body['confirm_password']   ?? ($_POST['confirm_password']   ?? '');

if ($currentPassword === '') {
    json_error('VALIDATION_ERROR', 'Current password is required.', 422);
}

if ($newPassword === '') {
    json_error('VALIDATION_ERROR', 'New password is required.', 422);
}

if (strlen($newPassword) < 10) {
    json_error('VALIDATION_ERROR', 'Password must be at least 10 characters.', 422);
}

if ($newPassword !== $confirmPassword) {
    json_error('VALIDATION_ERROR', 'Passwords do not match.', 422);
}

// ── 2. Fetch current user row ────────────────────────────────────────────────
$userId = current_user_id();

$user = db_row(
    "SELECT id, password_hash FROM users WHERE id = ? AND deleted_at IS NULL",
    [$userId]
);

if (!$user) {
    // WHY: should never happen for a live session, but guard defensively
    json_error('NOT_FOUND', 'User not found.', 404);
}

// ── 3. Verify current password ───────────────────────────────────────────────
if (!password_verify($currentPassword, $user['password_hash'] ?? '')) {
    json_error('VALIDATION_ERROR', 'Current password is incorrect.', 422);
}

// ── 4. Hash new password and persist ────────────────────────────────────────
// WHY: bcrypt cost 12 is the project standard (same as invite/register flow)
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

db_transaction(function () use ($userId, $newHash): void {
    db_execute(
        "UPDATE users SET password_hash = ? WHERE id = ? AND deleted_at IS NULL",
        [$newHash, $userId]
    );

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $userId,
        'entity_label' => current_user()['email'] ?? '',
        'notes'        => 'Password changed by user',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

json_success(['message' => 'Password changed successfully.']);
