<?php
declare(strict_types=1);

/**
 * api/v1/payments/index.php
 *
 * Paginated payments list with filters for customer_id, invoice_id, and status.
 * Snapshot columns on payments reduce joins — customer name from payments.customer_id
 * resolved via JOIN since there is no name snapshot on the payments table itself.
 *
 * @method  GET
 * @query   customer_id, invoice_id, status, q (search reference_number), sort, dir, page, per_page
 * @auth    Session required; require_permission('payments','view')
 * @returns 200 paginated list
 *
 * Decisions: D5/D13 (soft-delete — payments NEVER hard-deleted), D12 (permission matrix)
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('payments', 'view');

// --- Allowlisted sort columns (never trust user input for column names) ---
// company_name lives on the JOIN alias c, so it needs an explicit expression rather
// than the bare p.{$sort} interpolation used for the rest.
$allowedSorts = ['created_at', 'payment_date', 'amount', 'status', 'payment_number', 'payment_method', 'company_name', 'updated_at'];
$sortMap = ['company_name' => 'c.company_name'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'payment_date';
$sortExpr = $sortMap[$sort] ?? "p.{$sort}";
$dir  = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

// --- Build WHERE from allowlisted filters ---
$where  = ['p.deleted_at IS NULL'];
$params = [];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}

if ($customerId = clean_int($_GET['customer_id'] ?? null)) {
    $where[]  = 'p.customer_id = ?';
    $params[] = $customerId;
}

// Filter by invoice_id — joins through payment_allocations
if ($invoiceId = clean_int($_GET['invoice_id'] ?? null)) {
    // Semi-join: only payments that have an allocation for this invoice
    $where[]  = 'EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.payment_id = p.id AND pa.invoice_id = ?)';
    $params[] = $invoiceId;
}

// Search on reference_number (no FULLTEXT — reference numbers are short alphanumeric)
if ($search = clean_string($_GET['q'] ?? null)) {
    $where[]  = '(p.reference_number LIKE ? OR p.payment_number LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

// customer_filter — contextual sub-filter shown when sorting by customer name.
// c alias comes from LEFT JOIN customers c in the query below.
if ($customerFilter = clean_string($_GET['customer_filter'] ?? null)) {
    $like    = '%' . $customerFilter . '%';
    $where[] = 'c.company_name LIKE ?';
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);
$page     = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage  = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset   = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM payments p WHERE {$whereSQL}", $params);

$rows = db_select(
    "SELECT
        p.id,
        p.payment_number,
        p.customer_id,
        c.company_name,
        p.amount,
        p.currency,
        p.payment_method,
        p.reference_number,
        p.payment_date,
        p.status,
        p.overpayment_amount,
        p.recorded_by,
        p.created_at,
        p.updated_at
     FROM payments p
     LEFT JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
     WHERE {$whereSQL}
     ORDER BY {$sortExpr} {$dir}
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
