<?php
declare(strict_types=1);

/**
 * api/v1/equipment/templates/index.php
 *
 * Returns a paginated list of equipment templates. Supports filtering by
 * category, is_active flag, and full-text search on name. Includes a
 * unit_count showing how many non-deleted units use each template.
 *
 * @method  GET
 * @params  search   (optional) — search on name
 *          category (optional) — chassis|dry_van|reefer|container|flatbed|
 *                                step_deck|lowboy|tanker|dump|other
 *          active   (optional) — 1|0 (default: all)
 *          sort     (optional) — name|category|created_at  default: name ASC
 *          page     (optional, int ≥1, default 1)
 *          per_page (optional, int 1–100, default 25)
 * @auth    Session required; require_permission('equipment','view')
 * @returns json_paginated — templates with unit_count
 *
 * @depends api/bootstrap.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.3 Equipment Templates
 * @session S006
 */

// dirname(__DIR__, 4): api/v1/equipment/templates/ → equipment/ → v1/ → api/ → project root
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

// ── Input ──────────────────────────────────────────────────────
$search   = clean_string($_GET['search'] ?? null, 255);
$category = clean_string($_GET['category'] ?? null);
$active   = isset($_GET['active']) ? (int) $_GET['active'] : null;
$sort     = clean_string($_GET['sort'] ?? 'name');
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(1, clean_int($_GET['per_page'] ?? 25) ?? 25));

// ── Validate enum filters ──────────────────────────────────────
$validCategories = ['chassis','dry_van','reefer','container','flatbed',
                    'step_deck','lowboy','tanker','dump','other'];
if ($category !== null && !in_array($category, $validCategories, true)) {
    $category = null;
}

// ── Sort allowlist ─────────────────────────────────────────────
$sortMap = [
    'name'       => 't.name ASC',
    'category'   => 't.category ASC, t.name ASC',
    'created_at' => 't.created_at DESC',
];
$orderBy = $sortMap[$sort] ?? 't.name ASC';

// ── Build WHERE ────────────────────────────────────────────────
$conditions = ['t.deleted_at IS NULL'];
$params     = [];

if ($search !== null && $search !== '') {
    $conditions[] = 't.name LIKE ?';
    $params[]     = '%' . $search . '%';
}

if ($category !== null) {
    $conditions[] = 't.category = ?';
    $params[]     = $category;
}

// WHY: allow filtering to only active templates (e.g. for unit-creation dropdowns)
if ($active !== null) {
    $conditions[] = 't.is_active = ?';
    $params[]     = $active ? 1 : 0;
}

$whereSQL = 'WHERE ' . implode(' AND ', $conditions);

// ── Count ──────────────────────────────────────────────────────
$total = db_count("SELECT COUNT(*) FROM equipment_templates t {$whereSQL}", $params);

// ── Paginated query ────────────────────────────────────────────
$offset = ($page - 1) * $perPage;

// WHY LEFT JOIN: templates with zero units still appear — unit_count will be 0
$rows = db_select(
    "SELECT
        t.id,
        t.name,
        t.slug,
        t.category,
        t.brand,
        t.model,
        t.default_length_ft,
        t.default_daily_rate,
        t.default_weekly_rate,
        t.default_monthly_rate,
        t.default_mileage_rate,
        t.default_currency,
        t.default_mileage_unit,
        t.is_active,
        t.sort_order,
        t.created_at,
        t.updated_at,
        COUNT(u.id) AS unit_count
       FROM equipment_templates t
       LEFT JOIN equipment_units u
              ON u.template_id = t.id AND u.deleted_at IS NULL
      {$whereSQL}
      GROUP BY t.id
      ORDER BY {$orderBy}
      LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── Format response ────────────────────────────────────────────
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'                  => (int) $row['id'],
        'name'                => $row['name'],
        'slug'                => $row['slug'],
        'category'            => $row['category'],
        'brand'               => $row['brand'],
        'model'               => $row['model'],
        'default_length_ft'   => $row['default_length_ft'],
        'default_daily_rate'  => $row['default_daily_rate'],
        'default_weekly_rate' => $row['default_weekly_rate'],
        'default_monthly_rate'=> $row['default_monthly_rate'],
        'default_mileage_rate'=> $row['default_mileage_rate'],
        'default_currency'    => $row['default_currency'],
        'default_mileage_unit'=> $row['default_mileage_unit'],
        'is_active'           => (bool) $row['is_active'],
        'sort_order'          => (int) $row['sort_order'],
        'unit_count'          => (int) $row['unit_count'],
        'created_at'          => $row['created_at'],
        'updated_at'          => $row['updated_at'],
    ];
}

json_paginated($items, $total, $page, $perPage);
