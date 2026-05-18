<?php
declare(strict_types=1);

/**
 * api/v1/accounting/fx-revaluations/show.php
 *
 * Single revaluation: header + JE + JE lines.
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('journal_entries','view')
 *
 * Session: S037-FX
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$row = db_row(
    "SELECT r.*, p.name AS period_name, p.start_date AS period_start, p.end_date AS period_end,
            u.name AS created_by_name
       FROM acc_fx_revaluations r
       JOIN acc_periods p ON p.id = r.period_id
  LEFT JOIN users u ON u.id = r.created_by
      WHERE r.id = ?",
    [$id]
);
if (!$row) {
    json_error('NOT_FOUND', 'Revaluation not found.', 404);
}

$je = null;
$lines = [];
if (!empty($row['journal_entry_id'])) {
    $je = db_row(
        "SELECT id, entry_number, entry_date, status, description, is_reversal, reversed_by_id,
                auto_reverse, auto_reverse_date
           FROM acc_journal_entries WHERE id = ?",
        [(int) $row['journal_entry_id']]
    );
    $lines = db_select(
        "SELECT l.*, a.code AS account_code, a.name AS account_name
           FROM acc_journal_entry_lines l
           JOIN acc_accounts a ON a.id = l.account_id
          WHERE l.journal_entry_id = ?
          ORDER BY l.line_number, l.id",
        [(int) $row['journal_entry_id']]
    );
}

json_success([
    'revaluation'  => $row,
    'journal_entry' => $je,
    'lines'        => $lines,
]);
