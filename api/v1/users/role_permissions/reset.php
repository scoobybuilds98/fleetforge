<?php
declare(strict_types=1);

/**
 * api/v1/users/role_permissions/reset.php
 *
 * S-ROLE-PERM-OVERRIDE — Clear ALL overrides for a role.
 *
 * Deletes every row in role_permission_overrides for the given role_id
 * and touches users.permissions_updated_at for all affected users so
 * their sessions pick up the reset on the next request.
 *
 * @method  POST
 * @body    JSON: { role_id }
 * @auth    super_admin only
 * @returns 200 { cleared_count }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage role permissions.', 403);
}

$body   = json_body();
$roleId = clean_int($body['role_id'] ?? null);
if (!$roleId || $roleId <= 0) {
    json_validation_error(['role_id' => 'A valid role_id is required.']);
}

$role = db_row("SELECT id, name, slug FROM user_roles WHERE id = ?", [$roleId]);
if (!$role) json_error('NOT_FOUND', 'Role not found.', 404);

if ($role['slug'] === 'super_admin') {
    json_error('SUPER_ADMIN_PROTECTED', 'super_admin always has full access. No overrides to reset.', 409);
}

// Count before delete for the response
$count = db_count(
    "SELECT COUNT(*) FROM role_permission_overrides WHERE role_id = ?",
    [$roleId]
);

if ($count > 0) {
    db_transaction(function () use ($roleId, $role, $count) {
        db_execute("DELETE FROM role_permission_overrides WHERE role_id = ?", [$roleId]);

        $updatedById = current_user_id();
        $updatedBy   = current_user();

        db_insert('audit_log', [
            'user_id'      => $updatedById,
            'user_name'    => $updatedBy['name'] ?? 'system',
            'action'       => 'delete',
            'module'       => 'users',
            'entity_type'  => 'role_permission_override',
            'entity_id'    => $roleId,
            'entity_label' => $role['name'] . ' / all overrides reset',
            'old_values'   => json_encode(['cleared_count' => $count]),
            'new_values'   => json_encode(['cleared_count' => 0]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);

        // Touch all users of this role for session freshness.
        db_execute(
            "UPDATE users SET permissions_updated_at = NOW() WHERE role_id = ? AND deleted_at IS NULL",
            [$roleId]
        );
    });
}

json_success(['cleared_count' => $count]);
