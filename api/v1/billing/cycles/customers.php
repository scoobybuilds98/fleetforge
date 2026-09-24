<?php
declare(strict_types=1);

/**
 * api/v1/billing/cycles/customers.php
 *
 * S-BILLING-MODULE-2 — the cycle by CUSTOMER: for each customer with a lease
 * on rent this month, how many leases, how many are billed / still to bill,
 * their invoices (draft / sent / emailed), what the month bills them, where
 * the invoices go (recipient, delivery preference, bounced email) and any
 * hold. The Customers tab sends a customer's whole month in ONE email.
 *
 * @method  GET
 * @query   id | month
 * @auth    Session required; invoices:view (amounts need can_view_financials)
 * @returns 200 { rows: [...] }
 * @session S-BILLING-MODULE-2
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__) . '/_cycle.php';

require_method('GET');
require_auth_api();
require_permission('invoices', 'view');

use FleetForge\Billing\Cycle\BillingCycles;
use FleetForge\Billing\Cycle\BillingHolds;
use FleetForge\Billing\InvoiceDelivery;

$cycle = billing_cycle_from_request();
$showMoney = can_view_financials();

$cov = BillingCycles::coverage($cycle, false)['rows'];
$byCust = [];
foreach ($cov as $r) {
    $cid = $r['customer_id'];
    $byCust[$cid] ??= ['leases' => 0, 'billed' => 0, 'to_bill' => 0, 'held' => 0];
    $byCust[$cid]['leases']++;
    if (in_array($r['status'], ['billed', 'covered_elsewhere'], true)) $byCust[$cid]['billed']++;
    if (in_array($r['status'], ['to_bill', 'void_rebillable', 'closed_unbilled'], true)) $byCust[$cid]['to_bill']++;
    if ($r['status'] === 'held') $byCust[$cid]['held']++;
}

$invs = db_select(
    "SELECT i.id, i.customer_id, i.status, i.currency, i.total_amount, i.balance_due
       FROM invoices i
      WHERE " . BillingCycles::scopeSql('i') . " AND i.status <> 'void'",
    BillingCycles::scopeParams($cycle)
);
$emails = InvoiceDelivery::lastEmails(array_map(static fn($r) => (int) $r['id'], $invs));
$invByCust = [];
foreach ($invs as $i) {
    $cid = (int) $i['customer_id'];
    $invByCust[$cid] ??= ['count' => 0, 'drafts' => 0, 'sent' => 0, 'emailed' => 0, 'total' => [], 'balance' => [], 'last_emailed' => null];
    $b = &$invByCust[$cid];
    $b['count']++;
    if ($i['status'] === 'draft') $b['drafts']++; else $b['sent']++;
    $e = $emails[(int) $i['id']] ?? null;
    if ($i['status'] !== 'draft' && $e && $e['sent_count'] > 0) {
        $b['emailed']++;
        $b['last_emailed'] = max((string) $b['last_emailed'], (string) $e['at']);
    }
    $b['total'][$i['currency']] = bcadd($b['total'][$i['currency']] ?? '0.00', (string) $i['total_amount'], 2);
    $b['balance'][$i['currency']] = bcadd($b['balance'][$i['currency']] ?? '0.00', (string) $i['balance_due'], 2);
    unset($b);
}

$ids = array_values(array_unique(array_merge(array_keys($byCust), array_keys($invByCust))));
$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $custHolds = [];
    foreach (db_select(
        "SELECT customer_id, reason FROM billing_holds
          WHERE scope = 'customer' AND released_at IS NULL AND customer_id IN ({$ph})
            AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)",
        array_merge($ids, [$cycle['period_end'], $cycle['period_start']])
    ) as $h) {
        $custHolds[(int) $h['customer_id']] = $h['reason'];
    }
    foreach (db_select(
        "SELECT id, company_name, contact_name, invoice_email, billing_email, email, email_disabled,
                invoice_delivery, invoice_cc_emails, payment_terms, status
           FROM customers WHERE id IN ({$ph}) ORDER BY company_name",
        $ids
    ) as $c) {
        $cid = (int) $c['id'];
        $l = $byCust[$cid] ?? ['leases' => 0, 'billed' => 0, 'to_bill' => 0, 'held' => 0];
        $v = $invByCust[$cid] ?? ['count' => 0, 'drafts' => 0, 'sent' => 0, 'emailed' => 0, 'total' => [], 'balance' => [], 'last_emailed' => null];
        $rows[] = [
            'customer_id'     => $cid,
            'company_name'    => $c['company_name'],
            'contact_name'    => $c['contact_name'],
            'recipient'       => $c['invoice_email'] ?: ($c['billing_email'] ?: ($c['email'] ?: null)),
            'email_disabled'  => (int) $c['email_disabled'] === 1,
            'delivery_pref'   => $c['invoice_delivery'],
            'payment_terms'   => $c['payment_terms'],
            'customer_status' => $c['status'],
            'hold'            => $custHolds[$cid] ?? null,
            'leases'          => $l['leases'],
            'leases_billed'   => $l['billed'],
            'leases_to_bill'  => $l['to_bill'],
            'leases_held'     => $l['held'],
            'invoices'        => $v['count'],
            'drafts'          => $v['drafts'],
            'sent'            => $v['sent'],
            'emailed'         => $v['emailed'],
            'last_emailed'    => $v['last_emailed'],
            'total'           => $showMoney ? $v['total'] : null,
            'balance'         => $showMoney ? $v['balance'] : null,
        ];
    }
}

json_success(['rows' => $rows]);
