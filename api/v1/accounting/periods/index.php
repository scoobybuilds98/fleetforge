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
 * @returns 200 { periods: [... + je_count, posted_count, draft_count], status_counts: { open, closed, locked } }
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
// Same filter, qualified for the aliased period query below (the JE-count
// derived table also has a `period_id` scope, so bare `year` must name its table).
$periodWhereSQL = $where ? 'WHERE p.' . implode(' AND p.', $where) : '';

// --- Fetch periods ---
// je_count: every journal entry filed against the period (draft, posted or
// reversed) — the Periods page card shows it so an operator can see at a glance
// whether a month has activity before closing it. The card read `je_count` but
// this query never selected one, so every card said "0 journal entries".
// The grouped derived table keeps it one scan instead of a per-row subquery.
$periods = db_select(
    "SELECT p.id, p.name, p.year, p.month, p.start_date, p.end_date, p.status,
            p.closed_by, p.closed_at, p.locked_by, p.locked_at,
            p.is_year_end, p.notes, p.created_at,
            COALESCE(jc.je_count, 0)     AS je_count,
            COALESCE(jc.posted_count, 0) AS posted_count,
            COALESCE(jc.draft_count, 0)  AS draft_count
     FROM acc_periods p
     LEFT JOIN (
         SELECT period_id,
                COUNT(*)                   AS je_count,
                SUM(status = 'posted')     AS posted_count,
                SUM(status = 'draft')      AS draft_count
           FROM acc_journal_entries
          GROUP BY period_id
     ) jc ON jc.period_id = p.id
     {$periodWhereSQL}
     ORDER BY p.year ASC, p.month ASC",
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
