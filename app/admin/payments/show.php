<?php
declare(strict_types=1);

/**
 * app/admin/payments/show.php
 *
 * Payment detail page. Displays full payment info (method, reference, dates),
 * allocation table (which invoices the payment was applied to), and links to
 * the associated customer and each invoice.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.8 Payments
 * @decisions D30 (asset_url), D32 (CSS classes verified), D5/D13 (soft-delete filter)
 * @session  S009
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
        p.created_at, p.updated_at
     FROM payments p
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
            i.total_amount AS invoice_total, i.balance_due AS invoice_balance_due,
            pa.amount, pa.currency, pa.allocation_type, pa.created_at
     FROM payment_allocations pa
     JOIN invoices i ON i.id = pa.invoice_id
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

$pageTitle      = 'Payment ' . $payment['payment_number'];
$helpModuleSlug = 'payments';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb
     ============================================================ -->
<div class="breadcrumb" style="margin-bottom:20px;">
    <a href="<?= base_url('/payments') ?>" class="breadcrumb-item">Payments</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-item breadcrumb-current"><?= e($payment['payment_number']) ?></span>
</div>

<?php
// F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
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

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header" style="margin-bottom:24px;">
    <div>
        <h1 class="page-header-title h4"><?= e($payment['payment_number']) ?></h1>
        <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">
            Recorded <?= format_datetime($payment['created_at']) ?>
            <?php if ($payment['recorded_by_name']): ?>
                by <?= e($payment['recorded_by_name']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-header-actions">
        <?= help_button('payments') ?>
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
        <span class="badge <?= $statusBadge ?>" style="font-size:1rem; padding:6px 14px;">
            <?= e(strtoupper($payment['status'])) ?>
        </span>
        <?php if ($payment['customer_id'] && can('customers', 'create')): /* EMAIL-1: send receipt */ ?>
        <button type="button"
                class="btn btn-secondary btn-sm"
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
    </div>
</div>

<!-- ============================================================
     Summary tiles
     ============================================================ -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px;">

    <!-- TILES-2: Amount Received drills to all payments for this customer;
         Payment Method + Payment Date stay as display-only metadata;
         Customer becomes a full clickable <a> tile that drills to the
         customer profile (was a nested link inside a div before). -->
    <?php if ($payment['customer_id']): ?>
    <a class="stat-card"
       href="<?= base_url('payments') ?>?customer_id=<?= (int)$payment['customer_id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="View all payments from this customer">
        <div class="stat-label">Amount Received</div>
        <div class="stat-value font-mono">
            <?= format_currency($payment['amount']) ?>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?= e($payment['currency']) ?></span>
        </div>
    </a>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-label">Amount Received</div>
        <div class="stat-value font-mono">
            <?= format_currency($payment['amount']) ?>
            <span style="font-size:0.8rem; color:var(--text-muted);"><?= e($payment['currency']) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="stat-card">
        <div class="stat-label">Payment Method</div>
        <div class="stat-value"><?= e($methodLabels[$payment['payment_method']] ?? $payment['payment_method']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Payment Date</div>
        <div class="stat-value font-mono"><?= format_date($payment['payment_date']) ?></div>
    </div>

    <?php if ($payment['customer_id']): ?>
    <a class="stat-card"
       href="<?= base_url('customers/show') ?>?id=<?= (int)$payment['customer_id'] ?>"
       style="cursor:pointer;text-decoration:none"
       title="Open customer profile">
        <div class="stat-label">Customer</div>
        <div class="stat-value"><?= e($payment['company_name'] ?? '—') ?></div>
    </a>
    <?php else: ?>
    <div class="stat-card">
        <div class="stat-label">Customer</div>
        <div class="stat-value">—</div>
    </div>
    <?php endif; ?>

</div>

<!-- ============================================================
     Two-column detail layout
     ============================================================ -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">

    <!-- Payment details -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Payment Details</h3></div>
        <div class="card-body">
            <dl class="detail-grid">
                <dt>Payment Number</dt>
                <dd class="font-mono"><?= e($payment['payment_number']) ?></dd>

                <dt>Amount</dt>
                <dd class="font-mono">
                    <?= format_currency($payment['amount']) ?> <?= e($payment['currency']) ?>
                    <?php if ($payment['amount_in_cad'] && $payment['currency'] !== 'CAD'): ?>
                        <span style="color:var(--text-muted); font-size:0.85rem;">
                            (<?= format_currency($payment['amount_in_cad']) ?> CAD)
                        </span>
                    <?php endif; ?>
                </dd>

                <dt>Method</dt>
                <dd><?= e($methodLabels[$payment['payment_method']] ?? $payment['payment_method']) ?></dd>

                <?php if ($payment['reference_number']): ?>
                    <dt>Reference #</dt>
                    <dd class="font-mono"><?= e($payment['reference_number']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['check_number']): ?>
                    <dt>Cheque #</dt>
                    <dd class="font-mono"><?= e($payment['check_number']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['bank_name']): ?>
                    <dt>Bank</dt>
                    <dd><?= e($payment['bank_name']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['card_last_four']): ?>
                    <dt>Card Last 4</dt>
                    <dd class="font-mono">•••• <?= e($payment['card_last_four']) ?></dd>
                <?php endif; ?>

                <dt>Payment Date</dt>
                <dd class="font-mono"><?= format_date($payment['payment_date']) ?></dd>

                <?php if ($payment['deposited_date']): ?>
                    <dt>Deposited</dt>
                    <dd class="font-mono"><?= format_date($payment['deposited_date']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['cleared_date']): ?>
                    <dt>Cleared</dt>
                    <dd class="font-mono"><?= format_date($payment['cleared_date']) ?></dd>
                <?php endif; ?>

                <dt>Status</dt>
                <dd><span class="badge <?= $statusBadge ?>"><?= e($payment['status']) ?></span></dd>

                <?php if ($payment['failure_reason']): ?>
                    <dt>Failure Reason</dt>
                    <dd style="color:var(--color-danger);"><?= e($payment['failure_reason']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Overpayment / Refund / Verified -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Financial Notes</h3></div>
        <div class="card-body">
            <dl class="detail-grid">
                <?php if (bccomp((string)$payment['overpayment_amount'], '0', 2) > 0): ?>
                    <dt>Overpayment</dt>
                    <dd class="font-mono" style="color:var(--color-warning);">
                        <?= format_currency($payment['overpayment_amount']) ?>
                        <span style="font-size:0.8rem;">(<?= e($payment['overpayment_action'] ?? 'unresolved') ?>)</span>
                    </dd>
                    <dt>Overpayment Resolved</dt>
                    <dd><?= $payment['overpayment_resolved'] ? '✓ Yes' : '✗ No' ?></dd>
                <?php endif; ?>

                <?php if (bccomp((string)$payment['refund_amount'], '0', 2) > 0): ?>
                    <dt>Refund Amount</dt>
                    <dd class="font-mono"><?= format_currency($payment['refund_amount']) ?></dd>
                    <?php if ($payment['refund_date']): ?>
                        <dt>Refund Date</dt>
                        <dd class="font-mono"><?= format_date($payment['refund_date']) ?></dd>
                    <?php endif; ?>
                    <?php if ($payment['refund_reference']): ?>
                        <dt>Refund Ref #</dt>
                        <dd class="font-mono"><?= e($payment['refund_reference']) ?></dd>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($payment['verified_by']): ?>
                    <dt>Verified By</dt>
                    <dd><?= e($payment['verified_by_name'] ?? 'Unknown') ?></dd>
                    <dt>Verified At</dt>
                    <dd class="font-mono"><?= format_datetime($payment['verified_at']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['notes']): ?>
                    <dt>Notes</dt>
                    <dd style="white-space:pre-wrap;"><?= e($payment['notes']) ?></dd>
                <?php endif; ?>

                <?php if ($payment['internal_notes'] && can('payments', 'edit')): ?>
                    <dt>Internal Notes</dt>
                    <dd style="white-space:pre-wrap; color:var(--text-secondary);"><?= e($payment['internal_notes']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

</div>

<!-- ============================================================
     Allocation table
     ============================================================ -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3 class="card-title">Invoice Allocations</h3>
        <span class="badge badge-neutral"><?= count($allocations) ?> allocation<?= count($allocations) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body" style="padding:0; overflow-x:auto;">
        <?php if (empty($allocations)): ?>
            <div class="empty-state" style="padding:32px;">
                <p class="empty-state-title">No allocations</p>
                <p class="empty-state-text">This payment has not been applied to any invoice yet.</p>
            </div>
        <?php else: ?>
            <table class="table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Billing Period</th>
                        <th style="text-align:right;">Invoice Total</th>
                        <th style="text-align:right;">Applied Amount</th>
                        <th style="text-align:right;">Remaining Balance</th>
                        <th>Invoice Status</th>
                        <th>Type</th>
                        <th>Allocated</th>
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
                            </td>
                            <td class="font-mono" style="font-size:0.85rem;">
                                <?= format_date($alloc['billing_period_start']) ?>
                                – <?= format_date($alloc['billing_period_end']) ?>
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
                            <td><span class="badge <?= $invBadge ?>"><?= e($alloc['invoice_status']) ?></span></td>
                            <td><span class="badge badge-neutral" style="font-size:0.75rem;"><?= e($alloc['allocation_type']) ?></span></td>
                            <td class="font-mono" style="font-size:0.8rem; color:var(--text-muted);">
                                <?= format_datetime($alloc['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     Action buttons
     ============================================================ -->
<?php if (can('payments', 'edit')): ?>
<div class="card" style="margin-bottom:24px;" x-data="FF_PaymentActions()">
    <div class="card-header"><h3 class="card-title">Actions</h3></div>
    <div class="card-body" style="display:flex; gap:12px; flex-wrap:wrap;">

        <!-- Edit metadata button — opens inline form -->
        <button class="btn btn-secondary btn-md" @click="showEdit = !showEdit">
            <?= heroicon('pencil', 'icon-sm') ?>
            Edit Notes / Reference
        </button>

        <?php if (can('payments', 'delete')): ?>
        <button class="btn btn-danger btn-md" @click="showDelete = true">
            <?= heroicon('trash', 'icon-sm') ?>
            Void / Remove Payment
        </button>
        <?php endif; ?>

    </div>

    <!-- Inline edit form -->
    <div x-show="showEdit" x-cloak style="border-top:1px solid var(--border-color); padding:20px;">
        <h4 style="margin:0 0 16px; font-size:0.95rem;">Edit Payment Notes</h4>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
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

    <!-- Delete confirmation modal -->
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

</div>
<?php endif; ?>

<script>
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
                this.editError = res.message ?? 'Save failed.';
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
                this.deleteError = res.message ?? 'Removal failed.';
            }
        },
    };
}
</script>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php $activityEntityType = 'payment'; $activityEntityId = $id; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

<?php
// ── AI Payment Summary panel (S-AI-SUMMARY-PANELS) ──
$aiSummaryEntityType = 'payment';
$aiSummaryEntityId   = $id;
$aiSummaryType       = 'payment_summary';
$aiSummaryTitle      = 'Payment Summary — ' . ($payment['payment_number'] ?? ''); // raw — ai-panel.php escapes
require_once FF_ROOT . '/includes/partials/ai-panel.php';
?>
<?php require_once FF_ROOT . '/includes/footer.php'; ?>
