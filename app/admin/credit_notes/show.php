<?php
declare(strict_types=1);

/**
 * app/admin/credit_notes/show.php
 *
 * Credit note detail page (S-RECORD-REDESIGN layout):
 *   - Entity hero: CN number + status, fact chips (customer, source, issued,
 *     expiry). "Apply credit" is the visible action (jumps to the Apply form);
 *     Refund as cash / Edit details / Void sit in the More menu (Void keeps its
 *     window-event dispatch to the void modal).
 *   - Key-numbers strip: total, applied, remaining, applications (money
 *     roles); applications + issued / expiry for everyone else.
 *   - Main column — the primary work first: Apply to Invoice + Refund as Cash
 *     side by side (while the credit is usable), why the credit exists (reason
 *     + internal notes), Application History (with Un-apply), Cash refunds,
 *     Edit Metadata (only when the credit is no longer applicable — below the
 *     read-only sections), QuickBooks Sync panel, Activity.
 *   - Rail: needs attention (credit sitting unused > 30 days, expiring /
 *     expired, the customer has open invoices it could pay, QuickBooks push
 *     failure, void), credit balance (used-vs-remaining meter — money roles),
 *     customer, source (original invoice / payment / lease links, created,
 *     expiry), QuickBooks status summary.
 *
 * Sections kept from the original:
 *   - Apply to Invoice form (shows when status is active or partially_used) —
 *     FF_RecordPicker limited to this customer's sent/partially_paid/overdue
 *     invoices (number + balance), Max = min(credit remaining, invoice balance)
 *   - Applications history table
 *   - Void modal (requires reason)
 *
 * Financial redaction (I03): the page is reachable with invoices:view alone
 * (dispatchers, payments:NONE). For viewers failing can_view_financials() the
 * money fields are nulled at the source and every dollar surface is withheld —
 * the Total/Applied/Remaining strip segments, the credit-balance rail card, the
 * Amount Applied column + Total Applied footer, the cash-refund Amount column
 * (S-RECORD-REDESIGN: it used to show to everyone), the Apply to Invoice card
 * (every input is a dollar figure) and its CN_REMAINING_CENTS script constant,
 * and the void modal's balance figure. Number, customer, source, status,
 * currency, dates, reason and the linked invoices stay visible. Mirrors
 * api/v1/credit_notes/show.php.
 *
 * D32: All CSS classes verified in app.css.
 * D30: asset_url() / base_url() for links.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php,
 *           lib/Ui/ModuleHero.php, lib/Ui/RecordUi.php
 * @decisions D5/D12/D16/D18/D19/D20/D30/D32
 * @session  S011 → S-RECORD-REDESIGN
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
if (!$canSeeMoney) {
    // I03: same source-nulling as the note's own figures — the refund table
    // used to print every cash refund amount to dispatchers.
    foreach ($cnRefunds as &$_ref) {
        $_ref['amount'] = null;
    }
    unset($_ref);
}
// Edit Metadata is offered when the credit can no longer be applied (the
// Apply card takes its place while it can) — same condition as before.
$showEditMeta = !$showApply && $canEdit && $cn['status'] !== 'void';

// ── S-RECORD-REDESIGN: strip + rail data ─────────────────────────
$today       = ff_today();
$sourceLabel = $sourceLabels[$cn['source']] ?? (string) $cn['source'];
$liveApps    = array_values(array_filter($applications, static fn ($a) => ($a['application_status'] ?? 'applied') !== 'reversed'));
$reversedCnt = count($applications) - count($liveApps);

/** Whole days from a Y-m-d date to today (company-local); negative = future. */
$daysFrom = static function (?string $ymd) use ($today): ?int {
    if ($ymd === null || $ymd === '') {
        return null;
    }
    try {
        return (int) (new DateTimeImmutable($ymd))->diff(new DateTimeImmutable($today))->format('%r%a');
    } catch (Throwable) {
        return null;
    }
};
// created_at / applied_at are UTC DATETIMEs — compare on the company-local day.
$issuedYmd  = format_datetime($cn['created_at'], 'Y-m-d');
$issuedAgo  = $daysFrom($issuedYmd !== '—' ? $issuedYmd : null);
$lastUseYmd = $issuedYmd;
foreach ($liveApps as $_la) {
    $_d = format_datetime($_la['applied_at'], 'Y-m-d');
    if ($_d !== '—' && $_d > $lastUseYmd) {
        $lastUseYmd = $_d;
    }
}
unset($_la, $_d);
$idleDays   = $daysFrom($lastUseYmd !== '—' ? $lastUseYmd : null);
// "Sitting unused": still has credit (status says so — works without the
// redacted amount) and nothing applied for over 30 days.
$staleCredit = $isApplicable && $idleDays !== null && $idleDays > 30;
$expiresIn   = !empty($cn['expires_at']) ? -1 * (int) $daysFrom((string) $cn['expires_at']) : null;

$fmtDays = static fn (int $n): string => $n . ' day' . ($n === 1 ? '' : 's');

// Money figures (financial viewers only — the fields are null otherwise).
$appliedTotal = '0.00';
$refundTotal  = '0.00';
$usedPct      = 0.0;
if ($canSeeMoney) {
    foreach ($liveApps as $_la) {
        $appliedTotal = bcadd($appliedTotal, (string) $_la['amount_applied'], 2);
    }
    foreach ($cnRefunds as $_rf) {
        $refundTotal = bcadd($refundTotal, (string) $_rf['amount'], 2);
    }
    unset($_la, $_rf);
    // Used = applied (live applications) + refunded in cash — not
    // amount − remaining, which would call a VOIDED note "100 % used".
    $usedPct = bccomp((string) $cn['amount'], '0', 2) > 0
        ? (float) bcmul(bcdiv(bcadd($appliedTotal, $refundTotal, 6), (string) $cn['amount'], 6), '100', 2)
        : 0.0;
}

// Open invoices this credit could pay (same customer + currency) — a count
// for everyone, the owing total for financial viewers.
$openInv = ['cnt' => 0, 'owing' => '0'];
if ($isApplicable && !empty($cn['customer_id'])) {
    $openInv = db_row(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due), 0) AS owing FROM invoices
          WHERE customer_id = ? AND currency = ? AND deleted_at IS NULL
            AND status IN ('sent', 'partially_paid', 'overdue') AND balance_due > 0",
        [(int) $cn['customer_id'], (string) $cn['currency']]
    ) ?: $openInv;
}

// Source records by number (the page used to print "INV #4860" / "#459").
$srcInvoice = !empty($cn['source_invoice_id'])
    ? db_row("SELECT id, invoice_number FROM invoices WHERE id = ?", [(int) $cn['source_invoice_id']])
    : null;
$srcPayment = !empty($cn['source_payment_id']) && can('payments', 'view')
    ? db_row("SELECT id, payment_number FROM payments WHERE id = ? AND deleted_at IS NULL", [(int) $cn['source_payment_id']])
    : null;
$srcLease = !empty($cn['lease_id'])
    ? db_row("SELECT id, contract_number FROM leases WHERE id = ?", [(int) $cn['lease_id']])
    : null;

// QuickBooks status for the rail summary — the full panel stays in the main
// column. Same labels as includes/partials/qbo-sync-panel.php.
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboMap = $qboConnected
    ? db_row("SELECT push_status, qbo_credit_memo_id, pushed_at FROM acc_qbo_credit_memo_map WHERE ff_credit_note_id = ? LIMIT 1", [(int) $cn['id']])
    : null;

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

$pageTitle      = 'Credit Note ' . e($cn['credit_note_number']);
$helpModuleSlug = 'credit-notes';
require_once FF_ROOT . '/includes/header.php';
?>

<?php
// ============================================================
// Page header — entity hero (S-RECORD-REDESIGN). "Apply credit" is the
// visible action; Refund / Edit / Void live in the More menu.
// ============================================================
ob_start(); /* secondary actions → the header's More menu (S-RECORD-REDESIGN) */ ?>
        <?php if ($showRefund): ?>
            <a href="#cn-refund" class="btn btn-secondary btn-sm"><?= heroicon('banknotes', 'icon-sm') ?> Refund as cash</a>
        <?php endif; ?>
        <?php if ($showEditMeta): ?>
            <a href="#cn-edit" class="btn btn-secondary btn-sm"><?= heroicon('pencil-square', 'icon-sm') ?> Edit details</a>
        <?php endif; ?>
        <?php if ($canEdit && $isApplicable): ?>
            <hr>
            <button class="btn btn-sm btn-danger" @click.prevent="$dispatch('open-void-modal')">
                <?= heroicon('x-circle', 'icon-sm') ?> Void
            </button>
        <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
        <?= help_button('credit-notes') ?>
        <?php if ($showApply): ?>
            <a href="#cn-apply" class="btn btn-primary btn-sm"><?= heroicon('receipt-percent', 'icon-sm') ?> Apply credit</a>
        <?php endif; ?>
        <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?php
$heroFacts = [];
if (!empty($cn['customer_id'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('user-group')
        . '<a href="' . e(base_url('customers/show')) . '?id=' . (int) $cn['customer_id'] . '" style="color:inherit;">' . e($cn['customer_name'] ?? '—') . '</a>';
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('receipt-percent') . e($sourceLabel);
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('calendar-days') . 'Issued ' . e(format_datetime($cn['created_at'], 'M j, Y'))
    . ' by ' . e($cn['created_by_name'] ?? 'System');
if (!empty($cn['expires_at'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('clock') . 'Expires ' . e(format_date($cn['expires_at']));
}
$heroMark = (string) $cn['credit_note_number'];
if (preg_match('/(\d{3,})$/', $heroMark, $hm)) { $heroMark = $hm[1]; }
?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => 'info',
    'icon'       => 'receipt-percent',
    'mark'       => $heroMark,
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Credit Notes', base_url('credit_notes')], [(string) $cn['credit_note_number'], null]],
    'eyebrow'    => 'Credit note',
    'title_html' => '<span class="font-mono">' . e($cn['credit_note_number']) . '</span> <span class="' . $badgeClass . ' badge-no-dot">' . e($statusLabel) . '</span>'
                  . ($cn['currency'] !== 'CAD' ? ' <span class="badge badge-no-dot badge-warning">' . e($cn['currency']) . '</span>' : ''),
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php
// ============================================================
// KEY NUMBERS — the summary strip (S-RECORD-REDESIGN): total, applied,
// remaining, applications. The three money segments are dropped (not
// zeroed — a zero remaining would misreport the note as used up) for
// non-financial viewers, who get applications + issued / expiry instead;
// the .stat-grid--N modifier is resolved from the real segment count
// (tests/_smoke_financial_field_redaction.php pins --4 / --2). Source
// moved to the header facts + rail (identity, not a number).
// ============================================================
?>
<div class="stat-grid <?= $canSeeMoney ? 'stat-grid--4' : 'stat-grid--2' ?> ff-stats">
    <?php if ($canSeeMoney): ?>
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Total Amount</div>
        <div class="stat-value font-mono"><?= format_currency($cn['amount']) ?></div>
        <div class="stat-delta"><?= e($cn['currency']) ?></div>
    </div>

    <a class="stat-card stat-card--green" href="#cn-applications" title="Applied to invoices<?= bccomp($refundTotal, '0', 2) > 0 ? e(' · ' . format_currency($refundTotal) . ' refunded in cash') : '' ?>">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Applied</div>
        <div class="stat-value font-mono"><?= format_currency($appliedTotal) ?></div>
        <div class="stat-delta"><?= bccomp($refundTotal, '0', 2) > 0 ? '+ ' . e(format_currency($refundTotal)) . ' refunded' : e((string) round($usedPct)) . '% used' ?></div>
    </a>

    <?php
    $_hasLeft  = bccomp((string) $cn['amount_remaining'], '0', 2) > 0;
    $_remTone  = !$isApplicable ? 'slate' : ($_hasLeft ? ($staleCredit ? 'red' : 'amber') : 'green');
    $_remTag   = $showApply ? 'a href="#cn-apply" title="Apply the remaining credit to an invoice"' : 'div';
    ?>
    <<?= $_remTag ?> class="stat-card stat-card--<?= $_remTone ?>">
        <span class="stat-icon stat-icon--<?= $_remTone ?>"><svg><use href="#icon-<?= $_hasLeft && $isApplicable ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Remaining Balance</div>
        <div class="stat-value font-mono"<?= $staleCredit ? ' style="color:var(--color-danger);"' : '' ?>><?= format_currency($cn['amount_remaining']) ?></div>
        <div class="stat-delta"><?php
            if ($cn['status'] === 'void') {
                echo 'voided';
            } elseif ($cn['status'] === 'expired') {
                echo 'expired';
            } elseif (!$_hasLeft) {
                echo 'used up';
            } else {
                echo 'to apply';
            }
        ?></div>
    </<?= $_remTag === 'div' ? 'div' : 'a' ?>>
    <?php endif; ?>

    <a class="stat-card stat-card--purple" href="#cn-applications" title="Invoices this credit was applied to">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Applications</div>
        <div class="stat-value font-mono"><?= count($liveApps) ?></div>
        <div class="stat-delta"><?= $reversedCnt > 0 ? $reversedCnt . ' reversed' : 'invoices credited' ?></div>
    </a>

    <?php if ($canSeeMoney): /* 4 segments — issued / expiry are in the header facts */ ?>
    <?php elseif (!empty($cn['expires_at'])): ?>
    <div class="stat-card <?= $expiresIn !== null && $expiresIn <= 30 && $isApplicable ? 'stat-card--amber' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $expiresIn !== null && $expiresIn <= 30 && $isApplicable ? 'stat-icon--amber' : 'stat-icon--slate' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Expires</div>
        <div class="stat-value stat-value--date font-mono"><?= format_date($cn['expires_at']) ?></div>
        <div class="stat-delta"><?= $expiresIn === null ? '' : ($expiresIn < 0 ? e($fmtDays(-$expiresIn)) . ' ago' : ($expiresIn === 0 ? 'today' : 'in ' . e($fmtDays($expiresIn)))) ?></div>
    </div>
    <?php else: ?>
    <div class="stat-card stat-card--slate" title="Issued <?= e(format_datetime($cn['created_at'])) ?>">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Issued</div>
        <div class="stat-value stat-value--date font-mono"><?= e(format_datetime($cn['created_at'], 'M j, Y')) ?></div>
        <div class="stat-delta"><?= $issuedAgo === null ? '' : ($issuedAgo === 0 ? 'today' : e($fmtDays($issuedAgo)) . ' ago') ?></div>
    </div>
    <?php endif; ?>
</div>

<div class="rec-layout">
<div class="rec-main">

    <?php if ($showApply): ?>
    <!-- Apply to Invoice (+ Refund as Cash) — the note's primary work, first.
         Shown only when credit is usable and its figures are visible. -->
    <div class="<?= $showRefund ? 'grid-2' : '' ?>" style="margin-bottom:14px; align-items:start;">
    <div class="card" id="cn-apply" x-data="applyForm()" style="scroll-margin-top:80px;">
        <div class="card-header"><h3 class="card-title">Apply to Invoice</h3></div>
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
    <div class="card" id="cn-refund" x-data="refundForm()" style="scroll-margin-top:80px;">
        <div class="card-header"><h3 class="card-title">Refund as Cash</h3></div>
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
    </div>
    <?php endif; ?>

    <?php
    // Why this credit exists (was the lower half of the "Credit Note Details"
    // card; the identity rows moved to the header facts + rail).
    $_showInternal = $cn['internal_notes'] && can('invoices', 'edit');
    if ($cn['reason'] || $_showInternal): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header"><h3 class="card-title">Why this credit</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <?php if ($cn['reason']): ?>
                <dt>Reason</dt>
                <dd><?= nl2br(e($cn['reason'])) ?></dd>
                <?php endif; ?>
                <?php if ($_showInternal): ?>
                <dt>Internal Notes</dt>
                <dd style="color:var(--text-secondary);"><?= nl2br(e($cn['internal_notes'])) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <!-- Applications history -->
    <div class="card" id="cn-applications" style="margin-bottom:14px; scroll-margin-top:80px;">
        <div class="card-header">
            <h3 class="card-title">Application History</h3>
            <span class="badge badge-neutral"><?= count($liveApps) ?> applied<?= $reversedCnt > 0 ? ' · ' . $reversedCnt . ' reversed' : '' ?></span>
        </div>
        <?php if (empty($applications)): ?>
        <div class="card-body">
            <p class="text-secondary" style="margin:0; font-size:0.875rem;">Not applied to any invoice yet.<?= $isApplicable && (int) $openInv['cnt'] > 0 ? ' This customer has ' . (int) $openInv['cnt'] . ' open invoice' . ((int) $openInv['cnt'] === 1 ? '' : 's') . ' it could pay.' : '' ?></p>
        </div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <?php if ($cnRefunds): ?>
    <div class="card" id="cn-refunds" style="margin-bottom:14px;">
        <div class="card-header"><h3 class="card-title">Cash refunds</h3></div>
        <div class="card-body" style="padding:0; overflow-x:auto;">
            <table class="table" style="width:100%;">
                <thead><tr><th>Date</th><?php if ($canSeeMoney): ?><th style="text-align:right;">Amount</th><?php endif; ?><th>Method</th><th>Reference</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($cnRefunds as $r): ?>
                    <tr>
                        <td><?= e(format_date($r['refund_date'])) ?></td>
                        <?php if ($canSeeMoney): ?>
                        <td class="font-mono" style="text-align:right;"><?= e($cn['currency'] . ' ' . number_format((float) $r['amount'], 2)) ?></td>
                        <?php endif; ?>
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

    <?php if ($showEditMeta): ?>
    <?php /* S-RECORD-REDESIGN: below the read-only sections — the note is
             done being applied, so editing its details is secondary
             (the header's More → Edit details jumps here). */ ?>
    <!-- Edit metadata card when credit is not applicable -->
    <div class="card" id="cn-edit" x-data="editMeta()" style="margin-bottom:14px; scroll-margin-top:80px;">
        <div class="card-header"><h3 class="card-title">Edit Metadata</h3></div>
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

    <?php
    // F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
    // credit_notes/show.php had ZERO QBO mentions before this — now at parity with invoices.
    // S-RECORD-REDESIGN: below the note's own content; the rail carries its status.
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

    <!-- ── Activity Log ───────────────────────────────────────────── -->
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header"><h3 class="card-title">Activity</h3></div>
        <div class="card-body">
            <?php $activityEntityType = 'credit_note'; $activityEntityId = $id; ?>
            <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
        </div>
    </div>

</div><!-- /rec-main -->

<?php
// ── RAIL (S-RECORD-REDESIGN) — the credit note at a glance ───────
$R     = \FleetForge\Ui\RecordUi::class;
$railC = [];

// 1. Needs attention.
$alertsC = [];
if ($staleCredit) {
    $alertsC[] = ['warning', ($canSeeMoney ? e(format_currency($cn['amount_remaining'])) . ' of credit has' : 'This credit has')
        . ' sat unused for ' . e($fmtDays((int) $idleDays)) . ' — '
        . ($showApply ? '<a href="#cn-apply">apply it</a>' . ($showRefund ? ' or <a href="#cn-refund">refund it</a>' : '') : 'apply it or refund it') . '.'];
}
if ($isApplicable && (int) $openInv['cnt'] > 0) {
    $alertsC[] = ['info', 'The customer has <a href="' . e(base_url('invoices')) . '?customer_id=' . (int) $cn['customer_id'] . '&status=outstanding">'
        . (int) $openInv['cnt'] . ' open invoice' . ((int) $openInv['cnt'] === 1 ? '' : 's') . '</a>'
        . ($canSeeMoney ? ' (' . e(format_currency($openInv['owing'])) . ' owing)' : '') . ' this credit could pay.'];
}
if ($isApplicable && $expiresIn !== null) {
    if ($expiresIn < 0) {
        $alertsC[] = ['danger', 'Expiry date passed ' . e($fmtDays(-$expiresIn)) . ' ago but the credit is still open.'];
    } elseif ($expiresIn <= 30) {
        $alertsC[] = ['warning', 'Expires ' . ($expiresIn === 0 ? 'today' : 'in ' . e($fmtDays($expiresIn))) . '.'];
    }
}
if ($cn['status'] === 'expired') {
    $alertsC[] = ['info', 'Expired' . (!empty($cn['expires_at']) ? ' on ' . e(format_date($cn['expires_at'])) : '') . ' — it can no longer be applied.'];
}
if ($cn['status'] === 'void') {
    $alertsC[] = ['info', 'Voided' . ($cn['voided_by_name'] ? ' by ' . e($cn['voided_by_name']) : '') . ($cn['voided_at'] ? ' on ' . e(format_datetime($cn['voided_at'])) : '') . '.'];
}
if ($qboMap !== null && ((string) ($qboMap['push_status'] ?? '') === 'failed' || str_starts_with((string) ($qboMap['push_status'] ?? ''), 'failed_preflight'))) {
    $alertsC[] = ['danger', '<a href="#qbo-sync-panel">QuickBooks push failed</a> — retry from the QuickBooks panel.'];
}
$railC[] = $R::card('Needs attention', $R::alerts($alertsC, $cn['status'] === 'fully_used' ? 'Fully used — nothing to do.' : 'All clear — nothing needs attention.'), ['icon' => 'exclamation-triangle']);

// 2. Credit balance — used vs remaining (financial viewers only).
if ($canSeeMoney) {
    $balBody  = $cn['status'] === 'void'
        ? $R::big('Voided', e(format_currency($cn['amount'])) . ' ' . e($cn['currency']) . ' note — nothing left to use')
        : $R::big(e(format_currency($cn['amount_remaining'])), 'left of ' . e(format_currency($cn['amount'])) . ' ' . e($cn['currency']));
    $balBody .= $R::meter('Used', e((string) round($usedPct)) . '%', $usedPct,
        $usedPct >= 99.99 ? 'ok' : ($staleCredit ? 'warn' : 'info'),
        e(format_currency($appliedTotal)) . ' applied' . (bccomp($refundTotal, '0', 2) > 0 ? ' · ' . e(format_currency($refundTotal)) . ' refunded' : ''));
    $railC[] = $R::card('Credit balance', $balBody, ['icon' => 'banknotes', 'class' => 'rec-card--accent']
        + ($showApply ? ['link' => ['Apply', '#cn-apply']] : []));
}

// 3. Customer.
if (!empty($cn['customer_id'])) {
    $_cid   = (int) $cn['customer_id'];
    $_cname = (string) ($cn['customer_name'] ?? 'Customer');
    $custBody = $R::entity($_cname, base_url('customers/show') . '?id=' . $_cid,
        !empty($cn['customer_name_snapshot']) && $cn['customer_name_snapshot'] !== $_cname ? e($cn['customer_name_snapshot']) : 'Customer',
        \FleetForge\Ui\ModuleHero::initials($_cname));
    $custBody .= '<div style="margin-top:12px;">' . $R::links([
        ['Credit notes for this customer', base_url('credit_notes') . '?customer_id=' . $_cid, 'receipt-percent'],
        ['Open invoices', base_url('invoices') . '?customer_id=' . $_cid . '&status=outstanding', 'document-text', $isApplicable && (int) $openInv['cnt'] > 0 ? (string) (int) $openInv['cnt'] : ''],
    ]) . '</div>';
    $railC[] = $R::card('Customer', $custBody, ['icon' => 'user-group']);
}

// 4. Source + record facts (was the "Credit Note Details" card).
$railC[] = $R::card('Details', $R::kv([
    ['Source', e($sourceLabel)],
    ['From invoice', $cn['source_invoice_id']
        ? '<a href="' . e(base_url('invoices/show')) . '?id=' . (int) $cn['source_invoice_id'] . '">' . e($srcInvoice['invoice_number'] ?? ('INV #' . (int) $cn['source_invoice_id'])) . '</a>'
        : null],
    ['From payment', $srcPayment ? '<a href="' . e(base_url('payments/show')) . '?id=' . (int) $srcPayment['id'] . '">' . e($srcPayment['payment_number']) . '</a>' : null],
    ['Lease', $cn['lease_id']
        ? '<a href="' . e(base_url('leases/show')) . '?id=' . (int) $cn['lease_id'] . '">' . e($srcLease['contract_number'] ?? ('#' . (int) $cn['lease_id'])) . '</a>'
        : null],
    ['Currency', e($cn['currency'])],
    ['Expires', $cn['expires_at'] ? e(format_date($cn['expires_at'])) : '—'],
    ['Created by', e($cn['created_by_name'] ?? 'System')],
    ['Created at', e(format_datetime($cn['created_at']))],
    ['Voided', $cn['voided_at'] ? ($cn['voided_by_name'] ? e($cn['voided_by_name']) . ' · ' : '') . e(format_datetime($cn['voided_at'])) : null],
]), ['icon' => 'document-text']);

// 5. QuickBooks summary (full panel in the main column).
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
    $railC[] = $R::card('QuickBooks', $R::kv([
        ['Status', '<span class="badge badge-no-dot ' . $_qBadge . '">' . e($_qLabel) . '</span>'],
        ['Credit memo #', !empty($qboMap['qbo_credit_memo_id']) ? e('#' . $qboMap['qbo_credit_memo_id']) : null, 'mono'],
        ['Last push', !empty($qboMap['pushed_at']) ? e(format_datetime($qboMap['pushed_at'])) : null],
    ]), ['icon' => 'arrow-path', 'link' => ['Details', '#qbo-sync-panel']]);
}
?>
<aside class="rec-rail" aria-label="Credit note at a glance">
    <?= implode("\n    ", $railC) ?>
</aside>
</div><!-- /rec-layout -->

<!-- Void modal (opened from the header's More menu via a window event) -->
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

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
