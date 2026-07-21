<?php
declare(strict_types=1);

/**
 * api/v1/equipment/brands/delete.php
 *
 * S-UNIT-BRAND — soft-delete (retire) an equipment brand. BLOCKED while any
 * live unit still references it.
 *
 * WHY block rather than let the FK's ON DELETE SET NULL do its thing: this is a
 * SOFT delete, so the FK never fires — the row stays and the units would keep
 * pointing at a brand that has vanished from every picker and manage screen,
 * which reads as data loss the operator cannot explain or undo. Deactivating
 * (is_active = 0) is the supported way to retire a brand you still have units
 * from; that keeps the label rendering everywhere it already appears.
 *
 * @method  POST
 * @body    { id (required) }
 * @auth    Session required; require_permission('equipment','delete')
 * @returns 200 { id, deleted: true } or 409 IN_USE
 *
 * @depends api/bootstrap.php
 * @session S-UNIT-BRAND
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Brand ID is required.']);
}

$brand = db_row("SELECT id, label FROM equipment_brands WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$brand) {
    json_error('NOT_FOUND', 'Brand not found.', 404);
}

$unitCount = db_count("SELECT COUNT(*) FROM equipment_units WHERE brand_id = ? AND deleted_at IS NULL", [$id]);
if ($unitCount > 0) {
    json_error('IN_USE',
        'Cannot delete "' . $brand['label'] . '" — ' . $unitCount . ' unit' . ($unitCount === 1 ? '' : 's')
        . ' still use it. Deactivate it instead (it stays on those units but drops out of the picker), '
        . 'or change those units to another brand first.',
        409, ['unit_count' => $unitCount]);
}

$userId = current_user_id();
db_transaction(function () use ($id, $userId, $brand): void {
    db_execute("UPDATE equipment_brands SET deleted_at = NOW(), is_active = 0 WHERE id = ?", [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_brand',
        'entity_id'    => $id,
        'entity_label' => $brand['label'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
