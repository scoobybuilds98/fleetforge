<?php
declare(strict_types=1);

/**
 * app/portal/payments/payment_cancel.php
 *
 * Return page when a customer backs out of a QuickBooks payment
 * (?token=<initiation_token>) (S-QBO-15; rebuilt S-PORTAL-REDESIGN).
 * Marks the pending initiation cancelled and offers the ways to pay.
 *
 * Fixed here: the initiation was marked cancelled BEFORE checking it
 * belonged to this customer. The ownership check now comes first (Trap 8).
 *
 * @session S-QBO-15, S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

use FleetForge\QboPushers\PaymentInitiator;

$token   = (string) ($_GET['token'] ?? '');
$initRow = $token !== '' ? PaymentInitiator::findByToken($token) : null;
$invoice = $initRow ? db_row(
    "SELECT id, invoice_number, balance_due, currency, status
       FROM invoices WHERE id = ? AND customer_id = ? AND deleted_at IS NULL",
    [(int) $initRow['ff_invoice_id'], portal_customer_id()]
) : null;
if ($invoice) {
    PaymentInitiator::markCancelled($token);
}

$pageTitle    = 'Payment cancelled';
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:560px;margin:5vh auto 0">
    <div class="pt-card"><div class="pt-card-body" style="padding:32px;text-align:center">
        <span class="pt-empty-ic" style="margin:0 auto 14px"><?= pt_icon('x-circle') ?></span>
        <h1 class="pt-title" style="font-size:24px">Payment cancelled</h1>
        <p class="pt-sub" style="margin:8px auto 0">No payment was taken<?= $invoice ? ' for invoice ' . e($invoice['invoice_number']) : '' ?>.<?= $invoice && bccomp((string) $invoice['balance_due'], '0', 2) > 0 ? ' ' . e(pt_money($invoice['balance_due'], (string) $invoice['currency'])) . ' is still due.' : '' ?></p>
        <div class="pt-btn-row" style="justify-content:center;margin-top:22px">
            <?php if ($invoice): ?><a class="pt-btn pt-btn--primary" href="<?= e(pt_url('invoices/view?id=' . (int) $invoice['id'])) ?>">Back to the invoice</a><?php endif; ?>
            <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url('payments')) ?>">Other ways to pay</a>
        </div>
    </div></div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
