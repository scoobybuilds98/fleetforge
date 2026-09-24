<?php
declare(strict_types=1);

/**
 * app/portal/payments/go.php
 *
 * Customer portal — "Pay" for one invoice (S-PORTAL-REDESIGN).
 * Route: GET /portal/payments/go?invoice=<id>
 *
 * Every Pay button in the portal is a plain link here, opened in a new tab
 * (target=_blank). On a payable invoice that is already in QuickBooks it
 * 302-redirects to QuickBooks' secure pay page (PaymentInitiator::generate —
 * which also records the initiation row the Payment webhook handshakes
 * with). Anything else renders a short, customer-safe page in the portal.
 *
 * WHY a GET redirect instead of the old fetch-then-navigate button: opening
 * the pay page in a NEW tab after an await is blocked as a pop-up; a real
 * link never is. The customer keeps the portal open in the first tab, where
 * the Pay drawer polls and ticks the invoice off as soon as the payment lands.
 *
 * Trap 8: the invoice must belong to portal_customer_id() (the initiator
 * re-checks the portal user owns it). Trap 7: status → pt_payment_error_message().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

use FleetForge\QboPushers\PaymentInitiator;

$cid       = portal_customer_id();
$invoiceId = clean_int($_GET['invoice'] ?? null);

$inv = $invoiceId ? db_row(
    "SELECT i.id, i.invoice_number, i.status, i.balance_due, i.currency, i.due_date
       FROM invoices i
      WHERE i.id = ? AND i.customer_id = ? AND " . pt_invoice_visible_sql('i'),
    [$invoiceId, $cid]
) : null;

$status = null;
if (!$inv) {
    $status = 'invoice_not_found';
} elseif (!in_array($inv['status'], ['sent', 'partially_paid', 'overdue'], true) || bccomp((string) $inv['balance_due'], '0', 2) <= 0) {
    $status = 'invoice_no_balance';
} else {
    $result = PaymentInitiator::generate((int) $inv['id'], (int) portal_user_id());

    try {
        db_insert('audit_log', [
            'user_id'      => null,
            'user_name'    => 'portal:' . portal_user_id(),
            'action'       => 'create',
            'module'       => 'portal',
            'entity_type'  => 'qbo_payment_initiation',
            'entity_id'    => (int) $inv['id'],
            'entity_label' => 'Pay click on invoice ' . $inv['invoice_number'],
            'notes'        => 'Result: ' . ($result['status'] ?? 'unknown')
                            . (!empty($result['error']) ? ' — ' . substr((string) $result['error'], 0, 200) : ''),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        error_log('[portal/payments/go audit] ' . $e->getMessage());
    }

    if (!empty($result['success']) && !empty($result['url'])) {
        header('Cache-Control: no-store');
        header('Location: ' . $result['url'], true, 302);
        exit;
    }
    $status = (string) ($result['status'] ?? 'qbo_error');
}

$pageTitle    = 'Payment';
$ptHideRibbon = true;
require_once dirname(__DIR__) . '/includes/header.php';

$paidAlready = $status === 'invoice_no_balance';
?>

<div style="max-width:560px;margin:6vh auto 0">
    <div class="pt-card">
        <div class="pt-card-body" style="padding:30px">
            <span class="pt-empty-ic" style="margin:0 0 16px;<?= $paidAlready ? 'background:var(--color-success-light);color:var(--color-success-text)' : '' ?>">
                <?= pt_icon($paidAlready ? 'check-circle' : 'information-circle') ?>
            </span>
            <h1 class="pt-title" style="font-size:22px"><?= $paidAlready ? 'Nothing to pay here' : 'We couldn\'t open the payment page' ?></h1>
            <p class="pt-sub"><?= e(pt_payment_error_message($status)) ?></p>
            <?php if ($inv): ?>
                <dl class="pt-kv" style="margin-top:18px">
                    <div class="pt-kv-row"><dt>Invoice</dt><dd><?= e($inv['invoice_number']) ?></dd></div>
                    <div class="pt-kv-row"><dt>Balance</dt><dd><?= e(pt_money($inv['balance_due'], (string) $inv['currency'])) ?></dd></div>
                </dl>
            <?php endif; ?>
            <div class="pt-btn-row" style="margin-top:22px">
                <a class="pt-btn pt-btn--primary" href="<?= e(pt_url('payments')) ?>"><?= pt_icon('credit-card') ?> Other ways to pay</a>
                <?php if ($inv): ?>
                    <a class="pt-btn pt-btn--secondary" href="<?= e(pt_url('invoices/view?id=' . (int) $inv['id'])) ?>">View invoice</a>
                <?php endif; ?>
                <button type="button" class="pt-btn pt-btn--ghost" onclick="window.close()">Close tab</button>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
