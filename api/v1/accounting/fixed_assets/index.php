<?php declare(strict_types=1);

/**
 * api/v1/accounting/fixed_assets/index.php
 *
 * Paginated fixed asset listing with filters for status, asset_class,
 * equipment_unit_id, and free-text search across name and asset_number.
 *
 * @method  GET
 * @query   status, asset_class, equipment_unit_id, search, sort, dir, page, per_page
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 paginated list via json_paginated()
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

// Allowlisted sort columns
$allowedSorts = ['asset_number', 'name', 'acquisition_date', 'net_book_value', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : 'asset_number';
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$where  = ['1=1'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}

if ($class = clean_string($_GET['asset_class'] ?? null)) {
    $where[]  = 'a.asset_class = ?';
    $params[] = $class;
}

if ($unitId = clean_int($_GET['equipment_unit_id'] ?? null)) {
    $where[]  = 'a.equipment_unit_id = ?';
    $params[] = $unitId;
}

if ($search = clean_string($_GET['search'] ?? null)) {
    $where[]  = '(a.name LIKE ? OR a.asset_number LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset   = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_fixed_assets a {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT
        a.id,
        a.asset_number,
        a.name,
        a.asset_class,
        a.depreciation_method,
        a.acquisition_date,
        a.acquisition_cost,
        a.salvage_value,
        a.depreciable_cost,
        a.accumulated_depreciation,
        a.net_book_value,
        a.status,
        a.equipment_unit_id,
        eu.unit_number AS equipment_unit_number,
        a.location,
        a.serial_number,
        a.last_depreciation_date,
        a.created_at,
        a.updated_at
     FROM acc_fixed_assets a
     LEFT JOIN equipment_units eu ON eu.id = a.equipment_unit_id
     {$whereSQL}
     ORDER BY a.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
