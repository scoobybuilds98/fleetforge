<?php
declare(strict_types=1);

/**
 * api/v1/email/logs/show.php
 *
 * GET ?id=N → return one email log row with full body + attachments.
 *
 * Used by the History tab "View" action to show the rendered
 * email body in a modal.
 *
 * Note: file_path on attachments is INTENTIONALLY stripped from
 * the response (Trap 7) — only file_name and metadata go back.
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$id = require_id($_GET['id'] ?? null);

$log = db_row(
    "SELECT el.*, u.name AS sent_by_name, t.name AS template_name
     FROM email_logs el
     LEFT JOIN users u ON u.id = el.sent_by
     LEFT JOIN email_templates t ON t.id = el.template_id
     WHERE el.id = ?",
    [$id]
);
if (!$log) {
    json_error('NOT_FOUND', 'Email log not found.', 404);
}

$attachments = db_select(
    "SELECT id, file_name, file_size, mime_type, source_type, source_id, created_at
     FROM email_attachments
     WHERE email_log_id = ?
     ORDER BY id",
    [$id]
);
foreach ($attachments as &$a) {
    $a['id']        = (int)$a['id'];
    $a['file_size'] = $a['file_size'] ? (int)$a['file_size'] : null;
    $a['source_id'] = $a['source_id'] ? (int)$a['source_id'] : null;
}
unset($a);

$log['id']           = (int)$log['id'];
$log['customer_id']  = $log['customer_id']  ? (int)$log['customer_id']  : null;
$log['entity_id']    = $log['entity_id']    ? (int)$log['entity_id']    : null;
$log['template_id']  = $log['template_id']  ? (int)$log['template_id']  : null;
$log['sent_by']      = $log['sent_by']      ? (int)$log['sent_by']      : null;
$log['attachments']  = $attachments;

// JSON-decode the inline attachments column for legacy entries
if (isset($log['attachments_inline'])) unset($log['attachments_inline']);

json_success($log);
