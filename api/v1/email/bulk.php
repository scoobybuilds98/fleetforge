<?php
declare(strict_types=1);

/**
 * api/v1/email/bulk.php
 *
 * POST → send the same email to a list of customers.
 *
 * Wraps EmailService::send() in a loop with a 500ms sleep between
 * sends to avoid spam-rate trips on SES. Each send produces its
 * own email_logs row so the bulk batch is fully audited.
 *
 * Body (JSON):
 *   customer_ids   int[]   required (max 500 to avoid timeouts)
 *   template_id    int     optional
 *   subject        string  required
 *   body_html      string  required
 *   reply_to       string  optional
 *
 * Response:
 *   { sent: int, failed: int, results: [{customer_id, log_id, success, error}, ...] }
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'create');

use FleetForge\Email\EmailService;

$body = json_body();

$customerIds = is_array($body['customer_ids'] ?? null) ? $body['customer_ids'] : [];
$templateId  = (int)($body['template_id'] ?? 0);
$subject     = (string)($body['subject']   ?? '');
$bodyHtml    = (string)($body['body_html'] ?? '');
$replyTo     = (string)($body['reply_to']  ?? '');

if (empty($customerIds)) {
    json_error('VALIDATION_ERROR', 'At least one customer is required.', 422);
}
if (count($customerIds) > 500) {
    json_error('VALIDATION_ERROR', 'Maximum 500 recipients per bulk send.', 422);
}
if (trim($subject) === '' || trim($bodyHtml) === '') {
    json_error('VALIDATION_ERROR', 'Subject and body are required.', 422);
}

// Sanitize the list to a unique set of positive ints
$customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));

$results  = [];
$sent     = 0;
$failed   = 0;
$senderId = current_user_id() ?? 0;

// Cap PHP execution time on this bulk job — long bulk batches
// will time out the request, so the caller should split very
// large batches client-side.
set_time_limit(300);

foreach ($customerIds as $cid) {
    $cust = db_row(
        "SELECT id, company_name, contact_name, email, billing_email, invoice_email
         FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$cid]
    );
    if (!$cust) {
        $failed++;
        $results[] = ['customer_id' => $cid, 'log_id' => null, 'success' => false, 'error' => 'Customer not found.'];
        continue;
    }

    // Pick the best email — invoice_email > billing_email > email
    $toEmail = (string)($cust['invoice_email'] ?: $cust['billing_email'] ?: $cust['email']);
    $toName  = (string)($cust['contact_name'] ?: $cust['company_name']);

    if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        $failed++;
        $results[] = ['customer_id' => $cid, 'log_id' => null, 'success' => false, 'error' => 'No valid email on file.'];
        continue;
    }

    // Compile per-customer (so {customer_name} resolves correctly)
    $vars = EmailService::companyVariables($senderId);
    $vars = array_merge($vars, EmailService::resolveCustomerVariables($cid));
    $personalSubject  = EmailService::substitute($subject, $vars);
    $personalBodyHtml = EmailService::substitute($bodyHtml, $vars);

    $r = EmailService::send([
        'to_email'    => $toEmail,
        'to_name'     => $toName,
        'reply_to'    => $replyTo,
        'subject'     => $personalSubject,
        'body_html'   => $personalBodyHtml,
        'customer_id' => $cid,
        'template_id' => $templateId ?: null,
        'sent_by'     => $senderId,
    ]);

    if ($r['success']) {
        $sent++;
        $results[] = ['customer_id' => $cid, 'log_id' => $r['log_id'], 'success' => true, 'error' => null];
    } else {
        $failed++;
        $results[] = ['customer_id' => $cid, 'log_id' => $r['log_id'], 'success' => false, 'error' => $r['error']];
    }

    // 500 ms throttle to keep SES happy
    usleep(500000);
}

try {
    db_insert('audit_log', [
        'user_id'     => $senderId,
        'action'      => 'create',
        'module'      => 'customers',
        'entity_type' => 'email_bulk',
        'entity_id'   => null,
        'description' => 'Bulk email — ' . $sent . ' sent / ' . $failed . ' failed — ' . $subject,
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (Throwable $e) {
    error_log('[email/bulk] audit failed: ' . $e->getMessage());
}

json_success([
    'sent'    => $sent,
    'failed'  => $failed,
    'results' => $results,
]);
