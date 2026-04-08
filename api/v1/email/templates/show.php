<?php
declare(strict_types=1);

/**
 * api/v1/email/templates/show.php
 *
 * GET ?id=N → return a single email template (full body).
 *
 * Used by the templates management page editor.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$id = require_id($_GET['id'] ?? null);

$tpl = db_row(
    "SELECT * FROM email_templates WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$tpl) {
    json_error('NOT_FOUND', 'Email template not found.', 404);
}

$tpl['id']        = (int)$tpl['id'];
$tpl['is_active'] = (int)$tpl['is_active'];
if (!empty($tpl['variables'])) {
    $decoded = json_decode((string)$tpl['variables'], true);
    $tpl['variables'] = is_array($decoded) ? $decoded : [];
} else {
    $tpl['variables'] = [];
}

json_success($tpl);
