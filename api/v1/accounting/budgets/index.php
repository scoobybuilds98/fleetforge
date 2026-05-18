<?php
declare(strict_types=1);

/**
 * api/v1/accounting/budgets/index.php
 *
 * List budgets with optional year filter + pagination (25/page).
 *
 * @method  GET
 * @query   year? (filter), status? (draft|active|archived), page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$year   = clean_int($_GET['year'] ?? null);
$status = clean_string($_GET['status'] ?? null);
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($year)   { $where[] = 'b.year = ?';    $params[] = $year; }
if ($status) { $where[] = 'b.status = ?';  $params[] = $status; }
$whereSql = implode(' AND ', $where);

$total = (int) (db_row(
    "SELECT COUNT(*) AS n FROM acc_budgets b WHERE {$whereSql}",
    $params
)['n'] ?? 0);

$rows = db_select(
    "SELECT b.id, b.name, b.year, b.version, b.status, b.is_active,
            b.created_at, b.updated_at,
            u.name AS created_by_name,
            (SELECT COUNT(*) FROM acc_budget_lines WHERE budget_id = b.id) AS line_count
       FROM acc_budgets b
  LEFT JOIN users u ON u.id = b.created_by
      WHERE {$whereSql}
      ORDER BY b.year DESC, b.created_at DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_success([
    'data' => $rows,
    'pagination' => [
        'total' => $total,
        'page'  => $page,
        'per_page' => $perPage,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ],
]);
