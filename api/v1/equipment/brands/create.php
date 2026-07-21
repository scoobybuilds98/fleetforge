<?php
declare(strict_types=1);

/**
 * api/v1/equipment/brands/create.php
 *
 * S-UNIT-BRAND — add an equipment brand (manufacturer). The operator asked for
 * "a provision to add more brands", so this backs both the manage screen and
 * the inline "+ Add brand" affordance on the unit form.
 *
 * The slug is derived from the label and is IMMUTABLE thereafter; only the
 * label is editable, so renaming a brand never breaks a stored reference
 * (units reference brand_id, not the slug, but the slug stays stable anyway).
 *
 * @method  POST
 * @body    { label (required), sort_order (int, optional) }
 * @auth    Session required; require_permission('equipment','create')
 * @returns 201 { id, slug, label }
 *
 * @depends api/bootstrap.php
 * @session S-UNIT-BRAND
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'create');

/**
 * Derive a unique brand slug. Hyphenated to match the seeded rows
 * ('great-dane', 'trail-king'), which is a different convention from the
 * underscore-based taxonomy slugs — brands are their own namespace.
 *
 * WHY the collision check counts ALL rows, soft-deleted included: the UNIQUE
 * index uq_eqbrand_slug spans tombstones, so a `deleted_at IS NULL` check
 * would miss a retired holder and the INSERT would then 1062 with a 500
 * instead of a clean validation error (the documented soft-delete blind spot).
 */
function eqbrand_unique_slug(string $label): string
{
    $base = strtolower(trim($label));
    $base = str_replace('&', ' and ', $base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '') { $base = 'brand'; }
    $base = substr($base, 0, 50);

    $slug = $base;
    $i    = 2;
    while (db_count("SELECT COUNT(*) FROM equipment_brands WHERE slug = ?", [$slug]) > 0) {
        $slug = substr($base, 0, 46) . '-' . $i;
        $i++;
    }
    return $slug;
}

$body   = json_body();
$fields = [];

$label = clean_string($body['label'] ?? null, 100);
if (!$label) {
    $fields['label'] = 'Brand name is required.';
}

$sortOrder = 0;
if (isset($body['sort_order']) && $body['sort_order'] !== '' && $body['sort_order'] !== null) {
    $i = clean_int($body['sort_order']);
    if ($i === null || $i < 0) {
        $fields['sort_order'] = 'Sort order cannot be negative.';
    } else {
        $sortOrder = $i;
    }
}

// Duplicate-label guard among LIVE brands (case-insensitive). A retired brand
// with the same name is allowed to be re-created — the slug helper suffixes.
if ($label && db_count(
    "SELECT COUNT(*) FROM equipment_brands WHERE LOWER(label) = LOWER(?) AND deleted_at IS NULL",
    [$label]
) > 0) {
    $fields['label'] = 'A brand with this name already exists.';
}

if ($fields) {
    json_validation_error($fields);
}

// New brands sort after the seeded list unless the operator specifies.
if ($sortOrder === 0) {
    $sortOrder = (int) (db_row("SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM equipment_brands")['n'] ?? 10);
}

$slug   = eqbrand_unique_slug($label);
$userId = current_user_id();
$newId  = null;

try {
    db_transaction(function () use (&$newId, $label, $slug, $sortOrder, $userId): void {
        $newId = db_insert('equipment_brands', [
            'slug'       => $slug,
            'label'      => $label,
            'sort_order' => $sortOrder,
            'created_by' => $userId,
        ]);
        db_insert('audit_log', [
            'user_id'      => $userId,
            'user_name'    => current_user()['name'] ?? 'system',
            'action'       => 'create',
            'module'       => 'equipment',
            'entity_type'  => 'equipment_brand',
            'entity_id'    => $newId,
            'entity_label' => $label,
            'new_values'   => json_encode(['slug' => $slug, 'label' => $label, 'sort_order' => $sortOrder]),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    });
} catch (\PDOException $e) {
    // Belt-and-suspenders for a slug race on uq_eqbrand_slug.
    if ($e->getCode() === '23000' && stripos($e->getMessage(), 'slug') !== false) {
        json_validation_error(['label' => 'That name collides with an existing brand slug. Try a slightly different name.']);
    }
    throw $e;
}

json_success(['id' => $newId, 'slug' => $slug, 'label' => $label], 201);
