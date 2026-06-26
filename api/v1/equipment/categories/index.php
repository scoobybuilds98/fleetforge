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

$subCounts = [];
foreach (db_select(
    "SELECT subcategory_id, COUNT(*) n FROM equipment_templates
      WHERE deleted_at IS NULL AND subcategory_id IS NOT NULL GROUP BY subcategory_id"
) as $r) { $subCounts[(int) $r['subcategory_id']] = (int) $r['n']; }

$subsByCat = [];
foreach (db_select(
    "SELECT id, category_id, slug, label, is_active, sort_order
       FROM equipment_subcategories WHERE deleted_at IS NULL
      ORDER BY sort_order, label"
) as $s) {
    $cid = (int) $s['category_id'];
    $subsByCat[$cid][] = [
        'id'             => (int) $s['id'],
        'slug'           => $s['slug'],
        'label'          => $s['label'],
        'is_active'      => (int) $s['is_active'],
        'sort_order'     => (int) $s['sort_order'],
        'template_count' => $subCounts[(int) $s['id']] ?? 0,
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
        'subcategories'                => $subsByCat[$cid] ?? [],
    ];
}

json_success(['categories' => $categories]);
