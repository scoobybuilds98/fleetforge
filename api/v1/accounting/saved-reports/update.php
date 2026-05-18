<?php
declare(strict_types=1);

/**
 * api/v1/accounting/saved-reports/update.php
 *
 * Update a saved report's name or pinned flag (parameters are immutable
 * after creation — to change them, save a new configuration).
 *
 * @method  POST
 * @body    { id, name?, is_pinned? }
 * @auth    Session required; owner-only
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

$row = db_row("SELECT id, user_id, name, is_pinned FROM saved_reports WHERE id = ?", [$id]);
if (!$row) {
    json_error('NOT_FOUND', 'Saved report not found.', 404);
}
if ((int) $row['user_id'] !== current_user_id()) {
    json_error('FORBIDDEN', 'You can only edit your own saved reports.', 403);
}

$updates = [];
if (isset($input['name'])) {
    $name = trim((string) $input['name']);
    if ($name === '') json_error('VALIDATION_ERROR', 'name cannot be empty.', 422);
    $updates['name'] = $name;
}
if (array_key_exists('is_pinned', $input)) {
    $updates['is_pinned'] = !empty($input['is_pinned']) ? 1 : 0;
}
if (!$updates) {
    json_success(['id' => $id]); // nothing to do
}

db_update('saved_reports', $updates, 'id = ?', [$id]);
$fresh = db_row("SELECT id, name, report_type, parameters, is_pinned FROM saved_reports WHERE id = ?", [$id]);
if (is_string($fresh['parameters'])) {
    $fresh['parameters'] = json_decode($fresh['parameters'], true) ?? [];
}
json_success($fresh);
