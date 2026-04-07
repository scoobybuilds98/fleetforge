<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transactions/index.php
 *
 * List bank transactions with pagination, filtering by account/status/date/type.
 *
 * @method  GET
 * @query   bank_account_id (required), page?, per_page?, status?, type?,
 *          date_from?, date_to?, sort?, dir?, q?
 * @auth    Session required; require_permission('bank_accounts','view')
 * @returns 200 paginated bank transaction rows
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('bank_accounts', 'view');

$bankAccountId = clean_int($_GET['bank_account_id'] ?? null);
if (!$bankAccountId) json_error('VALIDATION_ERROR', 'bank_account_id is required.', 422);

$allowedSorts = ['transaction_date', 'amount', 'description', 'status', 'transaction_type', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'transaction_date';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$where = ['bt.bank_account_id = ?'];
$params = [$bankAccountId];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'bt.status = ?';
    $params[] = $status;
}
if ($type = clean_string($_GET['type'] ?? null)) {
    $where[] = 'bt.transaction_type = ?';
    $params[] = $type;
}
if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[] = 'bt.transaction_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[] = 'bt.transaction_date <= ?';
    $params[] = $dateTo;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[] = '(bt.description LIKE ? OR bt.reference LIKE ?)';
    $searchLike = "%{$search}%";
    $params[] = $searchLike;
    $params[] = $searchLike;
}

// Filter for reconciliation view
if ($reconId = clean_int($_GET['reconciliation_id'] ?? null)) {
    $where[] = '(bt.reconciliation_id = ? OR bt.reconciliation_id IS NULL)';
    $params[] = $reconId;
}

// Uncleared only filter
if (isset($_GET['uncleared']) && $_GET['uncleared'] === '1') {
    $where[] = 'bt.is_cleared = 0';
}

$whereSQL = implode(' AND ', $where);

$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 50) ?? 50));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_bank_transactions bt WHERE {$whereSQL}", $params);

$rows = db_select(
    "SELECT bt.*,
            u.name AS matched_by_name
     FROM acc_bank_transactions bt
     LEFT JOIN users u ON u.id = bt.matched_by
     WHERE {$whereSQL}
     ORDER BY bt.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
