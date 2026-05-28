<?php
declare(strict_types=1);

/**
 * app/portal/payments/payment_cancel.php
 *
 * Landing page after customer cancels at QBO Payments hosted page.
 * QBO redirects here with ?token=X carrying the initiation_token.
 *
 * Marks the initiation row status='cancelled' (idempotent) so admin
 * visibility shows the cancellation in /quickbooks/payments.
 *
 * @session S-QBO-15
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$token = (string) ($_GET['token'] ?? '');
$invoice = null;

if ($token !== '') {
    \FleetForge\QboPushers\PaymentInitiator::markCancelled($token);
    $initRow = \FleetForge\QboPushers\PaymentInitiator::findByToken($token);
    if ($initRow !== null) {
        $invoice = db_row(
            "SELECT id, invoice_number, balance_due, currency, status
               FROM invoices
              WHERE id = ? AND customer_id = ?",
            [(int) $initRow['ff_invoice_id'], portal_customer_id()]
        );
    }
}

$pageTitle = 'Payment Cancelled';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('portal')) ?>">Portal</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= e(base_url('portal/invoices')) ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Payment Cancelled</span>
</nav>

<div class="card" style="padding:32px;text-align:center;">
    <div style="font-size:48px;line-height:1;margin-bottom:16px;">↩</div>
    <h1 style="margin-bottom:8px;">Payment Cancelled</h1>
    <p class="text-secondary" style="margin-bottom:14px;">
        Your payment was cancelled and the invoice balance is unchanged.
    </p>
    <?php if ($invoice): ?>
        <p style="margin-bottom:14px;">
            Invoice <strong><?= e($invoice['invoice_number']) ?></strong> still has a balance due of <?= e(format_currency($invoice['balance_due'])) ?>.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;margin-top:20px;">
            <a href="<?= e(base_url('portal/invoices/view?id=' . $invoice['id'])) ?>" class="btn btn-primary">View Invoice</a>
            <a href="<?= e(base_url('portal/invoices')) ?>" class="btn btn-secondary">All Invoices</a>
        </div>
    <?php else: ?>
        <a href="<?= e(base_url('portal/invoices')) ?>" class="btn btn-primary" style="margin-top:14px;">Back to Invoices</a>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
