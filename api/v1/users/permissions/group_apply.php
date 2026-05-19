<?php
declare(strict_types=1);

/**
 * api/v1/users/permissions/group_apply.php
 *
 * S-PERM-EXPAND — Apply a bulk override macro to one module group.
 *
 * "Group macros" are syntactic sugar over the per-(user, module, action)
 * override API in update.php. This endpoint loops the group's module
 * list × get_actions_for_module(module) and writes one override row
 * per cell using the same upsert pattern update.php uses. There is
 * NO new override scope or table — the runtime can() check is
 * unchanged (D-PERM-EXPAND-1, D-PERM-EXPAND-3).
 *
 * Macros:
 *   grant_view        → set granted=1 only for action='view'
 *                       (all other actions in the module are SKIPPED,
 *                        leaving any existing override intact)
 *   grant_read_write  → set granted=1 for view/create/edit
 *                       (delete + extended verbs are SKIPPED)
 *   deny_all          → set granted=0 for EVERY action in every module
 *                       in the group (including extended verbs)
 *   clear             → DELETE the override row for every action
 *                       (revert to role defaults across the group)
 *
 * The QBO group is intentionally bulk-restricted: grant_view and
 * grant_read_write only flip the 'view' action even on the quickbooks
 * module. Extended QBO actions (force_resync, clear_queue, disconnect,
 * edit_credentials, view_raw_payloads, force_full_resync) require
 * individual per-action grants in the admin matrix — too sensitive
 * for bulk operations. The response includes a warning string when
 * such a macro is applied to the QBO group.
 *
 * @method  POST
 * @body    JSON: {
 *            user_id: int,
 *            group:   string (key in config/permission_groups.php),
 *            macro:   one of 'grant_view'|'grant_read_write'|'deny_all'|'clear',
 *            reason:  string (REQUIRED — appears in audit_log rows)
 *          }
 * @auth    super_admin only
 * @returns 200 { user_id, group, macro,
 *                applied_count, cleared_count,
 *                warnings: string[] }
 *          404 NOT_FOUND       (user doesn't exist)
 *          409 SUPER_ADMIN_PROTECTED (target is super_admin)
 *          422 VALIDATION_ERROR
 *          403 FORBIDDEN       (caller is not super_admin)
 *
 * @session  S-PERM-EXPAND
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

if (!is_super_admin()) {
    json_error('FORBIDDEN', 'Only super_admin may manage permission overrides.', 403);
}

$body = json_body();

// ── Field validation (first pass — presence + shape) ────────
$fields = [];

$userId = clean_int($body['user_id'] ?? null);
if (!$userId || $userId <= 0) {
    $fields['user_id'] = 'A valid user is required.';
}

$groupKey = clean_string($body['group'] ?? null, 50);
if ($groupKey === null || $groupKey === '') {
    $fields['group'] = 'Group key is required.';
}

$macro = clean_string($body['macro'] ?? null, 50);
$validMacros = ['grant_view', 'grant_read_write', 'deny_all', 'clear'];
if ($macro === null || !in_array($macro, $validMacros, true)) {
    $fields['macro'] = 'Macro must be one of: ' . implode(', ', $validMacros) . '.';
}

// Reason is REQUIRED for the audit trail — bulk operations affect
// many cells, so every cell's audit row should explain why.
$reason = isset($body['reason']) ? clean_string($body['reason'], 1000) : null;
if ($reason === null || $reason === '') {
    $fields['reason'] = 'A reason is required for bulk macro operations.';
}

if (!empty($fields)) {
    json_validation_error($fields);
}

// ── Validate group exists in config ─────────────────────────
$groups = get_permission_groups();
if (!isset($groups[$groupKey])) {
    json_validation_error([
        'group' => "Unknown group '{$groupKey}'. Valid groups: "
                 . implode(', ', array_keys($groups)) . '.',
    ]);
}
$group = $groups[$groupKey];

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

// Block writes against super_admin targets (mirrors update.php PERM-1 behaviour).
if ($user['role_slug'] === 'super_admin') {
    json_error(
        'SUPER_ADMIN_PROTECTED',
        'super_admin always has full access. Bulk macros cannot be applied to super_admin users.',
        409
    );
}

$grantedById = current_user_id();
$grantedBy   = current_user();
$actorName   = $grantedBy['name'] ?? 'system';

// Audit-log notes prefix shared by every row this macro writes.
$auditPrefix = "Group macro: {$groupKey} / {$macro} by {$actorName}. Reason: {$reason}";

/**
 * Decide what to do with a single (module, action) cell given the macro.
 *
 * @return int|string|null
 *   1, 0, or null — the target granted value
 *   'skip'        — leave any existing override alone (do nothing)
 *   'clear'       — DELETE any existing override row
 */
$decide = static function (string $macro, string $action): int|string|null {
    return match ($macro) {
        'grant_view'       => $action === 'view' ? 1 : 'skip',
        'grant_read_write' => in_array($action, ['view', 'create', 'edit'], true) ? 1 : 'skip',
        'deny_all'         => 0,
        'clear'            => 'clear',
    };
};

$applied = 0;
$cleared = 0;

db_transaction(function () use (
    &$applied, &$cleared,
    $group, $userId, $macro, $reason, $grantedById, $actorName, $user,
    $auditPrefix, $decide
) {
    foreach ($group['modules'] as $module) {
        $moduleActions = get_actions_for_module($module);

        foreach ($moduleActions as $action) {
            $decision = $decide($macro, $action);

            if ($decision === 'skip') {
                continue;
            }

            // Snapshot existing override for the audit row.
            $existing = db_row(
                "SELECT id, granted, reason FROM user_permission_overrides
                 WHERE user_id = ? AND module = ? AND action = ?",
                [$userId, $module, $action]
            );

            if ($decision === 'clear') {
                if (!$existing) {
                    continue; // nothing to clear; not counted toward cleared_count
                }
                db_execute(
                    "DELETE FROM user_permission_overrides WHERE id = ?",
                    [(int) $existing['id']]
                );
                $newValues = ['granted' => null, 'reason' => null];
                $cleared++;
            } else {
                // $decision is 0 or 1
                if ($existing) {
                    db_update('user_permission_overrides', [
                        'granted'    => $decision,
                        'reason'     => $auditPrefix,
                        'granted_by' => $grantedById,
                    ], 'id = ?', [(int) $existing['id']]);
                } else {
                    db_insert('user_permission_overrides', [
                        'user_id'    => $userId,
                        'module'     => $module,
                        'action'     => $action,
                        'granted'    => $decision,
                        'granted_by' => $grantedById,
                        'reason'     => $auditPrefix,
                    ]);
                }
                $newValues = ['granted' => $decision, 'reason' => $auditPrefix];
                $applied++;
            }

            // One audit row per cell (D-PERM-EXPAND-3 — not a summary row).
            db_insert('audit_log', [
                'user_id'      => $grantedById,
                'user_name'    => $actorName,
                'action'       => $decision === 'clear' ? 'delete' : 'update',
                'module'       => 'users',
                'entity_type'  => 'user_permission_override',
                'entity_id'    => $userId,
                'entity_label' => $user['email'] . ' / ' . $module . ':' . $action,
                'old_values'   => json_encode([
                    'granted' => $existing ? (int) $existing['granted'] : null,
                    'reason'  => $existing ? $existing['reason'] : null,
                ]),
                'new_values'   => json_encode($newValues),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        }
    }
});

// QBO-specific safety note for grant macros that only touched 'view'.
$warnings = [];
if ($groupKey === 'quickbooks' && in_array($macro, ['grant_view', 'grant_read_write'], true)) {
    $warnings[] = 'Extended QuickBooks actions (force_resync, force_full_resync, '
               . 'clear_queue, disconnect, edit_credentials, view_raw_payloads) must be '
               . 'granted individually in the matrix — they are too sensitive for bulk macros.';
}

_ff_refresh_user_permissions($userId);

json_success([
    'user_id'       => $userId,
    'group'         => $groupKey,
    'macro'         => $macro,
    'applied_count' => $applied,
    'cleared_count' => $cleared,
    'warnings'      => $warnings,
]);
