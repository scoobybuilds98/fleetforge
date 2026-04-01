<?php
declare(strict_types=1);

/**
 * app/admin/invoices/show.php
 *
 * Invoice detail page. Shows invoice header, line items table,
 * tax breakdown, and status action buttons (Send, Void).
 * Loads data server-side. Actions via Alpine.js fetch to API.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D12 (immutability), D30 (asset_url), D32 (CSS classes)
 * @session  S008
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

// Resolve invoice ID
$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    header('Location: ' . base_url('invoices'));
    exit;
}

// Load invoice
$invoice = db_row(
    "SELECT * FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$invoiceId]
);

if (!$invoice) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Invoice Not Found</h1>';
    exit;
}

// Load line items
$lineItems = db_select(
    "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC",
    [$invoiceId]
);

// Status badge class per design §9
$statusBadgeClass = match($invoice['status']) {
    'draft'          => 'badge-neutral',
    'sent'           => 'badge-info',
    'paid'           => 'badge-success',
    'partially_paid' => 'badge-warning',
    'overdue'        => 'badge-danger',
    'void'           => 'badge-neutral',
    'written_off'    => 'badge-danger',
    default          => 'badge-neutral',
};

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page Header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($invoice['invoice_number']) ?></span>
</nav>

<div class="page-header" x-data="FF_InvoiceActions()">
    <div>
        <h1 class="page-header-title h4">
            <?= e($invoice['invoice_number']) ?>
            <span class="badge badge-no-dot <?= $statusBadgeClass ?>" style="vertical-align:middle; margin-left:8px;">
                <?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?>
            </span>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            <?= e($invoice['company_name_snapshot'] ?? '—') ?>
            <?php if ($invoice['contract_number_snapshot']): ?>
                · <?= e($invoice['contract_number_snapshot']) ?>
            <?php endif; ?>
            <?php if ($invoice['unit_number_invoice_snapshot']): ?>
                · Unit <?= e($invoice['unit_number_invoice_snapshot']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if ($invoice['status'] === 'draft' && can('invoices', 'edit')): ?>
            <button class="btn btn-primary btn-sm" @click="sendInvoice()" :disabled="sending">
                <span x-show="!sending">Send Invoice</span>
                <span x-show="sending">Sending…</span>
            </button>
        <?php endif; ?>
        <?php if ($invoice['status'] === 'draft' && can('invoices', 'delete')): ?>
            <button class="btn btn-danger btn-sm" @click="showDeleteModal = true">Delete</button>
        <?php endif; ?>
        <?php if (in_array($invoice['status'], ['draft', 'sent']) && can('invoices', 'edit')): ?>
            <button class="btn btn-danger btn-sm" @click="showVoidModal = true">Void</button>
        <?php endif; ?>
        <?php if ($invoice['lease_id']): ?>
            <a href="<?= base_url('leases/show') ?>?id=<?= (int)$invoice['lease_id'] ?>" class="btn btn-secondary btn-sm">
                View Lease
            </a>
        <?php endif; ?>

        <!-- Void modal -->
        <template x-if="showVoidModal">
            <div class="modal-overlay" @click.self="showVoidModal = false">
                <div class="modal modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title">Void Invoice</h3>
                        <button class="modal-close" @click="showVoidModal = false">&times;</button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Reason for voiding <span class="text-danger">*</span></label>
                        <textarea class="form-control" x-model="voidReason" rows="3"
                                  placeholder="Why is this invoice being voided?"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" @click="showVoidModal = false">Cancel</button>
                        <button class="btn btn-danger btn-sm" @click="voidInvoice()" :disabled="voiding || !voidReason.trim()">
                            <span x-show="!voiding">Void Invoice</span>
                            <span x-show="voiding">Voiding…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Delete confirmation modal -->
        <template x-if="showDeleteModal">
            <div class="modal-overlay" @click.self="showDeleteModal = false">
                <div class="modal modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title">Delete Invoice</h3>
                        <button class="modal-close" @click="showDeleteModal = false">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete this draft invoice? This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" @click="showDeleteModal = false">Cancel</button>
                        <button class="btn btn-danger btn-sm" @click="deleteInvoice()" :disabled="deleting">
                            <span x-show="!deleting">Delete Invoice</span>
                            <span x-show="deleting">Deleting…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Toast -->
        <div x-show="toast" x-transition class="toast" :class="'toast-' + toastType"
             style="position:fixed; top:16px; right:16px; z-index:9999;"
             x-text="toast"></div>
    </div>
</div>

<!-- ============================================================
     Invoice Summary Card
     ============================================================ -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Invoice Date</div>
        <div class="stat-value font-mono"><?= format_date($invoice['invoice_date']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Due Date</div>
        <div class="stat-value font-mono" <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'void' && $invoice['due_date'] < date('Y-m-d')): ?>style="color:var(--color-danger);"<?php endif; ?>>
            <?= format_date($invoice['due_date']) ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Amount</div>
        <div class="stat-value font-mono"><?= format_currency($invoice['total_amount']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Balance Due</div>
        <div class="stat-value font-mono" <?php if (bccomp($invoice['balance_due'], '0', 2) > 0): ?>style="color:var(--color-danger);"<?php endif; ?>>
            <?= format_currency($invoice['balance_due']) ?>
        </div>
    </div>
</div>

<!-- ============================================================
     Invoice Details — Two-column layout
     ============================================================ -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">

    <!-- Left: Billing Details -->
    <div class="card" style="padding:20px;">
        <h3 class="card-title" style="margin-bottom:12px; font-size:14px; font-weight:600;">Billing Details</h3>
        <dl style="display:grid; grid-template-columns:140px 1fr; gap:8px 16px; font-size:13px;">
            <dt class="text-secondary">Period</dt>
            <dd><?= format_date($invoice['billing_period_start']) ?> → <?= format_date($invoice['billing_period_end']) ?></dd>
            <dt class="text-secondary">Days</dt>
            <dd><?= (int)$invoice['billing_period_days'] ?> days (<?= e($invoice['billing_type']) ?>)</dd>
            <dt class="text-secondary">Rate Method</dt>
            <dd><?= e($invoice['rate_method_used']) ?></dd>
            <dt class="text-secondary">Currency</dt>
            <dd><?= e($invoice['currency']) ?></dd>
            <?php if ($invoice['po_number']): ?>
            <dt class="text-secondary">PO Number</dt>
            <dd><?= e($invoice['po_number']) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['auto_generated']): ?>
            <dt class="text-secondary">Source</dt>
            <dd>Auto-generated (<?= e($invoice['generation_source'] ?? 'system') ?>)</dd>
            <?php endif; ?>
        </dl>
    </div>

    <!-- Right: Tax & Totals -->
    <div class="card" style="padding:20px;">
        <h3 class="card-title" style="margin-bottom:12px; font-size:14px; font-weight:600;">Financial Summary</h3>
        <dl style="display:grid; grid-template-columns:160px 1fr; gap:8px 16px; font-size:13px;">
            <dt class="text-secondary">Subtotal</dt>
            <dd class="font-mono"><?= format_currency($invoice['subtotal']) ?></dd>
            <?php if (bccomp($invoice['discount_amount'], '0', 2) > 0): ?>
            <dt class="text-secondary">Discount (<?= e($invoice['discount_type']) ?>)</dt>
            <dd class="font-mono" style="color:var(--color-success);">−<?= format_currency($invoice['discount_amount']) ?></dd>
            <?php endif; ?>
            <dt class="text-secondary">Subtotal After Disc.</dt>
            <dd class="font-mono"><?= format_currency($invoice['subtotal_after_discount']) ?></dd>
            <?php if (bccomp($invoice['tax_gst_amount'], '0', 2) > 0): ?>
            <dt class="text-secondary">GST (<?= e($invoice['tax_gst_rate']) ?>%)</dt>
            <dd class="font-mono"><?= format_currency($invoice['tax_gst_amount']) ?></dd>
            <?php endif; ?>
            <?php if (bccomp($invoice['tax_pst_amount'], '0', 2) > 0): ?>
            <dt class="text-secondary">PST (<?= e($invoice['tax_pst_rate']) ?>%)</dt>
            <dd class="font-mono"><?= format_currency($invoice['tax_pst_amount']) ?></dd>
            <?php endif; ?>
            <?php if (bccomp($invoice['tax_hst_amount'], '0', 2) > 0): ?>
            <dt class="text-secondary">HST (<?= e($invoice['tax_hst_rate']) ?>%)</dt>
            <dd class="font-mono"><?= format_currency($invoice['tax_hst_amount']) ?></dd>
            <?php endif; ?>
            <dt class="text-secondary">Tax Total</dt>
            <dd class="font-mono"><?= format_currency($invoice['tax_total']) ?></dd>
            <dt class="font-medium">Total Amount</dt>
            <dd class="font-mono font-medium"><?= format_currency($invoice['total_amount']) ?></dd>
            <?php if (bccomp($invoice['amount_paid'], '0', 2) > 0): ?>
            <dt class="text-secondary">Paid</dt>
            <dd class="font-mono" style="color:var(--color-success);">−<?= format_currency($invoice['amount_paid']) ?></dd>
            <?php endif; ?>
            <?php if (bccomp($invoice['credits_applied'], '0', 2) > 0): ?>
            <dt class="text-secondary">Credits Applied</dt>
            <dd class="font-mono" style="color:var(--color-success);">−<?= format_currency($invoice['credits_applied']) ?></dd>
            <?php endif; ?>
            <dt class="font-medium" style="border-top:1px solid var(--border-color); padding-top:8px;">Balance Due</dt>
            <dd class="font-mono font-medium" style="border-top:1px solid var(--border-color); padding-top:8px; <?= bccomp($invoice['balance_due'], '0', 2) > 0 ? 'color:var(--color-danger);' : '' ?>">
                <?= format_currency($invoice['balance_due']) ?>
            </dd>
        </dl>
        <?php if ($invoice['gst_exempt_snapshot'] || $invoice['pst_exempt_snapshot']): ?>
        <div class="text-sm text-secondary" style="margin-top:8px;">
            <?php if ($invoice['gst_exempt_snapshot']): ?>GST Exempt<?php endif; ?>
            <?php if ($invoice['gst_exempt_snapshot'] && $invoice['pst_exempt_snapshot']): ?> · <?php endif; ?>
            <?php if ($invoice['pst_exempt_snapshot']): ?>PST Exempt<?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     Line Items Table
     ============================================================ -->
<div class="card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color);">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Line Items</h3>
    </div>
    <?php if (empty($lineItems)): ?>
        <div class="empty-state">
            <p class="empty-state-title">No line items</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" aria-label="Invoice line items">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $i => $item): ?>
                    <tr <?php if ($item['is_credit']): ?>style="color:var(--color-success);"<?php endif; ?>>
                        <td class="text-secondary"><?= $i + 1 ?></td>
                        <td>
                            <span class="badge badge-no-dot badge-neutral" style="font-size:11px;">
                                <?= e(str_replace('_', ' ', $item['item_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <?= e($item['description']) ?>
                            <?php if ($item['billing_days']): ?>
                                <span class="text-sm text-secondary">(<?= (int)$item['billing_days'] ?> days, <?= e($item['rate_method'] ?? '') ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right;"><?= e(rtrim(rtrim(number_format((float)$item['quantity'], 4), '0'), '.')) ?></td>
                        <td class="font-mono" style="text-align:right;"><?= format_currency($item['unit_price']) ?></td>
                        <td class="font-mono" style="text-align:right;">
                            <?php if ($item['is_credit']): ?>−<?php endif; ?>
                            <?= format_currency($item['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     Payments History (S009) — server-rendered for correct per-invoice allocation amounts
     ============================================================ -->
<?php
// Load all payment allocations for this invoice, with payment detail
$invoicePayments = db_select(
    "SELECT
        pa.id AS allocation_id,
        pa.amount AS applied_amount,
        pa.allocation_type,
        pa.created_at AS allocated_at,
        p.id AS payment_id,
        p.payment_number,
        p.payment_method,
        p.reference_number,
        p.payment_date,
        p.status AS payment_status,
        p.currency
     FROM payment_allocations pa
     JOIN payments p ON p.id = pa.payment_id AND p.deleted_at IS NULL
     WHERE pa.invoice_id = ?
     ORDER BY p.payment_date ASC, pa.created_at ASC",
    [$invoiceId]
);

$totalApplied = array_reduce($invoicePayments, function($sum, $row) {
    return bcadd($sum, (string)$row['applied_amount'], 6);
}, '0');

$methodLabels = [
    'check' => 'Cheque', 'ach' => 'ACH', 'wire' => 'Wire',
    'credit_card' => 'Credit Card', 'cash' => 'Cash',
    'e_transfer' => 'e-Transfer', 'account_credit' => 'Acct Credit', 'other' => 'Other',
];
?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex; align-items:center; gap:12px;">
        <h3 class="card-title">Payment History</h3>
        <span class="badge badge-neutral"><?= count($invoicePayments) ?> payment<?= count($invoicePayments) !== 1 ? 's' : '' ?></span>
        <?php if (in_array($invoice['status'], ['sent', 'partially_paid', 'overdue']) && can('payments', 'create')): ?>
            <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
               class="btn btn-primary btn-sm" style="margin-left:auto;">
                <?= heroicon('plus', 'icon-sm') ?>
                Record Payment
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0; overflow-x:auto;">

        <?php if (empty($invoicePayments)): ?>
            <div class="empty-state" style="padding:32px;">
                <p class="empty-state-title">No payments recorded</p>
                <p class="empty-state-text">No payments have been applied to this invoice yet.</p>
                <?php if (in_array($invoice['status'], ['sent', 'partially_paid', 'overdue']) && can('payments', 'create')): ?>
                    <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
                       class="btn btn-primary btn-sm">Record First Payment</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Payment #</th>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th style="text-align:right;">Applied Amount</th>
                        <th>Status</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoicePayments as $pay):
                        $payBadge = match($pay['payment_status']) {
                            'cleared'  => 'badge-success',
                            'pending'  => 'badge-info',
                            'failed'   => 'badge-danger',
                            'refunded' => 'badge-warning',
                            'void'     => 'badge-neutral',
                            'returned' => 'badge-warning',
                            default    => 'badge-neutral',
                        };
                    ?>
                        <tr>
                            <td>
                                <a href="<?= base_url('/payments/show') ?>?id=<?= (int)$pay['payment_id'] ?>"
                                   class="link font-mono"><?= e($pay['payment_number']) ?></a>
                            </td>
                            <td class="font-mono"><?= e($pay['payment_date']) ?></td>
                            <td><?= e($methodLabels[$pay['payment_method']] ?? $pay['payment_method']) ?></td>
                            <td class="font-mono"><?= e($pay['reference_number'] ?? '—') ?></td>
                            <td class="font-mono" style="text-align:right; color:var(--color-success);">
                                <?= format_currency($pay['applied_amount']) ?> <?= e($pay['currency']) ?>
                            </td>
                            <td><span class="badge <?= $payBadge ?>"><?= e($pay['payment_status']) ?></span></td>
                            <td><span class="badge badge-neutral" style="font-size:0.75rem;"><?= e($pay['allocation_type']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-muted);">
                        <td colspan="4" style="padding:10px 14px; font-weight:600; font-size:0.9rem;">Total Applied</td>
                        <td class="font-mono" style="text-align:right; font-weight:700; color:var(--color-success); padding:10px 14px;">
                            <?= format_currency(bcround($totalApplied, 2)) ?> <?= e($invoice['currency']) ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>

    </div>
</div>

<!-- ============================================================
     Notes
     ============================================================ -->
<?php if ($invoice['notes'] || $invoice['internal_notes'] || $invoice['void_reason']): ?>
<div class="card" style="padding:20px; margin-bottom:24px;">
    <h3 style="font-size:14px; font-weight:600; margin:0 0 12px 0;">Notes</h3>
    <?php if ($invoice['notes']): ?>
        <div style="margin-bottom:8px;">
            <span class="text-secondary text-sm">Customer-facing:</span>
            <p style="margin:4px 0;"><?= e($invoice['notes']) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($invoice['internal_notes']): ?>
        <div style="margin-bottom:8px;">
            <span class="text-secondary text-sm">Internal:</span>
            <p style="margin:4px 0;"><?= e($invoice['internal_notes']) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($invoice['void_reason']): ?>
        <div style="margin-bottom:8px;">
            <span class="text-danger text-sm">Void reason:</span>
            <p style="margin:4px 0;"><?= e($invoice['void_reason']) ?></p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function FF_InvoiceActions() {
    return {
        sending:        false,
        showVoidModal:  false,
        voidReason:     '',
        voiding:        false,
        showDeleteModal: false,
        deleting:       false,
        toast:          '',
        toastType:      'success',

        async sendInvoice() {
            this.sending = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/send') ?>', {
                    id: <?= (int)$invoiceId ?>
                });
                if (r.success) {
                    this.showToast('Invoice sent successfully', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to send', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.sending = false;
        },

        async voidInvoice() {
            this.voiding = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/void') ?>', {
                    id: <?= (int)$invoiceId ?>,
                    void_reason: this.voidReason
                });
                if (r.success) {
                    this.showToast('Invoice voided', 'success');
                    this.showVoidModal = false;
                    setTimeout(() => location.reload(), 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to void', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.voiding = false;
        },

        async deleteInvoice() {
            this.deleting = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/delete') ?>', {
                    id: <?= (int)$invoiceId ?>
                });
                if (r.success) {
                    this.showToast('Invoice deleted', 'success');
                    this.showDeleteModal = false;
                    setTimeout(() => {
                        window.location.href = '<?= base_url('invoices') ?>';
                    }, 1200);
                } else {
                    this.showToast(r.error?.message || 'Failed to delete', 'error');
                }
            } catch(e) {
                this.showToast('Network error', 'error');
            }
            this.deleting = false;
        },

        showToast(msg, type) {
            this.toast = msg;
            this.toastType = type;
            setTimeout(() => { this.toast = ''; }, 4000);
        }
    };
}
</script>


<?php require_once FF_ROOT . '/includes/footer.php'; ?>
