<?php
declare(strict_types=1);

/**
 * api/v1/email/attachments/available.php
 *
 * GET ?customer_id=X&entity_type=invoice&entity_id=Y
 *
 * Returns the documents and invoice PDFs that can be attached to
 * an outgoing email for the given customer/entity. Powers the
 * "From FleetForge Documents" and "Attach Invoice PDF" pickers
 * inside the compose modal.
 *
 * Response shape:
 *   {
 *     documents: [{id, name, source_type, source_id, size, type, entity_label}, ...],
 *     invoices:  [{id, invoice_number, total_amount_formatted, status, status_class, ...}, ...],
 *     contacts:  [{id, name, email, label}, ...]   // primary + billing + invoice + customer_contacts
 *   }
 *
 * @session EMAIL-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('customers', 'view');

use FleetForge\Email\EmailService;

$customerId = (int)($_GET['customer_id'] ?? 0);
$entityType = clean_string($_GET['entity_type'] ?? null, 50);
$entityId   = (int)($_GET['entity_id'] ?? 0);

$documents = EmailService::getAvailableAttachments($customerId, $entityType, $entityId);
$invoices  = $customerId > 0 ? EmailService::getCustomerInvoices($customerId) : [];
$contacts  = $customerId > 0 ? EmailService::getCustomerContacts($customerId) : [];

// S-QBO-INVOICE-PAYNOW: tell the compose modal whether send will add the
// invoice's "Pay now" button (EmailService::withPayNow), so the operator
// knows before sending — the button is not in the editable body.
$payNow = null;
if ($entityType === 'invoice' && $entityId > 0 && \FleetForge\QboPushers\PayLink::payableUrl($entityId) !== '') {
    $inv    = db_row("SELECT balance_due, currency FROM invoices WHERE id = ?", [$entityId]);
    $payNow = ['amount' => format_currency($inv['balance_due'] ?? '0') . ' ' . ($inv['currency'] ?? '')];
}

json_success([
    'documents' => $documents,
    'invoices'  => $invoices,
    'contacts'  => $contacts,
    'pay_now'   => $payNow,
]);
