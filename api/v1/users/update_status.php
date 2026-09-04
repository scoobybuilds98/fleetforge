<?php
declare(strict_types=1);

/**
 * api/v1/users/update_status.php
 *
 * Change a user's status (active / inactive / suspended).
 *
 * Business rules:
 *   - Valid target statuses: active, inactive, suspended.
 *   - Cannot set 'locked' or 'invited' via this endpoint (see
 *     api/v1/users/lock.php for the super_admin-only hard-lock path).
 *   - Moving a user OUT of 'locked' via this endpoint (e.g. the Lockout
 *     tab's "Unlock" button posting status='active') clears locked_at/
 *     locked_by/lock_reason and the brute-force login_attempts/locked_until
 *     pair -- see S-USER-LOCKOUT.
 *   - Cannot deactivate yourself (would lock you out).
 *   - Audit log: action='status_change', old_values/new_values.
 *
 * @method  POST
 * @body    JSON: id (required), status (required)
 * @auth    Session required; require_permission('users','edit')
 * @returns 200 { id, status }
 *          404 NOT_FOUND | 403 FORBIDDEN | 422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S017
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('users', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$newStatus = clean_string($body['status'] ?? null);
if (!$newStatus) {
    json_error('MISSING_REQUIRED', 'status is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Validate new status — only allow safe transitions via this endpoint
// -----------------------------------------------------------------------
$allowedStatuses = ['active', 'inactive', 'suspended'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    json_error('VALIDATION_ERROR', 'status must be one of: active, inactive, suspended.', 422);
}

// -----------------------------------------------------------------------
// 3. Cannot change status of yourself (guard against self-lockout)
// -----------------------------------------------------------------------
if ($id === current_user_id() && $newStatus !== 'active') {
    json_error('FORBIDDEN', 'You cannot deactivate or suspend your own account.', 403);
}

// -----------------------------------------------------------------------
// 4. Fetch user
// -----------------------------------------------------------------------
$user = db_row(
    "SELECT id, name, email, status FROM users WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// No-op if status is already the same
if ($user['status'] === $newStatus) {
    json_success(['id' => $id, 'status' => $newStatus]);
}

// -----------------------------------------------------------------------
// 5. Update status inside transaction + audit log
// -----------------------------------------------------------------------
// S-USER-LOCKOUT: this is also how a locked-out user gets un-locked (the
// Lockout tab's "Unlock" button posts status='active' here rather than a
// bespoke endpoint). Whenever the OLD status was 'locked', clear the lock
// bookkeeping columns and the unrelated brute-force lockout counter too --
// otherwise a user unlocked through this generic endpoint would still show
// a stale "locked by X because Y" and could still be blocked by a leftover
// locked_until from an earlier failed-login streak.
db_transaction(function () use ($id, $newStatus, $user) {
    $wasLocked = $user['status'] === 'locked';

    db_execute(
        $wasLocked
            ? "UPDATE users
                  SET status = ?, locked_at = NULL, locked_by = NULL, lock_reason = NULL,
                      login_attempts = 0, locked_until = NULL
                WHERE id = ?"
            : "UPDATE users SET status = ? WHERE id = ?",
        [$newStatus, $id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $id,
        'entity_label' => $user['email'],
        'old_values'   => json_encode(['status' => $user['status']]),
        'new_values'   => json_encode(['status' => $newStatus]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

json_success(['id' => $id, 'status' => $newStatus]);
