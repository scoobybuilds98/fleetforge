<?php
declare(strict_types=1);

/**
 * api/v1/equipment/subcategories/delete.php
 *
 * S-EQTAX — soft-delete (retire) a sub-category. BLOCKED while any active
 * equipment type references it (FK is ON DELETE RESTRICT and removing it would
 * orphan those types' classification). Reassign or deactivate first.
 *
 * @method  POST
 * @body    { id (required) }
 * @auth    Session required; require_permission('equipment','delete')
 * @returns 200 { id, deleted: true } or 409 IN_USE
 *
 * @session S-EQTAX-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Sub-category ID is required.']);
}

$sub = db_row("SELECT id, label FROM equipment_subcategories WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$sub) {
    json_error('NOT_FOUND', 'Sub-category not found.', 404);
}

$tplCount = db_count("SELECT COUNT(*) FROM equipment_templates WHERE subcategory_id = ? AND deleted_at IS NULL", [$id]);
if ($tplCount > 0) {
    json_error('IN_USE',
        'Cannot delete "' . $sub['label'] . '" — ' . $tplCount . ' equipment type'
        . ($tplCount === 1 ? '' : 's') . ' use it. Reassign or deactivate them first.', 409,
        ['template_count' => $tplCount]);
}

$userId = current_user_id();
db_transaction(function () use ($id, $userId, $sub): void {
    db_execute("UPDATE equipment_subcategories SET deleted_at = NOW(), is_active = 0 WHERE id = ?", [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_subcategory',
        'entity_id'    => $id,
        'entity_label' => $sub['label'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
