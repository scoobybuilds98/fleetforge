<?php
declare(strict_types=1);

/**
 * api/v1/invoices/bulk_send.php
 *
 * S-BATCH-INVOICING — bulk draft→sent transition for up to 100 invoices in
 * one request, with an optional actual email dispatch layered on top. Each
 * ID is processed independently (its own status-transition + its own email
 * attempt) so one bad invoice never aborts the rest — same isolation model
 * as api/v1/invoices/bulk_void.php.
 *
 * The status transition and the email are DELIBERATELY separate outcomes
 * (matches api/v1/invoices/send.php's own PASS-15:E3 contract: "invoice
 * send vs email delivery are separate"). A transition can succeed with its
 * email failing (bad address, SES down) — both are reported per-id so the
 * operator can retry just the email without re-sending (re-sending an
 * already-sent invoice is rejected by FinancialActions::sendInvoice()).
 *
 * Recipient resolution per invoice, in order:
 *   1. email_overrides[id] from the request body (operator edited it in
 *      the batch page's Email Settings panel for this run only)
 *   2. customers.invoice_email → billing_email → email (same precedence
 *      EmailService::getCustomerContacts() documents)
 *   3. invoices.customer_email_snapshot (last resort — the frozen address
 *      at invoice-creation time; what FinancialActions::sendInvoice()
 *      itself falls back to when no override is given)
 * A recipient still resolving to '' skips both the override-to-send and
 * the email (send.php's own behaviour: sent_to_email stores whatever
 * resolves, even '').
 *
 * S-INVOICE-PDF: when send_email + attach_pdf are both true, each invoice's
 * PDF is generated on demand (if it doesn't already have one) right before
 * dispatch and attached via EmailService::send()'s attachments param — the
 * SAME {invoice_id} attachment shape api/v1/email/send.php already resolves
 * for the single-invoice compose modal. A PDF failure never blocks the
 * email itself (the customer still gets the notification without the
 * attachment; the operator can regenerate + resend later) — logged
 * server-side only, not surfaced as a per-id error.
 *
 * @method  POST
 * @body    { ids: [int,...], send_email: bool, attach_pdf: bool,
 *            email_overrides: { "<id>": "to@example.com", ... } }  (ids max 100)
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { actioned, skipped, errors: [{id, reason}],
 *                emailed, email_errors: [{id, reason}] }
 *
 * @depends lib/AI/Actions/FinancialActions.php (sendInvoice),
 *          lib/Email/EmailService.php (sendFromTemplate),
 *          lib/Billing/InvoicePdfGenerator.php
 * @decisions D12 (immutability after send), D45 (Path B counters — handled inside FinancialActions)
 * @session S-BATCH-INVOICING, S-INVOICE-PDF
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\AI\Actions\FinancialActions;
use FleetForge\AI\Actions\ActionException;
use FleetForge\Email\EmailService;
use FleetForge\Billing\InvoicePdfGenerator;

$body = json_body();

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

$ids = [];
foreach ($rawIds as $raw) {
    $id = clean_int($raw);
    if (!$id || $id <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $id;
}
$ids = array_values(array_unique($ids));

$sendEmail = !empty($body['send_email']);
$attachPdf = !empty($body['attach_pdf']);

// email_overrides: { "<invoice_id>": "to@example.com" } — keys arrive as
// strings from JSON; normalise to an int-keyed map.
$overridesRaw = is_array($body['email_overrides'] ?? null) ? $body['email_overrides'] : [];
$overrides    = [];
foreach ($overridesRaw as $k => $v) {
    $idKey = clean_int($k);
    $email = clean_email(is_string($v) ? $v : null);
    if ($idKey && $email) $overrides[$idKey] = $email;
}

$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$actioned    = 0;
$skipped     = 0;
$errors      = [];
$emailed     = 0;
$emailErrors = [];

foreach ($ids as $id) {
    $override = $overrides[$id] ?? null;

    try {
        $result = FinancialActions::sendInvoice($id, $override, $userId, $userName, $ipAddress);
    } catch (ActionException $e) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => $e->getMessage()];
        continue;
    } catch (\Throwable $e) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => $e->getMessage()];
        error_log("[bulk_send] Invoice #{$id} send failed: " . $e->getMessage());
        continue;
    }

    $actioned++;

    if (!$sendEmail) {
        continue;
    }

    // ── Resolve recipient + dispatch the invoice_ready email ──────────
    // (Separate try/catch: an email failure must not retroactively look
    // like the send transition itself failed — $actioned already counted.)
    try {
        $inv = db_row(
            "SELECT i.customer_id, i.invoice_number, i.customer_email_snapshot, i.customer_name_snapshot,
                    i.company_name_snapshot,
                    c.invoice_email, c.billing_email, c.email AS c_email,
                    c.contact_name, c.company_name
               FROM invoices i
               LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
              WHERE i.id = ?",
            [$id]
        );

        $toEmail = $override
            ?: (($inv['invoice_email'] ?? null) ?: (($inv['billing_email'] ?? null) ?: (($inv['c_email'] ?? null) ?: ($inv['customer_email_snapshot'] ?? null))));
        $toName  = (string) (($inv['contact_name'] ?? null) ?: ($inv['company_name'] ?? null) ?: ($inv['customer_name_snapshot'] ?? '') ?: ($inv['company_name_snapshot'] ?? ''));

        if (!$toEmail) {
            $emailErrors[] = ['id' => $id, 'reason' => 'No recipient email could be resolved for this customer.'];
            continue;
        }

        $variables = array_merge(
            $inv['customer_id'] ? EmailService::resolveCustomerVariables((int) $inv['customer_id']) : [],
            EmailService::resolveEntityVariables('invoice', $id)
        );

        // EmailService::send() (which sendFromTemplate() delegates to) expects
        // attachments PRE-RESOLVED to {path, name, type, source_type, source_id}
        // — the {invoice_id} shorthand only api/v1/email/send.php's own inline
        // code knows how to expand (it's endpoint-layer sugar, not part of
        // EmailService itself). Resolve it here the same way that file does.
        $attachments = [];
        if ($attachPdf) {
            try {
                $pdf = InvoicePdfGenerator::generate($id);
                $attachments[] = [
                    'path'        => $pdf['pdf_path'],
                    'name'        => $inv['invoice_number'] . '.pdf',
                    'type'        => 'application/pdf',
                    'source_type' => 'invoice_pdf',
                    'source_id'   => $id,
                ];
            } catch (\Throwable $e) {
                error_log("[bulk_send] Invoice #{$id} PDF generation failed (sending without attachment): " . $e->getMessage());
            }
        }

        $sendResult = EmailService::sendFromTemplate(
            'invoice_ready',
            $toEmail,
            $toName,
            $variables,
            [
                'customer_id' => $inv['customer_id'] ? (int) $inv['customer_id'] : null,
                'entity_type' => 'invoice',
                'entity_id'   => $id,
                'sent_by'     => $userId,
                'attachments' => $attachments,
            ]
        );

        if ($sendResult['success']) {
            $emailed++;
        } else {
            $emailErrors[] = ['id' => $id, 'reason' => $sendResult['error'] ?? 'Email could not be sent.'];
        }
    } catch (\Throwable $e) {
        $emailErrors[] = ['id' => $id, 'reason' => $e->getMessage()];
        error_log("[bulk_send] Invoice #{$id} email dispatch failed: " . $e->getMessage());
    }
}

json_success([
    'actioned'     => $actioned,
    'skipped'      => $skipped,
    'errors'       => $errors,
    'emailed'      => $emailed,
    'email_errors' => $emailErrors,
]);
