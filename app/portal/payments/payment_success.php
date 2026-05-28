<?php
declare(strict_types=1);

/**
 * app/portal/payments/payment_success.php
 *
 * Landing page after QBO Payments hosted-page checkout. QBO redirects
 * here with ?token=X carrying the initiation_token.
 *
 * Per QUICKBOOKS_SPEC.md §11.2 step 11 + D-QBO-15-6 (race handling):
 *   - Webhook may have already fired (status='completed') → show "Thank you"
 *     with invoice details
 *   - Webhook may NOT have fired yet (status='pending') → poll
 *     api/v1/portal/payments/status every 2s up to 30s, then time out
 *     gracefully with "We'll confirm shortly" message
 *
 * @session S-QBO-15
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$token = (string) ($_GET['token'] ?? '');
$initRow = null;
$invoice = null;

if ($token !== '') {
    $initRow = \FleetForge\QboPushers\PaymentInitiator::findByToken($token);
}

if ($initRow !== null) {
    $invoice = db_row(
        "SELECT id, invoice_number, total_amount, balance_due, currency, status
           FROM invoices
          WHERE id = ? AND customer_id = ?",
        [(int) $initRow['ff_invoice_id'], portal_customer_id()]
    );
}

$pageTitle = 'Payment Confirmation';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('portal')) ?>">Portal</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= e(base_url('portal/invoices')) ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Payment Confirmation</span>
</nav>

<?php if ($initRow === null): ?>
    <div class="card" style="padding:24px;text-align:center;">
        <h1>Payment Confirmation Unavailable</h1>
        <p>We couldn't find your payment initiation. If you completed a payment, please contact us with your QBO confirmation number.</p>
        <a href="<?= e(base_url('portal/invoices')) ?>" class="btn btn-primary" style="margin-top:14px;">Back to Invoices</a>
    </div>
<?php elseif ($invoice === null): ?>
    <div class="card" style="padding:24px;text-align:center;">
        <h1>Invoice Not Found</h1>
        <p>The invoice for this payment is no longer available.</p>
    </div>
<?php else: ?>

<div x-data="paymentSuccessPoller(<?= json_encode($token) ?>, <?= json_encode($initRow['status']) ?>)" x-init="init()" class="card" style="padding:32px;text-align:center;">

    <!-- Pending (waiting for webhook) -->
    <template x-if="status === 'pending' && !timedOut">
        <div>
            <div style="font-size:48px;line-height:1;margin-bottom:16px;">⏳</div>
            <h1 style="margin-bottom:8px;">Payment Processing</h1>
            <p class="text-secondary" style="margin-bottom:14px;">
                We're confirming your payment with QuickBooks. This usually takes 5-30 seconds.
            </p>
            <div class="text-sm text-secondary" style="margin-top:18px;">
                Polling for confirmation… (<span x-text="elapsedSeconds"></span>s)
            </div>
        </div>
    </template>

    <!-- Completed (webhook handshook) -->
    <template x-if="status === 'completed'">
        <div>
            <div style="font-size:48px;line-height:1;margin-bottom:16px;">✓</div>
            <h1 style="margin-bottom:8px;color:var(--color-success);">Payment Received</h1>
            <p style="margin-bottom:14px;">
                Thank you! Your payment for invoice <strong><?= e($invoice['invoice_number']) ?></strong> has been confirmed by QuickBooks.
            </p>
            <p class="text-secondary text-sm" style="margin-bottom:14px;">
                A receipt has been recorded against your account. The invoice will reflect the new status shortly.
            </p>
            <div style="display:flex;gap:12px;justify-content:center;margin-top:20px;">
                <a href="<?= e(base_url('portal/invoices/view?id=' . $invoice['id'])) ?>" class="btn btn-primary">View Invoice</a>
                <a href="<?= e(base_url('portal/invoices')) ?>" class="btn btn-secondary">All Invoices</a>
            </div>
        </div>
    </template>

    <!-- Timed out (webhook hasn't fired in 30s — uncommon but possible) -->
    <template x-if="status === 'pending' && timedOut">
        <div>
            <div style="font-size:48px;line-height:1;margin-bottom:16px;">⏱</div>
            <h1 style="margin-bottom:8px;">We'll Confirm Shortly</h1>
            <p style="margin-bottom:14px;">
                Your payment is processing. We haven't received final confirmation from QuickBooks yet, but this can sometimes take a few minutes.
            </p>
            <p class="text-secondary text-sm" style="margin-bottom:14px;">
                You'll see the updated invoice status the next time you refresh. If the invoice doesn't update within an hour, please contact us with your QBO confirmation number.
            </p>
            <a href="<?= e(base_url('portal/invoices/view?id=' . $invoice['id'])) ?>" class="btn btn-secondary">View Invoice</a>
        </div>
    </template>

    <!-- Cancelled or failed -->
    <template x-if="['cancelled','failed','expired'].includes(status)">
        <div>
            <div style="font-size:48px;line-height:1;margin-bottom:16px;">⚠</div>
            <h1 style="margin-bottom:8px;">Payment Not Completed</h1>
            <p class="text-secondary" style="margin-bottom:14px;">
                Your payment was <span x-text="status"></span>. The invoice balance is unchanged.
            </p>
            <a href="<?= e(base_url('portal/invoices/view?id=' . $invoice['id'])) ?>" class="btn btn-primary">Back to Invoice</a>
        </div>
    </template>
</div>

<script>
function paymentSuccessPoller(token, initialStatus) {
    return {
        status: initialStatus,
        elapsedSeconds: 0,
        timedOut: false,
        pollTimer: null,

        init() {
            if (this.status === 'pending') {
                this.startPolling();
            }
        },

        startPolling() {
            const startTime = Date.now();
            const maxWait = 30000;  // 30s timeout per D-QBO-15-6

            this.pollTimer = setInterval(async () => {
                const elapsed = Date.now() - startTime;
                this.elapsedSeconds = Math.floor(elapsed / 1000);

                if (elapsed >= maxWait) {
                    this.timedOut = true;
                    clearInterval(this.pollTimer);
                    return;
                }

                try {
                    const r = await FF_Api.get('<?= base_url('api/v1/portal/payments/status') ?>?token=' + encodeURIComponent(this.token));
                    if (r.success && r.status !== 'pending') {
                        this.status = r.status;
                        clearInterval(this.pollTimer);
                    }
                } catch (e) {
                    // Transient — continue polling.
                }
            }, 2000);
        },
    };
}
</script>

<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
