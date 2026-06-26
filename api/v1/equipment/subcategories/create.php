<?php
declare(strict_types=1);

/**
 * api/v1/equipment/subcategories/create.php
 *
 * S-EQTAX — create a sub-category under a category. Slug derived from the label
 * and made globally unique (immutable thereafter).
 *
 * @method  POST
 * @body    { category_id (required), label (required), sort_order (int) }
 * @auth    Session required; require_permission('equipment','create')
 * @returns 201 { id, category_id, slug, label }
 *
 * @session S-EQTAX-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_taxonomy_helpers.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'create');

$body   = json_body();
$fields = [];

$categoryId = clean_int($body['category_id'] ?? null);
$label      = clean_string($body['label'] ?? null, 100);

if (!$categoryId) {
    $fields['category_id'] = 'A parent category is required.';
} elseif (!db_row("SELECT id FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$categoryId])) {
    $fields['category_id'] = 'Parent category not found.';
}
if (!$label) {
    $fields['label'] = 'Sub-category name is required.';
}
$sortOrder = 0;
if (isset($body['sort_order']) && $body['sort_order'] !== '' && $body['sort_order'] !== null) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) { $fields['sort_order'] = 'Sort order cannot be negative.'; } else { $sortOrder = $i; }
}

// Duplicate label within the same category (among live rows).
if ($categoryId && $label
    && db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE category_id = ? AND LOWER(label) = LOWER(?) AND deleted_at IS NULL", [$categoryId, $label]) > 0) {
    $fields['label'] = 'A sub-category with this name already exists in this category.';
}

if ($fields) {
    json_validation_error($fields);
}

$slug   = eqtax_unique_slug($label);
$userId = current_user_id();
$newId  = null;

try {
    db_transaction(function () use (&$newId, $categoryId, $label, $slug, $sortOrder, $userId): void {
        $newId = db_insert('equipment_subcategories', [
            'category_id' => $categoryId,
            'slug'        => $slug,
            'label'       => $label,
            'sort_order'  => $sortOrder,
            'created_by'  => $userId,
        ]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'create',
            'module'       => 'equipment',
            'entity_type'  => 'equipment_subcategory',
            'entity_id'    => $newId,
            'entity_label' => $label,
            'new_values'   => json_encode(['category_id' => $categoryId, 'slug' => $slug, 'label' => $label]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    });
} catch (\PDOException $e) {
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_validation_error(['label' => 'That name collides with an existing type slug. Try a slightly different name.']);
    }
    throw $e;
}

json_success(['id' => $newId, 'category_id' => $categoryId, 'slug' => $slug, 'label' => $label], 201);
