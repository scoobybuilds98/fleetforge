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
 *          lib/Billing/InvoiceDelivery.php (email — shared with api/v1/billing/deliver.php)
 * @decisions D12 (immutability after send), D45 (Path B counters — handled inside FinancialActions)
 * @session S-BATCH-INVOICING, S-INVOICE-PDF
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\AI\Actions\FinancialActions;
use FleetForge\AI\Actions\ActionException;
use FleetForge\Billing\InvoiceDelivery;

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

    // ── Dispatch the invoice_ready email (S-BILLING-MODULE: shared with
    // api/v1/billing/deliver.php via InvoiceDelivery — one delivery path).
    // A separate outcome from the send transition above: an email failure
    // must not retroactively look like the send itself failed.
    $mail = InvoiceDelivery::email($id, $override, $attachPdf, $userId);
    if ($mail['success']) {
        $emailed++;
    } else {
        $emailErrors[] = ['id' => $id, 'reason' => $mail['error'] ?? 'Email could not be sent.'];
    }
}

json_success([
    'actioned'     => $actioned,
    'skipped'      => $skipped,
    'errors'       => $errors,
    'emailed'      => $emailed,
    'email_errors' => $emailErrors,
]);
