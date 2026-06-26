<?php
declare(strict_types=1);

/**
 * api/v1/equipment/categories/create.php
 *
 * S-EQTAX — create a top-level equipment category. The slug is derived from the
 * label and made globally unique (immutable thereafter). Billing rules attach
 * here via enforce_minimum_billing_days.
 *
 * @method  POST
 * @body    { label (required), enforce_minimum_billing_days (bool), sort_order (int) }
 * @auth    Session required; require_permission('equipment','create')
 * @returns 201 { id, slug, label }
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

$label = clean_string($body['label'] ?? null, 100);
if (!$label) {
    $fields['label'] = 'Category name is required.';
}
$enforce   = !empty($body['enforce_minimum_billing_days']) ? 1 : 0;
$sortOrder = 0;
if (isset($body['sort_order']) && $body['sort_order'] !== '' && $body['sort_order'] !== null) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) { $fields['sort_order'] = 'Sort order cannot be negative.'; } else { $sortOrder = $i; }
}

// Duplicate label guard (case-insensitive, among live categories).
if ($label && db_count("SELECT COUNT(*) FROM equipment_categories WHERE LOWER(label) = LOWER(?) AND deleted_at IS NULL", [$label]) > 0) {
    $fields['label'] = 'A category with this name already exists.';
}

if ($fields) {
    json_validation_error($fields);
}

$slug   = eqtax_unique_slug($label);
$userId = current_user_id();
$newId  = null;

try {
    db_transaction(function () use (&$newId, $label, $slug, $enforce, $sortOrder, $userId): void {
        $newId = db_insert('equipment_categories', [
            'slug'                         => $slug,
            'label'                        => $label,
            'enforce_minimum_billing_days' => $enforce,
            'sort_order'                   => $sortOrder,
            'created_by'                   => $userId,
        ]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'create',
            'module'       => 'equipment',
            'entity_type'  => 'equipment_category',
            'entity_id'    => $newId,
            'entity_label' => $label,
            'new_values'   => json_encode(['slug' => $slug, 'label' => $label, 'enforce_minimum_billing_days' => $enforce]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    });
} catch (\PDOException $e) {
    // Belt-and-suspenders for a slug race on uq_eqcat_slug.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_validation_error(['label' => 'That name collides with an existing category slug. Try a slightly different name.']);
    }
    throw $e;
}

json_success(['id' => $newId, 'slug' => $slug, 'label' => $label], 201);
