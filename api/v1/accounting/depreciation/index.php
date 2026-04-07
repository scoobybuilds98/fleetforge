<?php declare(strict_types=1);

/**
 * api/v1/accounting/depreciation/index.php
 *
 * Paginated listing of depreciation runs (preview + posted).
 *
 * @method  GET
 * @query   status, period_id, page, per_page
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
    $where[]  = 'r.status = ?';
    $params[] = $status;
}

if ($periodId = clean_int($_GET['period_id'] ?? null)) {
    $where[]  = 'r.period_id = ?';
    $params[] = $periodId;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset   = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_depreciation_runs r {$whereSQL}", $params);

$rows = db_select(
    "SELECT
        r.id, r.period_id, p.name AS period_name,
        r.status, r.asset_count, r.total_depreciation,
        r.journal_entry_id, r.notes, r.run_by, r.run_date, r.created_at
     FROM acc_depreciation_runs r
     LEFT JOIN acc_periods p ON p.id = r.period_id
     {$whereSQL}
     ORDER BY r.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
