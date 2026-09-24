<?php declare(strict_types=1);

/**
 * app/admin/accounting/ap-payments/show.php
 *
 * AP Payment detail view — read-only drill-down for a single
 * acc_ap_payments row. Displays payment header, allocations to bills
 * (linked to their detail pages), the linked journal entry, the
 * QuickBooks sync panel and the Documents section.
 *
 * Layout (S-RECORD-REDESIGN):
 *   header  — ModuleHero entity: payment number + status / "From
 *             QuickBooks" badges; vendor · date · method · bank chips
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — amount · allocated · unallocated · bills paid
 *   main    — Payment details · Bill allocations (with totals) ·
 *             QuickBooks sync · Documents
 *   rail    — Needs attention (void / pending / unapplied money / no GL
 *             entry / QBO failure) · Vendor · Allocation meter · Related
 *             (journal entry, bank account, vendor's payments) · Audit trail
 *   The old "Linked Journal Entry" card became a details row + rail link.
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §15 (subledger drill-down)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §20.2 (Phase 3 deliverable)
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/qbo-sync-panel.php,
 *          includes/partials/acc-documents-section.php, lib/Ui/RecordUi.php
 * @session S-ACCT-FIX-AP, S-RECORD-REDESIGN
 */

// dirname(__DIR__, 4): ap-payments/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('accounts_payable', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — AP Payment Not Specified</h1>';
    exit;
}

// ── Payment header ───────────────────────────────────────────────────────────
$payment = db_row(
    "SELECT ap.*, v.name AS vendor_name, v.id AS vendor_id_join,
            v.email AS vendor_email, v.phone AS vendor_phone,
            ba.name AS bank_account_name, ba.account_number_last4 AS bank_last4,
            je.id AS je_id, je.entry_number AS je_number, je.entry_date AS je_entry_date, je.status AS je_status,
            u.name AS created_by_name,
            uv.name AS voided_by_name
       FROM acc_ap_payments ap
       JOIN vendors v ON v.id = ap.vendor_id
       JOIN acc_bank_accounts ba ON ba.id = ap.bank_account_id
  LEFT JOIN acc_journal_entries je ON je.id = ap.journal_entry_id
  LEFT JOIN users u ON u.id = ap.created_by
  LEFT JOIN users uv ON uv.id = ap.voided_by
      WHERE ap.id = ?",
    [$id]
);

if (!$payment) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — AP Payment Not Found</h1>';
    exit;
}

// S-QBO-BILLPAY-MIRROR: the accountant paid this bill in QuickBooks and
// FleetForge mirrored it — QuickBooks owns it.
$fromQbo = ($payment['origin'] ?? 'ff_native') !== 'ff_native';

// ── Allocations ──────────────────────────────────────────────────────────────
$allocations = db_select(
    "SELECT apa.id, apa.bill_id, apa.amount_applied, apa.created_at,
            b.bill_number, b.vendor_bill_number, b.bill_date, b.due_date, b.total_amount, b.balance_due, b.status AS bill_status
       FROM acc_ap_payment_allocations apa
       JOIN acc_bills b ON b.id = apa.bill_id
      WHERE apa.ap_payment_id = ?
      ORDER BY apa.id ASC",
    [$id]
);

// Money in bcmath: allocated vs the payment amount → what is unapplied.
$allocated = '0.00';
$billsPaid = 0;
foreach ($allocations as $a) {
    $allocated = bcadd($allocated, (string) $a['amount_applied'], 2);
    if ($a['bill_status'] === 'paid') {
        $billsPaid++;
    }
}
$unallocated = bcsub((string) $payment['amount'], $allocated, 2);
$isVoid      = $payment['status'] === 'void';
$allocPct    = bccomp((string) $payment['amount'], '0', 2) > 0
    ? (float) bcmul(bcdiv($allocated, (string) $payment['amount'], 6), '100', 2)
    : 0.0;

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'cleared'        => 'badge-green',
        'pending'        => 'badge-amber',
        'void'           => 'badge-red',
        'paid'           => 'badge-green',
        'partially_paid' => 'badge-amber',
        'approved'       => 'badge-blue',
        'draft'          => 'badge-neutral',
        'posted'         => 'badge-green',
        'reversed'       => 'badge-red',
        default          => 'badge-neutral',
    };
};

// QuickBooks state for the rail (the panel itself renders in the main column).
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboMap = $qboConnected
    ? db_row("SELECT push_status, push_error FROM acc_qbo_bill_payment_map WHERE ff_ap_payment_id = ? LIMIT 1", [$id])
    : null;

$canVendor = can('vendors', 'view');
$ccy       = (string) ($payment['currency'] ?? 'CAD');

$pageTitle = 'AP Payment ' . $payment['payment_number'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroTitle = 'Payment ' . e($payment['payment_number'])
    . ' <span class="badge ' . e($statusBadgeClass((string) $payment['status'])) . '">' . e($payment['status']) . '</span>';
if ($fromQbo) { /* S-QBO-BILLPAY-MIRROR */
    $heroTitle .= ' <span class="badge badge-blue" title="Paid in QuickBooks by the accountant and copied into FleetForge. Change or void it in QuickBooks — FleetForge follows.">From QuickBooks</span>';
}
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('building-storefront') . ($canVendor
        ? '<a href="' . e(base_url('vendors/show')) . '?id=' . (int) $payment['vendor_id'] . '">' . e($payment['vendor_name']) . '</a>'
        : e($payment['vendor_name'])),
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($payment['payment_date'])),
    \FleetForge\Sop\SopIcons::svg('credit-card') . e(ucwords(str_replace('_', ' ', (string) $payment['payment_method'])))
        . ($payment['payment_method'] === 'check' && $payment['check_number'] ? ' #' . e($payment['check_number']) : ''),
    \FleetForge\Sop\SopIcons::svg('building-library') . e($payment['bank_account_name']),
];
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/ap-payments') ?>">All AP payments</a>
    <a href="<?= base_url('accounting/ap-payments') ?>?vendor_id=<?= (int) $payment['vendor_id'] ?>">Payments to this vendor</a>
    <?php if ($qboConnected): ?>
    <a href="#qbo-sync-panel">QuickBooks sync</a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?php if ($payment['je_id']): ?>
    <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $payment['je_id']) ?>"><?= \FleetForge\Sop\SopIcons::svg('book-open') ?> Journal entry</a>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => match ((string) $payment['status']) { 'void' => 'danger', 'pending' => 'warning', default => 'success' },
    'icon'       => 'banknotes',
    'mark'       => (string) $payment['payment_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['AP Payments', base_url('accounting/ap-payments')], [(string) $payment['payment_number'], null]],
    'eyebrow'    => 'Vendor payment',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): what was paid, how much of it is
     applied to bills, what is still unapplied, and how many bills it paid. -->
<div class="stat-grid stat-grid--4 ff-stats">
    <div class="stat-card <?= $isVoid ? 'stat-card--red' : 'stat-card--blue' ?>">
        <span class="stat-icon <?= $isVoid ? 'stat-icon--red' : 'stat-icon--blue' ?>"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Amount</div>
        <div class="stat-value font-mono"<?= $isVoid ? ' style="text-decoration:line-through;"' : '' ?>><?= e(format_currency($payment['amount'])) ?></div>
        <div class="stat-delta"><?= e($ccy) ?><?= $isVoid ? ' · void' : '' ?></div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Allocated</div>
        <div class="stat-value font-mono"><?= e(format_currency($allocated)) ?></div>
        <div class="stat-delta"><?= round($allocPct) ?>%</div>
    </div>
    <?php $hasUnapplied = bccomp($unallocated, '0', 2) !== 0 && !$isVoid; ?>
    <div class="stat-card <?= $hasUnapplied ? 'stat-card--amber' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= $hasUnapplied ? 'stat-icon--amber' : 'stat-icon--slate' ?>"><svg><use href="#icon-<?= $hasUnapplied ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Unallocated</div>
        <div class="stat-value font-mono"><?= e(format_currency($unallocated)) ?></div>
        <div class="stat-delta"><?= $hasUnapplied ? 'unapplied' : 'none left' ?></div>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Bills</div>
        <div class="stat-value font-mono"><?= count($allocations) ?></div>
        <div class="stat-delta"><?= $billsPaid ?> paid off</div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

    <!-- ── Payment details ─────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Payment details</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <dt>Vendor</dt>
                <dd><?= $canVendor ? '<a class="link" href="' . e(base_url('vendors/show')) . '?id=' . (int) $payment['vendor_id'] . '">' . e($payment['vendor_name']) . '</a>' : e($payment['vendor_name']) ?></dd>
                <dt>Payment date</dt>
                <dd class="font-mono"><?= e(format_date($payment['payment_date'])) ?></dd>
                <dt>Amount</dt>
                <dd class="font-mono" style="font-weight:700;"><?= e(format_currency($payment['amount'])) ?> <span class="text-secondary" style="font-weight:400;"><?= e($ccy) ?><?php if ($ccy !== 'CAD' && !empty($payment['exchange_rate_to_cad'])): ?> @ <?= e((string) $payment['exchange_rate_to_cad']) ?> to CAD<?php endif; ?></span></dd>
                <dt>Bank account</dt>
                <dd><?= e($payment['bank_account_name']) ?><?php if (!empty($payment['bank_last4'])): ?> <span class="text-secondary">··<?= e($payment['bank_last4']) ?></span><?php endif; ?></dd>
                <dt>Method</dt>
                <dd><?= e(ucwords(str_replace('_', ' ', (string) $payment['payment_method']))) ?></dd>
                <dt>Reference #</dt>
                <dd class="font-mono"><?= $payment['reference_number'] ? e($payment['reference_number']) : '<span class="text-secondary">—</span>' ?></dd>
                <?php if ($payment['payment_method'] === 'check'): ?>
                <dt>Check #</dt>
                <dd class="font-mono"><?= $payment['check_number'] ? e($payment['check_number']) : '<span class="text-secondary">—</span>' ?></dd>
                <?php endif; ?>
                <dt>Journal entry</dt>
                <dd>
                    <?php if ($payment['je_id']): ?>
                        <a class="link font-mono" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $payment['je_id']) ?>"><?= e($payment['je_number']) ?></a>
                        <span class="badge <?= e($statusBadgeClass((string) $payment['je_status'])) ?>"><?= e((string) $payment['je_status']) ?></span>
                        <span class="text-secondary">· <?= e(format_date($payment['je_entry_date'])) ?></span>
                    <?php else: ?>
                        <span class="text-secondary">No journal entry linked to this payment.</span>
                    <?php endif; ?>
                </dd>
                <?php if ($payment['notes']): ?>
                <dt>Notes</dt>
                <dd style="white-space:pre-wrap;"><?= e($payment['notes']) ?></dd>
                <?php endif; ?>
                <?php if ($isVoid && $payment['void_reason']): ?>
                <dt style="color:var(--color-danger);">Void reason</dt>
                <dd style="white-space:pre-wrap;"><?= e($payment['void_reason']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- ── Allocations ─────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Bill allocations</h3></div>
        <div class="card-body">
        <?php if (count($allocations) === 0): ?>
            <p class="text-secondary" style="margin:0;font-size:0.8125rem;">No allocations recorded for this payment.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bill #</th>
                            <th>Bill date</th>
                            <th>Due</th>
                            <th class="text-right">Bill total</th>
                            <th class="text-right">Applied</th>
                            <th class="text-right">Bill balance</th>
                            <th style="text-align:center;">Bill status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $a): ?>
                            <tr>
                                <td class="font-mono">
                                    <a class="link" href="<?= base_url('accounting/bills/show?id=' . (int) $a['bill_id']) ?>"><?= e($a['bill_number']) ?></a>
                                    <?php if (!empty($a['vendor_bill_number'])): ?><div class="text-secondary" style="font-size:0.72rem;">Vendor # <?= e($a['vendor_bill_number']) ?></div><?php endif; ?>
                                </td>
                                <td class="font-mono"><?= e(format_date($a['bill_date'])) ?></td>
                                <td class="font-mono"><?= e(format_date($a['due_date'])) ?></td>
                                <td class="font-mono text-right"><?= e(format_currency($a['total_amount'])) ?></td>
                                <td class="font-mono text-right" style="font-weight:600;"><?= e(format_currency($a['amount_applied'])) ?></td>
                                <td class="font-mono text-right"><?= e(format_currency($a['balance_due'])) ?></td>
                                <td style="text-align:center;">
                                    <span class="badge <?= e($statusBadgeClass($a['bill_status'])) ?>"><?= e(str_replace('_', ' ', $a['bill_status'])) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right" style="font-weight:600;">Applied to <?= count($allocations) ?> bill<?= count($allocations) === 1 ? '' : 's' ?></td>
                            <td class="font-mono text-right" style="font-weight:700;"><?= e(format_currency($allocated)) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <?php
    // F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
    // S-RECORD-REDESIGN: moved from above the header into the main column.
    $qboPanel = [
        'entity_type' => 'bill_payment',
        'map_table'   => 'acc_qbo_bill_payment_map',
        'qbo_id_col'  => 'qbo_bill_payment_id',
        'ff_fk'       => 'ff_ap_payment_id',
        'ff_id'       => (int) $payment['id'],
        'deep_link'   => 'billpayment',
        'retry_url'   => base_url('api/v1/quickbooks/bill_payments/retry'),
    ];
    require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
    ?>

    <!-- ── Documents ───────────────────────────────────────────────────── -->
    <?php
    $entityType = 'ap_payment';
    $entityId   = (int) $payment['id'];
    require FF_ROOT . '/includes/partials/acc-documents-section.php';
    ?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the payment at a glance ──────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($isVoid) {
    $alerts[] = ['danger', 'Voided' . (!empty($payment['voided_at']) ? ' ' . e(format_datetime($payment['voided_at'])) : '') . (!empty($payment['void_reason']) ? ' — ' . e($payment['void_reason']) : '') . '.'];
}
if ($payment['status'] === 'pending') {
    $alerts[] = ['warning', 'Not cleared yet — it clears when the bank line is matched on the <a href="' . e(base_url('accounting/bank-accounts')) . '">Banking</a> page.'];
}
if (!$isVoid && bccomp($unallocated, '0', 2) > 0) {
    $alerts[] = ['warning', '<b>' . e(format_currency($unallocated)) . '</b> is not applied to any bill.'];
}
if (!$isVoid && bccomp($unallocated, '0', 2) < 0) {
    $alerts[] = ['danger', 'Allocations exceed the payment by <b>' . e(format_currency(ltrim($unallocated, '-'))) . '</b>.'];
}
if (!$payment['je_id'] && !$isVoid) {
    $alerts[] = ['warning', 'No journal entry — this payment is not in the general ledger.'];
} elseif ($payment['je_id'] && $payment['je_status'] === 'reversed' && !$isVoid) {
    $alerts[] = ['warning', 'Its journal entry <a href="' . e(base_url('accounting/journal-entries/show?id=' . (int) $payment['je_id'])) . '">' . e($payment['je_number']) . '</a> is reversed.'];
}
if ($fromQbo) {
    $alerts[] = ['info', 'Mirrored from QuickBooks — change or void it there; FleetForge follows.'];
}
if ($qboMap !== null && str_starts_with((string) ($qboMap['push_status'] ?? ''), 'failed')) {
    $alerts[] = ['danger', '<a href="#qbo-sync-panel">QuickBooks push failed</a>' . (!empty($qboMap['push_error']) ? ' — ' . e(mb_strimwidth((string) $qboMap['push_error'], 0, 90, '…')) : '') . '.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — cleared and fully applied.'), ['icon' => 'exclamation-triangle']);

// Vendor.
$vendorSub = implode(' · ', array_filter([e((string) ($payment['vendor_email'] ?? '')), e((string) ($payment['vendor_phone'] ?? ''))])) ?: 'Vendor';
$rail[] = $R::card('Vendor',
    $R::entity((string) $payment['vendor_name'], $canVendor ? base_url('vendors/show') . '?id=' . (int) $payment['vendor_id'] : '', $vendorSub, \FleetForge\Ui\ModuleHero::initials((string) $payment['vendor_name'])),
    ['icon' => 'building-storefront']);

// Allocation meter.
$rail[] = $R::card('Allocation',
    $R::meter('Applied to bills', e((string) round($allocPct)) . '%', $allocPct, $allocPct >= 99.99 ? 'ok' : 'warn',
        e(format_currency($allocated)) . ' of ' . e(format_currency($payment['amount'])) . ' ' . e($ccy))
    . $R::kv([
        ['Unallocated', e(format_currency($unallocated)), 'mono'],
        ['Bills', (string) count($allocations)],
    ]),
    ['icon' => 'banknotes', 'class' => 'rec-card--accent']);

// Related.
$rel = [];
if ($payment['je_id']) {
    $rel[] = ['Journal entry ' . $payment['je_number'], base_url('accounting/journal-entries/show?id=' . (int) $payment['je_id']), 'book-open', (string) $payment['je_status']];
}
$rel[] = ['Bank account', base_url('accounting/bank-accounts'), 'building-library', (string) $payment['bank_account_name']];
$rel[] = ['Payments to this vendor', base_url('accounting/ap-payments') . '?vendor_id=' . (int) $payment['vendor_id'], 'document-duplicate'];
$rail[] = $R::card('Related', $R::links($rel), ['icon' => 'document-text']);

// Audit trail. S-UTC-STAMPS: created_at / voided_at are UTC — show company time.
$rail[] = $R::card('Audit trail', $R::kv([
    ['Created', e($payment['created_by_name'] ?? ($fromQbo ? 'QuickBooks' : 'system')) . '<br><span class="text-secondary">' . e(format_datetime($payment['created_at'])) . '</span>'],
    ['Voided', $isVoid ? e($payment['voided_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($payment['voided_at'])) . '</span>' : null],
    ['QuickBooks', $qboConnected ? '<a href="#qbo-sync-panel">' . e($qboMap !== null ? str_replace('_', ' ', (string) $qboMap['push_status']) : 'not synced') . '</a>' : null],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="AP payment at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
