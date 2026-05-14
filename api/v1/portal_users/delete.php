<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/delete.php
 *
 * S-USERS-CONSOLIDATE C3 — Permanently delete a portal user. Ported
 * from the inline `delete_portal_user` POST handler in pre-S-USERS-
 * CONSOLIDATE app/admin/settings/portal_users.php.
 *
 * portal_users is NOT a soft-delete table (D5 list does not include
 * it) — this is a HARD DELETE. Refuses to delete portal users with
 * is_primary=1; primary users are the account holder of record and
 * must be deactivated (status='inactive') rather than removed.
 *
 * @method  POST
 * @body    JSON { id: int }
 * @auth    Session required; require_permission('settings','delete')
 *          — super_admin gate matches the legacy $isSuperAdmin check
 *          that wrapped the inline handler.
 * @returns 200 { id, deleted: true }
 *          422 VALIDATION_ERROR
 *          404 NOT_FOUND
 *          409 CONFLICT (cannot delete primary)
 *
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_validation_error(['id' => 'Portal user ID is required.']);
}

$target = db_row(
    "SELECT pu.id, pu.name, pu.email, pu.is_primary, c.company_name
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ?",
    [$id]
);
if (!$target) {
    json_error('NOT_FOUND', 'Portal user not found.', 404);
}

if ((int) $target['is_primary'] === 1) {
    json_error(
        'CONFLICT',
        'Cannot delete the primary portal user. Deactivate instead.',
        409
    );
}

// Hard delete — portal_users has no deleted_at column.
db_execute("DELETE FROM portal_users WHERE id = ?", [$id]);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $id,
    'entity_label' => $target['name'] . ' (' . $target['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => "Permanently deleted portal user {$target['email']}",
]);

json_success(['id' => $id, 'deleted' => true]);
