<?php
declare(strict_types=1);

/**
 * api/v1/equipment/categories/index.php
 *
 * S-EQTAX — list the equipment taxonomy for the manage screen: every active or
 * inactive (not soft-deleted) category with its template usage count and its
 * sub-categories (each with its own usage count). Read-only.
 *
 * @method  GET
 * @auth    Session required; require_permission('equipment','view')
 * @returns 200 { categories: [ { id, slug, label, enforce_minimum_billing_days,
 *               is_active, sort_order, template_count,
 *               subcategories: [ { id, slug, label, is_active, sort_order, template_count } ] } ] }
 *
 * @session S-EQTAX-7
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

// Template usage counts (active templates) per category and per sub-category.
$catCounts = [];
foreach (db_select(
    "SELECT category_id, COUNT(*) n FROM equipment_templates
      WHERE deleted_at IS NULL AND category_id IS NOT NULL GROUP BY category_id"
) as $r) { $catCounts[(int) $r['category_id']] = (int) $r['n']; }

// S-EQTAX (two-level): the equipment types (templates) filed under each category,
// so the manage screen shows the full Category → Equipment Type structure.
$typesByCat = [];
foreach (db_select(
    "SELECT id, category_id, name, is_active
       FROM equipment_templates
      WHERE deleted_at IS NULL AND category_id IS NOT NULL
      ORDER BY name"
) as $t) {
    $cid = (int) $t['category_id'];
    $typesByCat[$cid][] = [
        'id'        => (int) $t['id'],
        'name'      => $t['name'],
        'is_active' => (int) $t['is_active'],
    ];
}

$categories = [];
foreach (db_select(
    "SELECT id, slug, label, enforce_minimum_billing_days, is_active, sort_order
       FROM equipment_categories WHERE deleted_at IS NULL
      ORDER BY sort_order, label"
) as $c) {
    $cid = (int) $c['id'];
    $categories[] = [
        'id'                           => $cid,
        'slug'                         => $c['slug'],
        'label'                        => $c['label'],
        'enforce_minimum_billing_days' => (int) $c['enforce_minimum_billing_days'],
        'is_active'                    => (int) $c['is_active'],
        'sort_order'                   => (int) $c['sort_order'],
        'template_count'               => $catCounts[$cid] ?? 0,
        'equipment_types'              => $typesByCat[$cid] ?? [],
    ];
}

json_success(['categories' => $categories]);
