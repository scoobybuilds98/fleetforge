<?php
declare(strict_types=1);

/**
 * api/v1/accounting/year-end-checklist/update.php
 *
 * Toggle the is_complete flag on a year-end checklist item. Stamps
 * completed_by + completed_at when marking complete; clears them when
 * marking incomplete.
 *
 * @method  POST
 * @body    { id, is_complete (bool) }
 * @auth    require_permission('journal_entries','edit')
 * @session S037-YE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
$markComplete = !empty($input['is_complete']);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$item = db_row("SELECT * FROM acc_year_end_checklist WHERE id = ?", [$id]);
if (!$item) {
    json_error('NOT_FOUND', 'Checklist item not found.', 404);
}

$userId = current_user_id();
$payload = [
    'is_complete'  => $markComplete ? 1 : 0,
    'completed_by' => $markComplete ? $userId : null,
    'completed_at' => $markComplete ? date('Y-m-d H:i:s') : null,
];
db_update('acc_year_end_checklist', $payload, 'id = ?', [$id]);

db_insert('audit_log', [
    'user_id'     => $userId,
    'user_name'   => current_user()['name'] ?? 'system',
    'action'      => 'update',
    'module'      => 'accounting',
    'entity_type' => 'year_end_checklist',
    'entity_id'   => $id,
    'entity_label' => (string) $item['item_label'],
    'notes'       => sprintf(
        "FY %d checklist item '%s' marked %s.",
        (int) $item['year'],
        $item['item_key'],
        $markComplete ? 'done' : 'undone'
    ),
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

$updated = db_row(
    "SELECT cl.*, u.name AS completed_by_name
       FROM acc_year_end_checklist cl
  LEFT JOIN users u ON u.id = cl.completed_by
      WHERE cl.id = ?",
    [$id]
);

json_success($updated);
