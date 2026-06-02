<?php
declare(strict_types=1);

/**
 * app/admin/credit_notes/show.php
 *
 * Credit note detail page. Shows full credit note info, application history,
 * and inline actions: Edit metadata, Apply to Invoice, Void.
 *
 * Sections:
 *   - Summary header (CN number, status badge, customer, amount)
 *   - Detail card (source, lease, reason, expiry, created by)
 *   - Apply to Invoice form (shows when status is active or partially_used)
 *   - Applications history table
 *   - Void modal (requires reason)
 *
 * D32: All CSS classes verified in app.css.
 * D30: asset_url() / base_url() for links.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @decisions D5/D12/D16/D18/D19/D20/D30/D32
 * @session  S011
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(400);
    die('Missing id.');
}

// Load credit note with customer + created_by name
$cn = db_row(
    "SELECT
        cn.id,
        cn.credit_note_number,
        cn.customer_id,
        c.company_name AS customer_name,
        cn.lease_id,
        cn.source,
        cn.source_invoice_id,
        cn.source_payment_id,
        cn.amount,
        cn.currency,
        cn.amount_remaining,
        cn.status,
        cn.expires_at,
        cn.reason,
        cn.internal_notes,
        cn.created_by,
        u.name AS created_by_name,
        cn.voided_by,
        vu.name AS voided_by_name,
        cn.voided_at,
        cn.created_at,
        cn.updated_at
     FROM credit_notes cn
     LEFT JOIN customers c  ON c.id = cn.customer_id AND c.deleted_at IS NULL
     LEFT JOIN users u      ON u.id = cn.created_by
     LEFT JOIN users vu     ON vu.id = cn.voided_by
     WHERE cn.id = ? AND cn.deleted_at IS NULL",
    [$id]
);

if (!$cn) {
    http_response_code(404);
    die('Credit note not found.');
}

// Load application history
$applications = db_select(
    "SELECT
        cna.id,
        cna.invoice_id,
        i.invoice_number,
        i.status AS invoice_status,
        cna.amount_applied,
        cna.applied_by,
        au.name AS applied_by_name,
        cna.applied_at,
        cna.status AS application_status,
        cna.reversed_at
     FROM credit_note_applications cna
     JOIN invoices i ON i.id = cna.invoice_id AND i.deleted_at IS NULL
     LEFT JOIN users au ON au.id = cna.applied_by
     WHERE cna.credit_note_id = ?
     ORDER BY cna.applied_at ASC",
    [$id]
);

// Source label map (PHP-side for server-rendered sections)
$sourceLabels = [
    'mileage_overpayment' => 'Mileage Overpayment',
    'invoice_adjustment'  => 'Invoice Adjustment',
    'damage_resolution'   => 'Damage Resolution',
    'goodwill'            => 'Goodwill',
    'payment_returned'    => 'Payment Returned',
    'other'               => 'Other',
];

$isApplicable = in_array($cn['status'], ['active', 'partially_used'], true);
$canEdit      = can('invoices', 'edit');
$canCreate    = can('invoices', 'create');

$pageTitle      = 'Credit Note ' . e($cn['credit_note_number']);
$helpModuleSlug = 'credit-notes';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="page-header">
    <div style="display:flex; align-items:center; gap:0.75rem;">
        <a href="<?= base_url('credit_notes') ?>" class="btn btn-sm btn-secondary">
            <?= heroicon('arrow-left', 'icon-sm') ?> Back
        </a>
        <div>
            <h1 class="page-header-title h4 font-mono"><?= e($cn['credit_note_number']) ?></h1>
            <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">
                <a href="<?= base_url('customers/show') ?>?id=<?= (int)$cn['customer_id'] ?>" class="link">
                    <?= e($cn['customer_name'] ?? '—') ?>
                </a>
            </p>
        </div>
    </div>
    <div class="page-header-actions" style="display:flex; gap:0.5rem; align-items:center;">
        <?= help_button('credit-notes') ?>
        <?php
        $badgeClass = match($cn['status']) {
            'active'         => 'badge badge-success',
            'partially_used' => 'badge badge-warning',
            'fully_used'     => 'badge badge-neutral',
            'expired'        => 'badge badge-neutral',
            'void'           => 'badge badge-neutral line-through',
            default          => 'badge badge-neutral',
        };
        $statusLabel = match($cn['status']) {
            'active'         => 'Active',
            'partially_used' => 'Partially Used',
            'fully_used'     => 'Fully Used',
            'expired'        => 'Expired',
            'void'           => 'Void',
            default          => ucfirst($cn['status']),
        };
        ?>
        <span class="<?= $badgeClass ?>"><?= $statusLabel ?></span>
        <?php if ($canEdit && $isApplicable): ?>
            <button class="btn btn-sm btn-danger" @click.prevent="$dispatch('open-void-modal')">
                <?= heroicon('x-circle', 'icon-sm') ?> Void
            </button>
        <?php endif; ?>
    </div>
</div>

<?php
// F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
// credit_notes/show.php had ZERO QBO mentions before this — now at parity with invoices.
$qboPanel = [
    'entity_type' => 'credit_memo',
    'map_table'   => 'acc_qbo_credit_memo_map',
    'qbo_id_col'  => 'qbo_credit_memo_id',
    'ff_fk'       => 'ff_credit_note_id',
    'ff_id'       => (int) $cn['id'],
    'deep_link'   => 'creditmemo',
    'retry_url'   => base_url('api/v1/quickbooks/credit_memos/retry'),
];
require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
?>

<!-- Summary tiles -->
<div class="stat-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-label">Total Amount</div>
        <div class="stat-value font-mono"><?= format_currency($cn['amount']) ?> <?= e($cn['currency']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Remaining Balance</div>
        <div class="stat-value font-mono"><?= format_currency($cn['amount_remaining']) ?> <?= e($cn['currency']) ?></div>
        <?php $used = bcsub((string)$cn['amount'], (string)$cn['amount_remaining'], 2); ?>
        <div class="stat-delta"><?= format_currency($used) ?> applied</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Source</div>
        <div class="stat-value" style="font-size:1rem;"><?= e($sourceLabels[$cn['source']] ?? $cn['source']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Applications</div>
        <div class="stat-value"><?= count($applications) ?></div>
        <div class="stat-delta">invoices credited</div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; align-items:start;">

    <!-- Detail card -->
    <div class="card">
        <div class="card-header"><strong>Credit Note Details</strong></div>
        <div class="card-body">
            <dl class="detail-grid">
                <dt>CN Number</dt>
                <dd class="font-mono"><?= e($cn['credit_note_number']) ?></dd>

                <dt>Customer</dt>
                <dd><a href="<?= base_url('customers/show') ?>?id=<?= (int)$cn['customer_id'] ?>" class="link"><?= e($cn['customer_name'] ?? '—') ?></a></dd>

                <?php if ($cn['lease_id']): ?>
                <dt>Lease</dt>
                <dd><a href="<?= base_url('leases/show') ?>?id=<?= (int)$cn['lease_id'] ?>" class="link">#<?= (int)$cn['lease_id'] ?></a></dd>
                <?php endif; ?>

                <?php if ($cn['source_invoice_id']): ?>
                <dt>Source Invoice</dt>
                <dd><a href="<?= base_url('invoices/show') ?>?id=<?= (int)$cn['source_invoice_id'] ?>" class="link">INV #<?= (int)$cn['source_invoice_id'] ?></a></dd>
                <?php endif; ?>

                <dt>Currency</dt>
                <dd><?= e($cn['currency']) ?></dd>

                <dt>Expires</dt>
                <dd><?= $cn['expires_at'] ? e(format_date($cn['expires_at'])) : '—' ?></dd>

                <dt>Created By</dt>
                <dd><?= e($cn['created_by_name'] ?? 'System') ?></dd>

                <dt>Created At</dt>
                <dd><?= e(format_datetime($cn['created_at'])) ?></dd>

                <?php if ($cn['voided_at']): ?>
                <dt>Voided By</dt>
                <dd><?= e($cn['voided_by_name'] ?? '—') ?> on <?= e(format_datetime($cn['voided_at'])) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($cn['reason']): ?>
        <div class="card-body" style="border-top:1px solid var(--border-default);">
            <strong style="font-size:0.875rem;">Reason</strong>
            <p style="margin:0.5rem 0 0; color:var(--text-secondary); font-size:0.875rem;"><?= nl2br(e($cn['reason'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($cn['internal_notes'] && can('invoices', 'edit')): ?>
        <div class="card-body" style="border-top:1px solid var(--border-default);">
            <strong style="font-size:0.875rem;">Internal Notes</strong>
            <p style="margin:0.5rem 0 0; color:var(--text-secondary); font-size:0.875rem;"><?= nl2br(e($cn['internal_notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Apply to Invoice card (shown only when credit is usable) -->
    <?php if ($canEdit && $isApplicable): ?>
    <div class="card" x-data="applyForm()">
        <div class="card-header"><strong>Apply to Invoice</strong></div>
        <div class="card-body">
            <p style="font-size:0.875rem; color:var(--text-secondary); margin:0 0 1rem;">
                Apply part or all of this credit note to an outstanding invoice for this customer.
                Max available: <strong class="font-mono"><?= format_currency($cn['amount_remaining']) ?> <?= e($cn['currency']) ?></strong>
            </p>

            <div x-show="error" class="alert alert-danger" x-text="error" style="margin-bottom:1rem;"></div>
            <div x-show="success" class="alert alert-success" x-text="success" style="margin-bottom:1rem;"></div>

            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <div>
                    <label class="form-label">Invoice ID</label>
                    <input class="form-input" type="number" min="1" x-model="invoiceId"
                           placeholder="e.g. 42" :disabled="submitting">
                    <div class="form-hint">Enter the invoice ID from the invoice detail page.</div>
                </div>
                <div>
                    <label class="form-label">Amount to Apply (<?= e($cn['currency']) ?>)</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input class="form-input font-mono" type="text" x-model="amount"
                               placeholder="0.00" :disabled="submitting">
                        <button type="button" class="btn btn-sm btn-secondary"
                                @click="amount = '<?= e($cn['amount_remaining']) ?>'"
                                :disabled="submitting">Full</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-md" @click="submit()" :disabled="submitting || !invoiceId || !amount">
                    <span x-show="!submitting">Apply Credit</span>
                    <span x-show="submitting">Applying…</span>
                </button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Edit metadata card when credit is not applicable -->
    <?php if ($canEdit && $cn['status'] !== 'void'): ?>
    <div class="card" x-data="editMeta()">
        <div class="card-header"><strong>Edit Metadata</strong></div>
        <div class="card-body">
            <div x-show="error" class="alert alert-danger" x-text="error" style="margin-bottom:1rem;"></div>
            <div x-show="success" class="alert alert-success" x-text="success" style="margin-bottom:1rem;"></div>
            <div style="display:flex; flex-direction:column; gap:0.75rem;">
                <div>
                    <label class="form-label">Reason</label>
                    <textarea class="form-input" rows="3" x-model="reason" :disabled="submitting"><?= e($cn['reason']) ?></textarea>
                </div>
                <div>
                    <label class="form-label">Expires At</label>
                    <input class="form-input" type="date" x-model="expiresAt" :disabled="submitting">
                </div>
                <div>
                    <label class="form-label">Internal Notes</label>
                    <textarea class="form-input" rows="2" x-model="internalNotes" :disabled="submitting"></textarea>
                </div>
                <button class="btn btn-primary btn-sm" @click="save()" :disabled="submitting">
                    <span x-show="!submitting">Save Changes</span>
                    <span x-show="submitting">Saving…</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<!-- Applications history -->
<?php if (!empty($applications)): ?>
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><strong>Application History</strong></div>
    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Invoice Status</th>
                    <th style="text-align:right;">Amount Applied</th>
                    <th>Applied By</th>
                    <th>Applied At</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                <?php $appReversed = (($app['application_status'] ?? 'applied') === 'reversed'); ?>
                <tr<?= $appReversed ? ' style="opacity:0.6;"' : '' ?>>
                    <td>
                        <a href="<?= base_url('invoices/show') ?>?id=<?= (int)$app['invoice_id'] ?>" class="link font-mono">
                            <?= e($app['invoice_number']) ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        $invBadge = match($app['invoice_status']) {
                            'paid'           => 'badge badge-success',
                            'partially_paid' => 'badge badge-warning',
                            'sent'           => 'badge badge-info',
                            'overdue'        => 'badge badge-danger',
                            'void'           => 'badge badge-neutral line-through',
                            default          => 'badge badge-neutral',
                        };
                        ?>
                        <span class="<?= $invBadge ?>"><?= e(ucfirst(str_replace('_', ' ', $app['invoice_status']))) ?></span>
                    </td>
                    <td class="font-mono" style="text-align:right;"><?= format_currency($app['amount_applied']) ?> <?= e($cn['currency']) ?></td>
                    <td><?= e($app['applied_by_name'] ?? '—') ?></td>
                    <td><?= e(format_datetime($app['applied_at'])) ?></td>
                    <td x-data="{ busy:false, msg:'',
                        async unapply(){
                            if(!confirm('Un-apply this credit from invoice <?= e($app['invoice_number']) ?>? This restores the invoice balance and the credit\'s remaining amount.')) return;
                            this.busy=true; this.msg='';
                            try {
                                const r = await FF_Api.post('<?= base_url('api/v1/credit_notes/unapply') ?>', { application_id: <?= (int)$app['id'] ?> });
                                if (r.success) { window.location.reload(); }
                                else { this.msg = (r.error && r.error.message) || 'Un-apply failed'; this.busy=false; }
                            } catch(e) { this.msg = e.message || 'Error'; this.busy=false; }
                        } }">
                        <?php if ($appReversed): ?>
                            <span class="badge badge-neutral line-through">Reversed</span>
                            <?php if (!empty($app['reversed_at'])): ?>
                                <div class="text-xs text-secondary"><?= e(format_datetime($app['reversed_at'])) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-success">Applied</span>
                            <?php if ($canEdit && $cn['status'] !== 'void'): ?>
                                <button type="button" class="btn btn-xs btn-secondary" style="margin-left:6px;" @click="unapply()" :disabled="busy">
                                    <span x-show="!busy">Un-apply</span><span x-show="busy" x-cloak>…</span>
                                </button>
                            <?php endif; ?>
                            <span x-show="msg" x-cloak x-text="msg" class="text-xs text-danger" style="display:block;margin-top:4px;"></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2">Total Applied</th>
                    <th class="font-mono" style="text-align:right;">
                        <?php
                        $totalApplied = array_reduce(
                            array_filter($applications, fn($a) => ($a['application_status'] ?? 'applied') !== 'reversed'),
                            fn($c, $a) => bcadd($c, (string)$a['amount_applied'], 6),
                            '0'
                        );
                        echo format_currency(bcround($totalApplied, 2));
                        ?> <?= e($cn['currency']) ?>
                    </th>
                    <th colspan="3"></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Void modal -->
<?php if ($canEdit && $isApplicable): ?>
<div x-data="voidModal()" @open-void-modal.window="open()">
    <div class="modal-backdrop" x-show="show" x-cloak @click.self="close()">
        <div class="modal modal-md">
            <div class="modal-header">
                <h3 class="modal-title">Void Credit Note</h3>
                <button class="modal-close-btn" aria-label="Close" @click="close()">×</button>
            </div>
            <div class="modal-body">
                <div x-show="error" class="alert alert-danger" x-text="error" style="margin-bottom:1rem;"></div>
                <p style="margin:0 0 1rem;">You are about to void <strong><?= e($cn['credit_note_number']) ?></strong>.
                   Remaining balance of <strong class="font-mono"><?= format_currency($cn['amount_remaining']) ?></strong> will be cancelled.
                   This action cannot be undone.</p>
                <label class="form-label">Reason (required)</label>
                <textarea class="form-input" rows="3" x-model="reason" :disabled="submitting"
                          placeholder="Explain why this credit note is being voided…"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" @click="close()" :disabled="submitting">Cancel</button>
                <button class="btn btn-danger" @click="submit()" :disabled="submitting || !reason.trim()">
                    <span x-show="!submitting">Void Credit Note</span>
                    <span x-show="submitting">Voiding…</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function applyForm() {
    return {
        invoiceId: '',
        amount: '',
        submitting: false,
        error: '',
        success: '',

        submit() {
            this.error = '';
            this.success = '';
            if (!this.invoiceId || !this.amount) return;
            this.submitting = true;

            FF_Api.post('<?= base_url('api/v1/credit_notes/apply') ?>', {
                credit_note_id: parseInt(this.invoiceId, 10) ? <?= (int)$id ?> : <?= (int)$id ?>,
                invoice_id: parseInt(this.invoiceId, 10),
                amount: this.amount,
            })
            .then(data => {
                this.success = 'Applied ' + this.amount + ' to invoice ' + data.data.invoice_number + '. Invoice status: ' + data.data.invoice_status + '.';
                this.invoiceId = '';
                this.amount = '';
                // Reload page to reflect updated balance
                setTimeout(() => location.reload(), 1500);
            })
            .catch(err => {
                this.error = err.message || 'Failed to apply credit note.';
            })
            .finally(() => { this.submitting = false; });
        }
    };
}

function editMeta() {
    return {
        reason: <?= json_encode($cn['reason'] ?? '') ?>,
        expiresAt: <?= json_encode($cn['expires_at'] ?? '') ?>,
        internalNotes: <?= json_encode($cn['internal_notes'] ?? '') ?>,
        submitting: false,
        error: '',
        success: '',

        save() {
            this.error = '';
            this.success = '';
            this.submitting = true;

            FF_Api.post('<?= base_url('api/v1/credit_notes/update') ?>', {
                id: <?= (int)$id ?>,
                updated_at: <?= json_encode($cn['updated_at']) ?>,
                reason: this.reason,
                expires_at: this.expiresAt || null,
                internal_notes: this.internalNotes,
            })
            .then(() => {
                this.success = 'Changes saved.';
            })
            .catch(err => {
                this.error = err.message || 'Failed to save changes.';
            })
            .finally(() => { this.submitting = false; });
        }
    };
}

function voidModal() {
    return {
        show: false,
        reason: '',
        submitting: false,
        error: '',

        open() { this.show = true; this.error = ''; this.reason = ''; },
        close() { if (!this.submitting) { this.show = false; } },

        submit() {
            this.error = '';
            if (!this.reason.trim()) return;
            this.submitting = true;

            FF_Api.post('<?= base_url('api/v1/credit_notes/void') ?>', {
                id: <?= (int)$id ?>,
                reason: this.reason,
            })
            .then(() => {
                location.reload();
            })
            .catch(err => {
                this.error = err.message || 'Failed to void credit note.';
                this.submitting = false;
            });
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
