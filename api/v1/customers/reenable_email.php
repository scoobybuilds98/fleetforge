<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Re-enable Email API
 *
 * @file        api/v1/customers/reenable_email.php
 * @description Clears email_disabled on a customer record.
 *              Only managers and super_admins may call this endpoint.
 *              The action is logged to audit_log (user_name, timestamp, reason cleared).
 *
 * @method      POST
 * @body        JSON { id: int }
 * @auth        Session required; manager or super_admin role
 * @returns     200 { success: true }
 *              403 on insufficient permissions
 *              404 if customer not found
 *              422 on missing id
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @design      D-C (S-PROD-2): hard bounces auto-disable; managers manually re-enable
 * @session     S-PROD-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_permission('customers', 'edit');

// Only managers and super_admins may re-enable.
// H6: session role lives under 'role_slug' (auth_login), not 'role' — reading
// $user['role'] yielded '' for everyone, so this gate 403'd every role.
$user = current_user();
if (!in_array($user['role_slug'] ?? '', ['manager', 'super_admin'], true)) {
    json_error('FORBIDDEN', 'Only managers and super admins can re-enable email.', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = isset($body['id']) ? (int) $body['id'] : 0;

if ($id <= 0) {
    json_error('VALIDATION_ERROR', 'Customer ID is required.', 422);
}

$customer = db_row(
    "SELECT id, email, email_disabled FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1",
    [$id]
);

if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

if (!(int) $customer['email_disabled']) {
    // Already enabled — idempotent success
    json_success(['id' => $id]);
}

db_execute(
    "UPDATE customers SET email_disabled=0, email_disabled_reason=NULL, email_disabled_at=NULL WHERE id=?",
    [$id]
);

db_insert('audit_log', [
    'user_id'      => $user['id'],
    'user_name'    => $user['name'] ?? $user['email'],
    'action'       => 'update',
    'module'       => 'customers',
    'entity_type'  => 'customer',
    'entity_id'    => $id,
    'entity_label' => $customer['email'],
    'notes'        => 'email_disabled cleared manually by ' . ($user['name'] ?? $user['email']) . '.',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
]);

json_success(['id' => $id]);
