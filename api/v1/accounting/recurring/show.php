<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/show.php
 *
 * Detail: header + lines (with account names) + posting history (last 24).
 *
 * @method  GET
 * @query   id
 * @auth    require_permission('journal_entries','view')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$template = db_row(
    "SELECT t.*, u.name AS created_by_name
       FROM acc_recurring_entries t
  LEFT JOIN users u ON u.id = t.created_by
      WHERE t.id = ?",
    [$id]
);
if (!$template) {
    json_error('NOT_FOUND', 'Recurring template not found.', 404);
}

$lines = db_select(
    "SELECT l.id, l.recurring_entry_id, l.account_id, l.line_number,
            l.description, l.debit, l.credit,
            a.code AS account_code, a.name AS account_name, a.normal_balance
       FROM acc_recurring_entry_lines l
       JOIN acc_accounts a ON a.id = l.account_id
      WHERE l.recurring_entry_id = ?
      ORDER BY l.line_number, l.id",
    [$id]
);

$history = db_select(
    "SELECT id, entry_number, entry_date, status, description, reference
       FROM acc_journal_entries
      WHERE source_type = 'recurring' AND source_id = ?
      ORDER BY entry_date DESC, id DESC
      LIMIT 24",
    [$id]
);

json_success([
    'template' => $template,
    'lines'    => $lines,
    'history'  => $history,
]);
