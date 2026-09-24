<?php declare(strict_types=1);

/**
 * app/admin/accounting/bills/show.php
 *
 * AP Bill detail view — read-only drill-down for a single acc_bills row,
 * with line items, payments, vendor credits, and a Documents section.
 *
 * The existing app/admin/accounting/bills/index.php still ships an inline
 * Alpine modal for quick view; this page is the dedicated entity surface
 * with a stable URL — required for deep-linking from notifications +
 * documents drill-down per FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3.
 *
 * Edit / void / pay actions stay in index.php — this page is view-only
 * (the one write on it is the per-line repair / betterment classification).
 *
 * Layout (S-RECORD-REDESIGN):
 *   - Entity hero: bill number + status (+ overdue) badges, fact chips
 *     (vendor, bill date, vendor invoice #, period). Visible actions link to
 *     the vendor and the journal entry; the More menu links to QuickBooks, the
 *     vendor's AP payments, AP aging and the bills list. The accounting
 *     sub-nav sits right under the hero (the hero replaces the old
 *     breadcrumb + page header, which rendered BELOW the QuickBooks panel).
 *   - Key-numbers strip: balance due (red when overdue), due date (relative:
 *     "in N days" / "N days overdue"), total (tax inside), paid.
 *   - Main column: notes / void reason, line items (asset-line repair vs
 *     betterment classifier kept) with a subtotal / tax / total block, payment
 *     history, documents, QuickBooks Sync panel.
 *   - Rail: needs attention (overdue, unpaid, draft awaiting approval, asset
 *     lines to classify, no document attached, QuickBooks push failure, void),
 *     vendor (contact + other open bills), bill details (vendor invoice #,
 *     dates, period, unit, work order, created), journal entry, QuickBooks
 *     status summary.
 *
 * The whole page is accounts_payable:view — every figure on it sits behind
 * that gate (unchanged). Related-record links check their own module's
 * permission (vendors = maintenance:view, JEs = journal_entries:view).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php,
 *          lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php
 * @session S-ACCT-FIX-DOCS → S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounts_payable', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Bill Not Specified</h1>';
    exit;
}

$bill = db_row(
    "SELECT b.*, v.name AS vendor_name, v.id AS vendor_id_join,
            v.contact_name AS vendor_contact, v.email AS vendor_email, v.phone AS vendor_phone,
            v.vendor_type,
            p.name AS period_name, p.status AS period_status,
            je.id AS je_id, je.entry_number AS je_number, je.status AS je_status,
            u.name AS created_by_name, uv.name AS voided_by_name
       FROM acc_bills b
       JOIN vendors v ON v.id = b.vendor_id
       JOIN acc_periods p ON p.id = b.period_id
  LEFT JOIN acc_journal_entries je ON je.id = b.journal_entry_id
  LEFT JOIN users u ON u.id = b.created_by
  LEFT JOIN users uv ON uv.id = b.voided_by
      WHERE b.id = ?",
    [$id]
);

if (!$bill) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Bill Not Found</h1>';
    exit;
}

// Line items
$lines = db_select(
    "SELECT bl.*, a.code AS account_code, a.name AS account_name
       FROM acc_bill_lines bl
       JOIN acc_accounts a ON a.id = bl.account_id
      WHERE bl.bill_id = ?
      ORDER BY bl.sort_order, bl.id",
    [$id]
);

// Payment allocations
$payments = db_select(
    "SELECT pa.amount_applied, ap.id AS ap_payment_id, ap.payment_number,
            ap.payment_date, ap.payment_method, ap.check_number, ap.status AS payment_status
       FROM acc_ap_payment_allocations pa
       JOIN acc_ap_payments ap ON ap.id = pa.ap_payment_id
      WHERE pa.bill_id = ?
      ORDER BY ap.payment_date DESC",
    [$id]
);

// S-RECORD-REDESIGN: the standard badge palette. The old map used
// badge-green / badge-blue / badge-red / badge-amber — badge-amber has no CSS
// definition, so partially-paid / pending badges rendered unstyled.
$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'paid'           => 'badge-success',
        'partially_paid' => 'badge-warning',
        'approved'       => 'badge-info',
        'scheduled'      => 'badge-info',
        'draft'          => 'badge-neutral',
        'void'           => 'badge-danger',
        'posted'         => 'badge-success',
        'reversed'       => 'badge-danger',
        'cleared'        => 'badge-success',
        'pending'        => 'badge-warning',
        default          => 'badge-neutral',
    };
};

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────
$today     = ff_today();
$cur       = (string) ($bill['currency'] ?? 'CAD');
$isVoid    = $bill['status'] === 'void';
$isDraft   = $bill['status'] === 'draft';
$isPaid    = $bill['status'] === 'paid';
$balance   = (string) $bill['balance_due'];
$owing     = !$isVoid && !$isPaid && bccomp($balance, '0', 2) > 0;
// Days from today to the due date (negative = overdue), company-local.
$dueIn = null;
if (!empty($bill['due_date'])) {
    try {
        $dueIn = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable((string) $bill['due_date']))->format('%r%a');
    } catch (Throwable) {
        $dueIn = null;
    }
}
$isOverdue = $owing && !$isDraft && $dueIn !== null && $dueIn < 0;
$fmtDays   = static fn (int $n): string => $n . ' day' . ($n === 1 ? '' : 's');
$dueRel    = '';
if ($owing && $isDraft) {
    // A draft isn't payable (or overdue) until it is approved.
    $dueRel = 'awaiting approval';
} elseif ($owing && $dueIn !== null) {
    $dueRel = $dueIn < 0 ? $fmtDays(-$dueIn) . ' overdue' : ($dueIn === 0 ? 'due today' : 'in ' . $fmtDays($dueIn));
}
$paidPct = bccomp((string) $bill['total_amount'], '0', 2) > 0
    ? (float) bcmul(bcdiv((string) $bill['amount_paid'], (string) $bill['total_amount'], 6), '100', 2)
    : 0.0;
$termsDays = null;
if (!empty($bill['bill_date']) && !empty($bill['due_date'])) {
    try {
        $termsDays = (int) (new DateTimeImmutable((string) $bill['bill_date']))->diff(new DateTimeImmutable((string) $bill['due_date']))->format('%r%a');
    } catch (Throwable) {
        $termsDays = null;
    }
}

// Asset-linked lines still waiting for the repair / betterment call — the
// same condition that shows the classifier below.
$unclassified = 0;
foreach ($lines as $_l) {
    if (!empty($_l['asset_id']) && (int) ($_l['capitalize'] ?? 0) !== 1 && trim((string) ($_l['betterment_note'] ?? '')) === '') {
        $unclassified++;
    }
}
unset($_l);

$docCount = (int) (db_row("SELECT COUNT(*) AS c FROM acc_documents WHERE entity_type = 'bill' AND entity_id = ?", [$id])['c'] ?? 0);

// The vendor's OTHER open bills (what else is owed to them).
$vendorOpen = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due), 0) AS owing FROM acc_bills
      WHERE vendor_id = ? AND id <> ? AND status IN ('approved', 'scheduled', 'partially_paid') AND balance_due > 0",
    [(int) $bill['vendor_id'], $id]
) ?: ['cnt' => 0, 'owing' => '0'];

$workOrder = !empty($bill['work_order_id'])
    ? db_row("SELECT id, work_order_number FROM maintenance_work_orders WHERE id = ?", [(int) $bill['work_order_id']])
    : null;
$billUnit = !empty($bill['equipment_unit_id'])
    ? db_row("SELECT id, unit_number FROM equipment_units WHERE id = ?", [(int) $bill['equipment_unit_id']])
    : null;

// QuickBooks status for the rail summary — the full panel stays in the main
// column. Same labels as includes/partials/qbo-sync-panel.php.
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboMap = $qboConnected
    ? db_row("SELECT push_status, qbo_bill_id, pushed_at FROM acc_qbo_bill_map WHERE ff_bill_id = ? LIMIT 1", [$id])
    : null;
$qboUrl = !empty($qboMap['qbo_bill_id'])
    ? 'https://' . ((string) settings_get('quickbooks.environment', 'sandbox') === 'production' ? 'app.qbo.intuit.com' : 'app.sandbox.qbo.intuit.com')
      . '/app/bill?txnId=' . urlencode((string) $qboMap['qbo_bill_id'])
    : null;

$canSeeVendor = can('maintenance', 'view');
$canSeeJe     = can('journal_entries', 'view');

$pageTitle = 'Bill ' . $bill['bill_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// ============================================================
// Page header — entity hero (S-RECORD-REDESIGN). View-only page: the
// actions are links to the records around the bill.
// ============================================================
ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if ($qboUrl): ?>
        <a class="btn btn-secondary btn-sm" href="<?= e($qboUrl) ?>" target="_blank" rel="noopener noreferrer">View in QuickBooks ↗</a>
        <?php endif; ?>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/ap-payments') ?>?vendor_id=<?= (int) $bill['vendor_id'] ?>">Payments to this vendor</a>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/ap-aging') ?>">AP aging</a>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/bills') ?>">All bills (approve / pay / void)</a>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?php if ($canSeeVendor): ?>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('vendors/show') ?>?id=<?= (int) $bill['vendor_id'] ?>"><?= heroicon('building-storefront', 'icon-sm') ?> Vendor</a>
        <?php endif; ?>
        <?php if ($bill['je_id'] && $canSeeJe): ?>
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $bill['je_id']) ?>"><?= heroicon('book-open', 'icon-sm') ?> Journal entry</a>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [];
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('building-storefront')
    . ($canSeeVendor
        ? '<a href="' . e(base_url('vendors/show')) . '?id=' . (int) $bill['vendor_id'] . '" style="color:inherit;">' . e($bill['vendor_name']) . '</a>'
        : e($bill['vendor_name']));
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Billed ' . e(format_date($bill['bill_date']));
if (!empty($bill['vendor_bill_number'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('document-text') . 'Vendor inv. ' . e($bill['vendor_bill_number']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Period ' . e($bill['period_name']);
$heroMark = (string) $bill['bill_number'];
if (preg_match('/(\d{3,})$/', $heroMark, $hm)) { $heroMark = $hm[1]; }
?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'purple',
    'icon'       => 'clipboard-document-list',
    'mark'       => $heroMark,
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Bills', base_url('accounting/bills')], [(string) $bill['bill_number'], null]],
    'eyebrow'    => 'Vendor bill',
    'title_html' => 'Bill ' . e($bill['bill_number'])
        . ' <span class="badge badge-no-dot ' . e($statusBadgeClass($bill['status'])) . '">' . e(str_replace('_', ' ', $bill['status'])) . '</span>'
        . ($isOverdue ? ' <span class="badge badge-no-dot badge-danger">Overdue</span>' : '')
        . ($cur !== 'CAD' ? ' <span class="badge badge-no-dot badge-warning">' . e($cur) . '</span>' : ''),
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ============================================================
     KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): what is still
     owed (red when overdue), when it is due, the total and what's paid.
     ============================================================ -->
<div class="stat-grid ff-stats">
    <?php $_balTone = $isVoid ? 'slate' : ($owing ? ($isOverdue ? 'red' : 'amber') : 'green'); ?>
    <div class="stat-card stat-card--<?= $_balTone ?>">
        <span class="stat-icon stat-icon--<?= $_balTone ?>"><svg><use href="#icon-<?= $owing ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Balance due</div>
        <div class="stat-value font-mono"<?= $isOverdue ? ' style="color:var(--color-danger);"' : '' ?>><?= e(format_currency($balance)) ?></div>
        <div class="stat-delta"><?= $isVoid ? 'void' : ($isPaid || !$owing ? 'paid in full' : e((string) round($paidPct)) . '% paid') ?></div>
    </div>

    <div class="stat-card <?= $isOverdue ? 'stat-card--red' : 'stat-card--amber' ?>" title="Due <?= e(format_date($bill['due_date'])) ?><?= $dueRel !== '' ? ' — ' . e($dueRel) : '' ?><?= $termsDays !== null ? ' · Net ' . max(0, $termsDays) : '' ?>">
        <span class="stat-icon <?= $isOverdue ? 'stat-icon--red' : 'stat-icon--amber' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Due</div>
        <div class="stat-value stat-value--date font-mono"<?= $isOverdue ? ' style="color:var(--color-danger);"' : '' ?>><?= e(format_date($bill['due_date'])) ?></div>
        <div class="stat-delta"<?= $isOverdue ? ' style="color:var(--color-danger);"' : '' ?>><?= $dueRel !== '' ? e($dueRel) : ($isVoid ? 'void' : ($owing ? '—' : 'settled')) ?></div>
    </div>

    <a class="stat-card stat-card--blue" href="#bill-lines" title="Line items">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Total</div>
        <div class="stat-value font-mono"><?= e(format_currency($bill['total_amount'])) ?></div>
        <div class="stat-delta"><?= bccomp((string) $bill['tax_total'], '0', 2) > 0 ? 'incl. ' . e(format_currency($bill['tax_total'])) . ' tax' : e($cur) ?></div>
    </a>

    <a class="stat-card stat-card--green" href="#bill-payments" title="Payments applied to this bill">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Paid</div>
        <div class="stat-value font-mono"><?= e(format_currency($bill['amount_paid'])) ?></div>
        <div class="stat-delta"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?></div>
    </a>
</div>

<div class="rec-layout">
<div class="rec-main">

<?php if ($bill['notes'] || $bill['internal_notes'] || ($isVoid && $bill['void_reason'])): ?>
<div class="card" style="margin-bottom:14px;">
    <div class="card-header"><h3 class="card-title">Notes</h3></div>
    <div class="card-body">
        <dl class="rec-dl">
            <?php if ($isVoid && $bill['void_reason']): ?>
            <dt style="color:var(--color-danger);">Void reason</dt>
            <dd style="white-space:pre-wrap;"><?= e($bill['void_reason']) ?></dd>
            <?php endif; ?>
            <?php if ($bill['notes']): ?>
            <dt>Notes</dt>
            <dd style="white-space:pre-wrap;"><?= e($bill['notes']) ?></dd>
            <?php endif; ?>
            <?php if ($bill['internal_notes']): ?>
            <dt>Internal notes</dt>
            <dd style="white-space:pre-wrap; color:var(--text-secondary);"><?= e($bill['internal_notes']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>
<?php endif; ?>

<!-- ── Line items ──────────────────────────────────────────────────────── -->
<div class="card" id="bill-lines" style="margin-bottom:14px; scroll-margin-top:80px;">
    <div class="card-header">
        <h3 class="card-title">Line Items</h3>
        <span class="badge badge-neutral"><?= count($lines) ?> line<?= count($lines) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (count($lines) === 0): ?>
    <div class="card-body">
        <p class="text-secondary" style="margin:0; font-size:0.8125rem;">No line items.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="table" style="width:100%;">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Description</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Unit</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td class="font-mono" style="font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                    <td><?= e($l['description'] ?? '') ?></td>
                    <td class="font-mono" style="text-align:right;"><?= e(number_format((float) $l['quantity'], 2)) ?></td>
                    <td class="font-mono" style="text-align:right;"><?= e('$' . number_format((float) $l['unit_cost'], 2)) ?></td>
                    <td class="font-mono" style="text-align:right;font-weight:600;"><?= e('$' . number_format((float) $l['amount'], 2)) ?></td>
                </tr>
                <?php
                // S-ACCT-COMP: betterment/repair classification row when line is asset-linked.
                if (!empty($l['asset_id'])):
                    $assetRow = db_row("SELECT id, asset_number, name FROM acc_fixed_assets WHERE id = ?", [(int) $l['asset_id']]);
                    $isCapitalized = (int) ($l['capitalize'] ?? 0) === 1;
                ?>
                <?php /* S-RECORD-REDESIGN: token background (was a hard-coded #fafafa that glared in dark mode). */ ?>
                <tr style="background:var(--bg-surface-2);">
                    <td colspan="5" style="padding:10px 14px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;font-size:0.8125rem;">
                            <div>
                                <span style="color:var(--text-secondary);font-size:0.75rem;">Linked to:</span>
                                <?php if ($assetRow): ?>
                                    <a class="font-mono" style="color:var(--color-accent);text-decoration:none;margin-left:4px;"
                                       href="<?= base_url('accounting/fixed-assets/show?id=' . (int) $assetRow['id']) ?>"><?= e($assetRow['asset_number']) ?></a>
                                    — <?= e($assetRow['name']) ?>
                                <?php else: ?>
                                    <span class="font-mono">#<?= (int) $l['asset_id'] ?></span> (asset row not found)
                                <?php endif; ?>
                            </div>
                            <?php if ($isCapitalized): ?>
                                <span class="badge badge-success" style="padding:3px 10px;font-size:0.6875rem;">
                                    ✓ Capitalized to <?= e($assetRow['asset_number'] ?? '?') ?>
                                </span>
                            <?php elseif ($assetRow && !empty($l['betterment_note']) && (int) $l['capitalize'] === 0): ?>
                                <span class="badge badge-neutral" style="padding:3px 10px;font-size:0.6875rem;">
                                    → Expensed (repair)
                                </span>
                            <?php else: ?>
                            <?php /* e(json_encode()): the raw double quotes json_encode emits closed this
                                     double-quoted x-data attribute early ("Unexpected token" on any bill line
                                     with an asset). The HTML parser decodes &quot; back before Alpine reads it. */ ?>
                            <div x-data="classifyLine(<?= (int) $l['id'] ?>, <?= (int) $l['asset_id'] ?>, <?= e(json_encode((string) $l['amount'])) ?>)" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <details style="font-size:0.75rem;color:var(--text-secondary);">
                                    <summary style="cursor:pointer;">ASPE 3061.14 rule</summary>
                                    <div style="margin-top:4px;max-width:480px;">
                                        <strong>Betterment:</strong> increases service potential or extends useful life.<br>
                                        <strong>Repair:</strong> maintains service potential without extending life.
                                    </div>
                                </details>
                                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="radio" name="classify_<?= (int) $l['id'] ?>" value="repair" x-model="form.choice">
                                    🔧 Repair (expense)
                                </label>
                                <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                                    <input type="radio" name="classify_<?= (int) $l['id'] ?>" value="betterment" x-model="form.choice">
                                    ⬆ Betterment (capitalize)
                                </label>
                                <input type="text" x-model="form.note" placeholder="Describe..." x-show="form.choice === 'betterment'" x-cloak
                                       style="padding:4px 8px;border:1px solid var(--border-default);border-radius:4px;font-size:0.8125rem;min-width:220px;">
                                <button class="btn btn-primary btn-xs" :disabled="!form.choice || form.busy" @click="submit()">
                                    <span x-show="!form.busy">Classify</span>
                                    <span x-show="form.busy">Saving...</span>
                                </button>
                                <span x-show="form.error" x-cloak style="font-size:0.75rem;color:var(--color-danger);" x-text="form.error"></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
            <?php /* S-RECORD-REDESIGN: the bill's own subtotal / tax / total (the
                     tax split was stored but never shown on this page). */ ?>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right; color:var(--text-secondary);">Subtotal</td>
                    <td class="font-mono" style="text-align:right;"><?= e(format_currency($bill['subtotal'])) ?></td>
                </tr>
                <?php foreach ([['tax_gst_amount', 'GST'], ['tax_pst_amount', 'PST'], ['tax_hst_amount', 'HST']] as [$_tk, $_tl]): ?>
                    <?php if (bccomp((string) ($bill[$_tk] ?? '0'), '0', 2) !== 0): ?>
                    <tr>
                        <td colspan="4" style="text-align:right; color:var(--text-secondary);"><?= e($_tl) ?></td>
                        <td class="font-mono" style="text-align:right;"><?= e(format_currency($bill[$_tk])) ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr>
                    <th colspan="4" style="text-align:right;">Total <?= $cur !== 'CAD' ? e($cur) : '' ?></th>
                    <th class="font-mono" style="text-align:right;"><?= e(format_currency($bill['total_amount'])) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Payments ────────────────────────────────────────────────────────── -->
<div class="card" id="bill-payments" style="margin-bottom:14px; scroll-margin-top:80px;">
    <div class="card-header">
        <h3 class="card-title">Payment History</h3>
        <span class="badge badge-neutral"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (count($payments) === 0): ?>
    <div class="card-body">
        <p class="text-secondary" style="margin:0; font-size:0.8125rem;">No payments recorded against this bill.<?= $owing && !$isDraft ? ' Pay it from the <a href="' . e(base_url('accounting/bills')) . '" class="link">Bills list</a>.' : '' ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="table" style="width:100%;">
            <thead>
                <tr>
                    <th>Payment #</th>
                    <th>Date</th>
                    <th>Method</th>
                    <th style="text-align:right;">Applied</th>
                    <th style="text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="font-mono">
                        <a href="<?= base_url('accounting/ap-payments/show?id=' . (int) $p['ap_payment_id']) ?>" class="link"><?= e($p['payment_number']) ?></a>
                    </td>
                    <td><?= e(format_date($p['payment_date'])) ?></td>
                    <td style="text-transform:capitalize;"><?= e(str_replace('_', ' ', $p['payment_method'])) ?><?= !empty($p['check_number']) ? ' #' . e($p['check_number']) : '' ?></td>
                    <td class="font-mono" style="text-align:right;font-weight:600;"><?= e('$' . number_format((float) $p['amount_applied'], 2)) ?></td>
                    <td style="text-align:center;"><span class="badge <?= e($statusBadgeClass($p['payment_status'])) ?>"><?= e($p['payment_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<div id="bill-documents" style="scroll-margin-top:80px;">
<?php
$entityType = 'bill';
$entityId   = (int) $bill['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>
</div>

<?php
// F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
// S-RECORD-REDESIGN: moved from above the page header to below the bill's own
// content; the rail carries its status summary.
$qboPanel = [
    'entity_type' => 'bill',
    'map_table'   => 'acc_qbo_bill_map',
    'qbo_id_col'  => 'qbo_bill_id',
    'ff_fk'       => 'ff_bill_id',
    'ff_id'       => (int) $bill['id'],
    'deep_link'   => 'bill',
    'retry_url'   => base_url('api/v1/quickbooks/bills/retry'),
];
require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
?>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the bill at a glance ──────────────
$R     = \FleetForge\Ui\RecordUi::class;
$railB = [];

// 1. Needs attention.
$alertsB = [];
if ($isOverdue) {
    $alertsB[] = ['danger', '<b>Overdue</b> — ' . e(format_currency($balance)) . ' was due ' . e(format_date($bill['due_date'])) . ' (' . e($fmtDays(-$dueIn)) . ' ago). Pay it from the <a href="' . e(base_url('accounting/bills')) . '">Bills list</a>.'];
} elseif ($owing && !$isDraft && $dueIn !== null && $dueIn <= 7) {
    $alertsB[] = ['warning', e(format_currency($balance)) . ' due ' . ($dueIn === 0 ? 'today' : 'in ' . e($fmtDays($dueIn))) . '.'];
}
if ($isDraft) {
    $alertsB[] = ['info', 'Draft — approve it in the <a href="' . e(base_url('accounting/bills')) . '">Bills list</a> before it can be paid (it posts to the ledger on approval).'];
}
if ($unclassified > 0 && !$isVoid) {
    $alertsB[] = ['warning', '<a href="#bill-lines">' . $unclassified . ' asset-linked line' . ($unclassified === 1 ? '' : 's') . '</a> need' . ($unclassified === 1 ? 's' : '') . ' a repair / betterment decision.'];
}
if (!$isVoid && $docCount === 0) {
    $alertsB[] = ['info', 'No document attached — <a href="#bill-documents">upload the vendor\'s invoice</a> for the audit trail.'];
}
if ($qboMap !== null && ((string) ($qboMap['push_status'] ?? '') === 'failed' || str_starts_with((string) ($qboMap['push_status'] ?? ''), 'failed_preflight'))) {
    $alertsB[] = ['danger', '<a href="#qbo-sync-panel">QuickBooks push failed</a> — retry from the QuickBooks panel.'];
}
if ($isVoid) {
    $alertsB[] = ['info', 'Voided' . ($bill['voided_by_name'] ? ' by ' . e($bill['voided_by_name']) : '') . ($bill['voided_at'] ? ' on ' . e(format_datetime($bill['voided_at'])) : '')
        . ($bill['void_reason'] ? ' — ' . e($bill['void_reason']) : '') . '.'];
}
$railB[] = $R::card('Needs attention', $R::alerts($alertsB, $isPaid ? 'Paid in full — nothing to do.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Payment progress.
if (!$isVoid) {
    $payBody = $R::meter('Paid', e((string) round($paidPct)) . '%', $paidPct,
        $paidPct >= 99.99 ? 'ok' : ($isOverdue ? 'danger' : 'info'),
        e(format_currency($bill['amount_paid'])) . ' of ' . e(format_currency($bill['total_amount'])) . ' ' . e($cur));
    $_lastPay = $payments[0] ?? null; // newest first
    $payBody .= $R::kv([
        ['Balance', e(format_currency($balance)), 'mono'],
        ['Last payment', $_lastPay ? '<a href="' . e(base_url('accounting/ap-payments/show?id=' . (int) $_lastPay['ap_payment_id'])) . '">' . e(format_currency($_lastPay['amount_applied'])) . '</a> · ' . e(format_date($_lastPay['payment_date'])) : 'None yet'],
        ['Terms', $termsDays !== null ? 'Net ' . max(0, $termsDays) : null],
    ]);
    $railB[] = $R::card('Payment', $payBody, ['icon' => 'banknotes', 'class' => 'rec-card--accent']);
}

// 3. Vendor.
$vendorSub = trim(implode(' · ', array_filter([
    !empty($bill['vendor_contact']) ? e($bill['vendor_contact']) : '',
    !empty($bill['vendor_type']) ? e(ucfirst(str_replace('_', ' ', (string) $bill['vendor_type']))) : '',
])));
$vendorBody = $R::entity((string) $bill['vendor_name'], $canSeeVendor ? base_url('vendors/show') . '?id=' . (int) $bill['vendor_id'] : '',
    $vendorSub !== '' ? $vendorSub : 'Vendor', \FleetForge\Ui\ModuleHero::initials((string) $bill['vendor_name']));
$vendorBody .= '<div style="margin-top:10px;">' . $R::kv([
    ['Email', !empty($bill['vendor_email']) ? '<a href="mailto:' . e($bill['vendor_email']) . '">' . e($bill['vendor_email']) . '</a>' : null],
    ['Phone', !empty($bill['vendor_phone']) ? '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', (string) $bill['vendor_phone'])) . '">' . e($bill['vendor_phone']) . '</a>' : null],
    ['Other open bills', (int) $vendorOpen['cnt'] > 0 ? (int) $vendorOpen['cnt'] . ' · ' . e(format_currency($vendorOpen['owing'])) : 'None'],
]) . '</div>';
$vendorBody .= '<div style="margin-top:10px;">' . $R::links([
    ['Payments to this vendor', base_url('accounting/ap-payments') . '?vendor_id=' . (int) $bill['vendor_id'], 'banknotes'],
]) . '</div>';
$railB[] = $R::card('Vendor', $vendorBody, ['icon' => 'building-storefront']);

// 4. Bill details (was the header grid).
$railB[] = $R::card('Bill details', $R::kv([
    ['Vendor invoice #', !empty($bill['vendor_bill_number']) ? e($bill['vendor_bill_number']) : '—', 'mono'],
    ['Bill date', e(format_date($bill['bill_date']))],
    ['Due date', e(format_date($bill['due_date']))],
    ['Period', e($bill['period_name']) . ($bill['period_status'] && $bill['period_status'] !== 'open' ? ' <span class="badge badge-no-dot badge-neutral">' . e($bill['period_status']) . '</span>' : '')],
    ['Currency', $cur !== 'CAD' ? e($cur) . (!empty($bill['exchange_rate_to_cad']) ? ' @ ' . e((string) $bill['exchange_rate_to_cad']) : '') : null],
    ['Unit', $billUnit ? (can('equipment', 'view') ? '<a href="' . e(base_url('equipment/show')) . '?id=' . (int) $billUnit['id'] . '">' . e($billUnit['unit_number']) . '</a>' : e($billUnit['unit_number'])) : null],
    ['Work order', $workOrder ? ($canSeeVendor ? '<a href="' . e(base_url('maintenance_work_orders/show')) . '?id=' . (int) $workOrder['id'] . '">' . e($workOrder['work_order_number']) . '</a>' : e($workOrder['work_order_number'])) : null],
    ['Created', e($bill['created_by_name'] ?? 'system') . ' · ' . e(format_datetime($bill['created_at']))],
]), ['icon' => 'document-text']);

// 5. Journal entry (was the "Linked Journal Entry" card).
if ($bill['je_id']) {
    $railB[] = $R::card('Journal entry', $R::kv([
        ['Entry #', $canSeeJe
            ? '<a href="' . e(base_url('accounting/journal-entries/show?id=' . (int) $bill['je_id'])) . '">' . e($bill['je_number']) . '</a>'
            : e($bill['je_number']), 'mono'],
        ['Status', '<span class="badge ' . e($statusBadgeClass((string) $bill['je_status'])) . '">' . e((string) $bill['je_status']) . '</span>'],
    ]), ['icon' => 'book-open']);
}

// 6. QuickBooks summary (full panel in the main column).
if ($qboConnected) {
    $_qs = $qboMap['push_status'] ?? null;
    [$_qBadge, $_qLabel] = match (true) {
        $qboMap === null                                   => ['badge-neutral', 'Not synced'],
        $_qs === 'pushed'                                  => ['badge-success', 'Synced'],
        $_qs === 'voided'                                  => ['badge-neutral', 'Voided'],
        $_qs === 'pending'                                 => ['badge-neutral', 'Pending'],
        $_qs === 'failed'                                  => ['badge-danger', 'Failed'],
        str_starts_with((string) $_qs, 'failed_preflight') => ['badge-warning', 'Failed pre-flight'],
        str_starts_with((string) $_qs, 'skipped_')         => ['badge-neutral', 'Skipped'],
        default                                            => ['badge-neutral', ucfirst(str_replace('_', ' ', (string) $_qs))],
    };
    $railB[] = $R::card('QuickBooks', $R::kv([
        ['Status', '<span class="badge badge-no-dot ' . $_qBadge . '">' . e($_qLabel) . '</span>'],
        ['QuickBooks #', $qboUrl ? '<a href="' . e($qboUrl) . '" target="_blank" rel="noopener noreferrer">#' . e($qboMap['qbo_bill_id']) . ' ↗</a>' : null, 'mono'],
        ['Last push', !empty($qboMap['pushed_at']) ? e(format_datetime($qboMap['pushed_at'])) : null],
    ]), ['icon' => 'arrow-path', 'link' => ['Details', '#qbo-sync-panel']]);
}
?>
<aside class="rec-rail" aria-label="Bill at a glance">
    <?= implode("\n    ", $railB) ?>
</aside>
</div><!-- /rec-layout -->

<script>
function classifyLine(lineId, assetId, amount) {
    return {
        lineId: lineId, assetId: assetId, amount: amount,
        form: { choice: '', note: '', busy: false, error: null },
        async submit() {
            const m = this.form;
            if (m.choice === 'betterment' && (!m.note || m.note.trim().length < 5)) {
                m.error = 'Betterment note (≥ 5 chars) is required.';
                return;
            }
            m.busy = true; m.error = null;
            try {
                if (m.choice === 'betterment') {
                    const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/betterment') ?>', {
                        asset_id: this.assetId, amount: this.amount,
                        note: m.note.trim(), bill_line_id: this.lineId,
                    });
                    if (r.success) {
                        // SOP I2: a draft bill's line is capitalized on approval.
                        FF_Toast.success(r.data && r.data.deferred ? r.data.message : 'Betterment capitalized — cost moved into the asset account.');
                        window.location.reload();
                    } else {
                        m.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Save failed.';
                    }
                } else {
                    // Repair → mark capitalize=0 + note via the classify_line endpoint.
                    // No asset-cost mutation; just stamps the audit trail.
                    const noteFinal = m.note && m.note.trim().length >= 5
                        ? m.note.trim()
                        : 'Classified as repair (ASPE 3061.14): maintains service potential without extending life.';
                    const r = await FF_Api.post('<?= base_url('api/v1/accounting/bills/classify_line') ?>', {
                        line_id: this.lineId, note: noteFinal,
                    });
                    if (r.success) {
                        FF_Toast.success('Line classified as repair (expensed).');
                        window.location.reload();
                    } else {
                        m.error = (r.error && (r.error.message || JSON.stringify(r.error.fields || {}))) || 'Save failed.';
                    }
                }
            } catch (e) { m.error = 'Network error.'; }
            m.busy = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
