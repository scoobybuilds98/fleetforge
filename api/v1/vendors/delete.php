<?php
declare(strict_types=1);

/**
 * api/v1/vendors/delete.php
 *
 * Soft-delete a vendor.
 *
 * Business rules:
 *   - Block delete if vendor has active work orders
 *     (status IN open, in_progress, waiting_parts).
 *   - Soft delete: sets deleted_at = NOW(), does NOT hard-delete the row.
 *   - ON DELETE SET NULL on the FK only fires on hard DELETE — soft delete
 *     leaves mwo.vendor_id intact so work order history is preserved.
 *   - Audit log: action='delete'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { id, deleted: true }
 *          404 NOT_FOUND | 422 HAS_ACTIVE_WORK_ORDERS
 *
 * Decisions: D5 (soft delete), §7 (audit log)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

// -----------------------------------------------------------------------
// 1. Parse body + resolve vendor
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$vendor = db_row(
    "SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$vendor) {
    json_error('NOT_FOUND', 'Vendor not found.', 404);
}

// -----------------------------------------------------------------------
// 2. Block delete if active work orders exist
// -----------------------------------------------------------------------
$activeWoCount = db_count(
    "SELECT COUNT(*) FROM maintenance_work_orders
     WHERE vendor_id = ? AND status IN ('open','in_progress','waiting_parts')
       AND deleted_at IS NULL",
    [$id]
);
if ($activeWoCount > 0) {
    json_error(
        'VALIDATION_ERROR',
        "Cannot delete vendor with $activeWoCount active work order(s). " .
        "Complete or cancel all work orders before deleting this vendor.",
        422
    );
}

// -----------------------------------------------------------------------
// 3. Soft delete inside transaction + audit log
// -----------------------------------------------------------------------
db_transaction(function() use ($id, $vendor) {
    db_execute(
        "UPDATE vendors SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'maintenance',
        'entity_type'  => 'vendor',
        'entity_id'    => $id,
        'entity_label' => $vendor['name'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
