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
    "SELECT i.*, l.contract_number
     FROM invoices i
     LEFT JOIN leases l ON l.id = i.lease_id AND l.deleted_at IS NULL
     WHERE i.id = ? AND i.customer_id = ? AND i.deleted_at IS NULL",
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
    <div class="portal-detail-actions">
        <?php if ($inv['pdf_path']): ?>
            <a href="<?= e(base_url('api/v1/invoices/download.php?id=' . $invoiceId)) ?>" class="btn btn-primary btn-sm" target="_blank">
                Download PDF
            </a>
        <?php endif; ?>
    </div>
</div>

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
            <li><span class="portal-info-label">Period</span><span class="portal-info-value font-mono"><?= e(format_date($inv['period_start'])) ?> — <?= e(format_date($inv['period_end'])) ?></span></li>
            <li><span class="portal-info-label">Currency</span><span class="portal-info-value"><?= e($inv['currency'] ?? 'CAD') ?></span></li>
            <li><span class="portal-info-label">Subtotal</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['subtotal'])) ?></span></li>
            <?php if (bccomp($inv['gst_amount'] ?? '0', '0', 2) > 0): ?>
            <li><span class="portal-info-label">GST</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['gst_amount'])) ?></span></li>
            <?php endif; ?>
            <?php if (bccomp($inv['pst_amount'] ?? '0', '0', 2) > 0): ?>
            <li><span class="portal-info-label">PST</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['pst_amount'])) ?></span></li>
            <?php endif; ?>
            <li style="font-weight:700;font-size:0.875rem;"><span class="portal-info-label">Total</span><span class="portal-info-value font-mono"><?= e(format_currency($inv['total_amount'])) ?></span></li>
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
                        <td class="text-right font-mono"><?= e(rtrim(rtrim($li['quantity'], '0'), '.')) ?></td>
                        <td class="text-right font-mono"><?= e(format_currency($li['unit_price'])) ?></td>
                        <td class="text-right font-mono" style="font-weight:600;"><?= e(format_currency($li['line_total'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
