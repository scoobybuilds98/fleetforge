<?php
declare(strict_types=1);

/**
 * api/v1/equipment/categories/update.php
 *
 * S-EQTAX — update a category's editable fields (label, enforce-minimum flag,
 * is_active, sort_order). The slug is IMMUTABLE (it is mirrored into
 * equipment_templates.category and rate_card_items.equipment_type).
 *
 * @method  POST
 * @body    { id (required), label?, enforce_minimum_billing_days?, is_active?, sort_order? }
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
    json_validation_error(['id' => 'Category ID is required.']);
}

$existing = db_row("SELECT id, label FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Category not found.', 404);
}

$updates = [];
$fields  = [];

if (array_key_exists('label', $body)) {
    $label = clean_string($body['label'], 100);
    if (!$label) {
        $fields['label'] = 'Category name is required.';
    } elseif (db_count("SELECT COUNT(*) FROM equipment_categories WHERE LOWER(label) = LOWER(?) AND id <> ? AND deleted_at IS NULL", [$label, $id]) > 0) {
        $fields['label'] = 'A category with this name already exists.';
    } else {
        $updates['label'] = $label;
    }
}
if (array_key_exists('enforce_minimum_billing_days', $body)) {
    $updates['enforce_minimum_billing_days'] = !empty($body['enforce_minimum_billing_days']) ? 1 : 0;
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
    db_update('equipment_categories', $updates, 'id = ?', [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_category',
        'entity_id'    => $id,
        'entity_label' => $existing['label'],
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id]);
