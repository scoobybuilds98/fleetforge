<?php
declare(strict_types=1);

/**
 * api/v1/users/role_permissions/update.php
 *
 * S-ROLE-PERM-OVERRIDE — Set or clear a single role-level permission override.
 *
 * Behaviour:
 *   granted = 1    → upsert override row (role-wide allow)
 *   granted = 0    → upsert override row (role-wide deny)
 *   granted = null → DELETE override row (revert to config default)
 *
 * Side effects:
 *   - Touches users.permissions_updated_at for every user with role_id
 *     so the existing session freshness mechanism auto-refreshes their
 *     role_permission_overrides map on the next request.
 *   - Writes an audit_log row.
 *
 * @method  POST
 * @body    JSON: { role_id, module, action, granted: 0|1|null, reason? }
 * @auth    super_admin only
 * @returns 200 { role_id, module, action, granted, override_id|null }
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage role permissions.', 403);
}

$body = json_body();

// ── Validate inputs ─────────────────────────────────────────
$fields = [];

$roleId = clean_int($body['role_id'] ?? null);
if (!$roleId || $roleId <= 0) $fields['role_id'] = 'A valid role_id is required.';

$module = clean_string($body['module'] ?? null, 50);
if (!$module) $fields['module'] = 'Module is required.';

$action = clean_string($body['action'] ?? null, 50);
if (!$action) $fields['action'] = 'Action is required.';

$grantedRaw = array_key_exists('granted', $body) ? $body['granted'] : '__missing__';
$granted    = null;
if ($grantedRaw === '__missing__') {
    $fields['granted'] = 'granted is required (0, 1, or null).';
} elseif ($grantedRaw === null) {
    $granted = null;
} elseif (in_array($grantedRaw, [0, 1, '0', '1'], true)) {
    $granted = (int) $grantedRaw;
} else {
    $fields['granted'] = 'granted must be 0, 1, or null.';
}

if (!empty($fields)) json_validation_error($fields);

// ── Validate module + action ────────────────────────────────
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$validModules = [];
foreach ($permissionsConfig as $rPerms) {
    foreach (array_keys($rPerms) as $m) $validModules[$m] = true;
}
if (!isset($validModules[$module])) {
    json_validation_error(['module' => "Unknown module: {$module}"]);
}

$validActions = get_actions_for_module($module);
if (!in_array($action, $validActions, true)) {
    json_validation_error(['action' => "Action '{$action}' is not valid for module '{$module}'. Valid: " . implode(', ', $validActions)]);
}

// ── Resolve role ────────────────────────────────────────────
$role = db_row("SELECT id, name, slug FROM user_roles WHERE id = ?", [$roleId]);
if (!$role) json_error('NOT_FOUND', 'Role not found.', 404);

// Block writes to super_admin role — it always has full access regardless.
if ($role['slug'] === 'super_admin') {
    json_error('SUPER_ADMIN_PROTECTED', 'super_admin always has full access. Role overrides cannot be applied to super_admin.', 409);
}

// Reason required for grant/deny
$reason = isset($body['reason']) ? clean_string($body['reason'], 1000) : null;
if ($granted !== null && ($reason === null || $reason === '')) {
    json_validation_error(['reason' => 'A reason is required for grant/deny changes.']);
}

// ── Existing override for audit ─────────────────────────────
$existing = db_row(
    "SELECT id, granted, reason FROM role_permission_overrides WHERE role_id = ? AND module = ? AND action = ?",
    [$roleId, $module, $action]
);

$updatedById = current_user_id();
$updatedBy   = current_user();

$resultPayload = ['role_id' => $roleId, 'module' => $module, 'action' => $action, 'granted' => $granted, 'override_id' => null];

db_transaction(function () use (
    &$resultPayload, $existing, $roleId, $module, $action,
    $granted, $reason, $updatedById, $updatedBy, $role
) {
    if ($granted === null) {
        if ($existing) {
            db_execute("DELETE FROM role_permission_overrides WHERE id = ?", [(int) $existing['id']]);
        }
    } else {
        if ($existing) {
            db_update('role_permission_overrides', [
                'granted'    => $granted,
                'reason'     => $reason,
                'updated_by' => $updatedById,
            ], 'id = ?', [(int) $existing['id']]);
            $resultPayload['override_id'] = (int) $existing['id'];
        } else {
            $newId = db_insert('role_permission_overrides', [
                'role_id'    => $roleId,
                'module'     => $module,
                'action'     => $action,
                'granted'    => $granted,
                'updated_by' => $updatedById,
                'reason'     => $reason,
            ]);
            $resultPayload['override_id'] = (int) $newId;
        }
    }

    db_insert('audit_log', [
        'user_id'      => $updatedById,
        'user_name'    => $updatedBy['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'role_permission_override',
        'entity_id'    => $roleId,
        'entity_label' => $role['name'] . ' / ' . $module . ':' . $action,
        'old_values'   => json_encode(['granted' => $existing ? (int) $existing['granted'] : null, 'reason' => $existing['reason'] ?? null]),
        'new_values'   => json_encode(['granted' => $granted, 'reason' => $reason]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);

    // Touch all users of this role so the freshness mechanism reloads
    // their role_permission_overrides map on next request (no re-login needed).
    db_execute(
        "UPDATE users SET permissions_updated_at = NOW() WHERE role_id = ? AND deleted_at IS NULL",
        [$roleId]
    );
});

json_success($resultPayload);
