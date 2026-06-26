<?php
declare(strict_types=1);

/**
 * api/v1/equipment/subcategories/update.php
 *
 * S-EQTAX — update a sub-category's editable fields (label, is_active,
 * sort_order). Slug is IMMUTABLE; parent category is fixed at create.
 *
 * @method  POST
 * @body    { id (required), label?, is_active?, sort_order? }
 * @auth    Session required; require_permission('equipment','edit')
 * @returns 200 { id }
 *
 * @session S-EQTAX-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Sub-category ID is required.']);
}

$existing = db_row("SELECT id, category_id, label FROM equipment_subcategories WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Sub-category not found.', 404);
}

$updates = [];
$fields  = [];

if (array_key_exists('label', $body)) {
    $label = clean_string($body['label'], 100);
    if (!$label) {
        $fields['label'] = 'Sub-category name is required.';
    } elseif (db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE category_id = ? AND LOWER(label) = LOWER(?) AND id <> ? AND deleted_at IS NULL", [(int) $existing['category_id'], $label, $id]) > 0) {
        $fields['label'] = 'A sub-category with this name already exists in this category.';
    } else {
        $updates['label'] = $label;
    }
}
if (array_key_exists('is_active', $body)) {
    $updates['is_active'] = !empty($body['is_active']) ? 1 : 0;
}
if (array_key_exists('sort_order', $body)) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) { $fields['sort_order'] = 'Sort order cannot be negative.'; } else { $updates['sort_order'] = $i; }
}

if ($fields) {
    json_validation_error($fields);
}
if (!$updates) {
    json_validation_error([], 'No fields provided to update.');
}

$userId = current_user_id();
db_transaction(function () use ($id, $updates, $userId, $existing): void {
    db_update('equipment_subcategories', $updates, 'id = ?', [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_subcategory',
        'entity_id'    => $id,
        'entity_label' => $existing['label'],
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id]);
