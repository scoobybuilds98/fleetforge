<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/delete.php
 *
 * Soft-delete a maintenance work order.
 *
 * Business rules:
 *   - Only 'open' and 'cancelled' work orders may be deleted.
 *   - 'in_progress', 'waiting_parts', 'completed' are blocked (active or terminal).
 *   - Sets deleted_at = NOW(). Does not affect line items (CASCADE is not set; line items
 *     remain in DB for historical reference but are unreachable via normal queries).
 *   - Audit log: action='delete'.
 *
 * @method  POST
 * @body    JSON: id (required)
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { deleted: true } | 404 NOT_FOUND | 422 if status blocks delete
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

$body   = json_body();
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Work order ID is required.';
    json_validation_error($fields);
}

$wo = db_row(
    "SELECT id, work_order_number, status FROM maintenance_work_orders
     WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$wo) {
    json_error('NOT_FOUND', 'Work order not found.', 404);
}

// Block delete on active or completed states
if (!in_array($wo['status'], ['open', 'cancelled'], true)) {
    json_error('INVALID_TRANSITION',
        "Cannot delete a work order with status '{$wo['status']}'. Only open or cancelled work orders may be deleted.", 422,
        ['fields' => ['id' => 'Only open or cancelled work orders may be deleted.']]);
}

db_transaction(function() use ($id, $wo) {
    db_execute(
        "UPDATE maintenance_work_orders SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $id,
        'entity_label' => $wo['work_order_number'],
        'old_values'   => json_encode(['status' => $wo['status']]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

invalidate_dashboard_cache();

json_success(['deleted' => true]);
