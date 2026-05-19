<?php
declare(strict_types=1);

/**
 * api/v1/users/permissions/update.php
 *
 * PERM-1 — Set or clear a single per-user permission override.
 *
 * Behaviour:
 *   granted = 1     → upsert override row, "explicitly granted"
 *   granted = 0     → upsert override row, "explicitly denied"
 *   granted = null  → DELETE the override row (revert to role default)
 *
 * Validation:
 *   - module must exist in config/permissions.php
 *   - action must be one of view/create/edit/delete/export
 *   - target user must exist and not be soft-deleted
 *   - target user must NOT be super_admin (can() always returns true
 *     for them, so overrides would be silently ignored — block at the
 *     API to prevent confusing UX)
 *
 * Audit:
 *   - Every change writes an audit_log row with the old override
 *     value (or null) and the new override value (or null) so a
 *     full history is preserved.
 *
 * Session sync:
 *   - If the editing super_admin is editing themselves the
 *     in-session permission_overrides map is refreshed so subsequent
 *     can() calls in the same request see the new value. Editing
 *     other users does not need this — their next page load loads
 *     a fresh map at auth_login() time / it's not in their session.
 *
 * @method  POST
 * @body    JSON: { user_id, module, action, granted: 0|1|null, reason? }
 * @auth    super_admin only
 * @returns 200 { user_id, module, action, granted, override_id|null }
 *          404 NOT_FOUND | 422 VALIDATION_ERROR | 403 FORBIDDEN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage permission overrides.', 403);
}

$body = json_body();

// ── Field validation ────────────────────────────────────────
$fields = [];

$userId = clean_int($body['user_id'] ?? null);
if (!$userId || $userId <= 0) {
    $fields['user_id'] = 'A valid user is required.';
}

$module = clean_string($body['module'] ?? null, 50);
if ($module === null || $module === '') {
    $fields['module'] = 'Module is required.';
}

// S-PERM-EXPAND: presence-only check here. The per-module action
// whitelist is applied AFTER we've verified the module is real
// (and therefore know which verbs it supports) — see below.
$action = clean_string($body['action'] ?? null, 50);
if ($action === null || $action === '') {
    $fields['action'] = 'Action is required.';
}

// granted: 0, 1, or null. Anything else (string, missing key) is invalid.
$grantedRaw = array_key_exists('granted', $body) ? $body['granted'] : '__missing__';
$granted    = null;
if ($grantedRaw === '__missing__') {
    $fields['granted'] = 'granted is required (0, 1, or null).';
} elseif ($grantedRaw === null) {
    $granted = null;
} elseif ($grantedRaw === 0 || $grantedRaw === 1 || $grantedRaw === '0' || $grantedRaw === '1') {
    $granted = (int) $grantedRaw;
} else {
    $fields['granted'] = 'granted must be 0, 1, or null.';
}

if (!empty($fields)) {
    json_validation_error($fields);
}

// ── Validate module is in the config ────────────────────────
$permissionsConfig = require FF_ROOT . '/config/permissions.php';
$validModules = [];
foreach ($permissionsConfig as $rolePerms) {
    foreach (array_keys($rolePerms) as $m) $validModules[$m] = true;
}
if (!isset($validModules[$module])) {
    json_validation_error(['module' => 'Unknown module: ' . $module]);
}

// S-PERM-EXPAND: validate action against the per-module vocabulary
// from config/permission_actions.php. Replaces the hardcoded
// 5-action CRUD whitelist that lived in the first-pass validation.
$validActions = get_actions_for_module($module);
if (!in_array($action, $validActions, true)) {
    json_validation_error([
        'action' => "Action '{$action}' is not valid for module '{$module}'. "
                  . 'Valid actions: ' . implode(', ', $validActions) . '.',
    ]);
}

// ── Resolve target user ─────────────────────────────────────
$user = db_row(
    "SELECT u.id, u.name, u.email, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);
if (!$user) {
    json_error('NOT_FOUND', 'User not found.', 404);
}

// Block writes against super_admin targets — would be silently ignored.
if ($user['role_slug'] === 'super_admin') {
    json_error(
        'SUPER_ADMIN_PROTECTED',
        'super_admin always has full access. Overrides cannot be applied to super_admin users.',
        409
    );
}

// Reason is optional but capped to a sensible length.
$reason = isset($body['reason']) ? clean_string($body['reason'], 1000) : null;

// ── Capture existing override (for audit log) ───────────────
$existing = db_row(
    "SELECT id, granted, reason FROM user_permission_overrides
     WHERE user_id = ? AND module = ? AND action = ?",
    [$userId, $module, $action]
);

// ── Apply change in a transaction + audit log ───────────────
$grantedById = current_user_id();
$grantedBy   = current_user();

$resultPayload = [
    'user_id'     => $userId,
    'module'      => $module,
    'action'      => $action,
    'granted'     => $granted,
    'override_id' => null,
];

db_transaction(function () use (
    &$resultPayload,
    $existing, $userId, $module, $action,
    $granted, $reason, $grantedById, $grantedBy, $user
) {
    if ($granted === null) {
        // Clear the override row (revert to role default).
        if ($existing) {
            db_execute(
                "DELETE FROM user_permission_overrides WHERE id = ?",
                [(int) $existing['id']]
            );
        }
        $resultPayload['override_id'] = null;
    } else {
        if ($existing) {
            db_update('user_permission_overrides', [
                'granted'    => $granted,
                'reason'     => $reason,
                'granted_by' => $grantedById,
            ], 'id = ?', [(int) $existing['id']]);
            $resultPayload['override_id'] = (int) $existing['id'];
        } else {
            $newId = db_insert('user_permission_overrides', [
                'user_id'    => $userId,
                'module'     => $module,
                'action'     => $action,
                'granted'    => $granted,
                'granted_by' => $grantedById,
                'reason'     => $reason,
            ]);
            $resultPayload['override_id'] = (int) $newId;
        }
    }

    db_insert('audit_log', [
        'user_id'      => $grantedById,
        'user_name'    => $grantedBy['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'users',
        'entity_type'  => 'user_permission_override',
        'entity_id'    => $userId,
        'entity_label' => $user['email'] . ' / ' . $module . ':' . $action,
        'old_values'   => json_encode([
            'granted' => $existing ? (int) $existing['granted'] : null,
            'reason'  => $existing ? $existing['reason'] : null,
        ]),
        'new_values'   => json_encode([
            'granted' => $granted,
            'reason'  => $reason,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
});

// Refresh in-session map if super_admin is editing themselves.
// (Won't happen because we block super_admin targets above, but the
//  helper is a no-op when current_user_id() != $userId so it's safe.)
_ff_refresh_user_permissions($userId);

json_success($resultPayload);
