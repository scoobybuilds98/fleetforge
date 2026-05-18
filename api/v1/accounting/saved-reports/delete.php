<?php
declare(strict_types=1);

/**
 * api/v1/accounting/saved-reports/delete.php
 *
 * Delete a saved report. Owner or super_admin only.
 *
 * @method  POST
 * @body    { id }
 * @auth    Session required
 *
 * Session: S036
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'view');

$body = json_body();
$input = !empty($body) ? $body : $_POST;
$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$row = db_row("SELECT id, user_id, name FROM saved_reports WHERE id = ?", [$id]);
if (!$row) {
    json_error('NOT_FOUND', 'Saved report not found.', 404);
}

$user = current_user();
$isOwner = ((int) $row['user_id']) === current_user_id();
$isSuperAdmin = ($user['role_slug'] ?? '') === 'super_admin';

if (!$isOwner && !$isSuperAdmin) {
    json_error('FORBIDDEN', 'Only the owner or a super_admin can delete this saved report.', 403);
}

db_execute("DELETE FROM saved_reports WHERE id = ?", [$id]);

db_insert('audit_log', [
    'user_id'      => current_user_id(),
    'user_name'    => $user['name'] ?? 'system',
    'action'       => 'delete',
    'module'       => 'accounting',
    'entity_type'  => 'saved_report',
    'entity_id'    => $id,
    'entity_label' => (string) $row['name'],
    'notes'        => "Saved report '{$row['name']}' (#{$id}) deleted.",
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['deleted' => true]);
