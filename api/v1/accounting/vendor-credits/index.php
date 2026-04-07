<?php
declare(strict_types=1);

/**
 * api/v1/accounting/vendor-credits/index.php
 *
 * List vendor credits with pagination, filtering, and sorting.
 *
 * @method  GET
 * @query   page?, per_page?, vendor_id?, status?, q?, sort?, dir?
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 paginated vendor credit rows
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

$allowedSorts = ['credit_number', 'credit_date', 'amount', 'amount_remaining', 'status', 'created_at'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'created_at';
$dir = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

$where = ['1=1'];
$params = [];

if ($vendorId = clean_int($_GET['vendor_id'] ?? null)) {
    $where[] = 'vc.vendor_id = ?';
    $params[] = $vendorId;
}
if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'vc.status = ?';
    $params[] = $status;
}
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[] = "(vc.credit_number LIKE ? OR vc.reason LIKE ? OR v.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_vendor_credits vc
     JOIN vendors v ON v.id = vc.vendor_id AND v.deleted_at IS NULL
     WHERE {$whereSQL}",
    $params
);

$rows = db_select(
    "SELECT vc.id, vc.credit_number, vc.vendor_id, v.name AS vendor_name,
            vc.credit_date, vc.reason, vc.amount, vc.amount_remaining,
            vc.currency, vc.status, vc.source_bill_id,
            vc.created_at
     FROM acc_vendor_credits vc
     JOIN vendors v ON v.id = vc.vendor_id AND v.deleted_at IS NULL
     WHERE {$whereSQL}
     ORDER BY vc.{$sort} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
