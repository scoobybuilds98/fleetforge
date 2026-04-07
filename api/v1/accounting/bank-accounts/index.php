<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-accounts/index.php
 *
 * List bank accounts with pagination, filtering, and sorting.
 * Returns GL account name alongside bank account data.
 *
 * @method  GET
 * @query   page?, per_page?, currency?, is_active?, sort?, dir?
 * @auth    Session required; require_permission('bank_accounts','view')
 * @returns 200 paginated bank account rows
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §7 (Bank & Cash Management)
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('bank_accounts', 'view');

$allowedSorts = ['name', 'institution', 'currency', 'account_type', 'is_active', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'name';
$dir = strtoupper($_GET['dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

$where = ['1=1'];
$params = [];

if ($currency = clean_string($_GET['currency'] ?? null)) {
    $where[] = 'ba.currency = ?';
    $params[] = $currency;
}

$isActive = $_GET['is_active'] ?? null;
if ($isActive !== null && $isActive !== '') {
    $where[] = 'ba.is_active = ?';
    $params[] = (int) $isActive;
}

$whereSQL = implode(' AND ', $where);

$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_bank_accounts ba WHERE {$whereSQL}", $params);

$rows = db_select(
    "SELECT ba.*, a.code AS gl_account_code, a.name AS gl_account_name
     FROM acc_bank_accounts ba
     LEFT JOIN acc_accounts a ON a.id = ba.gl_account_id
     WHERE {$whereSQL}
     ORDER BY ba.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
