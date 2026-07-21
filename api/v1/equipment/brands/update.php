<?php
declare(strict_types=1);

/**
 * api/v1/equipment/brands/update.php
 *
 * S-UNIT-BRAND — rename / reorder / (de)activate an equipment brand.
 *
 * The slug is deliberately NOT editable: it is the stable identifier. Only
 * `label`, `sort_order` and `is_active` change. Deactivating drops the brand
 * out of the unit picker while leaving it intact on the units that already
 * reference it — that is the intended way to retire a manufacturer you no
 * longer buy from without rewriting fleet history.
 *
 * @method  POST
 * @body    { id (required), label, sort_order, is_active }
 * @auth    Session required; require_permission('equipment','edit')
 * @returns 200 { id, label, is_active, sort_order }
 *
 * @depends api/bootstrap.php
 * @session S-UNIT-BRAND
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Brand ID is required.']);
}

$brand = db_row("SELECT id, slug, label, is_active, sort_order FROM equipment_brands WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$brand) {
    json_error('NOT_FOUND', 'Brand not found.', 404);
}

$fields  = [];
$updates = [];

if (array_key_exists('label', $body)) {
    $label = clean_string($body['label'], 100);
    if (!$label) {
        $fields['label'] = 'Brand name is required.';
    } elseif (db_count(
        "SELECT COUNT(*) FROM equipment_brands WHERE LOWER(label) = LOWER(?) AND id <> ? AND deleted_at IS NULL",
        [$label, $id]
    ) > 0) {
        $fields['label'] = 'Another brand already uses this name.';
    } else {
        $updates['label'] = $label;
    }
}

if (array_key_exists('sort_order', $body)) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) {
        $fields['sort_order'] = 'Sort order cannot be negative.';
    } else {
        $updates['sort_order'] = $i;
    }
}

if (array_key_exists('is_active', $body)) {
    $updates['is_active'] = !empty($body['is_active']) ? 1 : 0;
}

if ($fields) {
    json_validation_error($fields);
}
if (!$updates) {
    json_success(['id' => $id, 'label' => $brand['label'],
                  'is_active' => (int) $brand['is_active'], 'sort_order' => (int) $brand['sort_order']]);
}

$userId = current_user_id();
db_transaction(function () use ($id, $updates, $userId, $brand): void {
    db_update('equipment_brands', $updates, 'id = ?', [$id]);
    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_brand',
        'entity_id'    => $id,
        'entity_label' => $updates['label'] ?? $brand['label'],
        'old_values'   => json_encode(['label' => $brand['label'], 'is_active' => (int) $brand['is_active'], 'sort_order' => (int) $brand['sort_order']]),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

$fresh = db_row("SELECT id, label, is_active, sort_order FROM equipment_brands WHERE id = ?", [$id]);
json_success([
    'id'         => (int) $fresh['id'],
    'label'      => $fresh['label'],
    'is_active'  => (int) $fresh['is_active'],
    'sort_order' => (int) $fresh['sort_order'],
]);
