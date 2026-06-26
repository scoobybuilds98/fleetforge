<?php
declare(strict_types=1);

/**
 * api/v1/equipment/categories/delete.php
 *
 * S-EQTAX — soft-delete (retire) a category. BLOCKED while in use: a category
 * referenced by active equipment types or that still has active sub-categories
 * cannot be removed (it would orphan the billing-rule resolution and the FK is
 * ON DELETE RESTRICT). The operator should deactivate it (is_active=0) or
 * reassign the equipment types first. Not-in-use categories are soft-deleted.
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
    json_validation_error(['id' => 'Category ID is required.']);
}

$cat = db_row("SELECT id, label FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$cat) {
    json_error('NOT_FOUND', 'Category not found.', 404);
}

$tplCount = db_count("SELECT COUNT(*) FROM equipment_templates WHERE category_id = ? AND deleted_at IS NULL", [$id]);
$subCount = db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE category_id = ? AND deleted_at IS NULL", [$id]);
if ($tplCount > 0 || $subCount > 0) {
    $parts = [];
    if ($tplCount > 0) { $parts[] = "{$tplCount} equipment type" . ($tplCount === 1 ? '' : 's'); }
    if ($subCount > 0) { $parts[] = "{$subCount} sub-categor" . ($subCount === 1 ? 'y' : 'ies'); }
    json_error('IN_USE',
        'Cannot delete "' . $cat['label'] . '" — it is still used by ' . implode(' and ', $parts)
        . '. Deactivate it instead, or reassign/remove those first.', 409,
        ['template_count' => $tplCount, 'subcategory_count' => $subCount]);
}

$userId = current_user_id();
db_transaction(function () use ($id, $userId, $cat): void {
    db_execute("UPDATE equipment_categories SET deleted_at = NOW(), is_active = 0 WHERE id = ?", [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_category',
        'entity_id'    => $id,
        'entity_label' => $cat['label'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
