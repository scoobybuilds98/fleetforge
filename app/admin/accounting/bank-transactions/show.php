<?php declare(strict_types=1);

/**
 * app/admin/accounting/bank-transactions/show.php
 *
 * Bank transaction detail view — read-only drill-down for a single
 * acc_bank_transactions row with header summary + Documents section.
 *
 * Bank transactions are otherwise listed inline in
 * app/admin/accounting/bank-accounts/index.php; this is the dedicated
 * per-transaction surface needed for documents drill-down (e.g. attach
 * a deposit slip or wire confirmation) per FLEETFORGE_ACCOUNTING_SPEC.md
 * §13 + §20.3. The bank-accounts page links here from each row.
 *
 * Match / unmatch / categorize actions stay in the bank-accounts page —
 * this view is read-only.
 *
 * Layout (S-RECORD-REDESIGN):
 *   header  — ModuleHero entity: the bank description as the title +
 *             type / status badges; account · date · reference · source
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — amount (in/out) · match · cleared · reconciliation
 *   main    — Transaction details · Documents
 *   rail    — Needs attention (unmatched for N days, excluded, QuickBooks
 *             mirror) · Matched to (linked record) · Journal entry ·
 *             Audit trail
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php, lib/Ui/RecordUi.php
 * @session S-ACCT-FIX-DOCS, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('bank_accounts', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Bank Transaction Not Specified</h1>';
    exit;
}

$tx = db_row(
    "SELECT t.*, ba.name AS bank_account_name, ba.account_number_last4 AS bank_last4,
            je.entry_number AS je_number, je.status AS je_status, je.id AS je_id,
            u.name AS created_by_name, mu.name AS matched_by_name
       FROM acc_bank_transactions t
       JOIN acc_bank_accounts ba ON ba.id = t.bank_account_id
  LEFT JOIN acc_journal_entries je ON je.id = t.journal_entry_id
  LEFT JOIN users u ON u.id = t.created_by
  LEFT JOIN users mu ON mu.id = t.matched_by
      WHERE t.id = ?",
    [$id]
);

if (!$tx) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Bank Transaction Not Found</h1>';
    exit;
}

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'matched'   => 'badge-green',
        'unmatched' => 'badge-amber',
        'excluded'  => 'badge-neutral',
        'posted'    => 'badge-green',
        'reversed'  => 'badge-red',
        default     => 'badge-neutral',
    };
};
$typeBadgeClass = static function (string $type): string {
    return match ($type) {
        'deposit'      => 'badge-green',
        'withdrawal'   => 'badge-amber',
        'transfer'     => 'badge-blue',
        'bank_charge'  => 'badge-red',
        'interest'     => 'badge-green',
        'nsf'          => 'badge-red',
        default        => 'badge-neutral',
    };
};

// ── What it is matched to (S-RECORD-REDESIGN) ──────────────────────────────
// A link only for the record types that have a detail page, and only when the
// viewer may open it; the rest keep the plain "type #id" text.
$matchLink = null; // [label, url, sub, icon]
$mId = (int) ($tx['matched_id'] ?? 0);
if ($tx['status'] === 'matched' && $mId > 0) {
    switch ((string) $tx['matched_type']) {
        case 'payment':
            $r = can('payments', 'view') ? db_row("SELECT payment_number, amount FROM payments WHERE id = ? AND deleted_at IS NULL", [$mId]) : null;
            if ($r) $matchLink = ['Payment ' . $r['payment_number'], base_url('payments/show') . '?id=' . $mId, 'Customer payment · ' . format_currency($r['amount']), 'banknotes'];
            break;
        case 'ap_payment':
            $r = can('accounts_payable', 'view') ? db_row("SELECT payment_number, amount FROM acc_ap_payments WHERE id = ?", [$mId]) : null;
            if ($r) $matchLink = ['AP payment ' . $r['payment_number'], base_url('accounting/ap-payments/show') . '?id=' . $mId, 'Vendor payment · ' . format_currency($r['amount']), 'banknotes'];
            break;
        case 'journal_entry':
            $r = can('journal_entries', 'view') ? db_row("SELECT entry_number, entry_date FROM acc_journal_entries WHERE id = ?", [$mId]) : null;
            if ($r) $matchLink = ['Journal entry ' . $r['entry_number'], base_url('accounting/journal-entries/show') . '?id=' . $mId, format_date($r['entry_date']), 'book-open'];
            break;
    }
}

$amount     = (string) $tx['amount'];
$isOut      = bccomp($amount, '0', 2) < 0;
$today      = ff_today();
$ageDays    = max(0, (int) round((strtotime($today) - strtotime((string) $tx['transaction_date'])) / 86400));
$isReadOnly = (int) ($tx['is_readonly'] ?? 0) === 1;

$pageTitle = 'Bank Transaction #' . $tx['id'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
// The bank's own description is what staff recognise; the id goes to the
// eyebrow + watermark.
$heroTitle = e(mb_strimwidth((string) ($tx['description'] ?: 'Transaction #' . $tx['id']), 0, 70, '…'))
    . ' <span class="badge ' . e($typeBadgeClass((string) $tx['transaction_type'])) . '">' . e(str_replace('_', ' ', (string) $tx['transaction_type'])) . '</span>'
    . ' <span class="badge ' . e($statusBadgeClass((string) $tx['status'])) . '">' . e($tx['status']) . '</span>';
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('building-library') . e($tx['bank_account_name']) . (!empty($tx['bank_last4']) ? ' ··' . e($tx['bank_last4']) : ''),
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($tx['transaction_date'])),
    \FleetForge\Sop\SopIcons::svg('currency-dollar') . '<b>' . e(format_currency($amount)) . '</b>',
];
if (!empty($tx['reference'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('document-text') . 'Ref ' . e($tx['reference']);
}
$heroFacts[] = \FleetForge\Sop\SopIcons::svg('arrow-up-tray') . e(ucfirst(str_replace('_', ' ', (string) $tx['source'])));
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/bank-reconciliation') ?>">Bank reconciliation</a>
    <?php if ($tx['je_id']): ?>
    <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $tx['je_id']) ?>">Journal entry <?= e((string) $tx['je_number']) ?></a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <a class="btn btn-<?= $tx['status'] === 'unmatched' ? 'primary' : 'secondary' ?> btn-sm" href="<?= base_url('accounting/bank-accounts') ?>"><?= \FleetForge\Sop\SopIcons::svg('building-library') ?> <?= $tx['status'] === 'unmatched' ? 'Match in Banking' : 'Open Banking' ?></a>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => match ((string) $tx['status']) { 'unmatched' => 'warning', 'excluded' => 'info', default => 'success' },
    'icon'       => 'building-library',
    'mark'       => '#' . (int) $tx['id'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Bank Accounts', base_url('accounting/bank-accounts')], ['Transaction #' . (int) $tx['id'], null]],
    'eyebrow'    => 'Bank transaction #' . (int) $tx['id'],
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): the money, and the three states that
     decide whether it is done — matched, cleared, reconciled. -->
<div class="stat-grid stat-grid--4 ff-stats">
    <div class="stat-card <?= $isOut ? 'stat-card--red' : 'stat-card--green' ?>">
        <span class="stat-icon <?= $isOut ? 'stat-icon--red' : 'stat-icon--green' ?>"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label"><?= $isOut ? 'Money out' : 'Money in' ?></div>
        <div class="stat-value font-mono" style="color:<?= $isOut ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= e(format_currency(ltrim($amount, '-'))) ?></div>
    </div>
    <div class="stat-card <?= $tx['status'] === 'unmatched' ? 'stat-card--amber' : 'stat-card--blue' ?>">
        <span class="stat-icon <?= $tx['status'] === 'unmatched' ? 'stat-icon--amber' : 'stat-icon--blue' ?>"><svg><use href="#icon-<?= $tx['status'] === 'unmatched' ? 'exclamation-triangle' : 'check-circle' ?>"/></svg></span>
        <div class="stat-label">Match</div>
        <div class="stat-value"><?= e(ucfirst((string) $tx['status'])) ?></div>
        <div class="stat-delta"><?= $tx['status'] === 'unmatched' ? e($ageDays . 'd old') : ($tx['matched_type'] ? e(str_replace('_', ' ', (string) $tx['matched_type'])) : '') ?></div>
    </div>
    <div class="stat-card <?= (int) $tx['is_cleared'] === 1 ? 'stat-card--green' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= (int) $tx['is_cleared'] === 1 ? 'stat-icon--green' : 'stat-icon--slate' ?>"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Cleared</div>
        <div class="stat-value stat-value--date font-mono"><?= (int) $tx['is_cleared'] === 1 ? ($tx['cleared_date'] ? e(format_date($tx['cleared_date'])) : 'Yes') : 'Not yet' ?></div>
    </div>
    <div class="stat-card <?= !empty($tx['reconciliation_id']) ? 'stat-card--green' : 'stat-card--slate' ?>">
        <span class="stat-icon <?= !empty($tx['reconciliation_id']) ? 'stat-icon--green' : 'stat-icon--slate' ?>"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Reconciled</div>
        <div class="stat-value"><?= !empty($tx['reconciliation_id']) ? 'Yes' : 'No' ?></div>
        <div class="stat-delta"><?= !empty($tx['reconciliation_id']) ? 'rec #' . (int) $tx['reconciliation_id'] : '' ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

<div class="card">
    <div class="card-header"><h3 class="card-title">Transaction details</h3></div>
    <div class="card-body">
        <dl class="rec-dl">
            <dt>Bank account</dt>
            <dd><?= e($tx['bank_account_name']) ?><?= !empty($tx['bank_last4']) ? ' <span class="text-secondary">··' . e($tx['bank_last4']) . '</span>' : '' ?></dd>
            <dt>Transaction date</dt>
            <dd class="font-mono"><?= e(format_date($tx['transaction_date'])) ?></dd>
            <dt>Amount</dt>
            <dd class="font-mono" style="font-weight:700;color:<?= $isOut ? 'var(--color-danger)' : 'var(--color-success)' ?>;"><?= e(format_currency($amount)) ?></dd>
            <dt>Type</dt>
            <dd><?= e(ucfirst(str_replace('_', ' ', (string) $tx['transaction_type']))) ?></dd>
            <dt>Reference</dt>
            <dd class="font-mono"><?= $tx['reference'] ? e($tx['reference']) : '<span class="text-secondary">—</span>' ?></dd>
            <dt>Source</dt>
            <dd><?= e(ucfirst(str_replace('_', ' ', (string) $tx['source']))) ?><?= $isReadOnly ? ' <span class="badge badge-neutral">read-only</span>' : '' ?></dd>
            <dt>Cleared</dt>
            <dd><?php if ((int) $tx['is_cleared'] === 1): ?><span class="badge badge-green">Cleared</span><?php if ($tx['cleared_date']): ?> <span class="text-secondary font-mono"><?= e(format_date($tx['cleared_date'])) ?></span><?php endif; ?><?php else: ?><span class="text-secondary">—</span><?php endif; ?></dd>
            <?php if ($tx['matched_type']): ?>
            <dt>Matched against</dt>
            <dd><?php if ($matchLink): ?><a class="link" href="<?= e($matchLink[1]) ?>"><?= e($matchLink[0]) ?></a><?php else: ?><?= e(ucfirst(str_replace('_', ' ', (string) $tx['matched_type']))) ?> #<?= (int) $tx['matched_id'] ?><?php endif; ?></dd>
            <?php endif; ?>
            <?php if ($tx['matched_at']): ?>
            <dt>Matched</dt>
            <dd><?= e($tx['matched_by_name'] ?? 'system') ?> <span class="text-secondary">— <?= e(format_datetime($tx['matched_at'])) /* S-UTC-STAMPS: UTC → company tz */ ?></span></dd>
            <?php endif; ?>
            <?php if ($tx['je_id']): ?>
            <dt>Journal entry</dt>
            <dd><a class="link font-mono" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $tx['je_id']) ?>"><?= e((string) $tx['je_number']) ?></a> <span class="badge <?= e($statusBadgeClass((string) $tx['je_status'])) ?>"><?= e((string) $tx['je_status']) ?></span></dd>
            <?php endif; ?>
            <dt>Description</dt>
            <dd style="white-space:pre-wrap;"><?= e($tx['description']) ?></dd>
            <?php if ($tx['notes']): ?>
            <dt>Notes</dt>
            <dd style="white-space:pre-wrap;"><?= e($tx['notes']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'bank_transaction';
$entityId   = (int) $tx['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the transaction at a glance ──────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if ($tx['status'] === 'unmatched') {
    $alerts[] = [$ageDays > 30 ? 'danger' : 'warning', 'Unmatched for <b>' . $ageDays . ' day' . ($ageDays === 1 ? '' : 's') . '</b> — match it to a payment or entry on the <a href="' . e(base_url('accounting/bank-accounts')) . '">Banking</a> page.'];
}
if ($tx['status'] === 'excluded') {
    $alerts[] = ['info', 'Excluded — left out of matching and reconciliation.'];
}
if ($tx['status'] === 'matched' && (int) $tx['is_cleared'] !== 1) {
    $alerts[] = ['info', 'Matched but not cleared — it clears when the statement is reconciled.'];
}
if ($isReadOnly || $tx['source'] === 'qbo_cdc') {
    $alerts[] = ['info', 'Mirrored from QuickBooks — change it there.'];
}
if ($tx['je_id'] && $tx['je_status'] === 'reversed') {
    $alerts[] = ['warning', 'Its journal entry ' . e((string) $tx['je_number']) . ' is reversed.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — matched and cleared.'), ['icon' => 'exclamation-triangle']);

// Matched to.
if ($tx['matched_type']) {
    $body = $matchLink
        ? $R::entity($matchLink[0], $matchLink[1], e($matchLink[2]), '', $matchLink[3])
        : '<p class="rec-alerts-ok" style="margin:0;">' . e(ucfirst(str_replace('_', ' ', (string) $tx['matched_type']))) . ' #' . (int) $tx['matched_id'] . '</p>';
    $rail[] = $R::card('Matched to', $body, ['icon' => 'check-circle', 'class' => 'rec-card--accent']);
}

$rel = [['Bank accounts', base_url('accounting/bank-accounts'), 'building-library', (string) $tx['bank_account_name']]];
if ($tx['je_id']) {
    $rel[] = ['Journal entry ' . $tx['je_number'], base_url('accounting/journal-entries/show?id=' . (int) $tx['je_id']), 'book-open', (string) $tx['je_status']];
}
$rel[] = ['Bank reconciliation', base_url('accounting/bank-reconciliation'), 'scale'];
$rail[] = $R::card('Related', $R::links($rel), ['icon' => 'document-duplicate']);

// Audit. S-UTC-STAMPS: created_at / matched_at are UTC — show company time.
$rail[] = $R::card('Audit trail', $R::kv([
    ['Created', e($tx['created_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($tx['created_at'])) . '</span>'],
    ['Matched', $tx['matched_at'] ? e($tx['matched_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($tx['matched_at'])) . '</span>' : null],
    ['QuickBooks id', !empty($tx['qbo_bank_txn_id']) ? '<span class="font-mono">' . e($tx['qbo_bank_txn_id']) . '</span>' : null],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="Bank transaction at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
