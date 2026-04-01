<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Soft-Delete API
 *
 * @file        api/v1/customers/delete.php
 * @description Soft-deletes a customer by setting deleted_at to NOW().
 *              Blocks deletion if the customer has active_lease_count > 0
 *              (cannot delete a customer with live leases — HAS_ACTIVE_LEASES).
 *              Logs to audit_log.
 *
 * @method      POST
 * @body        JSON { id: int }
 * @required    id (int)
 * @auth        Session required; require_permission('customers','delete')
 * @returns     200 { id, deleted_at }
 *              409 HAS_ACTIVE_LEASES if customer has active leases
 *              404 if customer not found or already deleted
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'delete');

$body = json_body();

// ── Input ──────────────────────────────────────────────────────
$id = clean_int($body['id'] ?? null);
if (!$id || $id <= 0) {
    json_error('INVALID_ID', 'A valid customer ID is required.', 400);
}

// ── Load existing record ────────────────────────────────────────
$customer = db_row(
    "SELECT id, company_name, active_lease_count FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$customer) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// ── Block if active leases exist ────────────────────────────────
// WHY: deleting a customer with active leases would orphan financial records.
// The denormalized active_lease_count column is used here for a fast check.
if ((int) $customer['active_lease_count'] > 0) {
    json_error(
        'HAS_ACTIVE_LEASES',
        'This customer has active leases and cannot be deleted. Close or transfer all active leases first.',
        409
    );
}

$userId    = current_user_id();
$deletedAt = date('Y-m-d H:i:s');

// ── Soft delete ────────────────────────────────────────────────
db_transaction(function () use ($id, $userId, $deletedAt, $customer): void {
    db_update(
        'customers',
        ['deleted_at' => $deletedAt, 'updated_by' => $userId],
        'id = ?',
        [$id]
    );

    // Audit log
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'unknown',
        'action'       => 'delete',
        'module'       => 'customers',
        'entity_type'  => 'customer',
        'entity_id'    => $id,
        'entity_label' => $customer['company_name'],
        'old_values'   => json_encode(['deleted_at' => null]),
        'new_values'   => json_encode(['deleted_at' => $deletedAt]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted_at' => $deletedAt]);
