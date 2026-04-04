<?php
declare(strict_types=1);

/**
 * app/admin/invoices/show.php
 *
 * Comprehensive invoice detail page — the most feature-rich module in FleetForge.
 * Designed to be sent to customers, printed, and used for internal tracking.
 *
 * Sections:
 *   1. Status timeline + KPI stat cards
 *   2. From/To billing addresses + invoice metadata
 *   3. Rate method explanation (collapsible)
 *   4. Detailed line items with expandable calculation breakdowns
 *   5. Financial summary with full tax breakdown
 *   6. Delivery & tracking (sent date/by/method, late fees, credit notes)
 *   7. Payment history with allocations
 *   8. Notes (inline-editable for drafts)
 *   9. Activity log (audit trail)
 *  10. Action modals (Send, Void, Delete) + print support
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D5 (soft-delete), D12 (immutability), D16 (bcmath), D19 (optimistic lock), D22 (tax)
 * @session  S019
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

/* ─── Resolve invoice ID ────────────────────────────────────────── */
$invoiceId = clean_int($_GET['id'] ?? null);
if (!$invoiceId || $invoiceId <= 0) {
    header('Location: ' . base_url('invoices'));
    exit;
}

/* ─── Load invoice ──────────────────────────────────────────────── */
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

/* ─── Decode JSON fields ────────────────────────────────────────── */
$rateExplanation = null;
if (!empty($invoice['rate_method_explanation'])) {
    $rateExplanation = json_decode($invoice['rate_method_explanation'], true);
}
$sentCcEmails = [];
if (!empty($invoice['sent_cc_emails'])) {
    $sentCcEmails = json_decode($invoice['sent_cc_emails'], true) ?: [];
}

/* ─── Load line items ───────────────────────────────────────────── */
$lineItems = db_select(
    "SELECT * FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order ASC",
    [$invoiceId]
);

// WHY: Decode detail_lines JSON per item for rendering calculation breakdowns
foreach ($lineItems as &$item) {
    if (!empty($item['detail_lines'])) {
        $item['_detail'] = json_decode($item['detail_lines'], true);
    } else {
        $item['_detail'] = null;
    }
}
unset($item);

/* ─── Load payment allocations with payment details ─────────────── */
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

/* ─── Load credit notes linked to this invoice ─────────────────── */
// WHY: Credits sourced FROM this invoice (e.g., mileage overpayment generated a credit)
$creditNotesFrom = db_select(
    "SELECT id, credit_note_number, amount, amount_remaining, status, source, reason, created_at
     FROM credit_notes
     WHERE source_invoice_id = ? AND deleted_at IS NULL
     ORDER BY created_at DESC",
    [$invoiceId]
);
// WHY: If this invoice IS a credit note for another invoice
$creditNoteForInvoice = null;
if (!empty($invoice['credit_note_for_invoice_id'])) {
    $creditNoteForInvoice = db_row(
        "SELECT id, invoice_number, total_amount FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$invoice['credit_note_for_invoice_id']]
    );
}

/* ─── Load late fee linked invoice ─────────────────────────────── */
$lateFeeInvoice = null;
if (!empty($invoice['late_fee_invoice_id'])) {
    $lateFeeInvoice = db_row(
        "SELECT id, invoice_number, total_amount, status FROM invoices WHERE id = ? AND deleted_at IS NULL",
        [$invoice['late_fee_invoice_id']]
    );
}

/* ─── Load user names for tracking fields ──────────────────────── */
// WHY: Display human-readable names instead of raw user IDs
$userIds = array_filter([
    $invoice['created_by'] ?? null,
    $invoice['updated_by'] ?? null,
    $invoice['sent_by'] ?? null,
    $invoice['voided_by'] ?? null,
    $invoice['written_off_by'] ?? null,
]);
$userNames = [];
if (!empty($userIds)) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $users = db_select(
        "SELECT id, name FROM users WHERE id IN ({$placeholders})",
        array_values($userIds)
    );
    foreach ($users as $u) {
        $userNames[(int)$u['id']] = $u['name'];
    }
}

/* ─── Load activity log (most recent 50 entries) ────────────────── */
$activityLog = db_select(
    "SELECT action, user_name, notes, old_values, new_values, ip_address, created_at
     FROM audit_log
     WHERE entity_type = 'invoice' AND entity_id = ?
     ORDER BY created_at DESC
     LIMIT 50",
    [$invoiceId]
);

/* ─── Computed display values ───────────────────────────────────── */
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

$typeBadgeClass = match($invoice['invoice_type']) {
    'regular'     => 'badge-info',
    'final'       => 'badge-warning',
    'credit_note' => 'badge-success',
    'late_fee'    => 'badge-danger',
    'mileage_only'=> 'badge-neutral',
    'adjustment'  => 'badge-warning',
    default       => 'badge-neutral',
};

$typeLabels = [
    'regular'      => 'Regular',
    'final'        => 'Final',
    'credit_note'  => 'Credit Note',
    'late_fee'     => 'Late Fee',
    'mileage_only' => 'Mileage Only',
    'adjustment'   => 'Adjustment',
];

$methodLabels = [
    'check' => 'Cheque', 'ach' => 'ACH', 'wire' => 'Wire',
    'credit_card' => 'Credit Card', 'cash' => 'Cash',
    'e_transfer' => 'e-Transfer', 'account_credit' => 'Acct Credit', 'other' => 'Other',
];

$isOverdue = ($invoice['status'] !== 'paid' && $invoice['status'] !== 'void'
    && $invoice['status'] !== 'written_off' && !empty($invoice['due_date'])
    && $invoice['due_date'] < date('Y-m-d'));

$isDraft      = ($invoice['status'] === 'draft');
$isVoid       = ($invoice['status'] === 'void');
$isWrittenOff = ($invoice['status'] === 'written_off');
$isPaid       = ($invoice['status'] === 'paid');
$canRecordPayment = in_array($invoice['status'], ['sent', 'partially_paid', 'overdue']);
// WHY: Super admin can edit/delete invoices of any status — all other roles are draft-only (D12)
$isSuperAdmin = is_super_admin();
$canEdit   = ($isDraft || $isSuperAdmin) && can('invoices', 'edit');
$canDelete = ($isDraft || $isSuperAdmin) && can('invoices', 'delete');

// WHY: Count line items by category for summary badges
$creditLineCount = 0;
$mileageLineCount = 0;
foreach ($lineItems as $li) {
    if ($li['is_credit']) $creditLineCount++;
    if ($li['item_type'] === 'mileage_charge' || $li['item_type'] === 'mileage_overage') $mileageLineCount++;
}

$pageTitle = 'Invoice ' . $invoice['invoice_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ================================================================
     PRINT-ONLY STYLES — Scoped to this page
     ================================================================ -->
<style>
    /* Print-specific overrides for professional invoice output */
    @media print {
        .no-print, .page-header-actions, .breadcrumb,
        .invoice-actions-bar, .activity-log-section,
        .btn, button { display: none !important; }
        .invoice-status-timeline { display: none !important; }
        .stat-grid { break-inside: avoid; }
        .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
        .invoice-addresses { break-inside: avoid; }
        .line-items-card { break-inside: avoid; }
        .financial-summary-card { break-inside: avoid; }
        body { font-size: 12px; }
        .page-content { padding: 0 !important; }
        /* Show print header */
        .print-only { display: block !important; }
    }
    @media screen {
        .print-only { display: none !important; }
    }

    /* Status timeline styles */
    .invoice-status-timeline {
        display: flex;
        align-items: center;
        gap: 0;
        padding: 16px 20px;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        margin-bottom: 24px;
    }
    .timeline-step {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--text-muted);
        white-space: nowrap;
    }
    .timeline-step.active {
        color: var(--text-primary);
        font-weight: 600;
    }
    .timeline-step.completed {
        color: var(--color-success);
    }
    .timeline-step.void-step {
        color: var(--color-danger);
    }
    .timeline-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--border-color);
        flex-shrink: 0;
    }
    .timeline-step.active .timeline-dot {
        background: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(var(--color-primary-rgb, 59,130,246), 0.2);
    }
    .timeline-step.completed .timeline-dot { background: var(--color-success); }
    .timeline-step.void-step .timeline-dot { background: var(--color-danger); }
    .timeline-connector {
        flex: 1;
        height: 2px;
        background: var(--border-color);
        margin: 0 8px;
        min-width: 20px;
    }
    .timeline-connector.completed { background: var(--color-success); }

    /* Detail lines toggle */
    .detail-toggle {
        cursor: pointer;
        color: var(--color-primary);
        font-size: 12px;
        text-decoration: underline;
        text-decoration-style: dotted;
    }
    .detail-toggle:hover { text-decoration-style: solid; }
    .detail-expansion {
        background: var(--bg-muted);
        border-radius: 4px;
        padding: 8px 12px;
        margin-top: 6px;
        font-size: 12px;
        line-height: 1.6;
    }
    .detail-expansion li {
        color: var(--text-secondary);
        list-style: none;
        padding: 1px 0;
    }
    .detail-expansion li:last-child {
        font-weight: 600;
        color: var(--text-primary);
        border-top: 1px dashed var(--border-color);
        padding-top: 4px;
        margin-top: 4px;
    }

    /* Mileage detail block */
    .mileage-detail {
        display: inline-flex;
        gap: 12px;
        font-size: 11px;
        color: var(--text-secondary);
        margin-top: 4px;
    }
    .mileage-detail span { white-space: nowrap; }

    /* Financial summary */
    .financial-summary-table { width: 100%; border-collapse: collapse; }
    .financial-summary-table td { padding: 6px 16px; font-size: 13px; }
    .financial-summary-table .fs-label { color: var(--text-secondary); }
    .financial-summary-table .fs-value { text-align: right; font-family: 'DM Mono', monospace; }
    .financial-summary-table .fs-total { font-weight: 700; font-size: 15px; }
    .financial-summary-table .fs-divider td {
        border-top: 1px solid var(--border-color);
        padding-top: 10px;
    }
    .financial-summary-table .fs-grand td {
        border-top: 2px solid var(--text-primary);
        padding-top: 12px;
        padding-bottom: 12px;
    }

    /* Activity log */
    .activity-item {
        display: flex;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border-color);
        font-size: 13px;
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--color-primary);
        margin-top: 6px;
        flex-shrink: 0;
    }
    .activity-dot.action-create { background: var(--color-success); }
    .activity-dot.action-update { background: var(--color-primary); }
    .activity-dot.action-status_change { background: var(--color-warning); }
    .activity-dot.action-invoice_sent { background: var(--color-info); }
    .activity-dot.action-invoice_voided { background: var(--color-danger); }
    .activity-dot.action-payment_recorded { background: var(--color-success); }
    .activity-dot.action-delete { background: var(--color-danger); }

    /* Inline edit form */
    .inline-edit-section {
        background: var(--bg-muted);
        border-top: 1px solid var(--border-color);
        padding: 20px;
        border-radius: 0 0 var(--radius) var(--radius);
    }
</style>

<!-- ================================================================
     Breadcrumb + Page Header
     ================================================================ -->
<nav class="breadcrumb no-print">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($invoice['invoice_number']) ?></span>
</nav>

<div class="page-header" x-data="FF_InvoiceShow()" x-cloak>
    <div>
        <h1 class="page-header-title h4">
            <?= e($invoice['invoice_number']) ?>
            <span class="badge badge-no-dot <?= $statusBadgeClass ?>" style="vertical-align:middle; margin-left:8px;">
                <?= e(ucfirst(str_replace('_', ' ', $invoice['status']))) ?>
            </span>
            <?php if ($invoice['invoice_type'] !== 'regular'): ?>
            <span class="badge badge-no-dot <?= $typeBadgeClass ?>" style="vertical-align:middle; margin-left:4px; font-size:11px;">
                <?= e($typeLabels[$invoice['invoice_type']] ?? $invoice['invoice_type']) ?>
            </span>
            <?php endif; ?>
            <?php if ($isOverdue): ?>
            <span class="badge badge-no-dot badge-danger" style="vertical-align:middle; margin-left:4px; font-size:11px;">
                OVERDUE
            </span>
            <?php endif; ?>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            <?php if ($invoice['customer_id']): ?>
                <a href="<?= base_url('customers/show') ?>?id=<?= (int)$invoice['customer_id'] ?>" class="link">
                    <?= e($invoice['company_name_snapshot'] ?? $invoice['customer_name_snapshot'] ?? '—') ?>
                </a>
            <?php else: ?>
                <?= e($invoice['company_name_snapshot'] ?? '—') ?>
            <?php endif; ?>
            <?php if ($invoice['contract_number_snapshot']): ?>
                <span class="text-muted">·</span> <?= e($invoice['contract_number_snapshot']) ?>
            <?php endif; ?>
            <?php if ($invoice['unit_number_invoice_snapshot']): ?>
                <span class="text-muted">·</span> Unit <?= e($invoice['unit_number_invoice_snapshot']) ?>
            <?php endif; ?>
            <?php if ($invoice['currency'] !== 'CAD'): ?>
                <span class="text-muted">·</span>
                <span class="badge badge-no-dot badge-warning" style="font-size:10px;"><?= e($invoice['currency']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="page-header-actions">
        <!-- Print -->
        <button class="btn btn-secondary btn-sm no-print" onclick="window.print();" title="Print Invoice">
            <?= heroicon('document-text', 'icon-sm') ?>
            Print
        </button>

        <?php if ($canEdit): ?>
            <!-- Edit Notes -->
            <button class="btn btn-secondary btn-sm" @click="startEdit()">
                <?= heroicon('pencil-square', 'icon-sm') ?>
                Edit
            </button>
            <!-- Send -->
            <button class="btn btn-primary btn-sm" @click="sendInvoice()" :disabled="sending">
                <span x-show="!sending">Send Invoice</span>
                <span x-show="sending">Sending…</span>
            </button>
        <?php endif; ?>

        <?php if ($canRecordPayment && can('payments', 'create')): ?>
            <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
               class="btn btn-success btn-sm">
                <?= heroicon('banknotes', 'icon-sm') ?>
                Record Payment
            </a>
        <?php endif; ?>

        <?php if ($invoice['lease_id']): ?>
            <a href="<?= base_url('leases/show') ?>?id=<?= (int)$invoice['lease_id'] ?>" class="btn btn-secondary btn-sm">
                View Lease
            </a>
        <?php endif; ?>

        <?php if (in_array($invoice['status'], ['draft', 'sent']) && can('invoices', 'edit')): ?>
            <button class="btn btn-danger btn-sm" @click="showVoidModal = true">Void</button>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <button class="btn btn-danger btn-sm" @click="showDeleteModal = true">Delete</button>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         MODALS — Void, Delete
         ============================================================ -->

    <!-- Void modal -->
    <template x-if="showVoidModal">
        <div class="modal-overlay" @click.self="showVoidModal = false">
            <div class="modal modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">Void Invoice</h3>
                    <button class="modal-close" @click="showVoidModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-sm text-secondary" style="margin-bottom:12px;">
                        This will permanently void invoice <strong><?= e($invoice['invoice_number']) ?></strong>.
                        Voided invoices cannot be edited or sent.
                    </p>
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
                    <p>Are you sure you want to delete invoice <strong><?= e($invoice['invoice_number']) ?></strong>
                       (status: <strong><?= e($invoice['status']) ?></strong>)?</p>
                    <p class="text-sm text-danger" style="margin-top:8px;">This action cannot be undone.</p>
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


<!-- ================================================================
     STATUS TIMELINE — Visual invoice lifecycle
     ================================================================ -->
<?php
// WHY: Determine which steps are completed/active for visual timeline
$steps = [
    ['key' => 'draft',     'label' => 'Draft',     'date' => $invoice['created_at']],
    ['key' => 'sent',      'label' => 'Sent',      'date' => $invoice['sent_date']],
    ['key' => 'paid',      'label' => 'Paid',      'date' => $invoice['paid_date']],
];

$statusOrder = ['draft' => 0, 'sent' => 1, 'partially_paid' => 2, 'overdue' => 2, 'paid' => 3];
$currentIdx = $statusOrder[$invoice['status']] ?? 0;
?>
<div class="invoice-status-timeline no-print">
    <?php foreach ($steps as $i => $step): ?>
        <?php if ($i > 0): ?>
            <div class="timeline-connector <?= ($i <= $currentIdx && !$isVoid && !$isWrittenOff) ? 'completed' : '' ?>"></div>
        <?php endif; ?>
        <?php
        $stepClass = '';
        if ($isVoid || $isWrittenOff) {
            // WHY: If void/written-off, show completed steps up to where it stopped
            if ($step['date']) $stepClass = 'completed';
        } elseif ($i < $currentIdx) {
            $stepClass = 'completed';
        } elseif ($i === $currentIdx) {
            $stepClass = 'active';
        }
        ?>
        <div class="timeline-step <?= $stepClass ?>">
            <div class="timeline-dot"></div>
            <div>
                <div><?= e($step['label']) ?></div>
                <?php if ($step['date']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($step['date']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($isVoid): ?>
        <div class="timeline-connector"></div>
        <div class="timeline-step void-step active">
            <div class="timeline-dot"></div>
            <div>
                <div>Voided</div>
                <?php if ($invoice['voided_date']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($invoice['voided_date']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($isWrittenOff): ?>
        <div class="timeline-connector"></div>
        <div class="timeline-step void-step active">
            <div class="timeline-dot"></div>
            <div>
                <div>Written Off</div>
                <?php if ($invoice['written_off_at']): ?>
                    <div class="text-sm" style="font-size:11px; opacity:0.7;"><?= format_date($invoice['written_off_at']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     KPI STAT CARDS
     ================================================================ -->
<div class="stat-grid" style="grid-template-columns: repeat(5, 1fr);">
    <div class="stat-card">
        <div class="stat-label">Invoice Date</div>
        <div class="stat-value font-mono"><?= format_date($invoice['invoice_date']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Due Date</div>
        <div class="stat-value font-mono" <?php if ($isOverdue): ?>style="color:var(--color-danger);"<?php endif; ?>>
            <?= format_date($invoice['due_date']) ?>
            <?php if ($isOverdue): ?>
                <?php
                $daysOverdue = (int)((strtotime('today') - strtotime($invoice['due_date'])) / 86400);
                ?>
                <div class="text-sm" style="font-size:11px; color:var(--color-danger); margin-top:2px;">
                    <?= $daysOverdue ?> day<?= $daysOverdue !== 1 ? 's' : '' ?> overdue
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Amount</div>
        <div class="stat-value font-mono"><?= format_currency($invoice['total_amount']) ?></div>
        <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
            <div class="text-sm text-secondary" style="margin-top:2px; font-size:11px;">
                ≈ <?= format_currency(bcmul($invoice['total_amount'], $invoice['exchange_rate_to_cad'], 2)) ?> CAD
            </div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-label">Amount Paid</div>
        <div class="stat-value font-mono" style="<?= bccomp($invoice['amount_paid'], '0', 2) > 0 ? 'color:var(--color-success);' : '' ?>">
            <?= format_currency($invoice['amount_paid']) ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Balance Due</div>
        <div class="stat-value font-mono" <?php if (bccomp($invoice['balance_due'], '0', 2) > 0): ?>style="color:var(--color-danger);"<?php endif; ?>>
            <?= format_currency($invoice['balance_due']) ?>
        </div>
        <?php if ($isPaid): ?>
            <div class="text-sm" style="color:var(--color-success); font-size:11px; margin-top:2px;">
                ✓ Paid in full<?php if ($invoice['paid_date']): ?> · <?= format_date($invoice['paid_date']) ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- ================================================================
     FROM / TO ADDRESSES + INVOICE METADATA
     ================================================================ -->
<div class="invoice-addresses" style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">

    <!-- Left: Customer Billing Info (the "Bill To" section) -->
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">Bill To</h3>
        <div style="font-size:14px; line-height:1.7;">
            <?php if ($invoice['company_name_snapshot']): ?>
                <div style="font-weight:600; font-size:15px;"><?= e($invoice['company_name_snapshot']) ?></div>
            <?php endif; ?>
            <?php if ($invoice['customer_name_snapshot'] && $invoice['customer_name_snapshot'] !== $invoice['company_name_snapshot']): ?>
                <div><?= e($invoice['customer_name_snapshot']) ?></div>
            <?php endif; ?>
            <?php if ($invoice['billing_address_snapshot']): ?>
                <div style="margin-top:6px; color:var(--text-secondary);">
                    <?= nl2br(e($invoice['billing_address_snapshot'])) ?>
                </div>
            <?php endif; ?>
            <?php if ($invoice['customer_email_snapshot']): ?>
                <div style="margin-top:6px;">
                    <span class="text-secondary">Email:</span>
                    <a href="mailto:<?= e($invoice['customer_email_snapshot']) ?>" class="link"><?= e($invoice['customer_email_snapshot']) ?></a>
                </div>
            <?php endif; ?>
        </div>
        <!-- Tax exemptions -->
        <?php if ($invoice['gst_exempt_snapshot'] || $invoice['pst_exempt_snapshot'] || $invoice['tax_exempt_snapshot']): ?>
        <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--border-color);">
            <?php if ($invoice['tax_exempt_snapshot']): ?>
                <span class="badge badge-no-dot badge-warning" style="font-size:11px;">Fully Tax Exempt</span>
            <?php else: ?>
                <?php if ($invoice['gst_exempt_snapshot']): ?>
                    <span class="badge badge-no-dot badge-warning" style="font-size:11px;">GST Exempt</span>
                <?php endif; ?>
                <?php if ($invoice['pst_exempt_snapshot']): ?>
                    <span class="badge badge-no-dot badge-warning" style="font-size:11px;">PST Exempt</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Invoice Metadata -->
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">Invoice Details</h3>
        <dl style="display:grid; grid-template-columns:140px 1fr; gap:6px 16px; font-size:13px; margin:0;">
            <dt class="text-secondary">Billing Period</dt>
            <dd class="font-mono"><?= format_date($invoice['billing_period_start']) ?> → <?= format_date($invoice['billing_period_end']) ?></dd>

            <dt class="text-secondary">Billing Days</dt>
            <dd><?= (int)$invoice['billing_period_days'] ?> days
                <span class="badge badge-no-dot badge-neutral" style="font-size:10px; margin-left:4px;"><?= e($invoice['billing_type']) ?></span>
            </dd>

            <dt class="text-secondary">Rate Method</dt>
            <dd><?= e(ucfirst($invoice['rate_method_used'] ?? '—')) ?></dd>

            <dt class="text-secondary">Currency</dt>
            <dd>
                <?= e($invoice['currency']) ?>
                <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
                    <span class="text-secondary text-sm">(1 <?= e($invoice['currency']) ?> = <?= e(rtrim(rtrim(number_format((float)$invoice['exchange_rate_to_cad'], 6), '0'), '.')) ?> CAD)</span>
                <?php endif; ?>
            </dd>

            <?php if ($invoice['po_number']): ?>
            <dt class="text-secondary">PO Number</dt>
            <dd class="font-mono"><?= e($invoice['po_number']) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['auto_generated']): ?>
            <dt class="text-secondary">Source</dt>
            <dd>
                <span class="badge badge-no-dot badge-neutral" style="font-size:11px;">Auto-generated</span>
                <?= e($invoice['generation_source'] ?? '') ?>
                <?php if ($invoice['auto_generated_at']): ?>
                    <span class="text-sm text-secondary">· <?= format_datetime($invoice['auto_generated_at']) ?></span>
                <?php endif; ?>
            </dd>
            <?php endif; ?>

            <dt class="text-secondary">Created</dt>
            <dd>
                <?= format_datetime($invoice['created_at']) ?>
                <?php if (isset($userNames[(int)($invoice['created_by'] ?? 0)])): ?>
                    <span class="text-secondary">by <?= e($userNames[(int)$invoice['created_by']]) ?></span>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>


<!-- ================================================================
     RATE METHOD EXPLANATION — How the rental charge was calculated
     ================================================================ -->
<?php
// WHY: rate_method_explanation can be either {amount, method, explanation:[...]} or just [string, string, ...]
$rateLines = [];
$rateMethodLabel = $invoice['rate_method_used'] ?? '';
if ($rateExplanation) {
    if (isset($rateExplanation['explanation']) && is_array($rateExplanation['explanation'])) {
        $rateLines = $rateExplanation['explanation'];
        $rateMethodLabel = $rateExplanation['method'] ?? $rateMethodLabel;
    } elseif (is_array($rateExplanation) && !isset($rateExplanation['explanation'])) {
        // Flat array of explanation strings
        $rateLines = $rateExplanation;
    }
}
?>
<?php if (!empty($rateLines)): ?>
<div class="card" style="margin-bottom:24px; padding:16px 20px;">
    <div style="display:flex; align-items:center; gap:8px; cursor:pointer;"
         onclick="this.parentElement.querySelector('.rate-explanation-body').classList.toggle('hidden')">
        <?= heroicon('calculator', 'icon-sm') ?>
        <h3 style="font-size:13px; font-weight:600; margin:0;">Rate Calculation Breakdown</h3>
        <span class="badge badge-no-dot badge-neutral" style="font-size:11px;"><?= e(ucfirst($rateMethodLabel)) ?> method</span>
        <span class="text-sm text-secondary" style="margin-left:auto;">click to toggle</span>
    </div>
    <div class="rate-explanation-body" style="margin-top:12px;">
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach ($rateLines as $line): ?>
                <li style="padding:3px 0; font-size:13px; color:var(--text-secondary); font-family:'DM Mono',monospace;">
                    <?= e(is_string($line) ? $line : json_encode($line)) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     LINE ITEMS TABLE — Comprehensive with expandable details
     ================================================================ -->
<div class="card line-items-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Line Items</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($lineItems) ?> item<?= count($lineItems) !== 1 ? 's' : '' ?></span>
        <?php if ($creditLineCount > 0): ?>
            <span class="badge badge-no-dot badge-success" style="font-size:11px;"><?= $creditLineCount ?> credit<?= $creditLineCount !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <?php if ($mileageLineCount > 0): ?>
            <span class="badge badge-no-dot badge-info" style="font-size:11px;"><?= $mileageLineCount ?> mileage</span>
        <?php endif; ?>
    </div>

    <?php if (empty($lineItems)): ?>
        <div class="empty-state" style="padding:40px;">
            <p class="empty-state-title">No line items</p>
            <p class="empty-state-text">This invoice has no line items.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" aria-label="Invoice line items">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th style="width:110px;">Type</th>
                        <th>Description</th>
                        <th style="width:140px;">Period</th>
                        <th style="text-align:right; width:60px;">Qty</th>
                        <th style="text-align:right; width:100px;">Unit Price</th>
                        <th style="text-align:right; width:80px;">Tax</th>
                        <th style="text-align:right; width:110px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $i => $item):
                        $isCreditLine = (bool)$item['is_credit'];
                        $rowStyle = $isCreditLine ? 'color:var(--color-success);' : '';
                        $hasDetail = !empty($item['_detail']);
                        $isMileage = in_array($item['item_type'], ['mileage_charge', 'mileage_overage']);

                        // WHY: Calculate per-line tax total for display
                        $lineTaxTotal = bcadd(
                            bcadd($item['tax_gst_amount'] ?? '0', $item['tax_pst_amount'] ?? '0', 2),
                            $item['tax_hst_amount'] ?? '0',
                            2
                        );

                        // WHY: Item type badge color mapping
                        $itemTypeBadge = match($item['item_type']) {
                            'base_rental'       => 'badge-info',
                            'mileage_charge'    => 'badge-neutral',
                            'mileage_overage'   => 'badge-warning',
                            'late_fee'          => 'badge-danger',
                            'damage_charge'     => 'badge-danger',
                            'credit'            => 'badge-success',
                            'adjustment'        => 'badge-warning',
                            'discount'          => 'badge-success',
                            'tax_adjustment'    => 'badge-neutral',
                            'fuel_surcharge'    => 'badge-neutral',
                            'insurance'         => 'badge-neutral',
                            'admin_fee'         => 'badge-neutral',
                            default             => 'badge-neutral',
                        };
                    ?>
                    <tr style="<?= $rowStyle ?>">
                        <td class="text-secondary"><?= $i + 1 ?></td>
                        <td>
                            <span class="badge badge-no-dot <?= $itemTypeBadge ?>" style="font-size:11px; white-space:nowrap;">
                                <?= e(ucwords(str_replace('_', ' ', $item['item_type']))) ?>
                            </span>
                        </td>
                        <td>
                            <div><?= e($item['description']) ?></div>

                            <?php if ($item['billing_days'] || $item['rate_method']): ?>
                                <div class="text-sm text-secondary" style="margin-top:2px;">
                                    <?php if ($item['billing_days']): ?>
                                        <?= (int)$item['billing_days'] ?> billing days
                                    <?php endif; ?>
                                    <?php if ($item['rate_method']): ?>
                                        · <?= e(ucfirst($item['rate_method'])) ?> rate
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($isMileage): ?>
                                <div class="mileage-detail">
                                    <?php if ($item['mileage_distance']): ?>
                                        <span>Distance: <?= e(rtrim(rtrim(number_format((float)$item['mileage_distance'], 2), '0'), '.')) ?> <?= e($item['mileage_unit'] ?? 'km') ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_rate']): ?>
                                        <span>Rate: <?= format_currency($item['mileage_rate']) ?>/<?= e($item['mileage_unit'] ?? 'km') ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_estimated']): ?>
                                        <span>Est: <?= e(number_format((float)$item['mileage_estimated'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($item['mileage_actual']): ?>
                                        <span>Act: <?= e(number_format((float)$item['mileage_actual'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- WHY: Expandable detail lines show the pro-rate calculation breakdown -->
                            <?php if ($hasDetail): ?>
                                <div style="margin-top:4px;">
                                    <span class="detail-toggle"
                                          onclick="this.nextElementSibling.classList.toggle('hidden'); this.textContent = this.nextElementSibling.classList.contains('hidden') ? 'Show calculation ▸' : 'Hide calculation ▾'">
                                        Show calculation ▸
                                    </span>
                                    <div class="detail-expansion hidden">
                                        <ul style="margin:0; padding:0;">
                                            <?php
                                            // WHY: detail_lines can be {amount, method, explanation:[]} or just an array of strings
                                            $explanationLines = $item['_detail']['explanation'] ?? $item['_detail'];
                                            if (is_array($explanationLines)):
                                                foreach ($explanationLines as $line):
                                            ?>
                                                <li><?= e(is_string($line) ? $line : json_encode($line)) ?></li>
                                            <?php endforeach; endif; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono text-sm">
                            <?php if ($item['period_start'] && $item['period_end']): ?>
                                <?= format_date($item['period_start']) ?>
                                <br>→ <?= format_date($item['period_end']) ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right;">
                            <?= e(rtrim(rtrim(number_format((float)$item['quantity'], 4), '0'), '.')) ?>
                            <?php if ($item['unit']): ?>
                                <div class="text-sm text-secondary" style="font-size:11px;"><?= e($item['unit']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right;">
                            <?= format_currency($item['unit_price']) ?>
                        </td>
                        <td class="font-mono text-sm" style="text-align:right;">
                            <?php if (bccomp($lineTaxTotal, '0', 2) > 0): ?>
                                <?= format_currency($lineTaxTotal) ?>
                                <?php if (!$item['taxable']): ?>
                                    <div class="text-sm text-secondary" style="font-size:10px;">non-taxable</div>
                                <?php endif; ?>
                            <?php elseif (!$item['taxable']): ?>
                                <span class="text-secondary" style="font-size:11px;">exempt</span>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono" style="text-align:right; font-weight:600;">
                            <?php if ($isCreditLine): ?>−<?php endif; ?>
                            <?= format_currency($item['amount']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-muted);">
                        <td colspan="7" style="text-align:right; font-weight:600; font-size:13px; padding:12px 14px;">
                            Subtotal
                        </td>
                        <td class="font-mono" style="text-align:right; font-weight:700; padding:12px 14px;">
                            <?= format_currency($invoice['subtotal']) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     FINANCIAL SUMMARY — Full tax breakdown with visual hierarchy
     ================================================================ -->
<div class="card financial-summary-card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color);">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Financial Summary</h3>
    </div>
    <div style="padding:16px 20px;">
        <table class="financial-summary-table" style="max-width:480px; margin-left:auto;">
            <tbody>
                <!-- Subtotal -->
                <tr>
                    <td class="fs-label">Subtotal</td>
                    <td class="fs-value"><?= format_currency($invoice['subtotal']) ?></td>
                </tr>

                <!-- Discount -->
                <?php if (bccomp($invoice['discount_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">
                        Discount
                        <?php if ($invoice['discount_type'] === 'percentage'): ?>
                            (<?= e(rtrim(rtrim(number_format((float)($invoice['discount_value'] ?? 0), 2), '0'), '.')) ?>%)
                        <?php elseif ($invoice['discount_type'] === 'fixed'): ?>
                            (fixed)
                        <?php endif; ?>
                    </td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['discount_amount']) ?></td>
                </tr>
                <tr>
                    <td class="fs-label">Subtotal After Discount</td>
                    <td class="fs-value"><?= format_currency($invoice['subtotal_after_discount']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Tax Breakdown -->
                <?php if (bccomp($invoice['tax_gst_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">GST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_gst_rate'] ?? 0), 4), '0'), '.')) ?>%)</td>
                    <td class="fs-value"><?= format_currency($invoice['tax_gst_amount']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_pst_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">PST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_pst_rate'] ?? 0), 4), '0'), '.')) ?>%)</td>
                    <td class="fs-value"><?= format_currency($invoice['tax_pst_amount']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_hst_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">HST (<?= e(rtrim(rtrim(number_format((float)($invoice['tax_hst_rate'] ?? 0), 4), '0'), '.')) ?>%)</td>
                    <td class="fs-value"><?= format_currency($invoice['tax_hst_amount']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['tax_total'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label" style="font-weight:500;">Tax Total</td>
                    <td class="fs-value" style="font-weight:500;"><?= format_currency($invoice['tax_total']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Total Amount -->
                <tr class="fs-grand">
                    <td class="fs-label fs-total">Total Amount</td>
                    <td class="fs-value fs-total"><?= format_currency($invoice['total_amount']) ?> <?= e($invoice['currency']) ?></td>
                </tr>

                <!-- Payments & Credits applied -->
                <?php if (bccomp($invoice['amount_paid'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">Payments Applied</td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['amount_paid']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (bccomp($invoice['credits_applied'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label">Credits Applied</td>
                    <td class="fs-value" style="color:var(--color-success);">−<?= format_currency($invoice['credits_applied']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Late Fee -->
                <?php if ($invoice['late_fee_applied'] && bccomp($invoice['late_fee_amount'] ?? '0', '0', 2) > 0): ?>
                <tr>
                    <td class="fs-label" style="color:var(--color-danger);">Late Fee Applied</td>
                    <td class="fs-value" style="color:var(--color-danger);">+<?= format_currency($invoice['late_fee_amount']) ?></td>
                </tr>
                <?php endif; ?>

                <!-- Balance Due -->
                <tr class="fs-divider">
                    <td class="fs-label fs-total" style="font-size:16px;">Balance Due</td>
                    <td class="fs-value fs-total" style="font-size:16px; <?= bccomp($invoice['balance_due'], '0', 2) > 0 ? 'color:var(--color-danger);' : 'color:var(--color-success);' ?>">
                        <?= format_currency($invoice['balance_due']) ?> <?= e($invoice['currency']) ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Exchange rate note -->
        <?php if ($invoice['currency'] !== 'CAD' && !empty($invoice['exchange_rate_to_cad'])): ?>
        <div class="text-sm text-secondary" style="text-align:right; margin-top:12px; max-width:480px; margin-left:auto;">
            Exchange rate: 1 <?= e($invoice['currency']) ?> = <?= e(rtrim(rtrim(number_format((float)$invoice['exchange_rate_to_cad'], 6), '0'), '.')) ?> CAD ·
            CAD equivalent: <?= format_currency(bcmul($invoice['total_amount'], $invoice['exchange_rate_to_cad'], 2)) ?> CAD
        </div>
        <?php endif; ?>

        <!-- Tax exemption notes -->
        <?php if ($invoice['gst_exempt_snapshot'] || $invoice['pst_exempt_snapshot'] || $invoice['tax_exempt_snapshot']): ?>
        <div class="text-sm text-secondary" style="text-align:right; margin-top:8px; max-width:480px; margin-left:auto;">
            Tax exemptions:
            <?php if ($invoice['tax_exempt_snapshot']): ?>
                Fully tax exempt
            <?php else: ?>
                <?php if ($invoice['gst_exempt_snapshot']): ?>GST exempt<?php endif; ?>
                <?php if ($invoice['gst_exempt_snapshot'] && $invoice['pst_exempt_snapshot']): ?>, <?php endif; ?>
                <?php if ($invoice['pst_exempt_snapshot']): ?>PST exempt<?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ================================================================
     DELIVERY & TRACKING — Sent info, Late fees, Credit notes
     ================================================================ -->
<?php
$hasDeliveryInfo = $invoice['sent_date'] || $invoice['sent_to_email'] || $invoice['delivery_method'];
$hasLateFee = $invoice['late_fee_applied'] && bccomp($invoice['late_fee_amount'] ?? '0', '0', 2) > 0;
$hasCreditNotes = !empty($creditNotesFrom) || $creditNoteForInvoice;
$hasVoidInfo = $isVoid && $invoice['void_reason'];
$hasWriteOffInfo = $isWrittenOff && $invoice['write_off_reason'];

if ($hasDeliveryInfo || $hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo):
?>
<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">

    <!-- Delivery Tracking -->
    <?php if ($hasDeliveryInfo): ?>
    <div class="card" style="padding:20px;">
        <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-secondary); margin:0 0 12px 0;">
            Delivery Tracking
        </h3>
        <dl style="display:grid; grid-template-columns:120px 1fr; gap:6px 16px; font-size:13px; margin:0;">
            <?php if ($invoice['sent_date']): ?>
            <dt class="text-secondary">Sent Date</dt>
            <dd class="font-mono"><?= format_date($invoice['sent_date']) ?></dd>
            <?php endif; ?>

            <?php if (!empty($invoice['sent_by']) && isset($userNames[(int)$invoice['sent_by']])): ?>
            <dt class="text-secondary">Sent By</dt>
            <dd><?= e($userNames[(int)$invoice['sent_by']]) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['sent_to_email']): ?>
            <dt class="text-secondary">Sent To</dt>
            <dd>
                <a href="mailto:<?= e($invoice['sent_to_email']) ?>" class="link"><?= e($invoice['sent_to_email']) ?></a>
            </dd>
            <?php endif; ?>

            <?php if (!empty($sentCcEmails)): ?>
            <dt class="text-secondary">CC</dt>
            <dd><?= e(implode(', ', $sentCcEmails)) ?></dd>
            <?php endif; ?>

            <?php if ($invoice['delivery_method']): ?>
            <dt class="text-secondary">Method</dt>
            <dd>
                <span class="badge badge-no-dot badge-neutral" style="font-size:11px;">
                    <?= e(ucfirst($invoice['delivery_method'])) ?>
                </span>
            </dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>

    <!-- Late Fee / Credit Notes / Void Info -->
    <?php if ($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo): ?>
    <div class="card" style="padding:20px;">
        <!-- Late Fee -->
        <?php if ($hasLateFee): ?>
        <div style="<?= ($hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo) ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Late Fee
            </h3>
            <div style="font-size:13px;">
                <span class="font-mono" style="font-weight:600; color:var(--color-danger);"><?= format_currency($invoice['late_fee_amount']) ?></span>
                <?php if ($invoice['late_fee_date']): ?>
                    <span class="text-secondary">applied <?= format_date($invoice['late_fee_date']) ?></span>
                <?php endif; ?>
                <?php if ($lateFeeInvoice): ?>
                    <div style="margin-top:4px;">
                        Late fee invoice:
                        <a href="<?= base_url('invoices/show') ?>?id=<?= (int)$lateFeeInvoice['id'] ?>" class="link font-mono">
                            <?= e($lateFeeInvoice['invoice_number']) ?>
                        </a>
                        <span class="text-secondary">(<?= format_currency($lateFeeInvoice['total_amount']) ?>)</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Credit Notes -->
        <?php if ($hasCreditNotes): ?>
        <div style="<?= ($hasVoidInfo || $hasWriteOffInfo) ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-success); margin:0 0 8px 0;">
                Credit Notes
            </h3>

            <?php if ($creditNoteForInvoice): ?>
            <div style="font-size:13px; margin-bottom:8px;">
                <span class="text-secondary">This invoice is a credit note for:</span>
                <a href="<?= base_url('invoices/show') ?>?id=<?= (int)$creditNoteForInvoice['id'] ?>" class="link font-mono">
                    <?= e($creditNoteForInvoice['invoice_number']) ?>
                </a>
                <span class="text-secondary">(<?= format_currency($creditNoteForInvoice['total_amount']) ?>)</span>
            </div>
            <?php endif; ?>

            <?php if (!empty($creditNotesFrom)): ?>
            <div style="font-size:13px;">
                <span class="text-secondary">Credits generated from this invoice:</span>
                <?php foreach ($creditNotesFrom as $cn): ?>
                <div style="margin-top:4px; padding:6px 10px; background:var(--bg-muted); border-radius:4px;">
                    <span class="font-mono" style="font-weight:500;"><?= e($cn['credit_note_number']) ?></span>
                    <span class="font-mono"><?= format_currency($cn['amount']) ?></span>
                    <span class="badge badge-no-dot <?= $cn['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>" style="font-size:10px;">
                        <?= e($cn['status']) ?>
                    </span>
                    <?php if ($cn['reason']): ?>
                        <span class="text-secondary text-sm">— <?= e($cn['reason']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Void Info -->
        <?php if ($hasVoidInfo): ?>
        <div style="<?= $hasWriteOffInfo ? 'margin-bottom:16px; padding-bottom:16px; border-bottom:1px solid var(--border-color);' : '' ?>">
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Void Details
            </h3>
            <div style="font-size:13px;">
                <div style="color:var(--color-danger); font-weight:500;"><?= e($invoice['void_reason']) ?></div>
                <div class="text-secondary text-sm" style="margin-top:4px;">
                    Voided <?= format_datetime($invoice['voided_date'] ?? '') ?>
                    <?php if (!empty($invoice['voided_by']) && isset($userNames[(int)$invoice['voided_by']])): ?>
                        by <?= e($userNames[(int)$invoice['voided_by']]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Write-Off Info -->
        <?php if ($hasWriteOffInfo): ?>
        <div>
            <h3 style="font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--color-danger); margin:0 0 8px 0;">
                Write-Off Details
            </h3>
            <div style="font-size:13px;">
                <div style="color:var(--color-danger); font-weight:500;"><?= e($invoice['write_off_reason']) ?></div>
                <div class="text-secondary text-sm" style="margin-top:4px;">
                    Written off <?= format_datetime($invoice['written_off_at'] ?? '') ?>
                    <?php if (!empty($invoice['written_off_by']) && isset($userNames[(int)$invoice['written_off_by']])): ?>
                        by <?= e($userNames[(int)$invoice['written_off_by']]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- WHY: If only one card in the grid, fill the gap with an empty div -->
    <?php if ($hasDeliveryInfo && !($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo)): ?>
        <div></div>
    <?php endif; ?>
    <?php if (!$hasDeliveryInfo && ($hasLateFee || $hasCreditNotes || $hasVoidInfo || $hasWriteOffInfo)): ?>
        <div></div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ================================================================
     PAYMENT HISTORY — Allocations table with Record Payment button
     ================================================================ -->
<div class="card" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Payment History</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($invoicePayments) ?> payment<?= count($invoicePayments) !== 1 ? 's' : '' ?></span>
        <?php if ($canRecordPayment && can('payments', 'create')): ?>
            <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
               class="btn btn-primary btn-sm" style="margin-left:auto;">
                <?= heroicon('plus', 'icon-sm') ?>
                Record Payment
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($invoicePayments)): ?>
        <div class="empty-state" style="padding:32px;">
            <p class="empty-state-title">No payments recorded</p>
            <p class="empty-state-text">No payments have been applied to this invoice yet.</p>
            <?php if ($canRecordPayment && can('payments', 'create')): ?>
                <a href="<?= base_url('/payments/create') ?>?invoice_id=<?= (int)$invoiceId ?>"
                   class="btn btn-primary btn-sm" style="margin-top:8px;">Record First Payment</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" style="width:100;" aria-label="Payment allocations">
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
                        <td class="font-mono"><?= format_date($pay['payment_date']) ?></td>
                        <td><?= e($methodLabels[$pay['payment_method']] ?? ucfirst($pay['payment_method'])) ?></td>
                        <td class="font-mono"><?= e($pay['reference_number'] ?? '—') ?></td>
                        <td class="font-mono" style="text-align:right; color:var(--color-success); font-weight:600;">
                            <?= format_currency($pay['applied_amount']) ?> <?= e($pay['currency']) ?>
                        </td>
                        <td><span class="badge badge-no-dot <?= $payBadge ?>" style="font-size:11px;"><?= e(ucfirst($pay['payment_status'])) ?></span></td>
                        <td><span class="badge badge-no-dot badge-neutral" style="font-size:11px;"><?= e(ucfirst(str_replace('_', ' ', $pay['allocation_type']))) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-muted);">
                        <td colspan="4" style="padding:10px 14px; font-weight:600; font-size:13px;">Total Applied</td>
                        <td class="font-mono" style="text-align:right; font-weight:700; color:var(--color-success); padding:10px 14px;">
                            <?= format_currency(bcround($totalApplied, 2)) ?> <?= e($invoice['currency']) ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     NOTES — Customer-facing, Internal, Void reason
     With inline editing for draft invoices
     ================================================================ -->
<div class="card" id="invoice-edit-section" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Notes & References</h3>
        <?php if ($canEdit): ?>
            <button class="btn btn-secondary btn-sm" style="margin-left:auto;" @click="startEdit()" x-show="!editing">
                <?= heroicon('pencil-square', 'icon-sm') ?> Edit
            </button>
        <?php endif; ?>
    </div>

    <!-- Read-only display -->
    <div style="padding:20px;" x-show="!editing">
        <?php if (!$invoice['notes'] && !$invoice['internal_notes'] && !$invoice['po_number'] && !$invoice['void_reason'] && !$invoice['write_off_reason']): ?>
            <p class="text-secondary text-sm">No notes or references on this invoice.</p>
        <?php else: ?>
            <div style="display:grid; gap:16px;">
                <?php if ($invoice['po_number']): ?>
                <div>
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">PO Number</div>
                    <div class="font-mono"><?= e($invoice['po_number']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['notes']): ?>
                <div>
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">Customer-Facing Notes</div>
                    <div style="white-space:pre-wrap;"><?= e($invoice['notes']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['internal_notes']): ?>
                <div>
                    <div class="text-secondary text-sm" style="font-weight:600; margin-bottom:4px;">Internal Notes <span class="badge badge-no-dot badge-warning" style="font-size:10px;">Staff Only</span></div>
                    <div style="white-space:pre-wrap;"><?= e($invoice['internal_notes']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['void_reason']): ?>
                <div>
                    <div class="text-danger text-sm" style="font-weight:600; margin-bottom:4px;">Void Reason</div>
                    <div style="color:var(--color-danger);"><?= e($invoice['void_reason']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($invoice['write_off_reason']): ?>
                <div>
                    <div class="text-danger text-sm" style="font-weight:600; margin-bottom:4px;">Write-Off Reason</div>
                    <div style="color:var(--color-danger);"><?= e($invoice['write_off_reason']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Inline edit form -->
    <?php if ($canEdit): ?>
    <div class="inline-edit-section" x-show="editing" x-cloak>
        <div style="display:grid; gap:16px; max-width:600px;">
            <div>
                <label class="form-label">PO Number</label>
                <input type="text" class="form-control" x-model="editForm.po_number" placeholder="e.g., PO-2025-001">
            </div>
            <div>
                <label class="form-label">Sent To Email</label>
                <input type="email" class="form-control" x-model="editForm.sent_to_email" placeholder="billing@customer.com">
            </div>
            <div>
                <label class="form-label">Customer-Facing Notes</label>
                <textarea class="form-control" x-model="editForm.notes" rows="3"
                          placeholder="Notes visible to the customer on the invoice"></textarea>
            </div>
            <div>
                <label class="form-label">Internal Notes <span class="badge badge-no-dot badge-warning" style="font-size:10px;">Staff Only</span></label>
                <textarea class="form-control" x-model="editForm.internal_notes" rows="3"
                          placeholder="Internal notes (not visible to customer)"></textarea>
            </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:8px; align-items:center;">
            <button class="btn btn-primary btn-sm" @click="saveEdit()" :disabled="editSaving">
                <span x-show="!editSaving">Save Changes</span>
                <span x-show="editSaving">Saving…</span>
            </button>
            <button class="btn btn-secondary btn-sm" @click="cancelEdit()">Cancel</button>
            <span class="text-danger text-sm" x-show="editError" x-text="editError"></span>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ================================================================
     ACTIVITY LOG — Audit trail timeline
     ================================================================ -->
<?php if (!empty($activityLog)): ?>
<div class="card activity-log-section" style="margin-bottom:24px;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px;">
        <h3 style="font-size:14px; font-weight:600; margin:0;">Activity Log</h3>
        <span class="badge badge-no-dot badge-neutral"><?= count($activityLog) ?> event<?= count($activityLog) !== 1 ? 's' : '' ?></span>
    </div>
    <div style="padding:16px 20px;">
        <?php foreach ($activityLog as $event):
            // WHY: Map action types to human-readable labels
            $actionLabel = match($event['action']) {
                'create'            => 'Created',
                'update'            => 'Updated',
                'delete'            => 'Deleted',
                'status_change'     => 'Status Changed',
                'invoice_sent'      => 'Sent',
                'invoice_voided'    => 'Voided',
                'payment_recorded'  => 'Payment Recorded',
                'view'              => 'Viewed',
                'export'            => 'Exported',
                default             => ucfirst(str_replace('_', ' ', $event['action'])),
            };
        ?>
        <div class="activity-item">
            <div class="activity-dot action-<?= e($event['action']) ?>"></div>
            <div style="flex:1;">
                <div>
                    <strong><?= e($actionLabel) ?></strong>
                    <?php if ($event['user_name']): ?>
                        <span class="text-secondary">by <?= e($event['user_name']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($event['notes']): ?>
                    <div class="text-sm text-secondary" style="margin-top:2px;"><?= e($event['notes']) ?></div>
                <?php endif; ?>
                <div class="text-sm text-secondary" style="margin-top:2px; font-size:11px;">
                    <?= format_datetime($event['created_at']) ?>
                    <?php if ($event['ip_address'] && $event['ip_address'] !== '127.0.0.1'): ?>
                        · IP <?= e($event['ip_address']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<!-- ================================================================
     PRINT-ONLY FOOTER — Shows on printed invoice
     ================================================================ -->
<div class="print-only" style="text-align:center; padding:24px 0; border-top:2px solid #333; margin-top:40px; font-size:12px; color:#666;">
    <strong>Invoice <?= e($invoice['invoice_number']) ?></strong> ·
    Generated by FleetForge ·
    <?= date('M j, Y g:i A') ?>
</div>


</div><!-- end x-data="FF_InvoiceShow()" -->


<!-- ================================================================
     ALPINE.JS — Invoice actions, inline editing, modals
     ================================================================ -->
<script>
function FF_InvoiceShow() {
    return {
        /* ── Action states ──────────────────────────────────── */
        sending:        false,
        voiding:        false,
        deleting:       false,

        /* ── Modal states ───────────────────────────────────── */
        showVoidModal:  false,
        voidReason:     '',
        showDeleteModal: false,

        /* ── Inline edit (draft only) ───────────────────────── */
        editing:    false,
        editSaving: false,
        editError:  '',
        editForm: {
            po_number:      <?= json_encode($invoice['po_number'] ?? '') ?>,
            notes:          <?= json_encode($invoice['notes'] ?? '') ?>,
            internal_notes: <?= json_encode($invoice['internal_notes'] ?? '') ?>,
            sent_to_email:  <?= json_encode($invoice['sent_to_email'] ?? $invoice['customer_email_snapshot'] ?? '') ?>,
        },

        /* ── Toast ──────────────────────────────────────────── */
        toast:     '',
        toastType: 'success',

        /* ── Send Invoice ───────────────────────────────────── */
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

        /* ── Void Invoice ───────────────────────────────────── */
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

        /* ── Delete Invoice (draft only) ────────────────────── */
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

        /* ── Inline Edit — Start / Cancel / Save ────────────── */
        startEdit() {
            this.editing = true;
            this.editError = '';
            // Scroll to the edit form — it lives in the Notes section at the bottom of the page
            this.$nextTick(() => {
                document.getElementById('invoice-edit-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },

        cancelEdit() {
            this.editing = false;
            this.editError = '';
            // WHY: Reset form to original values on cancel
            this.editForm = {
                po_number:      <?= json_encode($invoice['po_number'] ?? '') ?>,
                notes:          <?= json_encode($invoice['notes'] ?? '') ?>,
                internal_notes: <?= json_encode($invoice['internal_notes'] ?? '') ?>,
                sent_to_email:  <?= json_encode($invoice['sent_to_email'] ?? $invoice['customer_email_snapshot'] ?? '') ?>,
            };
        },

        async saveEdit() {
            this.editSaving = true;
            this.editError  = '';
            try {
                // WHY: D19 optimistic lock — must send updated_at
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/update') ?>', {
                    id:         <?= (int)$invoiceId ?>,
                    updated_at: <?= json_encode($invoice['updated_at']) ?>,
                    po_number:      this.editForm.po_number,
                    notes:          this.editForm.notes,
                    internal_notes: this.editForm.internal_notes,
                    sent_to_email:  this.editForm.sent_to_email,
                });
                if (r.success) {
                    this.showToast('Invoice updated', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    this.editError = r.error?.message || 'Failed to save';
                    if (r.error?.code === 'STALE_DATA') {
                        this.editError = 'This invoice was modified by another user. Please refresh the page.';
                    }
                }
            } catch(e) {
                this.editError = 'Network error — please try again';
            }
            this.editSaving = false;
        },

        /* ── Toast ──────────────────────────────────────────── */
        showToast(msg, type) {
            this.toast = msg;
            this.toastType = type;
            setTimeout(() => { this.toast = ''; }, 4000);
        }
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
