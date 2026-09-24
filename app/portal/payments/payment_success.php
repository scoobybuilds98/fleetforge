<?php
declare(strict_types=1);

/**
 * app/portal/payments/payment_success.php
 *
 * Return page after a QuickBooks payment (?token=<initiation_token>)
 * (S-QBO-15; rebuilt S-PORTAL-REDESIGN).
 *
 * The Payment webhook may land before or after the customer does
 * (D-QBO-15-6), so a pending initiation is polled via
 * api/v1/portal/payments/status every 3 s for up to a minute, then settles
 * on "we'll confirm shortly".
 *
 * Fixed here: the old poller never worked — it sent `token=undefined` (the
 * token was never stored on the component) and read `r.status` instead of
 * `r.data.status`, so every pending payment ended on the timeout message.
 * Also: the invoice lookup now excludes deleted invoices.
 *
 * Note: QuickBooks' hosted invoice page does not redirect back
 * (QuickBooksClient::generatePaymentsHostedUrl), so most customers never
 * reach this page — the portal's Pay drawer polls instead.
 *
 * Trap 8: the initiation's invoice must belong to portal_customer_id().
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
    "SELECT id, invoice_number, total_amount, balance_due, currency, status
       FROM invoices WHERE id = ? AND customer_id = ? AND deleted_at IS NULL",
    [(int) $initRow['ff_invoice_id'], portal_customer_id()]
) : null;

$pageTitle    = 'Payment';
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div style="max-width:560px;margin:5vh auto 0">
<?php if (!$initRow || !$invoice): ?>
    <div class="pt-card"><div class="pt-card-body" style="padding:30px">
        <?= pt_empty('information-circle', 'We couldn\'t match that payment', 'If you completed a payment, it will show on your account as soon as QuickBooks confirms it. Contact us with your confirmation number if anything looks wrong.', '<a class="pt-btn pt-btn--primary pt-btn--sm" href="' . e(pt_url('payments')) . '">Go to Pay &amp; payments</a>') ?>
    </div></div>
<?php else: ?>
    <div class="pt-card" x-data="{
            status: <?= e(json_encode((string) $initRow['status'])) ?>,
            token: <?= e(json_encode($token)) ?>,
            tries: 0,
            init() { if (this.status === 'pending') this.poll(); },
            async poll() {
                while (this.status === 'pending' && this.tries < 20) {
                    await new Promise(r => setTimeout(r, 3000));
                    this.tries++;
                    const r = await PT.get('api/v1/portal/payments/status?token=' + encodeURIComponent(this.token));
                    if (r && r.success && r.data && r.data.status) this.status = r.data.status;
                }
                if (this.status === 'pending') this.status = 'timeout';
            }
        }">
        <div class="pt-card-body" style="padding:32px;text-align:center">
            <template x-if="status === 'completed'">
                <div>
                    <span class="pt-empty-ic" style="margin:0 auto 14px;background:var(--color-success-light);color:var(--color-success-text)"><?= pt_icon('check-circle') ?></span>
                    <h1 class="pt-title" style="font-size:24px">Payment received — thank you!</h1>
                    <p class="pt-sub" style="margin:8px auto 0">Invoice <?= e($invoice['invoice_number']) ?> has been updated on your account.</p>
                </div>
            </template>
            <template x-if="status === 'pending'">
                <div>
                    <span class="pt-empty-ic" style="margin:0 auto 14px"><span class="pt-spin" style="width:24px;height:24px"></span></span>
                    <h1 class="pt-title" style="font-size:24px">Confirming your payment…</h1>
                    <p class="pt-sub" style="margin:8px auto 0">We're waiting for QuickBooks to confirm the payment on invoice <?= e($invoice['invoice_number']) ?>. This usually takes a few seconds.</p>
                </div>
            </template>
            <template x-if="status === 'timeout'">
                <div>
                    <span class="pt-empty-ic" style="margin:0 auto 14px"><?= pt_icon('clock') ?></span>
                    <h1 class="pt-title" style="font-size:24px">We'll confirm shortly</h1>
                    <p class="pt-sub" style="margin:8px auto 0">Your payment is being processed. Invoice <?= e($invoice['invoice_number']) ?> will update automatically once QuickBooks confirms it — there's no need to pay again.</p>
                </div>
            </template>
            <template x-if="['cancelled','failed','expired'].includes(status)">
                <div>
                    <span class="pt-empty-ic" style="margin:0 auto 14px;background:var(--color-warning-light);color:var(--color-warning-text)"><?= pt_icon('exclamation-triangle') ?></span>
                    <h1 class="pt-title" style="font-size:24px">Payment not completed</h1>
                    <p class="pt-sub" style="margin:8px auto 0">No payment was taken for invoice <?= e($invoice['invoice_number']) ?>. You can try again or pay another way.</p>
                </div>
            </template>
            <div class="pt-btn-row" style="justify-content:center;margin-top:22px">
                <a class="pt-btn pt-btn--primary" href="<?= e(pt_url('invoices/view?id=' . (int) $invoice['id'])) ?>">View invoice</a>
                <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url('payments')) ?>">Pay &amp; payments</a>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
