<?php declare(strict_types=1);

/**
 * api/v1/accounting/periods/show.php
 *
 * Return a single accounting period with a summary of journal entry activity:
 * total posted JEs, total debits, and total credits within the period.
 *
 * @method  GET
 * @query   id (required — period ID)
 * @auth    Session required; require_permission('period_management','view')
 * @returns 200 { period + summary } | 404 NOT_FOUND
 *
 * @depends api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('period_management', 'view');

$id = require_id($_GET['id'] ?? null);

// --- Fetch period ---
$period = db_row(
    "SELECT id, name, year, month, start_date, end_date, status,
            closed_by, closed_at, locked_by, locked_at,
            is_year_end, notes, created_at
     FROM acc_periods
     WHERE id = ?",
    [$id]
);

if (!$period) {
    json_error('NOT_FOUND', 'Accounting period not found.', 404);
}

// --- Summary: count of posted JEs and total debits/credits ---
$summary = db_row(
    "SELECT
        COUNT(DISTINCT je.id) AS total_entries,
        COALESCE(SUM(jel.debit), 0)  AS total_debits,
        COALESCE(SUM(jel.credit), 0) AS total_credits
     FROM acc_journal_entries je
     LEFT JOIN acc_journal_entry_lines jel ON jel.journal_entry_id = je.id
     WHERE je.period_id = ?
       AND je.status = 'posted'",
    [$id]
);

$period['summary'] = [
    'total_entries' => (int) ($summary['total_entries'] ?? 0),
    'total_debits'  => $summary['total_debits'] ?? '0.00',
    'total_credits' => $summary['total_credits'] ?? '0.00',
];

json_success($period);
