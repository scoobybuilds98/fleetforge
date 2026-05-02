<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/update.php
 *
 * POST → update an email template (D19 optimistic lock).
 *
 * Permission: settings.edit
 *
 * Body (JSON):
 *   id          int     required
 *   updated_at  string  required (D19 optimistic lock)
 *   name, subject, body_html, body_text, category, variables, is_active
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

$body = json_body();

$id        = (int)($body['id'] ?? 0);
$submittedUpdatedAt = (string)($body['updated_at'] ?? '');

if ($id <= 0) {
    json_error('VALIDATION_ERROR', 'Template id is required.', 422);
}

$existing = db_row(
    "SELECT * FROM email_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Email template not found.', 404);
}

// D19 optimistic lock — refuse if record changed
if ($submittedUpdatedAt && !optimistic_lock_matches($submittedUpdatedAt, $existing['updated_at'])) {
    json_error('STALE_DATA', 'Template was modified by another user. Refresh and try again.', 409);
}

$update = [];
if (isset($body['name']))      $update['name']      = clean_string($body['name'], 255);
if (isset($body['subject']))   $update['subject']   = clean_string($body['subject'], 500);
if (isset($body['body_html'])) $update['body_html'] = (string)$body['body_html'];
if (isset($body['body_text'])) $update['body_text'] = (string)$body['body_text'];
if (isset($body['category'])) {
    $cat = clean_string($body['category'], 50);
    $valid = ['invoice','payment','lease','reminder','compliance','general','collection'];
    if (!in_array($cat, $valid, true)) {
        json_error('VALIDATION_ERROR', 'Invalid category.', 422);
    }
    $update['category'] = $cat;
}
if (isset($body['variables']) && is_array($body['variables'])) {
    $update['variables'] = json_encode(array_values(array_unique($body['variables'])));
}
if (isset($body['is_active'])) {
    $update['is_active'] = (int)(bool)$body['is_active'];
}

if (empty($update)) {
    json_error('VALIDATION_ERROR', 'No fields to update.', 422);
}

// Auto-strip text body if html changed but text was not provided
if (isset($update['body_html']) && !isset($update['body_text'])) {
    $update['body_text'] = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $update['body_html']));
}

db_update('email_templates', $update, 'id = ?', [$id]);

try {
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'settings',
        'entity_type' => 'email_template',
        'entity_id'   => $id,
        'description' => 'Updated email template: ' . ($existing['name'] ?? ''),
        'old_values'  => json_encode([
            'name'      => $existing['name'],
            'subject'   => $existing['subject'],
            'is_active' => $existing['is_active'],
        ]),
        'new_values'  => json_encode($update),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log('[email/templates/update] audit failed: ' . $e->getMessage());
}

// Re-read the row so we can return fresh updated_at
$fresh = db_row("SELECT id, updated_at FROM email_templates WHERE id = ?", [$id]);
json_success($fresh);
