<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/delivery.php
 *
 * S-BILLING-MODULE — has every invoice in the cycle reached its customer?
 *
 * One row per live invoice with a delivery state:
 *   draft         not sent yet (Send & Email it from the workbench / here)
 *   emailed       sent, and the last email attempt succeeded
 *   email_failed  the last email attempt failed (bad address, SES down)
 *   bounced       customer email is switched off after a bounce/complaint
 *   print         customer is billed by mail — print and post
 *   portal        customer is billed through the portal only
 *   not_emailed   sent (status) but never emailed
 * The email facts come from email_logs (every InvoiceDelivery / compose
 * attempt is logged against entity_type 'invoice').
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { rows: [...], counts: {state: n} }
 * @session S-BILLING-MODULE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\InvoiceDelivery;

$cycle = billing_cycle_from_request();
$showMoney = can_view_financials();

$rows = db_select(
    "SELECT i.id, i.invoice_number, i.status, i.total_amount, i.balance_due, i.currency,
            i.sent_at, i.sent_to_email, i.pdf_generated_at, i.due_date,
            COALESCE(c.company_name, i.company_name_snapshot) AS company_name, i.customer_id,
            COALESCE(i.contract_number_snapshot, l.contract_number) AS contract_number,
            c.invoice_delivery, c.invoice_email, c.billing_email, c.email AS customer_email, c.email_disabled
       FROM invoices i
       LEFT JOIN customers c ON c.id = i.customer_id
       LEFT JOIN leases l ON l.id = i.lease_id
      WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void'
      ORDER BY company_name, i.invoice_number",
    BillingCycles::scopeParams($cycle)
);

$emails = InvoiceDelivery::lastEmails(array_map(static fn($r) => (int) $r['id'], $rows));

$out = [];
$counts = [];
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $last = $emails[$id] ?? null;
    $pref = (string) ($r['invoice_delivery'] ?? 'email');
    $recipient = $r['invoice_email'] ?: ($r['billing_email'] ?: ($r['customer_email'] ?: null));

    if ($r['status'] === 'draft') {
        $state = 'draft';
    } elseif ($last && $last['status'] === 'sent') {
        $state = 'emailed';
    } elseif ($last && $last['status'] === 'failed') {
        $state = 'email_failed';
    } elseif ((int) $r['email_disabled'] === 1 && $pref === 'email') {
        $state = 'bounced';
    } elseif ($pref === 'mail') {
        $state = 'print';
    } elseif ($pref === 'portal' || $pref === 'none') {
        $state = 'portal';
    } else {
        $state = 'not_emailed';
    }
    $counts[$state] = ($counts[$state] ?? 0) + 1;

    $out[] = [
        'id'             => $id,
        'invoice_number' => $r['invoice_number'],
        'status'         => $r['status'],
        'company_name'   => $r['company_name'],
        'customer_id'    => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        'contract_number'=> $r['contract_number'],
        'currency'       => $r['currency'],
        'total_amount'   => $showMoney ? $r['total_amount'] : null,
        'balance_due'    => $showMoney ? $r['balance_due'] : null,
        'due_date'       => $r['due_date'],
        'sent_at'        => $r['sent_at'],
        'sent_to_email'  => $r['sent_to_email'],
        'recipient'      => $recipient,
        'delivery_pref'  => $pref,
        'email_disabled' => (int) $r['email_disabled'] === 1,
        'has_pdf'        => $r['pdf_generated_at'] !== null,
        'last_email'     => $last,
        'state'          => $state,
    ];
}

json_success(['rows' => $out, 'counts' => $counts]);
