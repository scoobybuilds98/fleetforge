<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/deposits/index.php
 *
 * List customer deposits with optional filters.
 *
 * @method  GET
 * @query   customer_id?, status?, page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated deposits
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §2.10 (Customer deposits)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$where = ['1=1'];
$params = [];

$customerId = clean_int($_GET['customer_id'] ?? null);
if ($customerId) {
    $where[] = 'd.customer_id = ?';
    $params[] = $customerId;
}

$status = clean_string($_GET['status'] ?? null);
if ($status && in_array($status, ['held', 'applied', 'refunded', 'forfeited'], true)) {
    $where[] = 'd.status = ?';
    $params[] = $status;
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_customer_deposits d WHERE {$whereSQL}", $params);
$rows = db_select(
    "SELECT d.*, c.company_name,
            l.contract_number AS lease_number,
            inv.invoice_number AS applied_to_invoice_number
     FROM acc_customer_deposits d
     LEFT JOIN customers c ON c.id = d.customer_id
     LEFT JOIN leases l ON l.id = d.lease_id
     LEFT JOIN invoices inv ON inv.id = d.applied_to_invoice_id
     WHERE {$whereSQL}
     ORDER BY d.received_date DESC, d.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
