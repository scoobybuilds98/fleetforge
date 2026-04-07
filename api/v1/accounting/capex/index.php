<?php declare(strict_types=1);

/**
 * api/v1/accounting/capex/index.php
 *
 * Paginated CapEx request listing with status / asset_class filters
 * and free-text search across request_number and title.
 *
 * @method  GET
 * @query   status, asset_class, search, page, per_page
 * @auth    Session required; require_permission('fixed_assets','view')
 * @returns 200 paginated list
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('fixed_assets', 'view');

$where  = ['1=1'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}

if ($class = clean_string($_GET['asset_class'] ?? null)) {
    $where[]  = 'c.asset_class = ?';
    $params[] = $class;
}

if ($search = clean_string($_GET['search'] ?? null)) {
    $where[]  = '(c.request_number LIKE ? OR c.title LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset   = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_capex_requests c {$whereSQL}", $params);

$rows = db_select(
    "SELECT c.*,
            a.asset_number AS linked_asset_number, a.name AS linked_asset_name,
            (c.budget_amount - COALESCE(c.actual_amount, 0)) AS variance
     FROM acc_capex_requests c
     LEFT JOIN acc_fixed_assets a ON a.id = c.asset_id
     {$whereSQL}
     ORDER BY c.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
