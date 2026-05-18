<?php
declare(strict_types=1);

/**
 * api/v1/accounting/recurring/pause.php
 *
 * Toggle is_active. Pause (1→0) or unpause (0→1).
 *
 * @method  POST
 * @body    { id }
 * @auth    require_permission('journal_entries','edit')
 * @session S037-REC
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$row = db_row("SELECT id, name, is_active FROM acc_recurring_entries WHERE id = ?", [$id]);
if (!$row) {
    json_error('NOT_FOUND', 'Template not found.', 404);
}

$newActive = ((int) $row['is_active']) === 1 ? 0 : 1;
db_update('acc_recurring_entries', ['is_active' => $newActive], 'id = ?', [$id]);

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'user_name'   => current_user()['name'] ?? 'system',
    'action'      => 'status_change',
    'module'      => 'accounting',
    'entity_type' => 'recurring_entry',
    'entity_id'   => $id,
    'entity_label' => (string) $row['name'],
    'notes'       => "Recurring template '{$row['name']}' " . ($newActive === 1 ? 'unpaused (re-activated).' : 'paused.'),
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success([
    'id'        => $id,
    'is_active' => $newActive,
]);
