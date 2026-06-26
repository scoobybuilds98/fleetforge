<?php
declare(strict_types=1);

/**
 * api/v1/equipment/categories/convert_to_subcategory.php
 *
 * S-EQTAX — one-click "demote" a top-level category into a SUB-category of
 * another category (e.g. make "Combo" a sub-type under "Chassis"). In one
 * transaction:
 *   1. create-or-reuse a sub-category under the target parent that KEEPS the
 *      source category's slug + label (so the `category` mirror, rate-card
 *      matching by equipment_type, and report grouping all stay byte-identical);
 *   2. re-point every equipment type that was in the source category to the
 *      parent category + the new sub-category (mirror = the preserved slug);
 *   3. soft-delete the now-empty source category.
 *
 * Billing semantics: rules live at the CATEGORY level, so the moved equipment
 * now follows the PARENT category's enforce_minimum_billing_days flag.
 *
 * Blocked when: source has active sub-categories (move/remove them first), or
 * source == parent.
 *
 * @method  POST
 * @body    { id (source category, required), parent_category_id (required) }
 * @auth    Session required; require_permission('equipment','edit')
 * @returns 200 { source_id, parent_category_id, subcategory_id, moved_templates }
 *          or 409 HAS_SUBCATEGORIES / 404 / 422
 *
 * @session S-EQTAX-9
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_taxonomy_helpers.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body     = json_body();
$id       = clean_int($body['id'] ?? null);
$parentId = clean_int($body['parent_category_id'] ?? null);

$fields = [];
if (!$id)       { $fields['id'] = 'Source category is required.'; }
if (!$parentId) { $fields['parent_category_id'] = 'Choose a parent category.'; }
if ($id && $parentId && $id === $parentId) {
    $fields['parent_category_id'] = 'A category cannot become a sub-category of itself.';
}
if ($fields) {
    json_validation_error($fields);
}

$src = db_row("SELECT id, slug, label FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$src) {
    json_error('NOT_FOUND', 'Source category not found.', 404);
}
$parent = db_row("SELECT id, slug, label FROM equipment_categories WHERE id = ? AND deleted_at IS NULL", [$parentId]);
if (!$parent) {
    json_validation_error(['parent_category_id' => 'Parent category not found.']);
}

// Block when the source still has active sub-categories — those would be
// orphaned. The operator must move/retire them first.
$srcSubs = db_count("SELECT COUNT(*) FROM equipment_subcategories WHERE category_id = ? AND deleted_at IS NULL", [$id]);
if ($srcSubs > 0) {
    json_error('HAS_SUBCATEGORIES',
        'Cannot convert "' . $src['label'] . '" — it still has ' . $srcSubs . ' sub-categor'
        . ($srcSubs === 1 ? 'y' : 'ies') . '. Move or remove those first.', 409,
        ['subcategory_count' => $srcSubs]);
}

$userId = current_user_id();
$result = ['source_id' => $id, 'parent_category_id' => $parentId, 'subcategory_id' => null, 'moved_templates' => 0];

db_transaction(function () use ($id, $parentId, $src, $parent, $userId, &$result): void {
    // 1. Create-or-reuse a sub-category under the parent keeping the source slug.
    //    (Reuse covers a re-run or a previously soft-deleted sub of the same slug.)
    $existing = db_row(
        "SELECT id FROM equipment_subcategories WHERE category_id = ? AND slug = ? LIMIT 1",
        [$parentId, $src['slug']]
    );
    if ($existing) {
        $subId = (int) $existing['id'];
        db_execute("UPDATE equipment_subcategories SET deleted_at = NULL, is_active = 1, label = ? WHERE id = ?", [$src['label'], $subId]);
    } else {
        $subId = db_insert('equipment_subcategories', [
            'category_id' => $parentId,
            'slug'        => $src['slug'],   // preserve the slug (mirror/rate-card stability)
            'label'       => $src['label'],
            'created_by'  => $userId,
        ]);
    }
    $result['subcategory_id'] = $subId;

    // 2. Re-point templates that were classified under the source category —
    //    both backfilled (category_id = src) and any unbackfilled-by-mirror rows
    //    (category_id NULL but mirror = src.slug), so the gate keeps resolving
    //    after the source category is soft-deleted. Mirror = the preserved slug.
    db_execute(
        "UPDATE equipment_templates SET category_id = ?, subcategory_id = ?, category = ?
          WHERE deleted_at IS NULL AND (category_id = ? OR (category_id IS NULL AND category = ?))",
        [$parentId, $subId, $src['slug'], $id, $src['slug']]
    );
    $result['moved_templates'] = db_count(
        "SELECT COUNT(*) FROM equipment_templates WHERE deleted_at IS NULL AND category_id = ? AND subcategory_id = ?",
        [$parentId, $subId]
    );

    // 3. Soft-delete the now-empty source category.
    db_execute("UPDATE equipment_categories SET deleted_at = NOW(), is_active = 0 WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_category',
        'entity_id'    => $id,
        'entity_label' => $src['label'],
        'new_values'   => json_encode([
            'converted_to_subcategory_of' => $parent['slug'],
            'subcategory_id'              => $subId,
        ]),
        'notes'        => "S-EQTAX: converted category '{$src['slug']}' into a sub-category under '{$parent['slug']}'.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success($result);
