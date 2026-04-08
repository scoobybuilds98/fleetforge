<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/delete.php
 *
 * POST → soft-delete an email template.
 *
 * Soft-deletes (rather than hard-deletes) so the existing email_logs
 * rows that reference this template via FK can stay valid.
 *
 * Permission: settings.delete (super_admin only — matches the
 * permission matrix entry for templates).
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'delete');

$body = json_body();
$id = (int)($body['id'] ?? 0);
if ($id <= 0) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}

$existing = db_row(
    "SELECT id, name, slug FROM email_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Email template not found or already deleted.', 404);
}

db_execute(
    "UPDATE email_templates SET deleted_at = NOW(), is_active = 0 WHERE id = ?",
    [$id]
);

try {
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'delete',
        'module'      => 'settings',
        'entity_type' => 'email_template',
        'entity_id'   => $id,
        'description' => 'Deleted email template: ' . $existing['name'] . ' (slug: ' . $existing['slug'] . ')',
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log('[email/templates/delete] audit failed: ' . $e->getMessage());
}

json_success(['id' => $id, 'message' => 'Template deleted.']);
