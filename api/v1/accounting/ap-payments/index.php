<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ap-payments/index.php
 *
 * List AP payments with pagination, filtering, and sorting.
 * Joins vendor name and bank account info.
 *
 * @method  GET
 * @query   page?, per_page?, vendor_id?, status?, date_from?, date_to?, q?, sort?, dir?
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 paginated payment rows
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

$allowedSorts = ['payment_number', 'payment_date', 'amount', 'status', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$where = ['1=1'];
$params = [];

if ($vendorId = clean_int($_GET['vendor_id'] ?? null)) {
    $where[] = 'ap.vendor_id = ?';
    $params[] = $vendorId;
}
if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'ap.status = ?';
    $params[] = $status;
}
if ($dateFrom = clean_date($_GET['date_from'] ?? null)) {
    $where[] = 'ap.payment_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo = clean_date($_GET['date_to'] ?? null)) {
    $where[] = 'ap.payment_date <= ?';
    $params[] = $dateTo;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[] = "(ap.payment_number LIKE ? OR ap.reference_number LIKE ? OR ap.check_number LIKE ? OR v.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_ap_payments ap
     JOIN vendors v ON v.id = ap.vendor_id AND v.deleted_at IS NULL
     WHERE {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT ap.id, ap.payment_number, ap.vendor_id, v.name AS vendor_name,
            ap.payment_date, ap.payment_method, ap.reference_number,
            ap.check_number, ap.amount, ap.currency, ap.status,
            ba.name AS bank_account_name,
            ap.created_at
     FROM acc_ap_payments ap
     JOIN vendors v ON v.id = ap.vendor_id AND v.deleted_at IS NULL
     JOIN acc_bank_accounts ba ON ba.id = ap.bank_account_id
     WHERE {$whereSQL}
     ORDER BY ap.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
