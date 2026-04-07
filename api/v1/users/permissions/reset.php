<?php
declare(strict_types=1);

/**
 * api/v1/users/permissions/reset.php
 *
 * PERM-1 — Clear ALL permission overrides for a single user.
 *
 * Reverts the target user to the plain role defaults from
 * config/permissions.php. Equivalent to calling update.php with
 * granted=null for every row the user currently has.
 *
 * Audit:
 *   - Writes ONE audit_log row summarising how many overrides
 *     were cleared and which modules were affected, so the action
 *     is easily searchable.
 *
 * @method  POST
 * @body    JSON: { user_id }
 * @auth    super_admin only
 * @returns 200 { user_id, cleared_count }
 *          404 NOT_FOUND | 403 FORBIDDEN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage permission overrides.', 403);
}

$body   = json_body();
$userId = clean_int($body['user_id'] ?? null);
if (!$userId || $userId <= 0) {
    json_validation_error(['user_id' => 'A valid user is required.']);
}

$user = db_row(
    "SELECT u.id, u.email, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);
if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// Snapshot existing overrides (for audit log)
$existing = db_select(
    "SELECT id, module, action, granted FROM user_permission_overrides WHERE user_id = ?",
    [$userId]
);
$count = count($existing);

if ($count === 0) {
    // Idempotent no-op: still return 200 so the UI can treat it as success.
    json_success([
        'user_id'       => $userId,
        'cleared_count' => 0,
    ]);
}

$grantedById = current_user_id();
$grantedBy   = current_user();

db_transaction(function () use ($userId, $existing, $grantedById, $grantedBy, $user, $count) {
    db_execute(
        "DELETE FROM user_permission_overrides WHERE user_id = ?",
        [$userId]
    );

    $summary = [];
    foreach ($existing as $row) {
        $summary[] = $row['module'] . ':' . $row['action'] . '=' . (int) $row['granted'];
    }

    db_insert('audit_log', [
        'user_id'      => $grantedById,
        'user_name'    => $grantedBy['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'users',
        'entity_type'  => 'user_permission_override',
        'entity_id'    => $userId,
        'entity_label' => $user['email'] . ' / reset_all (' . $count . ')',
        'old_values'   => json_encode(['overrides' => $summary]),
        'new_values'   => json_encode(['overrides' => []]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

_ff_refresh_user_permissions($userId);

json_success([
    'user_id'       => $userId,
    'cleared_count' => $count,
]);
