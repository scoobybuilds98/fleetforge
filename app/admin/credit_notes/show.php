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
 *   - Apply to Invoice form (shows when status is active or partially_used) —
 *     FF_RecordPicker limited to this customer's sent/partially_paid/overdue
 *     invoices (number + balance), Max = min(credit remaining, invoice balance)
 *   - Applications history table
 *   - Void modal (requires reason)
 *
 * Financial redaction (I03): the page is reachable with invoices:view alone
 * (dispatchers, payments:NONE). For viewers failing can_view_financials() the
 * money fields are nulled at the source and every dollar surface is withheld —
 * the Total/Remaining tiles, the Amount Applied column + Total Applied footer,
 * the Apply to Invoice card (every input is a dollar figure) and its
 * CN_REMAINING_CENTS script constant, and the void modal's balance figure.
 * Number, customer, source, status, currency, dates, reason and the linked
 * invoices stay visible. Mirrors api/v1/credit_notes/show.php.
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
        COALESCE(cn.company_name_snapshot, c.company_name) AS customer_name,
        cn.company_name_snapshot,
        cn.customer_name_snapshot,
        cn.billing_address_snapshot,
        cn.province_snapshot,
        cn.customer_email_snapshot,
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

// I03 serve-time redaction — mirrors api/v1/credit_notes/show.php, which already
// strips amount/amount_remaining/amount_applied for roles without payments:view.
// The page used to render all three (tiles, history table, void modal) AND embed
// the remaining balance in the applyForm() script, so a dispatcher saw every
// figure the API hid. Null them at the SOURCE so a template reference that
// escapes a gate below renders format_currency()'s '—', never the real figure.
$canSeeMoney = can_view_financials();
if (!$canSeeMoney) {
    $cn['amount']           = null;
    $cn['amount_remaining'] = null;
    foreach ($applications as &$_app) {
        $_app['amount_applied'] = null;
    }
    unset($_app);
}

// Source label map (PHP-side for server-rendered sections)
$sourceLabels = [
    'mileage_overpayment' => 'Mileage Overpayment',
    'invoice_adjustment'  => 'Invoice Adjustment',
    'damage_resolution'   => 'Damage Resolution',
    'goodwill'            => 'Goodwill',
    'payment_returned'    => 'Payment Returned',
    // System-minted sources (credit_notes.source ENUM) — previously rendered raw.
    'overpayment'         => 'Overpayment',
    'hours_overpayment'   => 'Hours Overpayment',
    'precharge_refund'    => 'Pre-charge Refund',
    'base_rental_reconciliation_overflow' => 'Rental Reconciliation Credit',
    'other'               => 'Other',
];

$isApplicable = in_array($cn['status'], ['active', 'partially_used'], true);
$canEdit      = can('invoices', 'edit');
$canCreate    = can('invoices', 'create');
// Applying credit is driven entirely by dollar figures (credit remaining, the
// picked invoice's balance, the amount to apply, the Max fill), so it is offered
// only to users who can see them. No built-in role has invoices:edit without
// payments:view — this only bites per-user overrides, who fall through to the
// Edit Metadata card instead. api/v1/credit_notes/apply.php is unchanged.
$showApply    = $canEdit && $isApplicable && $canSeeMoney;
// SOP I18: pay the unused credit back in cash (api/v1/credit_notes/refund.php).
$showRefund   = $showApply && can('payments', 'create');
$refundBanks  = $showRefund
    ? db_select("SELECT id, name, is_default FROM acc_bank_accounts WHERE is_active = 1 AND currency = ? ORDER BY is_default DESC, name", [$cn['currency']])
    : [];
$cnRefunds    = db_select(
    "SELECT r.refund_date, r.amount, r.method, r.reference, u.name AS by_name
       FROM credit_note_refunds r LEFT JOIN users u ON u.id = r.created_by
      WHERE r.credit_note_id = ? ORDER BY r.id",
    [(int) $cn['id']]
);

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

<!-- Summary tiles — the two money tiles are dropped (not zeroed: "$0.00 remaining"
     would misreport the note as used up) for non-financial viewers, so the
     .stat-grid--N modifier is resolved from the real tile count. -->
<div class="stat-grid <?= $canSeeMoney ? 'stat-grid--4' : 'stat-grid--2' ?>" style="margin-bottom:1.5rem;">
    <?php if ($canSeeMoney): ?>
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
    <?php endif; ?>
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

    <!-- Apply to Invoice card (shown only when credit is usable and its figures are visible) -->
    <?php if ($showApply): ?>
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
                    <label class="form-label">Invoice</label>
                    <?php
                    // Invoice picker (replaces a raw "Invoice ID" number box that made staff
                    // copy a database id off another page). Scoped server-side to what
                    // api/v1/credit_notes/apply.php will accept: THIS customer's invoices in
                    // a payable status (sent / partially_paid / overdue), oldest due first.
                    // Currency can't be filtered by invoices/index.php, so a mismatch is
                    // flagged in the sublabel and blocked on pick (D18).
                    $_cnCurJs     = json_encode((string) $cn['currency']);
                    $pickerConfig = [
                        'endpoint'    => '/api/v1/invoices/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 15,
                        'extraParams' => 'customer_id=' . (int) $cn['customer_id']
                                       . '&statuses=sent,partially_paid,overdue&sort=due_date&dir=ASC',
                        'placeholder' => 'Search this customer’s open invoices…',
                        'mapResult'   => "r => ({ id: r.id, label: r.invoice_number, sublabel: [r.currency + ' ' + Number(r.balance_due || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' due', r.due_date ? ('due ' + r.due_date) : '', String(r.status || '').replace('_', ' '), r.currency !== {$_cnCurJs} ? 'different currency — cannot apply' : ''].filter(Boolean).join(' · '), raw: r })",
                    ];
                    $pickerOnPicked  = 'onInvoicePicked($event.detail.raw)';
                    $pickerOnCleared = 'onInvoiceCleared()';
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    unset($_cnCurJs);
                    ?>
                    <div class="form-hint" x-show="!invoice">Only this customer’s sent, partially paid and overdue invoices are listed.</div>
                    <div class="form-hint" x-show="invoice" x-cloak>
                        Invoice balance:
                        <strong class="font-mono" x-text="invoice ? (invoice.currency + ' ' + money(invoice.balance_due)) : ''"></strong>
                        <span x-show="invoice && invoice.due_date" x-text="invoice ? (' · due ' + invoice.due_date) : ''"></span>
                    </div>
                </div>
                <div>
                    <label class="form-label">Amount to Apply (<?= e($cn['currency']) ?>)</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input class="form-input font-mono" type="text" x-model="amount"
                               placeholder="0.00" :disabled="submitting">
                        <!-- Max = the smaller of the credit remaining and the picked invoice's
                             balance (apply.php rejects anything above either). -->
                        <button type="button" class="btn btn-sm btn-secondary"
                                @click="fillMax()"
                                :disabled="submitting">Max</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-md" @click="submit()" :disabled="submitting || !invoiceId || !amount">
                    <span x-show="!submitting">Apply Credit</span>
                    <span x-show="submitting">Applying…</span>
                </button>
            </div>
        </div>
    </div>
    <?php if ($showRefund): ?>
    <!-- Refund as cash (SOP I18) -->
    <div class="card" x-data="refundForm()" style="margin-top:1rem;">
        <div class="card-header"><strong>Refund as Cash</strong></div>
        <div class="card-body">
            <p style="font-size:0.875rem; color:var(--text-secondary); margin:0 0 1rem;">
                Pay the customer back instead of applying the credit. Posts DR 2060 Customer Credits / CR the bank.
                Not sent to QuickBooks — record the refund against this credit memo in QuickBooks too.
            </p>
            <div x-show="error" class="alert alert-danger" x-text="error" style="margin-bottom:1rem;"></div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div>
                    <label class="form-label">Amount (<?= e($cn['currency']) ?>)</label>
                    <input class="form-input font-mono" type="text" x-model="amount">
                </div>
                <div>
                    <label class="form-label">Date paid</label>
                    <input class="form-input" type="date" x-model="refund_date">
                </div>
                <div>
                    <label class="form-label">Paid by</label>
                    <select class="form-input" x-model="method">
                        <option value="cheque">Cheque</option>
                        <option value="eft">EFT</option>
                        <option value="e_transfer">e-Transfer</option>
                        <option value="wire">Wire</option>
                        <option value="credit_card">Credit card</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Reference (cheque #…)</label>
                    <input class="form-input" type="text" x-model="reference" maxlength="100">
                </div>
                <?php if ($refundBanks): ?>
                <div style="grid-column:1 / -1;">
                    <label class="form-label">Paid from</label>
                    <select class="form-input" x-model="bank_account_id">
                        <option value="">Default bank account</option>
                        <?php foreach ($refundBanks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= $b['is_default'] ? ' (default)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div style="margin-top:1rem;">
                <button class="btn btn-primary btn-sm" @click="submit()" :disabled="submitting || !(parseFloat(amount) > 0)">
                    <span x-text="submitting ? 'Recording…' : 'Record Refund'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
                    <?php if ($canSeeMoney): ?>
                    <th style="text-align:right;">Amount Applied</th>
                    <?php endif; ?>
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
                    <?php if ($canSeeMoney): ?>
                    <td class="font-mono" style="text-align:right;"><?= format_currency($app['amount_applied']) ?> <?= e($cn['currency']) ?></td>
                    <?php endif; ?>
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
            <?php // The footer carries only the Total Applied figure — nothing to show without it. ?>
            <?php if ($canSeeMoney): ?>
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
            <?php endif; ?>
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
                   <?php if ($canSeeMoney): ?>
                   Remaining balance of <strong class="font-mono"><?= format_currency($cn['amount_remaining']) ?></strong> will be cancelled.
                   <?php else: ?>
                   Its remaining balance will be cancelled.
                   <?php endif; ?>
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

<?php if ($cnRefunds): ?>
<div class="card" style="margin-top:1rem;">
    <div class="card-header"><strong>Cash refunds</strong></div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="width:100%;">
            <thead><tr><th>Date</th><th style="text-align:right;">Amount</th><th>Method</th><th>Reference</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($cnRefunds as $r): ?>
                <tr>
                    <td><?= e(format_date($r['refund_date'])) ?></td>
                    <td class="font-mono" style="text-align:right;"><?= e($cn['currency'] . ' ' . number_format((float) $r['amount'], 2)) ?></td>
                    <td><?= e(str_replace('_', ' ', $r['method'])) ?></td>
                    <td><?= e($r['reference'] ?? '') ?></td>
                    <td><?= e($r['by_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// SOP I18: refund the unused credit in cash.
function refundForm() {
    return {
        amount: <?= json_encode((string) $cn['amount_remaining']) ?>,
        refund_date: FF_localDate(),
        method: 'cheque',
        reference: '',
        bank_account_id: '',
        submitting: false,
        error: '',
        async submit() {
            this.submitting = true;
            this.error = '';
            const r = await FF_Api.post('<?= base_url('api/v1/credit_notes/refund') ?>', {
                credit_note_id: <?= (int) $cn['id'] ?>,
                amount: String(this.amount),
                refund_date: this.refund_date,
                method: this.method,
                reference: this.reference || null,
                bank_account_id: this.bank_account_id || null,
            });
            this.submitting = false;
            if (r.success) {
                FF_Toast.success('Refund recorded. ' + (r.data.quickbooks_note || ''));
                setTimeout(() => location.reload(), 1000);
            } else {
                const f = (r.error && r.error.fields) || {};
                this.error = Object.values(f)[0] || (r.error && r.error.message) || 'Could not record the refund.';
            }
        },
    };
}
<?php
// applyForm() embeds the note's remaining balance in page source, so it is only
// emitted alongside the card that uses it — a financial viewer's page (see $showApply).
if ($showApply):
?>
function applyForm() {
    // Credit still available on this note, in integer cents (server-rendered).
    const CN_REMAINING_CENTS = <?= (int) bcmul((string) $cn['amount_remaining'], '100', 0) ?>;
    const CN_CURRENCY        = <?= json_encode((string) $cn['currency']) ?>;

    return {
        // NOTE: every key the nested invoice picker's @record-picked / @record-cleared
        // handlers write must be declared HERE — Alpine's merged-scope setter writes an
        // undeclared key onto the innermost (picker) scope, where this form never sees it.
        invoiceId: '',
        invoice: null,      // raw invoices/index.php row for the picked invoice
        amount: '',
        submitting: false,
        error: '',
        success: '',

        /**
         * Picker selection → remember the invoice; block a currency mismatch up front
         * (apply.php 422s it anyway — D18).
         * @param {object} raw invoices/index.php row
         */
        onInvoicePicked(raw) {
            this.error = '';
            this.success = '';
            if (!raw) { this.onInvoiceCleared(); return; }
            this.invoiceId = raw.id;
            this.invoice   = raw;
            if (raw.currency !== CN_CURRENCY) {
                this.error = 'Invoice ' + raw.invoice_number + ' is in ' + raw.currency
                    + '; this credit note is ' + CN_CURRENCY + '. Pick an invoice in ' + CN_CURRENCY + '.';
            }
        },

        onInvoiceCleared() {
            this.invoiceId = '';
            this.invoice   = null;
            this.error     = '';
        },

        /**
         * Parse a money string/number to integer cents (NaN when not numeric).
         * @param {string|number} v
         * @returns {number}
         */
        cents(v) {
            const n = parseFloat(v);
            return isNaN(n) ? NaN : Math.round(n * 100);
        },

        /**
         * @param {string|number} v
         * @returns {string} "$1,234.56"
         */
        money(v) {
            const n = parseFloat(v);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        /** Fill the largest amount apply.php will accept: min(credit remaining, invoice balance). */
        fillMax() {
            let max = CN_REMAINING_CENTS;
            if (this.invoice) {
                const bal = this.cents(this.invoice.balance_due);
                if (!isNaN(bal)) max = Math.min(max, bal);
            }
            this.amount = (Math.max(0, max) / 100).toFixed(2);
        },

        submit() {
            this.error = '';
            this.success = '';
            if (!this.invoiceId || !this.amount) return;

            // Client-side mirror of apply.php's guards so the common mistakes explain
            // themselves inline; the server re-checks everything under FOR UPDATE.
            const amt = this.cents(this.amount);
            if (isNaN(amt) || amt <= 0) { this.error = 'Enter an amount greater than zero.'; return; }
            if (this.invoice && this.invoice.currency !== CN_CURRENCY) {
                this.error = 'Invoice ' + this.invoice.invoice_number + ' is in ' + this.invoice.currency + '; this credit note is ' + CN_CURRENCY + '.';
                return;
            }
            if (amt > CN_REMAINING_CENTS) {
                this.error = 'Amount exceeds the credit remaining on this note (' + this.money(CN_REMAINING_CENTS / 100) + ').';
                return;
            }
            if (this.invoice && !isNaN(this.cents(this.invoice.balance_due)) && amt > this.cents(this.invoice.balance_due)) {
                this.error = 'Amount exceeds the balance due on ' + this.invoice.invoice_number + ' (' + this.money(this.invoice.balance_due) + ').';
                return;
            }
            this.submitting = true;

            FF_Api.post('<?= base_url('api/v1/credit_notes/apply') ?>', {
                credit_note_id: <?= (int)$id ?>,
                invoice_id: parseInt(this.invoiceId, 10),
                amount: this.amount,
            })
            .then(data => {
                // I09: FF_Api.post RESOLVES on 422 — gate on data.success or this
                // success path (and the page reload) runs on a failed apply.
                if (!data || !data.success) {
                    this.error = (data && data.error && data.error.message) || 'Failed to apply credit note.';
                    return;
                }
                this.success = 'Applied ' + this.amount + ' to invoice ' + data.data.invoice_number + '. Invoice status: ' + data.data.invoice_status + '.';
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
<?php endif; ?>

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
            .then(data => {
                // I09: gate on data.success — FF_Api.post resolves on 422, so this
                // previously showed "Changes saved." even when the update failed.
                if (!data || !data.success) {
                    this.error = (data && data.error && data.error.message) || 'Failed to save changes.';
                    return;
                }
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
            .then(data => {
                // I09: gate on data.success — FF_Api.post resolves on 422, so this
                // previously reloaded (masking the error) even when the void failed.
                if (!data || !data.success) {
                    this.error = (data && data.error && data.error.message) || 'Failed to void credit note.';
                    this.submitting = false;
                    return;
                }
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

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php $activityEntityType = 'credit_note'; $activityEntityId = $id; ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
