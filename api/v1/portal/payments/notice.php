<?php
declare(strict_types=1);

/**
 * api/v1/portal/payments/notice.php
 *
 * Customer portal — "Tell us about a payment" (remittance advice)
 * (S-PORTAL-REDESIGN).
 *
 * Most B2B customers pay by cheque, e-Transfer or EFT, and billing used to
 * learn which invoices a deposit covered by phoning around. The customer now
 * says so from the portal: amount, date sent, method, reference and the
 * invoices it covers. It lands as a Billing Inquiry service request, so it
 * reaches the staff the operator routed billing questions to
 * (Settings → Portal & Requests), shows up in the Requests inbox, and staff
 * can reply in the same thread.
 *
 * It records NOTHING in the ledger: no payment row, no allocation, no GL.
 * Only staff record payments, once the money has actually arrived (Path B
 * counters stay untouched).
 *
 * Trap 8: invoice ids are filtered to the customer's own visible invoices.
 * Rate limit: 10 notices per portal user per hour.
 *
 * @method  POST (JSON; X-CSRF-Token)
 * @body    { amount, paid_on (Y-m-d), method, reference?, note?, invoice_ids?: int[] }
 * @auth    portal session (401 JSON when it has ended)
 * @returns { request_id, url }
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/auth.php';
require_once dirname(__DIR__, 4) . '/app/portal/includes/ui.php';

use FleetForge\Security\RateLimiter;

require_method('POST');
require_portal_auth_api();

$cid  = portal_customer_id();
$puid = (int) portal_user_id();
$body = json_body();

$rl = RateLimiter::check('portal_pay_notice:' . $puid, 10, 60);
if (!$rl['allowed']) {
    json_error('RATE_LIMITED', 'You have sent several payment notices recently. Please wait a little and try again, or message us.', 429);
}

// ── Amount: plain decimal, 2 places max, > 0 (bcmath from here on) ──────
$amountRaw = str_replace([',', '$', ' '], '', (string) ($body['amount'] ?? ''));
if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountRaw) || bccomp($amountRaw, '0', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'Enter the amount you paid, e.g. 1250.00.', 422);
}
$amount = bcadd($amountRaw, '0', 2);

// ── Date sent: not in the future, not more than a year back ─────────────
$paidOn = clean_date($body['paid_on'] ?? null);
$today  = ff_today();
if (!$paidOn || $paidOn > ff_local_date_add($today, 1) || $paidOn < ff_local_date_add($today, -366)) {
    json_error('VALIDATION_ERROR', 'Enter the date you sent the payment (within the last year).', 422);
}

$methods = [
    'e_transfer'  => 'Interac e-Transfer',
    'ach'         => 'Bank transfer (EFT)',
    'wire'        => 'Wire transfer',
    'check'       => 'Cheque',
    'credit_card' => 'Card (by phone)',
    'other'       => 'Other',
];
$method = (string) ($body['method'] ?? '');
if (!isset($methods[$method])) {
    json_error('VALIDATION_ERROR', 'Choose how you paid.', 422);
}

$reference = trim((string) clean_string($body['reference'] ?? '', 100));
$note      = trim((string) clean_string($body['note'] ?? '', 1000));

// ── Invoices it covers (optional; customer-scoped) ──────────────────────
$ids = pt_parse_ids($body['invoice_ids'] ?? [], 50);
$invoices = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $invoices = db_select(
        "SELECT i.id, i.invoice_number, i.balance_due, i.currency
           FROM invoices i
          WHERE i.customer_id = ? AND " . pt_invoice_visible_sql('i') . " AND i.id IN ({$ph})
          ORDER BY i.invoice_number",
        array_merge([$cid], $ids)
    );
}

$currency = (string) (db_row("SELECT currency FROM customers WHERE id = ?", [$cid])['currency'] ?? 'CAD');
if ($invoices) {
    $invCurrencies = array_unique(array_column($invoices, 'currency'));
    if (count($invCurrencies) === 1) $currency = (string) $invCurrencies[0];
}

$amountLabel = pt_money($amount, $currency) . ($currency === 'CAD' ? ' CAD' : '');
$subject = 'Payment sent: ' . $amountLabel . ' by ' . $methods[$method];

$lines   = [];
$lines[] = 'Payment notice sent from the customer portal.';
$lines[] = '';
$lines[] = 'Amount: ' . $amountLabel;
$lines[] = 'Date sent: ' . format_date($paidOn);
$lines[] = 'Method: ' . $methods[$method];
$lines[] = 'Reference: ' . ($reference !== '' ? $reference : '—');
if ($invoices) {
    $lines[] = 'Invoices it covers:';
    foreach ($invoices as $inv) {
        $lines[] = '  • ' . $inv['invoice_number'] . ' (balance ' . pt_money($inv['balance_due'], (string) $inv['currency']) . ')';
    }
} else {
    $lines[] = 'Invoices it covers: not specified';
}
if ($note !== '') {
    $lines[] = '';
    $lines[] = 'Customer note: ' . $note;
}
$lines[] = '';
$lines[] = 'Nothing has been recorded yet — record the payment once it arrives.';

$requestId = pt_create_request([
    'type'    => 'billing_inquiry',
    'subject' => $subject,
    'message' => implode("\n", $lines),
]);

try {
    db_insert('audit_log', [
        'user_id'      => null,
        'user_name'    => 'portal:' . $puid,
        'action'       => 'create',
        'module'       => 'portal',
        'entity_type'  => 'payment_notice',
        'entity_id'    => $requestId,
        'entity_label' => mb_substr($subject, 0, 255),
        'notes'        => 'Customer reported a payment from the portal (no ledger change).',
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (\Throwable $e) {
    error_log('[portal/payments/notice audit] ' . $e->getMessage());
}

json_success([
    'request_id' => $requestId,
    'url'        => pt_url('requests/view?id=' . $requestId),
], 201);
