<?php
declare(strict_types=1);

/**
 * app/admin/payments/show.php
 *
 * Payment detail page (S-RECORD-REDESIGN layout):
 *   - Entity hero: payment number + status (+ "From QuickBooks" when the
 *     payment is QuickBooks' record), fact chips (customer, received date,
 *     method, who recorded it). Send Receipt is the one visible action; Edit
 *     notes / AI Analysis / Void-Remove live in the More menu. The page's
 *     Alpine component (FF_PaymentActions) opens ABOVE the hero so those
 *     header buttons drive the edit card + remove modal.
 *   - Key-numbers strip: amount received, applied to invoices, unapplied
 *     (warn/danger when money is sitting on no invoice), invoices touched,
 *     received date.
 *   - Main column: inline edit card (toggled from the header), Invoice
 *     Allocations (with Move — SOP I17 — and a Total applied footer), Notes,
 *     the QuickBooks Sync panel, Activity.
 *   - Rail: needs attention (unapplied money, unresolved overpayment,
 *     pending / failed / returned / void / refunded, QuickBooks push failure),
 *     where the money went (applied meter + account-credit note + refund),
 *     customer, payment facts (method, reference, cheque, bank, deposit /
 *     cleared dates, verification), QuickBooks status summary.
 *
 * The page is reachable only with payments:view, which IS the financial
 * predicate (can_view_financials() === can('payments','view')), so every figure
 * here is already money-gated; the internal-notes (payments:edit) and
 * credit-note (invoices:view) gates are kept.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.8 Payments
 * @decisions D30 (asset_url), D32 (CSS classes verified), D5/D13 (soft-delete filter)
 * @session  S009 → S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('payments', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(400);
    die('Missing payment id.');
}

// --- Load payment with customer name ---
$payment = db_row(
    "SELECT
        p.id, p.payment_number, p.customer_id, c.company_name,
        p.amount, p.currency, p.exchange_rate_to_cad, p.amount_in_cad,
        p.payment_method, p.reference_number, p.bank_name,
        p.check_number, p.card_last_four,
        p.payment_date, p.received_at, p.deposited_date, p.cleared_date,
        p.status, p.failure_reason, p.returned_reason, p.returned_date,
        p.overpayment_amount, p.overpayment_action, p.overpayment_resolved,
        p.refund_amount, p.refund_date, p.refund_method, p.refund_reference,
        p.notes, p.internal_notes,
        p.recorded_by, ru.name AS recorded_by_name,
        p.verified_by, vu.name AS verified_by_name, p.verified_at,
        p.created_at, p.updated_at,
        p.origin, p.deposit_bank_account_id, dba.name AS deposit_bank_name
     FROM payments p
     LEFT JOIN acc_bank_accounts dba ON dba.id = p.deposit_bank_account_id
     LEFT JOIN customers c ON c.id = p.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users ru ON ru.id = p.recorded_by
     LEFT JOIN users vu ON vu.id = p.verified_by
     WHERE p.id = ? AND p.deleted_at IS NULL",
    [$id]
);

if (!$payment) {
    http_response_code(404);
    $pageTitle = 'Payment Not Found';
    require_once FF_ROOT . '/includes/header.php';
    echo '<div class="empty-state"><p class="empty-state-title">Payment not found.</p></div>';
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// --- Load allocation detail ---
$allocations = db_select(
    "SELECT pa.id, pa.invoice_id, i.invoice_number, i.status AS invoice_status,
            i.billing_period_start, i.billing_period_end,
            l.actual_return_date, l.actual_return_time, l.start_time, l.billing_days_removed,
            i.total_amount AS invoice_total, i.balance_due AS invoice_balance_due,
            pa.amount, pa.currency, pa.allocation_type, pa.created_at
     FROM payment_allocations pa
     JOIN invoices i ON i.id = pa.invoice_id
     LEFT JOIN leases l ON l.id = i.lease_id
     WHERE pa.payment_id = ?
     ORDER BY pa.created_at ASC",
    [$id]
);

// --- Method label map ---
$methodLabels = [
    'check'          => 'Cheque',
    'ach'            => 'ACH / Direct Deposit',
    'wire'           => 'Wire Transfer',
    'credit_card'    => 'Credit Card',
    'cash'           => 'Cash',
    'e_transfer'     => 'e-Transfer',
    'account_credit' => 'Account Credit',
    'other'          => 'Other',
];

// --- Status badge class map ---
$statusBadge = match($payment['status']) {
    'pending'  => 'badge-info',
    'cleared'  => 'badge-success',
    'failed'   => 'badge-danger',
    'refunded' => 'badge-warning',
    'void'     => 'badge-neutral',
    'returned' => 'badge-warning',
    default    => 'badge-neutral',
};

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────
$today      = ff_today();
$methodName = $methodLabels[$payment['payment_method']] ?? (string) $payment['payment_method'];
$isQboOwned = in_array($payment['origin'] ?? 'ff_native', ['qbo_payments_webhook', 'qbo_other'], true);
// Money that should be sitting on invoices: a void / refunded / failed /
// returned payment has no "unapplied" balance to chase.
$isLive     = in_array($payment['status'], ['pending', 'cleared'], true);

$allocTotal   = '0.00';
$allocPaidCnt = 0;
foreach ($allocations as $_a) {
    $allocTotal = bcadd($allocTotal, (string) $_a['amount'], 2);
    if ($_a['invoice_status'] === 'paid') {
        $allocPaidCnt++;
    }
}
unset($_a);

// Overpayment excess that payments/create.php converted into an account-credit
// note (credit_notes.source_payment_id). Same hold rule as
// api/v1/payments/allocate.php: every non-void note counts, spent or not — the
// money lives on the note, so it is NOT unapplied.
$cnHold = (string) (db_row(
    "SELECT COALESCE(SUM(amount), 0) AS s FROM credit_notes
      WHERE source_payment_id = ? AND status <> 'void' AND voided_at IS NULL AND deleted_at IS NULL",
    [$id]
)['s'] ?? '0');
$refunded  = (string) ($payment['refund_amount'] ?? '0');
// Unapplied = received − on invoices − held as account credit − paid back.
$unapplied = bcsub(bcsub(bcsub((string) $payment['amount'], $allocTotal, 2), $cnHold, 2), $refunded, 2);
$hasUnapplied = $isLive && bccomp($unapplied, '0', 2) > 0;

$daysSince = null;
if (!empty($payment['payment_date'])) {
    try {
        $daysSince = (int) (new DateTimeImmutable((string) $payment['payment_date']))->diff(new DateTimeImmutable($today))->format('%r%a');
    } catch (Throwable) {
        $daysSince = null;
    }
}
$daysSinceLabel = $daysSince === null ? ''
    : ($daysSince === 0 ? 'today' : ($daysSince > 0 ? $daysSince . ' day' . ($daysSince === 1 ? '' : 's') . ' ago' : 'in ' . (-$daysSince) . ' days'));
// Money on no invoice for over a month is stale — the strip turns red.
$unappliedStale = $hasUnapplied && $daysSince !== null && $daysSince > 30;

// Where unapplied money could go: this customer's open invoices in the
// payment's currency (count + total owing). Only asked when there IS money.
$openInv = ['cnt' => 0, 'owing' => '0'];
if ($hasUnapplied && !empty($payment['customer_id'])) {
    $openInv = db_row(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due), 0) AS owing FROM invoices
          WHERE customer_id = ? AND currency = ? AND deleted_at IS NULL
            AND status IN ('sent', 'partially_paid', 'overdue') AND balance_due > 0",
        [(int) $payment['customer_id'], (string) $payment['currency']]
    ) ?: $openInv;
}

// Account-credit notes minted from this payment's overpayment (linked in the
// rail). Gated on the credit-notes module's own permission (invoices:view).
$overpaymentCns = can('invoices', 'view')
    ? db_select(
        "SELECT id, credit_note_number, amount_remaining, currency, status
         FROM credit_notes
         WHERE source_payment_id = ? AND deleted_at IS NULL
         ORDER BY id ASC",
        [$id]
    )
    : [];

// QuickBooks status for the rail summary — the full panel stays in the main
// column. Same 6-state labels as includes/partials/qbo-sync-panel.php.
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboMap = $qboConnected
    ? db_row("SELECT push_status, qbo_payment_id, pushed_at FROM acc_qbo_payment_map WHERE ff_payment_id = ? LIMIT 1", [$id])
    : null;

$pageTitle      = 'Payment ' . $payment['payment_number'];
$helpModuleSlug = 'payments';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// ============================================================
// Page header — entity hero (S-RECORD-REDESIGN). Send Receipt is the
// visible action; Edit / AI / Void-Remove sit in the More menu. The
// buttons are the page's own markup, captured unchanged.
// ============================================================
ob_start(); ?>
        <span class="badge badge-no-dot <?= $statusBadge ?>"><?= e(ucfirst((string) $payment['status'])) ?></span>
        <?php if ($isQboOwned): ?>
        <span class="badge badge-no-dot badge-info" title="Mirrored from QuickBooks — apply or change it in QuickBooks; FleetForge follows.">From QuickBooks</span>
        <?php endif; ?>
        <?php if ($payment['currency'] !== 'CAD'): ?>
        <span class="badge badge-no-dot badge-warning"><?= e($payment['currency']) ?></span>
        <?php endif; ?>
<?php $heroBadges = ob_get_clean(); ?>
<?php ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if (can('payments', 'edit')): ?>
        <!-- Edit metadata — opens the inline edit card at the top of the page -->
        <button type="button" class="btn btn-secondary btn-sm"
                @click="showEdit = true; $nextTick(() => { const el = document.getElementById('pay-edit'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })">
            <?= heroicon('pencil', 'icon-sm') ?>
            Edit Notes / Reference
        </button>
        <?php endif; ?>
        <?php if (function_exists('can') && can('ai', 'view') && (bool)settings_get('ai.enabled', false) && (settings_get('ai.anthropic_api_key') ?: env('AI_ANTHROPIC_API_KEY', ''))): ?>
        <button type="button" class="btn btn-secondary btn-sm no-print"
                onclick="aiPanel_payment_<?= (int)$id ?>_payment_summary_open()"
                title="Open AI Payment Summary">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;" aria-hidden="true">
                <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" fill="currentColor"/>
            </svg>
            AI Analysis
        </button>
        <?php endif; ?>
        <?php if (can('payments', 'edit') && can('payments', 'delete')): ?>
        <hr>
        <button type="button" class="btn btn-danger btn-sm" @click="showDelete = true">
            <?= heroicon('trash', 'icon-sm') ?>
            Void / Remove Payment
        </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('payments') ?>
        <?php if ($payment['customer_id'] && can('customers', 'create')): /* EMAIL-1: send receipt */ ?>
        <button type="button"
                class="btn btn-primary btn-sm"
                onclick="openEmailCompose({
                    customerId:   <?= (int)$payment['customer_id'] ?>,
                    templateSlug: 'payment_received',
                    entityType:   'payment',
                    entityId:     <?= (int)$payment['id'] ?>
                })"
                title="Send payment receipt to customer">
            <?= heroicon('envelope', 'btn-icon') ?>
            Send Receipt
        </button>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [];
if ($payment['customer_id']) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group')
        . '<a href="' . e(base_url('customers/show')) . '?id=' . (int) $payment['customer_id'] . '" style="color:inherit;">' . e($payment['company_name'] ?? 'Customer') . '</a>';
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Received ' . e(format_date($payment['payment_date']));
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('credit-card') . e($methodName)
    . (!empty($payment['check_number']) ? ' #' . e($payment['check_number']) : '')
    . (!empty($payment['card_last_four']) ? ' •••• ' . e($payment['card_last_four']) : '');
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Recorded ' . e(format_datetime($payment['created_at']))
    . ($payment['recorded_by_name'] ? ' by ' . e($payment['recorded_by_name']) : '');
$heroMark = (string) $payment['payment_number'];
if (preg_match('/(\d{3,})$/', $heroMark, $hm)) { $heroMark = $hm[1]; }
?>
<!-- ============================================================
     PAYMENT DETAIL — Alpine component. Opens ABOVE the hero
     (S-RECORD-REDESIGN) so the header's Edit / Void-Remove buttons
     drive the edit card and the remove modal below.
     ============================================================ -->
<div x-data="FF_PaymentActions()">
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'success',
    'icon'       => 'banknotes',
    'mark'       => $heroMark,
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Payments', base_url('payments')], [(string) $payment['payment_number'], null]],
    'eyebrow'    => 'Payment',
    'title_html' => e($payment['payment_number']) . ' ' . $heroBadges,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN). What came in,
     how much of it is on invoices, and what is still sitting on no
     invoice lead. Method / Customer tiles are gone (identity, not
     numbers — they are in the header facts and the rail).
     ============================================================ -->
<div class="stat-grid ff-stats">
    <?php
    $_amtTag = $payment['customer_id'] && can('customers', 'view')
        ? 'a href="' . e(base_url('customers/show')) . '?id=' . (int) $payment['customer_id'] . '#payments" title="All payments from this customer"'
        : 'div';
    ?>
    <<?= $_amtTag ?> class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Amount received</div>
        <div class="stat-value font-mono"><?= format_currency($payment['amount']) ?></div>
        <div class="stat-delta"><?php
            if (bccomp($refunded, '0', 2) > 0) {
                echo e(format_currency($refunded)) . ' refunded';
            } elseif ($payment['amount_in_cad'] && $payment['currency'] !== 'CAD') {
                echo '≈ ' . e(format_currency($payment['amount_in_cad'])) . ' CAD';
            } else {
                echo e($payment['currency']);
            }
        ?></div>
    </<?= $_amtTag === 'div' ? 'div' : 'a' ?>>

    <a class="stat-card stat-card--green" href="#pay-allocations" title="Share of this payment applied to invoices">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Applied to invoices</div>
        <div class="stat-value font-mono"><?= format_currency($allocTotal) ?></div>
        <div class="stat-delta"><?php
            $_pct = bccomp((string) $payment['amount'], '0', 2) > 0
                ? (int) round((float) bcmul(bcdiv($allocTotal, (string) $payment['amount'], 6), '100', 2))
                : 0;
            echo $allocations ? $_pct . '%' : 'none';
        ?></div>
    </a>

    <?php
    $_unTone = !$isLive ? 'slate' : ($hasUnapplied ? ($unappliedStale ? 'red' : 'amber') : 'green');
    ?>
    <div class="stat-card stat-card--<?= $_unTone ?>" title="Money received that is on no invoice, not held as account credit and not refunded<?= bccomp($cnHold, '0', 2) > 0 ? e(' — ' . format_currency($cnHold) . ' of it is account credit') : '' ?>">
        <span class="stat-icon stat-icon--<?= $_unTone ?>"><svg><use href="#icon-<?= $hasUnapplied ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Unapplied</div>
        <?php if ($isLive): ?>
        <div class="stat-value font-mono"<?= $unappliedStale ? ' style="color:var(--color-danger);"' : '' ?>><?= format_currency(bccomp($unapplied, '0', 2) > 0 ? $unapplied : '0.00') ?></div>
        <div class="stat-delta"><?php
            if ($hasUnapplied) {
                echo $isQboOwned ? 'apply in QuickBooks' : 'on no invoice';
            } elseif (bccomp($cnHold, '0', 2) > 0) {
                echo 'rest is credit';
            } else {
                echo 'all applied';
            }
        ?></div>
        <?php else: ?>
        <div class="stat-value">—</div>
        <div class="stat-delta">payment <?= e((string) $payment['status']) ?></div>
        <?php endif; ?>
    </div>

    <a class="stat-card stat-card--purple" href="#pay-allocations" title="Invoices this payment touches">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Invoices</div>
        <div class="stat-value font-mono"><?= count($allocations) ?></div>
        <div class="stat-delta"><?php
            if (!$allocations) {
                echo 'none yet';
            } else {
                $_owing = count($allocations) - $allocPaidCnt;
                echo $allocPaidCnt . ' paid in full' . ($_owing > 0 ? ' · ' . $_owing . ' still owing' : '');
            }
        ?></div>
    </a>

    <div class="stat-card stat-card--slate" title="Received <?= e(format_date($payment['payment_date'])) ?><?= $daysSinceLabel !== '' ? ' (' . e($daysSinceLabel) . ')' : '' ?><?= $payment['cleared_date'] ? ' · cleared ' . e(format_date($payment['cleared_date'])) : '' ?>">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Received</div>
        <div class="stat-value stat-value--date font-mono"><?= format_date($payment['payment_date']) ?></div>
        <div class="stat-delta"><?= e($daysSinceLabel) ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

<?php if (can('payments', 'edit')): ?>
<!-- ============================================================
     Edit payment notes — opened from the header's More menu
     (was the "Actions" card's inline form)
     ============================================================ -->
<div class="card" id="pay-edit" x-show="showEdit" x-cloak style="margin-bottom:14px; scroll-margin-top:80px;">
    <div class="card-header">
        <h3 class="card-title">Edit payment notes</h3>
        <button type="button" class="btn btn-ghost btn-xs" @click="showEdit = false" aria-label="Close">&times;</button>
    </div>
    <div class="card-body">
        <div class="grid-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">Reference Number</label>
                <input type="text" class="form-input" x-model="editForm.reference_number" maxlength="100">
            </div>
            <div>
                <label class="form-label">Bank Name</label>
                <input type="text" class="form-input" x-model="editForm.bank_name" maxlength="100">
            </div>
            <div>
                <label class="form-label">Public Notes</label>
                <textarea class="form-input" rows="3" x-model="editForm.notes" maxlength="2000"></textarea>
            </div>
            <div>
                <label class="form-label">Internal Notes</label>
                <textarea class="form-input" rows="3" x-model="editForm.internal_notes" maxlength="2000"></textarea>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-primary btn-sm" @click="saveEdit()" :disabled="saving">
                <span x-text="saving ? 'Saving…' : 'Save Changes'"></span>
            </button>
            <button class="btn btn-secondary btn-sm" @click="showEdit = false">Cancel</button>
        </div>
        <p x-show="editError" x-text="editError" style="color:var(--color-danger); margin-top:8px;"></p>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     Allocation table
     ============================================================ -->
<?php
// SOP I17: an allocation can be moved to another open invoice of the same
// customer (api/v1/payments/reallocate.php). Not for QuickBooks-owned
// payments (re-applied in QuickBooks) or void/refunded ones.
$canMoveAlloc = can('payments', 'edit')
    && !in_array($payment['origin'] ?? 'ff_native', ['qbo_payments_webhook', 'qbo_other'], true)
    && !in_array($payment['status'] ?? '', ['void', 'refunded', 'failed', 'returned'], true);
?>
<div class="card" id="pay-allocations" style="margin-bottom:14px; scroll-margin-top:80px;" x-data="FF_PaymentMove()">
    <div class="card-header">
        <h3 class="card-title">Invoice Allocations</h3>
        <span class="badge badge-neutral"><?= count($allocations) ?> allocation<?= count($allocations) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body" style="padding:0; overflow-x:auto;">
        <?php if (empty($allocations)): ?>
            <div class="empty-state" style="padding:32px;">
                <p class="empty-state-title">No allocations</p>
                <p class="empty-state-text">This payment has not been applied to any invoice yet.<?= $hasUnapplied && $isQboOwned ? ' It came from QuickBooks — apply it to the invoice there and FleetForge will follow.' : '' ?></p>
            </div>
        <?php else: ?>
            <table class="table" style="width:100%;">
                <thead>
                    <?php /* S-RECORD-REDESIGN: short headers + the invoice status under the
                             number, so the table fits the main column beside the rail. */ ?>
                    <tr>
                        <th>Invoice</th>
                        <th>Billing Period</th>
                        <th style="text-align:right;">Total</th>
                        <th style="text-align:right;">Applied</th>
                        <th style="text-align:right;">Balance Left</th>
                        <th>Allocated</th>
                        <?php if ($canMoveAlloc): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allocations as $alloc): ?>
                        <?php
                        $invBadge = match($alloc['invoice_status']) {
                            'paid'           => 'badge-success',
                            'partially_paid' => 'badge-warning',
                            'sent'           => 'badge-info',
                            'overdue'        => 'badge-danger',
                            'void'           => 'badge-neutral',
                            default          => 'badge-neutral',
                        };
                        ?>
                        <tr>
                            <td>
                                <a href="<?= base_url('/invoices/show') ?>?id=<?= (int) $alloc['invoice_id'] ?>"
                                   class="link font-mono">
                                    <?= e($alloc['invoice_number']) ?>
                                </a>
                                <div style="margin-top:3px;"><span class="badge <?= $invBadge ?>"><?= e(str_replace('_', ' ', (string) $alloc['invoice_status'])) ?></span></div>
                            </td>
                            <td style="font-size:0.85rem; white-space:normal;">
                                <?= format_date($alloc['billing_period_start']) ?>
                                – <?= format_date(ff_invoice_display_period_end($alloc)) ?>
                            </td>
                            <td class="font-mono" style="text-align:right;">
                                <?= format_currency($alloc['invoice_total']) ?>
                            </td>
                            <td class="font-mono" style="text-align:right; color:var(--color-success);">
                                <?= format_currency($alloc['amount']) ?>
                            </td>
                            <td class="font-mono" style="text-align:right;">
                                <?= format_currency($alloc['invoice_balance_due']) ?>
                            </td>
                            <?php /* S-RECORD-REDESIGN: the Type column (auto / manual) folded into
                                     Allocated so the table fits beside the rail; full time on hover. */ ?>
                            <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;"
                                title="<?= e(format_datetime($alloc['created_at'])) ?>">
                                <?= format_date(format_datetime($alloc['created_at'], 'Y-m-d')) ?>
                                <div style="font-size:0.72rem;"><?= e($alloc['allocation_type']) ?></div>
                            </td>
                            <?php if ($canMoveAlloc): ?>
                            <td>
                                <?php if (!in_array($alloc['invoice_status'], ['void', 'written_off'], true)): ?>
                                <button class="btn btn-secondary btn-xs"
                                        @click="openMove(<?= (int) $alloc['id'] ?>, <?= e(json_encode($alloc['invoice_number'])) ?>, <?= e(json_encode((string) $alloc['amount'])) ?>)">Move</button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php /* S-RECORD-REDESIGN: the applied total, so it reconciles to the strip at a glance. */ ?>
                <tfoot>
                    <tr>
                        <th colspan="3">Total applied</th>
                        <th class="font-mono" style="text-align:right;"><?= format_currency($allocTotal) ?></th>
                        <th colspan="<?= $canMoveAlloc ? 3 : 2 ?>"></th>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($canMoveAlloc): ?>
    <template x-if="moveAllocId">
        <div class="modal-backdrop" @click.self="moveAllocId = null">
            <div class="modal modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">Move payment to another invoice</h3>
                    <button class="modal-close-btn" aria-label="Close" @click="moveAllocId = null">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-sm text-secondary" style="margin-bottom:12px;">
                        Moves money from <strong x-text="moveFrom"></strong> to another open invoice of this customer.
                        No journal entry: both invoices are in the same receivable. QuickBooks is updated.
                    </p>
                    <label class="form-label">To invoice</label>
                    <?php
                    $pickerName   = 'movePicker';
                    $pickerConfig = [
                        'endpoint'    => '/api/v1/invoices/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 15,
                        'extraParams' => 'customer_id=' . (int) $payment['customer_id']
                                       . '&statuses=sent,partially_paid,overdue&sort=due_date&dir=ASC',
                        'placeholder' => 'Search this customer’s open invoices…',
                        'mapResult'   => "r => ({ id: r.id, label: r.invoice_number, sublabel: [r.currency + ' ' + Number(r.balance_due || 0).toFixed(2) + ' due', r.due_date ? ('due ' + r.due_date) : ''].filter(Boolean).join(' · '), raw: r })",
                    ];
                    $pickerOnPicked  = 'moveTarget = $event.detail.raw';
                    $pickerOnCleared = 'moveTarget = null';
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                    <label class="form-label" style="margin-top:10px;">Amount</label>
                    <input type="text" class="form-input font-mono" x-model="moveAmount">
                    <p x-show="moveError" x-text="moveError" style="color:var(--color-danger); margin-top:8px;"></p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" @click="moveAllocId = null">Cancel</button>
                    <button class="btn btn-primary btn-sm" @click="submitMove()" :disabled="moving || !moveTarget">
                        <span x-text="moving ? 'Moving…' : 'Move'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
    <?php endif; ?>
</div>

<?php
// ── Notes (was part of the "Financial Notes" card; the overpayment, refund
// and verification rows moved to the rail). Internal notes stay payments:edit.
$_showInternal = $payment['internal_notes'] && can('payments', 'edit');
if ($payment['notes'] || $_showInternal): ?>
<div class="card" style="margin-bottom:14px;">
    <div class="card-header"><h3 class="card-title">Notes</h3></div>
    <div class="card-body">
        <dl class="rec-dl">
            <?php if ($payment['notes']): ?>
                <dt>Notes</dt>
                <dd style="white-space:pre-wrap;"><?= e($payment['notes']) ?></dd>
            <?php endif; ?>
            <?php if ($_showInternal): ?>
                <dt>Internal Notes</dt>
                <dd style="white-space:pre-wrap; color:var(--text-secondary);"><?= e($payment['internal_notes']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>
<?php endif; ?>

<?php
// F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
// S-RECORD-REDESIGN: moved below the payment's own content; the rail carries
// its status summary.
$qboPanel = [
    'entity_type' => 'payment',
    'map_table'   => 'acc_qbo_payment_map',
    'qbo_id_col'  => 'qbo_payment_id',
    'ff_fk'       => 'ff_payment_id',
    'ff_id'       => (int) $payment['id'],
    'deep_link'   => 'recvpayment',
    'retry_url'   => base_url('api/v1/quickbooks/payments/retry'),
];
require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
?>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php $activityEntityType = 'payment'; $activityEntityId = $id; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the payment at a glance ─────────
$R      = \FleetForge\Ui\RecordUi::class;
$railP  = [];
$_cur   = (string) $payment['currency'];

// 1. Needs attention.
$alertsP = [];
if ($hasUnapplied) {
    $_msg = e(format_currency($unapplied)) . ' ' . e($_cur) . ' is on no invoice';
    if ($isQboOwned) {
        $_msg .= ' — it came from QuickBooks: apply it to the invoice there and FleetForge follows.';
    } elseif ((int) $openInv['cnt'] > 0) {
        $_msg .= ' — this customer has <a href="' . e(base_url('invoices')) . '?customer_id=' . (int) $payment['customer_id'] . '&status=outstanding">'
            . (int) $openInv['cnt'] . ' open invoice' . ((int) $openInv['cnt'] === 1 ? '' : 's') . '</a> (' . e(format_currency($openInv['owing'])) . ' owing).';
    } else {
        $_msg .= '.';
    }
    $alertsP[] = [$unappliedStale ? 'danger' : 'warning', $_msg];
}
if (bccomp((string) $payment['overpayment_amount'], '0', 2) > 0 && !(int) $payment['overpayment_resolved']) {
    $alertsP[] = ['warning', 'Overpayment of ' . e(format_currency($payment['overpayment_amount'])) . ' not resolved'
        . ($payment['overpayment_action'] ? ' (' . e(str_replace('_', ' ', (string) $payment['overpayment_action'])) . ')' : '') . '.'];
}
if ($payment['status'] === 'pending') {
    $alertsP[] = ['info', 'Pending — not cleared by the bank yet' . ($daysSince !== null && $daysSince > 0 ? ' (' . e($daysSinceLabel) . ')' : '') . '.'];
} elseif ($payment['status'] === 'failed') {
    $alertsP[] = ['danger', '<b>Failed</b>' . ($payment['failure_reason'] ? ' — ' . e($payment['failure_reason']) : '') . '.'];
} elseif ($payment['status'] === 'returned') {
    $alertsP[] = ['danger', '<b>Returned</b>' . ($payment['returned_date'] ? ' ' . e(format_date($payment['returned_date'])) : '')
        . ($payment['returned_reason'] ? ' — ' . e($payment['returned_reason']) : '') . '. Check the invoices it paid are owing again.'];
} elseif ($payment['status'] === 'void') {
    $alertsP[] = ['info', 'Voided — this payment no longer counts toward any invoice.'];
} elseif ($payment['status'] === 'refunded') {
    $alertsP[] = ['info', 'Refunded' . (bccomp($refunded, '0', 2) > 0 ? ' ' . e(format_currency($refunded)) : '')
        . ($payment['refund_date'] ? ' on ' . e(format_date($payment['refund_date'])) : '') . '.'];
}
// failure_reason can be set on a non-failed status (a retried payment) — keep it visible.
if ($payment['failure_reason'] && $payment['status'] !== 'failed') {
    $alertsP[] = ['warning', 'Failure note: ' . e($payment['failure_reason'])];
}
if (!$payment['customer_id']) {
    $alertsP[] = ['warning', 'Not linked to a customer.'];
}
if ($qboMap !== null && ((string) ($qboMap['push_status'] ?? '') === 'failed' || str_starts_with((string) ($qboMap['push_status'] ?? ''), 'failed_preflight'))) {
    $alertsP[] = ['danger', '<a href="#qbo-sync-panel">QuickBooks push failed</a> — retry from the QuickBooks panel.'];
}
$railP[] = $R::card('Needs attention', $R::alerts($alertsP, 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Where the money went — applied vs the rest.
$_appliedPct = bccomp((string) $payment['amount'], '0', 2) > 0
    ? (float) bcmul(bcdiv($allocTotal, (string) $payment['amount'], 6), '100', 2)
    : 0.0;
$moneyBody = $R::meter('On invoices', e((string) round($_appliedPct)) . '%', $_appliedPct,
    $_appliedPct >= 99.99 ? 'ok' : ($hasUnapplied ? ($unappliedStale ? 'danger' : 'warn') : 'info'),
    e(format_currency($allocTotal)) . ' of ' . e(format_currency($payment['amount'])) . ' ' . e($_cur));
$_cnHtml = '';
foreach ($overpaymentCns as $_ocn) {
    $_cnHtml .= ($_cnHtml !== '' ? '<br>' : '')
        . '<a href="' . e(base_url('credit_notes/show')) . '?id=' . (int) $_ocn['id'] . '">' . e($_ocn['credit_note_number']) . '</a> · '
        . e(format_currency($_ocn['amount_remaining'])) . ' left';
}
$moneyBody .= $R::kv([
    ['Account credit', bccomp($cnHold, '0', 2) > 0 ? e(format_currency($cnHold)) : null, 'mono'],
    ['Credit note', $_cnHtml !== '' ? $_cnHtml : null],
    ['Overpayment', bccomp((string) $payment['overpayment_amount'], '0', 2) > 0
        ? e(format_currency($payment['overpayment_amount'])) . ' · ' . e(str_replace('_', ' ', (string) ($payment['overpayment_action'] ?? 'unresolved'))) . ((int) $payment['overpayment_resolved'] ? ' ✓' : '')
        : null],
    ['Refunded', bccomp($refunded, '0', 2) > 0 ? e(format_currency($refunded)) . ($payment['refund_date'] ? ' · ' . e(format_date($payment['refund_date'])) : '') : null],
    ['Refund method', !empty($payment['refund_method']) ? e(str_replace('_', ' ', (string) $payment['refund_method'])) : null],
    ['Refund ref #', !empty($payment['refund_reference']) ? e($payment['refund_reference']) : null, 'mono'],
    ['Unapplied', $isLive && bccomp($unapplied, '0', 2) !== 0 ? e(format_currency($unapplied)) : null, 'mono'],
]);
$railP[] = $R::card('Where the money went', $moneyBody, ['icon' => 'banknotes', 'class' => 'rec-card--accent']);

// 3. Customer.
if ($payment['customer_id']) {
    $_cid   = (int) $payment['customer_id'];
    $_cname = (string) ($payment['company_name'] ?? 'Customer');
    $custBody = $R::entity($_cname, can('customers', 'view') ? base_url('customers/show') . '?id=' . $_cid : '', 'Customer', \FleetForge\Ui\ModuleHero::initials($_cname));
    $custLinks = [];
    if (can('customers', 'view')) {
        $custLinks[] = ['Payments from this customer', base_url('customers/show') . '?id=' . $_cid . '#payments', 'banknotes'];
    }
    if (can('invoices', 'view')) {
        $custLinks[] = ['Open invoices', base_url('invoices') . '?customer_id=' . $_cid . '&status=outstanding', 'document-text'];
        $custLinks[] = ['Credit notes', base_url('credit_notes') . '?customer_id=' . $_cid, 'receipt-percent'];
    }
    if ($custLinks) {
        $custBody .= '<div style="margin-top:12px;">' . $R::links($custLinks) . '</div>';
    }
    $railP[] = $R::card('Customer', $custBody, ['icon' => 'user-group']);
}

// 4. Payment facts (was the "Payment Details" card).
$railP[] = $R::card('Payment details', $R::kv([
    ['Method', e($methodName)],
    ['Reference #', !empty($payment['reference_number']) ? e($payment['reference_number']) : null, 'mono'],
    ['Cheque #', !empty($payment['check_number']) ? e($payment['check_number']) : null, 'mono'],
    ['Card', !empty($payment['card_last_four']) ? '•••• ' . e($payment['card_last_four']) : null, 'mono'],
    ["Customer's bank", !empty($payment['bank_name']) ? e($payment['bank_name']) : null],
    // SOP I10: the FleetForge bank account the ledger entry debited.
    ['Deposited to', $payment['deposit_bank_name'] ? e($payment['deposit_bank_name']) : '<span class="text-secondary">Default cash account</span>'],
    ['Amount in CAD', $payment['amount_in_cad'] && $payment['currency'] !== 'CAD' ? e(format_currency($payment['amount_in_cad'])) : null, 'mono'],
    ['Received at', !empty($payment['received_at']) ? e(format_datetime($payment['received_at'])) : null],
    ['Deposited', $payment['deposited_date'] ? e(format_date($payment['deposited_date'])) : null],
    ['Cleared', $payment['cleared_date'] ? e(format_date($payment['cleared_date'])) : null],
    ['Verified', $payment['verified_by'] ? e($payment['verified_by_name'] ?? 'Unknown') . ' · ' . e(format_datetime($payment['verified_at'])) : null],
    ['Source', $isQboOwned ? 'QuickBooks' . ($payment['origin'] === 'qbo_payments_webhook' ? ' Payments' : '') : 'FleetForge'],
]), ['icon' => 'credit-card']);

// 5. QuickBooks summary (full panel in the main column).
if ($qboConnected) {
    $_qs = $qboMap['push_status'] ?? null;
    [$_qBadge, $_qLabel] = match (true) {
        $qboMap === null                                  => ['badge-neutral', 'Not synced'],
        $_qs === 'pushed'                                 => ['badge-success', 'Synced'],
        $_qs === 'voided'                                 => ['badge-neutral', 'Voided'],
        $_qs === 'pending'                                => ['badge-neutral', 'Pending'],
        $_qs === 'failed'                                 => ['badge-danger', 'Failed'],
        str_starts_with((string) $_qs, 'failed_preflight') => ['badge-warning', 'Failed pre-flight'],
        str_starts_with((string) $_qs, 'skipped_')        => ['badge-neutral', 'Skipped'],
        default                                           => ['badge-neutral', ucfirst(str_replace('_', ' ', (string) $_qs))],
    };
    $railP[] = $R::card('QuickBooks', $R::kv([
        ['Status', '<span class="badge badge-no-dot ' . $_qBadge . '">' . e($_qLabel) . '</span>'],
        ['QuickBooks #', !empty($qboMap['qbo_payment_id']) ? e('#' . $qboMap['qbo_payment_id']) : null, 'mono'],
        ['Last push', !empty($qboMap['pushed_at']) ? e(format_datetime($qboMap['pushed_at'])) : null],
    ]), ['icon' => 'arrow-path', 'link' => ['Details', '#qbo-sync-panel']]);
}
?>
<aside class="rec-rail" aria-label="Payment at a glance">
    <?= implode("\n    ", $railP) ?>
</aside>
</div><!-- /rec-layout -->

<?php if (can('payments', 'edit') && can('payments', 'delete')): ?>
<!-- Void / Remove confirmation modal (opened from the header's More menu) -->
<div class="modal-backdrop" x-show="showDelete" x-cloak @click.self="showDelete = false">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Void / Remove Payment?</h3>
            <button class="modal-close-btn" aria-label="Close" @click="showDelete = false">&times;</button>
        </div>
        <div class="modal-body">
            <p style="color:var(--text-secondary); margin-bottom:16px;">
                This will soft-delete payment <strong><?= e($payment['payment_number']) ?></strong>
                and reverse all invoice allocations. Invoice statuses will revert.
            </p>
            <label class="form-label">Reason <span style="color:var(--color-danger);">*</span></label>
            <textarea class="form-input" rows="3" x-model="deleteReason"
                      placeholder="Enter reason for removal…" maxlength="500"></textarea>
            <p x-show="deleteError" x-text="deleteError" style="color:var(--color-danger); margin-top:8px;"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" @click="showDelete = false">Cancel</button>
            <button class="btn btn-danger btn-sm" @click="confirmDelete()" :disabled="deleting">
                <span x-text="deleting ? 'Removing…' : 'Confirm Remove'"></span>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- end x-data="FF_PaymentActions()" -->

<script>
// SOP I17: move an allocation to another invoice (api/v1/payments/reallocate.php).
function FF_PaymentMove() {
    return {
        moveAllocId: null,
        moveFrom:    '',
        moveAmount:  '',
        moveTarget:  null,
        moving:      false,
        moveError:   '',
        openMove(allocId, invoiceNumber, amount) {
            this.moveAllocId = allocId;
            this.moveFrom    = invoiceNumber;
            this.moveAmount  = amount;
            this.moveTarget  = null;
            this.moveError   = '';
        },
        async submitMove() {
            if (!this.moveTarget) return;
            this.moving = true;
            this.moveError = '';
            const r = await FF_Api.post('<?= base_url('api/v1/payments/reallocate.php') ?>', {
                allocation_id: this.moveAllocId,
                target_invoice_id: this.moveTarget.id,
                amount: String(this.moveAmount || '')
            });
            this.moving = false;
            if (r.success) {
                FF_Toast.success('Moved to ' + r.data.to_invoice.number + '.');
                setTimeout(() => location.reload(), 800);
            } else {
                const f = (r.error && r.error.fields) || {};
                this.moveError = Object.values(f)[0] || (r.error && r.error.message) || 'Could not move the payment.';
            }
        },
    };
}

// Page component (opens above the hero): the edit card + remove modal state
// the header's More menu drives.
function FF_PaymentActions() {
    return {
        showEdit:  false,
        showDelete: false,
        saving:    false,
        deleting:  false,
        editError: '',
        deleteError: '',
        deleteReason: '',
        editForm: {
            id:               <?= (int)$payment['id'] ?>,
            updated_at:       <?= json_encode($payment['updated_at']) ?>,
            reference_number: <?= json_encode($payment['reference_number'] ?? '') ?>,
            bank_name:        <?= json_encode($payment['bank_name'] ?? '') ?>,
            notes:            <?= json_encode($payment['notes'] ?? '') ?>,
            internal_notes:   <?= json_encode($payment['internal_notes'] ?? '') ?>,
        },

        async saveEdit() {
            this.saving    = true;
            this.editError = '';
            const res = await FF_Api.post('<?= base_url('api/v1/payments/update.php') ?>', this.editForm);
            this.saving = false;
            if (res.success) {
                this.editForm.updated_at = res.data.updated_at;
                this.showEdit = false;
                window.location.reload();
            } else {
                this.editError = res.error?.message ?? 'Save failed.';
            }
        },

        async confirmDelete() {
            if (!this.deleteReason.trim()) {
                this.deleteError = 'Reason is required.';
                return;
            }
            this.deleting    = true;
            this.deleteError = '';
            const res = await FF_Api.post('<?= base_url('api/v1/payments/delete.php') ?>', {
                id:     <?= (int)$payment['id'] ?>,
                reason: this.deleteReason,
            });
            this.deleting = false;
            if (res.success) {
                window.location.href = '<?= base_url('/payments') ?>';
            } else {
                this.deleteError = res.error?.message ?? 'Removal failed.';
            }
        },
    };
}
</script>

<?php
// ── AI Payment Summary panel (S-AI-SUMMARY-PANELS) ──
$aiSummaryEntityType = 'payment';
$aiSummaryEntityId   = $id;
$aiSummaryType       = 'payment_summary';
$aiSummaryTitle      = 'Payment Summary — ' . ($payment['payment_number'] ?? ''); // raw — ai-panel.php escapes
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
