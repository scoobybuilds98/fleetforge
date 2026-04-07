<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/promise_to_pay/index.php
 *
 * List promise-to-pay records for a customer.
 *
 * @method  GET
 * @query   customer_id (required), status?, page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated promises
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Collections — Promise to pay)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$where = ['p.customer_id = ?'];
$params = [$customerId];

$status = clean_string($_GET['status'] ?? null);
if ($status && in_array($status, ['pending', 'kept', 'broken', 'cancelled'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_promise_to_pay p WHERE {$whereSQL}", $params);
$rows = db_select(
    "SELECT p.*, u.name AS created_by_name,
            i.invoice_number
     FROM acc_promise_to_pay p
     LEFT JOIN users u ON u.id = p.created_by
     LEFT JOIN invoices i ON i.id = p.invoice_id
     WHERE {$whereSQL}
     ORDER BY p.promise_date DESC, p.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
