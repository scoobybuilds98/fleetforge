<?php
declare(strict_types=1);

/**
 * app/admin/pay.php
 *
 * Public, token-gated "Pay online" redirect. Route: GET /pay?i=<invoice id>&t=<token>
 * — the {pay_online_link} in FleetForge's invoice emails (lib/QboPushers/PayLink.php).
 *
 * NO login (the customer clicks it from an email). Same pattern as the
 * credit-application page: the token is the proof. It is an HMAC of the
 * invoice id, so it can't be guessed or re-pointed; a rate limit keeps the
 * page from being used to hammer the QuickBooks API.
 *
 * On a valid, payable invoice that is already in QuickBooks, 302-redirect to
 * the QuickBooks pay-now page (Invoice.InvoiceLink, fetched at click time —
 * QuickBooksClient::generatePaymentsHostedUrl). The payment is taken by
 * QuickBooks Payments and reaches FleetForge through the Payment webhook,
 * which marks the invoice paid and posts FleetForge's own journal entry.
 * Every other case renders a short plain page that says what to do next.
 *
 * Trap 7: nothing internal (QBO ids, balances beyond what the customer's own
 * invoice shows, error text) is echoed to the visitor.
 *
 * @session S-QBO-GOLIVE-AUDIT
 */

require_once FF_ROOT . '/includes/auth.php';

use FleetForge\QboPushers\PayLink;
use FleetForge\QuickBooksClient;
use FleetForge\Security\RateLimiter;

$companyName  = (string) (settings_get('company.name') ?: 'FleetForge');
$companyEmail = (string) settings_get('company.email', '');
$companyPhone = (string) settings_get('company.phone', '');

/**
 * Render the plain customer-facing page and stop.
 */
function ff_pay_page(string $title, string $message, int $status = 200): never
{
    global $companyName, $companyEmail, $companyPhone;
    http_response_code($status);
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> — <?= e($companyName) ?></title>
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
</head>
<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:var(--bg-primary);">
    <main class="card" style="max-width:460px;width:100%;padding:28px;display:flex;flex-direction:column;gap:12px;">
        <div style="font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;color:var(--text-secondary);"><?= e($companyName) ?></div>
        <h1 class="h4" style="margin:0;"><?= e($title) ?></h1>
        <p style="margin:0;color:var(--text-secondary);"><?= e($message) ?></p>
        <?php if ($companyEmail !== '' || $companyPhone !== ''): ?>
            <p style="margin:0;font-size:.9rem;color:var(--text-secondary);">
                Questions? <?= e(trim($companyEmail . ($companyEmail !== '' && $companyPhone !== '' ? ' · ' : '') . $companyPhone)) ?>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
    <?php
    exit;
}

$invoiceId = (int) ($_GET['i'] ?? 0);
$token     = (string) ($_GET['t'] ?? '');

$rl = RateLimiter::check('pay:' . RateLimiter::getClientIp(), 30, 10, 10);
if (!$rl['allowed']) {
    ff_pay_page('Please try again shortly', 'Too many payment-link requests from your network. Please wait a few minutes and try again.', 429);
}

if (!PayLink::verify($invoiceId, $token)) {
    ff_pay_page('Link not valid', 'This payment link is not valid. Please use the link in your most recent invoice email, or contact us.', 404);
}

$invoice = db_row(
    "SELECT id, invoice_number, status, balance_due, deleted_at FROM invoices WHERE id = ?",
    [$invoiceId]
);
if (!$invoice || $invoice['deleted_at'] !== null || $invoice['status'] === 'void') {
    ff_pay_page('Invoice not available', 'This invoice is no longer available for payment. Please contact us if you believe this is a mistake.', 410);
}
if ($invoice['status'] === 'paid' || bccomp((string) $invoice['balance_due'], '0', 2) <= 0) {
    ff_pay_page('Already paid', "Invoice {$invoice['invoice_number']} has been paid. Thank you!");
}
if (!in_array($invoice['status'], ['sent', 'partially_paid', 'overdue'], true)) {
    ff_pay_page('Not ready for payment', "Invoice {$invoice['invoice_number']} is not ready for payment yet. Please contact us.");
}
if ((string) settings_get('quickbooks.payments_enabled', '0') !== '1'
    || (string) settings_get('quickbooks.connection_status', '') !== 'connected') {
    ff_pay_page('Online payment unavailable', 'Online payment is not available right now. Please contact us to arrange payment.', 503);
}

$map = db_row("SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?", [$invoiceId]);
if (!$map || empty($map['qbo_invoice_id'])) {
    // Sent moments ago — the QuickBooks copy (and its pay page) isn't there yet.
    ff_pay_page('Payment link almost ready', "Your payment page for invoice {$invoice['invoice_number']} is being prepared. Please try this link again in a few minutes.", 503);
}

try {
    $link = (new QuickBooksClient())->generatePaymentsHostedUrl((string) $map['qbo_invoice_id'], '', '');
} catch (\Throwable $e) {
    error_log("[pay.php] invoice {$invoiceId}: " . $e->getMessage());
    ff_pay_page('Online payment unavailable', 'We could not open the payment page just now. Please try again later, or contact us to arrange payment.', 503);
}

db_insert('audit_log', [
    'user_id'      => null,
    'user_name'    => 'customer',
    'action'       => 'view',
    'module'       => 'quickbooks',
    'entity_type'  => 'invoice',
    'entity_id'    => $invoiceId,
    'entity_label' => (string) $invoice['invoice_number'],
    'notes'        => 'Pay-online link opened from invoice email → QuickBooks payment page.',
    'ip_address'   => RateLimiter::getClientIp(),
]);

header('Cache-Control: no-store');
header('Location: ' . $link['url'], true, 302);
exit;
