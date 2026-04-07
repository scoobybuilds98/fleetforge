<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bills/index.php
 *
 * List AP bills with pagination, filtering, and sorting.
 * Joins vendor name for display. Supports status/vendor/date filters.
 *
 * @method  GET
 * @query   page?, per_page?, status?, vendor_id?, date_from?, date_to?, q?, sort?, dir?
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 paginated bill rows
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (Accounts Payable)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

// Allowlisted sort columns
$allowedSorts = ['bill_number', 'bill_date', 'due_date', 'total_amount', 'balance_due', 'status', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$where = ['1=1'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'b.status = ?';
    $params[] = $status;
}
if ($vendorId = clean_int($_GET['vendor_id'] ?? null)) {
    $where[] = 'b.vendor_id = ?';
    $params[] = $vendorId;
}
if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[] = 'b.bill_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[] = 'b.bill_date <= ?';
    $params[] = $dateTo;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[] = "(b.bill_number LIKE ? OR b.vendor_bill_number LIKE ? OR v.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_bills b
     JOIN vendors v ON v.id = b.vendor_id AND v.deleted_at IS NULL
     WHERE {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT b.id, b.bill_number, b.vendor_id, v.name AS vendor_name,
            b.vendor_bill_number, b.bill_date, b.due_date, b.status,
            b.subtotal, b.tax_total, b.total_amount, b.amount_paid,
            b.balance_due, b.currency, b.work_order_id, b.equipment_unit_id,
            b.created_at, b.updated_at
     FROM acc_bills b
     JOIN vendors v ON v.id = b.vendor_id AND v.deleted_at IS NULL
     WHERE {$whereSQL}
     ORDER BY b.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
