<?php
declare(strict_types=1);

/**
 * api/v1/accounting/fx-revaluations/index.php
 *
 * List acc_fx_revaluations rows with period + JE joins. Pagination 25/page.
 *
 * @method  GET
 * @query   year? (filter by YEAR(revaluation_date)), status?, page?, per_page?
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S037-FX
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$year    = clean_int($_GET['year'] ?? null);
$status  = clean_string($_GET['status'] ?? null);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(5, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = ['preview', 'posted', 'reversed'];
if ($status && !in_array($status, $validStatuses, true)) {
    $status = null;
}

$where  = ['1=1'];
$params = [];
if ($year)   { $where[] = 'YEAR(r.revaluation_date) = ?'; $params[] = $year; }
if ($status) { $where[] = 'r.status = ?';                 $params[] = $status; }
$whereSql = implode(' AND ', $where);

$total = (int) (db_row(
    "SELECT COUNT(*) AS n FROM acc_fx_revaluations r WHERE {$whereSql}",
    $params
)['n'] ?? 0);

$rows = db_select(
    "SELECT r.id, r.revaluation_date, r.period_id, p.name AS period_name,
            r.exchange_rate_used, r.total_ar_usd, r.total_ar_cad_book,
            r.total_ar_cad_revalued, r.unrealized_gain_loss,
            r.status, r.run_at, r.journal_entry_id,
            je.entry_number AS je_number, je.status AS je_status,
            u.name AS created_by_name, r.created_at
       FROM acc_fx_revaluations r
       JOIN acc_periods p ON p.id = r.period_id
  LEFT JOIN acc_journal_entries je ON je.id = r.journal_entry_id
  LEFT JOIN users u ON u.id = r.created_by
      WHERE {$whereSql}
      ORDER BY r.revaluation_date DESC, r.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

json_success([
    'data' => $rows,
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ],
]);
