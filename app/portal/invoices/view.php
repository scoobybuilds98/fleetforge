<?php
declare(strict_types=1);

/**
 * app/portal/invoices/view.php
 *
 * Portal invoice detail — line items, payment history,
 * payment instructions, PDF download.
 * Trap 8: query filters by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();
$invoiceId = clean_int($_GET['id'] ?? null);

if (!$invoiceId) {
    header('Location: ' . base_url('portal/invoices'));
    exit;
}

$inv = db_row(
    // S-LEASE-CLOSE-ACTUAL-DATE: pull the lease's actual return date + time fields
    // so the Period row can show the REAL rental end (the customer should see the
    // actual lease dates; billing_period_end stays the trimmed billed extent).
    "SELECT i.*, l.contract_number,
            l.actual_return_date, l.actual_return_time, l.start_time, l.billing_days_removed
     FROM invoices i
     LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
     WHERE i.id = ? AND i.customer_id = ? AND i.deleted_at IS NULL
       AND i.status <> 'void'
       AND (i.status <> 'draft' OR i.generation_source = 'advance')",
    [$invoiceId, $cid]
);

if (!$inv) {
    header('Location: ' . base_url('portal/invoices'));
    exit;
}

// Line items
$lineItems = db_select(
    "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC",
    [$invoiceId]
);

// S-REFUND-ON-INVOICE: show capped credit lines at their ORIGINAL amounts plus
// one balancing "converted to account credit — CN-xxx" row, so the customer
// sees the real refund math instead of a "$0.00 credit" (lines still sum to
// the stored subtotal).
$lineItems = ff_expand_capped_invoice_lines($lineItems, $invoiceId);

// Payment history
$payments = db_select(
    "SELECT pa.amount AS allocated, p.payment_date, p.payment_method, p.reference_number
     FROM payment_allocations pa
     JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
     WHERE pa.invoice_id = ?
     ORDER BY p.payment_date DESC",
    [$invoiceId]
);

// Overdue days
$daysOverdue = 0;
if ($inv['status'] === 'overdue' && $inv['due_date']) {
    $daysOverdue = max(0, (int) floor((time() - strtotime($inv['due_date'])) / 86400));
}

// Payment instructions from settings
$paymentInstructions = settings_get('company.payment_instructions', '');
$bankName    = settings_get('company.bank_name', '');
$bankAccount = settings_get('company.bank_account', '');
$checkPayable = settings_get('company.check_payable_to', '');

// S-QBO-15 Pay Online button visibility per D-QBO-15-3.
// Gates: feature enabled + invoice payable + has QBO mapping + balance > 0
$qboPaymentsEnabled = (string) settings_get('quickbooks.payments_enabled', '0') === '1';
$invoicePayable = in_array($inv['status'], ['sent', 'overdue', 'partially_paid'], true);
$hasBalance = bccomp($inv['balance_due'] ?? '0', '0.00', 2) > 0;
$qboInvMapped = false;
if ($qboPaymentsEnabled && $invoicePayable && $hasBalance) {
    $qboInvCheck = db_row(
        "SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
        [$invoiceId]
    );
    $qboInvMapped = $qboInvCheck && !empty($qboInvCheck['qbo_invoice_id']);
}
$showPayOnline = $qboPaymentsEnabled && $invoicePayable && $hasBalance && $qboInvMapped;

$statusBadge = match($inv['status']) {
    'paid'           => 'badge-success',
    'overdue'        => 'badge-danger',
    'sent'           => 'badge-info',
    'partially_paid' => 'badge-warning',
    'void'           => 'badge-neutral line-through',
    default          => 'badge-neutral',
};

$pageTitle = 'Invoice ' . $inv['invoice_number'];
require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- Header -->
<div class="portal-detail-header">
    <div>
        <a href="<?= e(base_url('portal/invoices')) ?>" class="portal-form-link" style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
            Back to Invoices
        </a>
        <h1 class="portal-detail-title"><?= e($inv['invoice_number']) ?></h1>
        <p class="portal-detail-subtitle">
            <?php if ($inv['contract_number']): ?>
                Lease <?= e($inv['contract_number']) ?> &middot;
            <?php endif; ?>
            Issued <?= e(format_date($inv['invoice_date'])) ?>
            &nbsp; <span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></span>
            <?php if ($daysOverdue > 0): ?>
                &nbsp; <span style="color:var(--color-danger);font-weight:600;font-size:0.8125rem;"><?= e((string)$daysOverdue) ?> days overdue</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="portal-detail-actions" x-data="payOnlineButton(<?= (int) $invoiceId ?>, <?= $showPayOnline ? 'true' : 'false' ?>)">
        <?php if ($showPayOnline): ?>
            <!-- S-QBO-15 Pay Online — generates Intuit Payments hosted URL + redirects -->
            <button class="btn btn-success btn-sm" @click="payOnline()" :disabled="loading">
                <span x-show="!loading">Pay Online</span>
                <span x-show="loading" x-cloak>Generating payment link…</span>
            </button>
            <div x-show="error" x-cloak class="text-danger text-xs" style="margin-top:4px;max-width:240px;" x-text="error"></div>
        <?php endif; ?>
        <?php if ($inv['pdf_path']): ?>
            <a href="<?= e(base_url('api/v1/invoices/download.php?id=' . $invoiceId)) ?>" class="btn btn-primary btn-sm" target="_blank">
                Download PDF
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($showPayOnline): ?>
<script>
function payOnlineButton(invoiceId, enabled) {
    return {
        loading: false,
        error: '',
        async payOnline() {
            this.loading = true;
            this.error = '';
            try {
                const r = await FF_Api.post('<?= e(base_url('api/v1/portal/invoices/initiate_qbo_payment')) ?>', {
                    invoice_id: invoiceId,
                });
                // I10: json_success double-nests — the body is
                //   { success:true, data:{ success:<bool>, url, status, error } }
                // and json_error (422) resolves (FF_Api.post does not reject) as
                //   { success:false, error:{ message } }.
                // The old code read r.url/r.error off the OUTER envelope, so it
                // always navigated to `undefined` and never surfaced the error.
                if (r && r.success && r.data) {
                    if (r.data.success && r.data.url) {
                        window.location.href = r.data.url;
                        return;
                    }
                    this.error = r.data.error || ('Unable to start payment: ' + (r.data.status || 'unknown error'));
                    this.loading = false;
                } else {
                    const em = (r && r.error && (r.error.message || r.error)) || 'unknown error';
                    this.error = 'Unable to start payment: ' + (typeof em === 'string' ? em : 'unknown error');
                    this.loading = false;
                }
            } catch (e) {
                this.error = 'Network error: ' + (e.message || e);
                this.loading = false;
            }
        },
    };
}
</script>
<?php endif; ?>

<!-- Balance Due (large) -->
<?php if (bccomp($inv['balance_due'], '0.00', 2) > 0): ?>
<div style="background:<?= $inv['status'] === 'overdue' ? 'var(--badge-danger-bg)' : 'var(--bg-card)' ?>;border:1px solid <?= $inv['status'] === 'overdue' ? 'var(--color-danger)' : 'var(--border-color)' ?>;border-radius:12px;padding:24px;margin-bottom:20px;text-align:center;">
    <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:<?= $inv['status'] === 'overdue' ? 'var(--badge-danger-text)' : 'var(--text-muted)' ?>;font-weight:600;margin-bottom:6px;">Balance Due</div>
    <div style="font-size:2rem;font-weight:700;color:<?= $inv['status'] === 'overdue' ? 'var(--badge-danger-text)' : 'var(--text-primary)' ?>;font-family:'DM Mono',monospace;"><?= e(format_currency($inv['balance_due'])) ?></div>
    <div style="font-size:0.8125rem;color:<?= $inv['status'] === 'overdue' ? 'var(--badge-danger-text)' : 'var(--text-secondary)' ?>;margin-top:4px;">Due: <?= e(format_date($inv['due_date'])) ?></div>
</div>
<?php endif; ?>

<!-- Invoice Summary + Line Items -->
<div class="portal-detail-grid">

    <div class="portal-info-card">
        <div class="portal-info-card-header">Invoice Summary</div>
        <ul class="portal-info-list">
            <li><span class="portal-info-label">Invoice #</span><span class="portal-info-value font-mono"><?= e($inv['invoice_number']) ?></span></li>
            <li><span class="portal-info-label">Issue Date</span><span class="portal-info-value font-mono"><?= e(format_date($inv['invoice_date'])) ?></span></li>
            <li><span class="portal-info-label">Due Date</span><span class="portal-info-value font-mono"><?= e(format_date($inv['due_date'])) ?></span></li>
            <?php
            // [C4-FIX] Column names are billing_period_start/billing_period_end on
            // the invoices table. The old 'period_start'/'period_end' keys were
            // undefined and emitted Undefined array key warnings on every render.
            // Hide the Period row entirely when both dates are missing so
            // non-lease invoices (one-off bills) don't render "— —".
            $_periodStart = $inv['billing_period_start'] ?? null;
            // S-LEASE-CLOSE-ACTUAL-DATE: show the ACTUAL rental end (real return
            // date) to the customer when the time-of-day rule trimmed the billed
            // extent — billing_period_end stays the trimmed value for billing math.
            $_periodEnd   = ff_invoice_display_period_end($inv);
            if ($_periodStart || $_periodEnd):
            ?>
            <li><span class="portal-info-label">Period</span><span class="portal-info-value font-mono"><?= e(format_date($_periodStart)) ?> — <?= e(format_date($_periodEnd)) ?></span></li>
            <?php endif; ?>
            <li><span class="portal-info-label">Currency</span><span class="portal-info-value"><?= e($inv['currency'] ?? 'CAD') ?></span></li>
            <li><span class="portal-info-label">Subtotal</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['subtotal'])) ?></span></li>
            <?php if (bccomp($inv['tax_gst_amount'] ?? '0', '0', 2) > 0): ?>
            <li><span class="portal-info-label">GST</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['tax_gst_amount'])) ?></span></li>
            <?php endif; ?>
            <?php if (bccomp($inv['tax_pst_amount'] ?? '0', '0', 2) > 0): ?>
            <li><span class="portal-info-label">PST</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['tax_pst_amount'])) ?></span></li>
            <?php endif; ?>
            <?php if (bccomp($inv['tax_hst_amount'] ?? '0', '0', 2) > 0): ?>
            <li><span class="portal-info-label">HST</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['tax_hst_amount'])) ?></span></li>
            <?php endif; ?>
            <li style="font-weight:700;font-size:0.875rem;"><span class="portal-info-label">Total</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['total_amount'])) ?></span></li>
            <?php
            // CURRENCY-MARKUP-1: D-D — show FX breakdown when USD + markup > 0 (transparency)
            $_portalCurrency  = $inv['currency'] ?? 'CAD';
            $_portalBankRate  = (string)($inv['exchange_rate_to_cad'] ?? '');
            $_portalMarkupPct = (string)($inv['currency_markup_pct'] ?? '0.0000');
            $_portalHasFx     = $_portalCurrency !== 'CAD' && $_portalBankRate !== '';
            $_portalHasMarkup = $_portalHasFx && bccomp($_portalMarkupPct, '0', 4) > 0;
            if ($_portalHasFx):
                if ($_portalHasMarkup) {
                    $_pFactor   = bcadd('1', bcdiv($_portalMarkupPct, '100', 10), 10);
                    $_pEffRate  = bcmul($_portalBankRate, $_pFactor, 6);
                    $_pCadTotal = bcmul($inv['total_amount'], $_pEffRate, 2);
                } else {
                    $_pEffRate  = $_portalBankRate;
                    $_pCadTotal = bcmul($inv['total_amount'], $_portalBankRate, 2);
                }
                $fmtR = static fn(string $r): string => rtrim(rtrim(number_format((float)$r, 6), '0'), '.');
            ?>
            <?php if ($_portalHasMarkup): ?>
            <li><span class="portal-info-label">Bank Rate (USD→CAD)</span><span class="portal-info-value font-mono"><?= e($fmtR($_portalBankRate)) ?></span></li>
            <li><span class="portal-info-label">Markup Applied</span><span class="portal-info-value font-mono"><?= e(rtrim(rtrim(number_format((float)$_portalMarkupPct, 4), '0'), '.')) ?>%</span></li>
            <li><span class="portal-info-label">Effective Rate</span><span class="portal-info-value font-mono"><?= e($fmtR($_pEffRate)) ?></span></li>
            <li style="font-weight:600;"><span class="portal-info-label">CAD Equivalent</span><span class="portal-info-value font-mono"><?= e(format_currency($_pCadTotal)) ?> CAD</span></li>
            <?php else: ?>
            <li><span class="portal-info-label">Exchange Rate</span><span class="portal-info-value font-mono">1 USD = <?= e($fmtR($_portalBankRate)) ?> CAD</span></li>
            <li><span class="portal-info-label">CAD Equivalent</span><span class="portal-info-value font-mono"><?= e(format_currency($_pCadTotal)) ?> CAD</span></li>
            <?php endif; ?>
            <?php endif; ?>
            <li style="font-weight:700;"><span class="portal-info-label" style="color:var(--color-danger);">Balance Due</span><span class="portal-info-value font-mono" style="color:var(--color-danger);"><?= e(format_currency($inv['balance_due'])) ?></span></li>
        </ul>
    </div>

    <!-- Payment Instructions -->
    <div class="portal-info-card">
        <div class="portal-info-card-header">Payment Information</div>
        <ul class="portal-info-list">
            <?php if ($bankName): ?>
            <li><span class="portal-info-label">Bank</span><span class="portal-info-value"><?= e($bankName) ?></span></li>
            <?php endif; ?>
            <?php if ($bankAccount): ?>
            <li><span class="portal-info-label">Account</span><span class="portal-info-value font-mono"><?= e($bankAccount) ?></span></li>
            <?php endif; ?>
            <?php if ($checkPayable): ?>
            <li><span class="portal-info-label">Checks Payable To</span><span class="portal-info-value"><?= e($checkPayable) ?></span></li>
            <?php endif; ?>
        </ul>
        <?php if ($paymentInstructions): ?>
        <div style="padding:14px 18px;font-size:0.8125rem;color:var(--text-secondary);line-height:1.6;border-top:1px solid var(--border-color);">
            <?= nl2br(e($paymentInstructions)) ?>
        </div>
        <?php endif; ?>
        <?php if (!$bankName && !$paymentInstructions): ?>
        <div style="padding:14px 18px;font-size:0.8125rem;color:var(--text-muted);">
            Contact us for payment instructions.
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Line Items -->
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Line Items</h2>
    </div>
    <div class="portal-section-body--flush">
        <?php if (empty($lineItems)): ?>
            <div class="portal-empty"><p class="portal-empty-text">No line items.</p></div>
        <?php else: ?>
            <div class="table-responsive">
<table class="portal-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $li): ?>
                    <tr>
                        <td><?= e($li['description']) ?></td>
                        <td class="text-right font-mono"><?= e(rtrim(rtrim($li['quantity'], '0'), '.')) ?><?= !empty($li['unit']) ? ' <span class="text-secondary" style="font-size:11px;">' . e($li['unit']) . '</span>' : '' ?></td>
                        <td class="text-right font-mono"><?= e(format_currency($li['unit_price'])) ?></td>
                        <?php
                        // [C4-FIX] invoice_line_items column is `amount`, not
                        // `line_total`. Previous code emitted Undefined array key.
                        // S-REFUND-ON-INVOICE: credit lines carry an explicit −
                        // sign (mirrors the admin invoice view) — without it a
                        // mileage refund read as a positive charge.
                        ?>
                        <td class="text-right font-mono" style="font-weight:600;"><?= !empty($li['is_credit']) ? '−' : '' ?><?= e(format_currency($li['amount'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
</div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment History -->
<?php if (!empty($payments)): ?>
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">Payment History</h2>
    </div>
    <div class="portal-section-body--flush">
        <div class="table-responsive">
<table class="portal-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-right">Amount Applied</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pmt): ?>
                <tr>
                    <td class="font-mono"><?= e(format_date($pmt['payment_date'])) ?></td>
                    <td><?= e(ucfirst(str_replace('_', ' ', $pmt['payment_method'] ?? '—'))) ?></td>
                    <td class="font-mono"><?= e($pmt['reference_number'] ?: '—') ?></td>
                    <td class="text-right font-mono" style="color:var(--color-success);font-weight:600;"><?= e(format_currency($pmt['allocated'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
