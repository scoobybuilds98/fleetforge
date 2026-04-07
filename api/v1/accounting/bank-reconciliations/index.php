<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-reconciliations/index.php
 *
 * List reconciliations for a bank account with pagination.
 *
 * @method  GET
 * @query   bank_account_id (required), page?, per_page?, status?
 * @auth    Session required; require_permission('bank_accounts','view')
 * @returns 200 paginated reconciliation rows
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('bank_accounts', 'view');

$bankAccountId = clean_int($_GET['bank_account_id'] ?? null);
if (!$bankAccountId) json_error('VALIDATION_ERROR', 'bank_account_id is required.', 422);

$where = ['r.bank_account_id = ?'];
$params = [$bankAccountId];

if ($status = clean_string($_GET['status'] ?? null)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

$whereSQL = implode(' AND ', $where);

$page = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = min(100, max(10, clean_int($_GET['per_page'] ?? 25) ?? 25));
$offset = ($page - 1) * $perPage;

$total = db_count("SELECT COUNT(*) FROM acc_bank_reconciliations r WHERE {$whereSQL}", $params);

$rows = db_select(
    "SELECT r.*, p.name AS period_name,
            u.name AS completed_by_name,
            cu.name AS created_by_name
     FROM acc_bank_reconciliations r
     LEFT JOIN acc_periods p ON p.id = r.period_id
     LEFT JOIN users u ON u.id = r.completed_by
     LEFT JOIN users cu ON cu.id = r.created_by
     WHERE {$whereSQL}
     ORDER BY r.statement_date DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_paginated($rows, $total, $page, $perPage);
