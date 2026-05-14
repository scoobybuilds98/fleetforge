<?php
declare(strict_types=1);

/**
 * api/v1/portal_users/update_status.php
 *
 * S-USERS-CONSOLIDATE C3 — Toggle portal user status between
 * active / inactive. Ported from the inline `change_portal_status`
 * POST handler in the pre-S-USERS-CONSOLIDATE app/admin/settings/portal_users.php.
 *
 * The portal_users.status ENUM has 3 values (active / inactive / invited)
 * but only active ↔ inactive is reachable from the admin UI. The 'invited'
 * value is set ONLY at creation by api/v1/portal_users/create.php and
 * cleared automatically when the user redeems their password_reset_token
 * via portal/auth/reset_password.php — admins do not flip back to invited.
 *
 * @method  POST
 * @body    JSON { id: int, status: 'active'|'inactive' }
 * @auth    Session required; require_permission('settings','edit')
 * @returns 200 { id, status }
 *          422 VALIDATION_ERROR
 *          404 NOT_FOUND
 *
 * @session S-USERS-CONSOLIDATE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Portal user ID is required.']);
}

$status = clean_string($body['status'] ?? null, 20);
$allowed = ['active', 'inactive'];
if ($status === null || !in_array($status, $allowed, true)) {
    json_validation_error(['status' => 'Status must be one of: ' . implode(', ', $allowed) . '.']);
}

$target = db_row(
    "SELECT pu.id, pu.name, pu.status, c.company_name
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ?",
    [$id]
);
if (!$target) {
    json_error('NOT_FOUND', 'Portal user not found.', 404);
}

// Idempotent — if already at the target status, return success without
// writing an audit row. Avoids audit-log noise from double-clicks.
if ($target['status'] === $status) {
    json_success(['id' => $id, 'status' => $status]);
}

db_update('portal_users', ['status' => $status], 'id = ?', [$id]);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'status_change',
    'module'       => 'portal_users',
    'entity_type'  => 'portal_user',
    'entity_id'    => $id,
    'entity_label' => $target['name'] . ' (' . $target['company_name'] . ')',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'notes'        => "Portal user status: {$target['status']} → {$status}",
]);

json_success(['id' => $id, 'status' => $status]);
