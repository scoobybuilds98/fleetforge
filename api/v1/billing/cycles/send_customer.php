<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/send_customer.php
 *
 * S-BILLING-MODULE-2 — send a customer their whole month in ONE email.
 *
 *   1. (send_drafts) each of the customer's DRAFT invoices in the cycle is
 *      sent through FinancialActions::sendInvoice() — the one send path
 *      (status, balances, ledger, QuickBooks; due date per Billing → Settings);
 *   2. every sent invoice of theirs in the cycle goes out in one email
 *      (template 'invoice_bundle': summary table + Pay now links + one PDF
 *      per invoice) via InvoiceDelivery::emailBundle().
 * Per-invoice send failures are reported and left out of the email.
 *
 * @method  POST
 * @body    { id, customer_id, send_drafts: bool (default true), attach_pdf: bool (default true), to?: email }
 * @auth    Session required; invoices:edit
 * @returns 200 { sent, send_errors: [{id, reason}], emailed: bool, email_error, to, count }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

use FleetForge\AI\Actions\FinancialActions;
use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\InvoiceDelivery;

$cycle = billing_cycle_from_request();
$body  = json_body();
$customerId = require_id($body['customer_id'] ?? null);
$sendDrafts = !array_key_exists('send_drafts', $body) || !empty($body['send_drafts']);
$attachPdf  = !array_key_exists('attach_pdf', $body) || !empty($body['attach_pdf']);
$to = null;
if (!empty($body['to'])) {
    $to = clean_email(is_string($body['to']) ? $body['to'] : null);
    if (!$to) json_validation_error(['to' => 'Enter a valid email address.']);
}

$invs = db_select(
    "SELECT i.id, i.status, i.invoice_number FROM invoices i
      WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void' AND i.customer_id = ?
      ORDER BY i.invoice_number",
    array_merge(BillingCycles::scopeParams($cycle), [$customerId])
);
if (!$invs) {
    json_error('NOT_FOUND', 'This customer has no invoices in the cycle.', 404);
}

$userId = current_user_id();
$user   = current_user()['name'] ?? 'System';
$sent = 0;
$sendErrors = [];
$ready = [];
foreach ($invs as $i) {
    if ($i['status'] === 'draft') {
        if (!$sendDrafts) continue;
        try {
            FinancialActions::sendInvoice((int) $i['id'], $to, $userId, $user, $_SERVER['REMOTE_ADDR'] ?? null);
            $sent++;
            $ready[] = (int) $i['id'];
        } catch (\Throwable $e) {
            $sendErrors[] = ['id' => (int) $i['id'], 'reason' => $i['invoice_number'] . ': ' . $e->getMessage()];
        }
    } else {
        $ready[] = (int) $i['id'];
    }
}

$mail = $ready
    ? InvoiceDelivery::emailBundle($ready, $to, $attachPdf, $userId, $cycle['label'])
    : ['success' => false, 'to' => null, 'error' => 'Nothing was sent, so nothing to email.', 'count' => 0];

BillingCycles::audit($cycle, 'update',
    "Customer #{$customerId}: {$sent} draft(s) sent" . ($mail['success'] ? ", {$mail['count']} invoice(s) emailed together to {$mail['to']}" : ', email not sent: ' . ($mail['error'] ?? '?')) . '.');

json_success([
    'sent'        => $sent,
    'send_errors' => $sendErrors,
    'emailed'     => (bool) $mail['success'],
    'email_error' => $mail['success'] ? null : $mail['error'],
    'to'          => $mail['to'] ?? null,
    'count'       => $mail['count'] ?? 0,
]);
