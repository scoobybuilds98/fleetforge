<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/collection_notes/index.php
 *
 * List collection notes for a customer, with optional invoice filter.
 *
 * @method  GET
 * @query   customer_id (required), invoice_id?, page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated collection notes
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Collections workflow)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$where = ['cn.customer_id = ?'];
$params = [$customerId];

$invoiceId = clean_int($_GET['invoice_id'] ?? null);
if ($invoiceId) {
    $where[] = 'cn.invoice_id = ?';
    $params[] = $invoiceId;
}

$whereSQL = implode(' AND ', $where);
$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_collection_notes cn WHERE {$whereSQL}", $params);
$rows = db_select(
    "SELECT cn.*, u.name AS created_by_name,
            i.invoice_number
     FROM acc_collection_notes cn
     LEFT JOIN users u ON u.id = cn.created_by
     LEFT JOIN invoices i ON i.id = cn.invoice_id
     WHERE {$whereSQL}
     ORDER BY cn.note_date DESC, cn.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
