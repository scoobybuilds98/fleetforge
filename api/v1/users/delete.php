<?php
declare(strict_types=1);

/**
 * api/v1/users/delete.php
 *
 * Soft-delete a user.
 *
 * Business rules:
 *   - Cannot delete yourself (compare to current_user_id()).
 *   - Cannot delete an active user — must deactivate (status='inactive') first.
 *   - Soft delete: SET deleted_at = NOW().
 *   - Audit log: action='delete'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('users','delete')
 * @returns 200 { deleted: true }
 *          404 NOT_FOUND | 403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'delete');

// -----------------------------------------------------------------------
// 1. Parse body
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Cannot delete yourself
// -----------------------------------------------------------------------
if ($id === current_user_id()) {
    json_error('FORBIDDEN', 'You cannot delete your own account.', 403);
}

// -----------------------------------------------------------------------
// 3. Fetch user
// -----------------------------------------------------------------------
$user = db_row(
    "SELECT id, name, email, status FROM users WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// -----------------------------------------------------------------------
// 4. Block deletion of active users — must deactivate first
// -----------------------------------------------------------------------
if ($user['status'] === 'active') {
    json_error('VALIDATION_ERROR', 'Active users cannot be deleted. Deactivate the user first.', 422);
}

// -----------------------------------------------------------------------
// 5. Soft delete inside transaction + audit log
// -----------------------------------------------------------------------
db_transaction(function () use ($id, $user) {
    db_execute(
        "UPDATE users SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $user['email'],
        'old_values'   => json_encode(['status' => $user['status'], 'email' => $user['email']]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

json_success(['deleted' => true]);
