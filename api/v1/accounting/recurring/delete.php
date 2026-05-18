<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/delete.php
 *
 * Hard delete only when no posting history exists. Templates with at
 * least one posted JE must be paused, not deleted, so the JE source_id
 * back-reference stays meaningful.
 *
 * @method  POST
 * @body    { id }
 * @auth    require_permission('journal_entries','delete')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'delete');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$row = db_row("SELECT id, name FROM acc_recurring_entries WHERE id = ?", [$id]);
if (!$row) {
    json_error('NOT_FOUND', 'Template not found.', 404);
}

$history = db_row(
    "SELECT COUNT(*) AS n FROM acc_journal_entries
      WHERE source_type='recurring' AND source_id = ?",
    [$id]
);
if ((int) ($history['n'] ?? 0) > 0) {
    json_error(
        'HAS_HISTORY',
        'Cannot delete a template with posting history. Pause it instead.',
        422
    );
}

db_transaction(function () use ($id) {
    db_execute("DELETE FROM acc_recurring_entry_lines WHERE recurring_entry_id = ?", [$id]);
    db_execute("DELETE FROM acc_recurring_entries WHERE id = ?", [$id]);
});

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'user_name'   => current_user()['name'] ?? 'system',
    'action'      => 'delete',
    'module'      => 'accounting',
    'entity_type' => 'recurring_entry',
    'entity_id'   => $id,
    'entity_label'=> (string) $row['name'],
    'notes'       => "Recurring template '{$row['name']}' deleted (no posting history).",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true]);
