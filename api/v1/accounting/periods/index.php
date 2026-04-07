<?php declare(strict_types=1);

/**
 * api/v1/accounting/periods/index.php
 *
 * List all accounting periods with optional year filter and status counts.
 * Returns every period row plus an aggregate summary of open/closed/locked counts.
 *
 * @method  GET
 * @query   year (optional — filter to a single fiscal year)
 * @auth    Session required; require_permission('period_management','view')
 * @returns 200 { periods: [...], status_counts: { open, closed, locked } }
 *
 * @depends api/bootstrap.php, AccountingService
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('period_management', 'view');

// --- Optional year filter ---
$where  = [];
$params = [];

if ($year = clean_int($_GET['year'] ?? null)) {
    $where[]  = 'year = ?';
    $params[] = $year;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Fetch periods ---
$periods = db_select(
    "SELECT id, name, year, month, start_date, end_date, status,
            closed_by, closed_at, locked_by, locked_at,
            is_year_end, notes, created_at
     FROM acc_periods
     {$whereSQL}
     ORDER BY year ASC, month ASC",
    $params
);

// --- Status counts (same filter scope) ---
$countRows = db_select(
    "SELECT status, COUNT(*) AS cnt
     FROM acc_periods
     {$whereSQL}
     GROUP BY status",
    $params
);

$statusCounts = ['open' => 0, 'closed' => 0, 'locked' => 0];
foreach ($countRows as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}

json_success([
    'periods'       => $periods,
    'status_counts' => $statusCounts,
]);
