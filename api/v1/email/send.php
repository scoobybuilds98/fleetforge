<?php
declare(strict_types=1);

/**
 * api/v1/email/send.php
 *
 * POST → send a customer email + log it.
 *
 * Wraps FleetForge\Email\EmailService::send() in a permission-
 * checked HTTP layer. Permission required: customers.view (any
 * role that can see customers can email them — viewer is allowed
 * because the spec says "viewer cannot send" but the customers
 * permission matrix already excludes viewers from POST customer
 * actions; we re-check explicitly with `customers.create` so the
 * Read-Only role is correctly blocked).
 *
 * Request body (JSON):
 *   to_email     string  required
 *   to_name      string  optional
 *   reply_to     string  optional
 *   subject      string  required
 *   body_html    string  required
 *   customer_id  int     optional
 *   entity_type  string  optional ('invoice','lease','payment','equipment_unit')
 *   entity_id    int     optional
 *   template_id  int     optional
 *   attachments  array   optional [{document_id} | {invoice_id} | {upload_path,name,size,type}]
 *
 * Response: { success, log_id, error }
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_method('POST');
require_auth_api();
// WHY: viewer/read_only role does not have customers.create — using
// it as the gate keeps the permission story consistent with the
// matrix in REFERENCE.md §12.
require_permission('customers', 'create');

use FleetForge\Email\EmailService;

$body = json_body();

$toEmail    = (string)($body['to_email']    ?? '');
$toName     = (string)($body['to_name']     ?? '');
$replyTo    = (string)($body['reply_to']    ?? '');
$subject    = (string)($body['subject']     ?? '');
$bodyHtml   = (string)($body['body_html']   ?? '');
$customerId = isset($body['customer_id']) ? (int)$body['customer_id'] : 0;
$entityType = clean_string($body['entity_type'] ?? null, 50);
$entityId   = isset($body['entity_id']) ? (int)$body['entity_id'] : 0;
$templateId = isset($body['template_id']) ? (int)$body['template_id'] : 0;
$rawAttachments = is_array($body['attachments'] ?? null) ? $body['attachments'] : [];

// ── Validate required fields ─────────────────────────────────
$errors = [];
if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
    $errors['to_email'] = 'A valid recipient email is required.';
}
if (trim($subject) === '') {
    $errors['subject'] = 'Subject is required.';
}
if (trim($bodyHtml) === '') {
    $errors['body_html'] = 'Email body is required.';
}
if (!empty($errors)) {
    json_validation_error($errors);
}

// ── Resolve attachments ──────────────────────────────────────
// Each attachment may be one of:
//   {document_id: int}        → resolve from documents table
//   {invoice_id:  int}        → resolve invoice PDF (build pdf if missing — out of scope: skip if no pdf_path)
//   {upload_path, name, ...}  → freshly uploaded file
$attachments = [];
foreach ($rawAttachments as $a) {
    if (!is_array($a)) continue;

    // Document attachment — pull file_path/name/size/type from documents table
    if (!empty($a['document_id'])) {
        $docId = (int)$a['document_id'];
        $doc = db_row(
            "SELECT id, title, file_name, file_path, file_size_kb, mime_type
             FROM documents
             WHERE id = ? AND deleted_at IS NULL",
            [$docId]
        );
        if (!$doc) continue;
        $attachments[] = [
            'path'        => (string)$doc['file_path'],
            'name'        => (string)($doc['title'] ?: $doc['file_name']),
            'size'        => $doc['file_size_kb'] ? (int)$doc['file_size_kb'] * 1024 : null,
            'type'        => (string)($doc['mime_type'] ?? ''),
            'source_type' => 'document',
            'source_id'   => $docId,
        ];
        continue;
    }

    // Invoice PDF attachment
    if (!empty($a['invoice_id'])) {
        $invId = (int)$a['invoice_id'];
        $inv = db_row(
            "SELECT id, invoice_number, pdf_path
             FROM invoices
             WHERE id = ? AND deleted_at IS NULL",
            [$invId]
        );
        if (!$inv || empty($inv['pdf_path'])) continue;
        $attachments[] = [
            'path'        => (string)$inv['pdf_path'],
            'name'        => $inv['invoice_number'] . '.pdf',
            'type'        => 'application/pdf',
            'source_type' => 'invoice_pdf',
            'source_id'   => $invId,
        ];
        continue;
    }

    // Freshly uploaded file (already saved to storage by upload endpoint)
    if (!empty($a['upload_path']) && !empty($a['name'])) {
        $attachments[] = [
            'path'        => (string)$a['upload_path'],
            'name'        => (string)$a['name'],
            'size'        => isset($a['size']) ? (int)$a['size'] : null,
            'type'        => (string)($a['type'] ?? ''),
            'source_type' => 'upload',
            'source_id'   => null,
        ];
    }
}

// ── Dispatch via EmailService ────────────────────────────────
$result = EmailService::send([
    'to_email'    => $toEmail,
    'to_name'     => $toName,
    'reply_to'    => $replyTo,
    'subject'     => $subject,
    'body_html'   => $bodyHtml,
    'customer_id' => $customerId,
    'entity_type' => $entityType,
    'entity_id'   => $entityId,
    'template_id' => $templateId,
    'attachments' => $attachments,
    'sent_by'     => current_user_id() ?? 0,
]);

if (!$result['success']) {
    json_error('EMAIL_SEND_FAILED', $result['error'] ?? 'Email could not be sent.', 500, ['log_id' => $result['log_id']]);
}

// ── Audit log ─────────────────────────────────────────────────
try {
    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'customers',
        'entity_type' => 'email',
        'entity_id'   => (int)$result['log_id'],
        'description' => 'Sent email to ' . $toEmail . ' — ' . $subject,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log('[email/send] audit insert failed: ' . $e->getMessage());
}

json_success([
    'log_id'  => $result['log_id'],
    'message' => 'Email sent successfully.',
], 201);
