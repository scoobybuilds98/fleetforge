<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/bad_debt_writeoffs/index.php
 *
 * List bad debt write-off records for a customer, with recovery info.
 *
 * @method  GET
 * @query   customer_id (required), per_page?, page?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 paginated write-off records joined with invoice numbers
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Bad debt write-off)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$customerId = clean_int($_GET['customer_id'] ?? null);
if (!$customerId) json_error('VALIDATION_ERROR', 'customer_id is required.', 422);

$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset  = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM acc_bad_debt_writeoffs WHERE customer_id = ?",
    [$customerId]
);

$rows = db_select(
    "SELECT bw.*, i.invoice_number, u.name AS created_by_name
     FROM acc_bad_debt_writeoffs bw
     LEFT JOIN invoices i ON i.id = bw.invoice_id
     LEFT JOIN users u ON u.id = bw.created_by
     WHERE bw.customer_id = ?
     ORDER BY bw.writeoff_date DESC, bw.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    [$customerId]
);

json_paginated($rows, $total, $page, $perPage);
