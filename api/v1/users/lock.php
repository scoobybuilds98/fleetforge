<?php
declare(strict_types=1);

/**
 * api/v1/users/lock.php
 *
 * S-USER-LOCKOUT: super_admin-only endpoint that immediately revokes a
 * user's access to FleetForge. Sets users.status = 'locked', which every
 * existing auth checkpoint (login.php, the self-service + admin-triggered
 * password-reset lookups, auth_check_remember_me(), and the mid-session
 * freshness re-check in _ff_check_permission_freshness()) already treats
 * the same way it treats 'suspended'/'inactive': login fails, password-
 * reset requests silently no-op, and an already-open session is force-
 * ended on the user's very next authenticated request.
 *
 * This endpoint additionally:
 *   - requires a mandatory reason (accountability for a hard lock, unlike
 *     the softer Suspend/Deactivate actions which don't require one),
 *   - clears remember_token so a still-valid "remember me" cookie can't
 *     restore a session even before the freshness check would catch it,
 *   - deletes any outstanding self-service password-reset tokens for the
 *     account, so a reset link issued before the lock can't be redeemed.
 *
 * Business rules:
 *   - Caller must be super_admin (require_role, not just users.edit --
 *     locking is never delegable via a permission override).
 *   - Cannot lock your own account.
 *   - Target must exist, not be soft-deleted, and not already be locked.
 *   - Audit log: action='status_change', notes carry the reason.
 *
 * @method  POST
 * @body    JSON: { user_id: N, reason: "..." }
 * @returns 200 { message }
 *          403 FORBIDDEN | 404 NOT_FOUND | 409 CONFLICT | 422 VALIDATION_ERROR
 *
 * @session S-USER-LOCKOUT
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_role('super_admin');

$body     = json_body();
$targetId = clean_int($body['user_id'] ?? null);
$reason   = trim((string) ($body['reason'] ?? ''));

if (!$targetId) {
    json_error('MISSING_REQUIRED', 'user_id is required.', 422);
}

if ($reason === '') {
    json_error('VALIDATION_ERROR', 'A reason is required to lock a user out.', 422);
}

if (mb_strlen($reason) > 500) {
    json_error('VALIDATION_ERROR', 'Reason must be 500 characters or fewer.', 422);
}

$actorId = current_user_id();

// WHY: mirrors the self-guard on every other high-stakes user-admin
// endpoint (disable_mfa.php, set_password.php, update_status.php) -- a
// super_admin must never be able to lock themselves out.
if ($targetId === $actorId) {
    json_error('FORBIDDEN', 'You cannot lock your own account.', 403);
}

$target = db_row(
    "SELECT id, name, email, status FROM users WHERE id = ? AND deleted_at IS NULL",
    [$targetId]
);

if (!$target) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

if ($target['status'] === 'locked') {
    json_error('CONFLICT', 'This user is already locked out.', 409);
}

db_transaction(function () use ($targetId, $actorId, $reason, $target): void {
    db_execute(
        "UPDATE users
            SET status = 'locked',
                locked_at = NOW(),
                locked_by = ?,
                lock_reason = ?,
                remember_token = NULL
          WHERE id = ?",
        [$actorId, $reason, $targetId]
    );

    // WHY: a password-reset link emailed minutes before the lock would
    // otherwise still exist -- both reset_password.php lookups already
    // filter on status='active' so it can't be redeemed, but deleting
    // the token outright removes it rather than relying only on that
    // filter as the sole line of defense.
    db_execute("DELETE FROM password_reset_tokens WHERE user_id = ?", [$targetId]);

    db_insert('audit_log', [
        'user_id'      => $actorId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'users',
        'entity_type'  => 'user',
        'entity_id'    => $targetId,
        'entity_label' => $target['name'] ?? $target['email'],
        'old_values'   => json_encode(['status' => $target['status']]),
        'new_values'   => json_encode(['status' => 'locked']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'notes'        => "Locked out by super admin. Reason: {$reason}",
    ]);
});

json_success(['message' => 'User locked out.']);
