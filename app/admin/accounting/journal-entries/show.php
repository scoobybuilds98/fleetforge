<?php declare(strict_types=1);

/**
 * app/admin/accounting/journal-entries/show.php
 *
 * Journal Entry detail view — read-only drill-down for a single
 * acc_journal_entries row with all lines, account info, and a
 * Documents section. The existing index.php carries an Alpine modal
 * for quick view; this page provides a stable URL for deep-linking
 * (required for AP-payments / bills / fixed-assets / bank-tx pages
 * that link to their backing JE via JE#) and for the Documents
 * attachment surface per FLEETFORGE_ACCOUNTING_SPEC.md §13 + §20.3.
 *
 * Reverse / edit actions stay in index.php — this page is view-only.
 *
 * Layout (S-RECORD-REDESIGN):
 *   header  — ModuleHero entity (status/type badges, date / period /
 *             reference chips; "Open source" when the entry came from a
 *             document we can link to)
 *   nav     — the accounting sub-nav, directly under the header
 *   strip   — debits · credits · balanced? · lines · period
 *   main    — Entry details · Journal lines (parties linked) ·
 *             QuickBooks sync panel · Documents
 *   rail    — Needs attention (unbalanced / draft / awaiting approval /
 *             closed period / reversed / QBO failure) · Source & links ·
 *             Audit trail
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php,
 *          includes/partials/qbo-sync-panel.php, lib/Ui/RecordUi.php
 * @session S-ACCT-FIX-DOCS, S-RECORD-REDESIGN
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Journal Entry Not Specified</h1>';
    exit;
}

$entry = db_row(
    "SELECT je.*, p.name AS period_name, p.status AS period_status,
            u.name AS created_by_name, pu.name AS posted_by_name,
            su.name AS submitted_by_name, au.name AS approved_by_name,
            ro.entry_number AS reversal_of_entry_number,
            rb.entry_number AS reversed_by_entry_number
       FROM acc_journal_entries je
       JOIN acc_periods p ON p.id = je.period_id
  LEFT JOIN users u ON u.id = je.created_by
  LEFT JOIN users pu ON pu.id = je.posted_by
  LEFT JOIN users su ON su.id = je.submitted_by_id
  LEFT JOIN users au ON au.id = je.approved_by_id
  LEFT JOIN acc_journal_entries ro ON ro.id = je.reversal_of_id
  LEFT JOIN acc_journal_entries rb ON rb.id = je.reversed_by_id
      WHERE je.id = ?",
    [$id]
);

if (!$entry) {
    http_response_code(404);
    $errorFile = FF_ROOT . '/app/errors/404.php';
    if (file_exists($errorFile)) require $errorFile;
    else echo '<h1>404 — Journal Entry Not Found</h1>';
    exit;
}

// WHY c.company_name: customers has no `name` column — `c.name` threw SQLSTATE 42S22
// and 500'd this page for EVERY journal entry (found verifying S-UTC-STAMPS).
$lines = db_select(
    "SELECT l.*, a.code AS account_code, a.name AS account_name,
            v.name AS vendor_name, c.company_name AS customer_name,
            eu.unit_number AS equipment_unit_number
       FROM acc_journal_entry_lines l
       JOIN acc_accounts a ON a.id = l.account_id
  LEFT JOIN vendors v ON v.id = l.vendor_id
  LEFT JOIN customers c ON c.id = l.customer_id
  LEFT JOIN equipment_units eu ON eu.id = l.equipment_unit_id
      WHERE l.journal_entry_id = ?
      ORDER BY l.line_number, l.id",
    [$id]
);

// Totals in bcmath (money) — the old float sums could show a phantom
// one-cent imbalance on long entries.
$totalDebit  = '0.00';
$totalCredit = '0.00';
$accountIds  = [];
foreach ($lines as $l) {
    $totalDebit  = bcadd($totalDebit, (string) $l['debit'], 2);
    $totalCredit = bcadd($totalCredit, (string) $l['credit'], 2);
    $accountIds[(int) $l['account_id']] = true;
}
$imbalance  = bcsub($totalDebit, $totalCredit, 2);
$isBalanced = bccomp($imbalance, '0', 2) === 0;

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'posted'   => 'badge-green',
        'draft'    => 'badge-neutral',
        'reversed' => 'badge-red',
        'open'     => 'badge-green',
        'closed', 'locked' => 'badge-amber',
        default    => 'badge-neutral',
    };
};
$typeBadgeClass = static function (string $type): string {
    return match ($type) {
        'manual'     => 'badge-blue',
        'system'     => 'badge-neutral',
        'recurring'  => 'badge-amber',
        'reversing'  => 'badge-red',
        'year_end'   => 'badge-amber',
        'adjustment' => 'badge-amber',
        default      => 'badge-neutral',
    };
};

// ── Source document (S-RECORD-REDESIGN) ────────────────────────────────────
// Resolve the record this entry was generated from into a link, so staff can
// jump from the ledger to the business document. Only source types whose
// source_id meaning is verified in the posting code are linked (the rest keep
// the plain "type #id" text); links respect the target page's permission.
// AP payments and bank transactions post without a source_id, so they are
// found by the reverse FK (journal_entry_id) instead.
// A link is only built when the target row still exists (and is not
// soft-deleted) — demo resets hard-delete documents whose JEs remain, and a
// link to a 404 is worse than the plain text.
$sourceLink = null; // [label, url, sub, icon]
$srcType = (string) ($entry['source_type'] ?? '');
$srcId   = (int) ($entry['source_id'] ?? 0);
if ($srcId > 0) {
    switch ($srcType) {
        case 'invoice':
            $r = can('invoices', 'view') ? db_row("SELECT invoice_number FROM invoices WHERE id = ? AND deleted_at IS NULL", [$srcId]) : null;
            if ($r) {
                $sourceLink = ['Invoice ' . $r['invoice_number'], base_url('invoices/show') . '?id=' . $srcId, 'Posted from the invoice', 'document-text'];
            }
            break;
        case 'payment':
            $r = can('payments', 'view') ? db_row("SELECT payment_number FROM payments WHERE id = ? AND deleted_at IS NULL", [$srcId]) : null;
            if ($r) {
                $sourceLink = ['Payment ' . $r['payment_number'], base_url('payments/show') . '?id=' . $srcId, 'Customer payment', 'banknotes'];
            }
            break;
        case 'credit_note':
            $r = can('invoices', 'view') ? db_row("SELECT credit_note_number FROM credit_notes WHERE id = ? AND deleted_at IS NULL", [$srcId]) : null;
            if ($r) {
                $sourceLink = ['Credit note ' . $r['credit_note_number'], base_url('credit_notes/show') . '?id=' . $srcId, 'Customer credit', 'receipt-percent'];
            }
            break;
        case 'ap_bill':
            $r = can('accounts_payable', 'view') ? db_row("SELECT bill_number FROM acc_bills WHERE id = ?", [$srcId]) : null;
            if ($r) {
                $sourceLink = ['Bill ' . $r['bill_number'], base_url('accounting/bills/show') . '?id=' . $srcId, 'Vendor bill', 'document-text'];
            }
            break;
        case 'recurring':
            $r = db_row("SELECT name FROM acc_recurring_entries WHERE id = ?", [$srcId]);
            if ($r) {
                $sourceLink = [(string) $r['name'], base_url('accounting/recurring-entries/show') . '?id=' . $srcId, 'Recurring template', 'arrow-path'];
            }
            break;
        case 'lease_inception':
        case 'lease_period':
        case 'lease_termination':
            $r = db_row("SELECT contract_number FROM leases WHERE id = ? AND deleted_at IS NULL", [$srcId]);
            if ($r) {
                $sourceLink = ['Lease ' . $r['contract_number'], base_url('accounting/leases/show') . '?id=' . $srcId, 'Capital lease schedule', 'calendar-days'];
            }
            break;
        case 'tax_remittance':
            if (can('tax_management', 'view')) {
                $r = db_row("SELECT filing_period_id FROM acc_tax_remittances WHERE id = ?", [$srcId]);
                if (!empty($r['filing_period_id'])) {
                    $sourceLink = ['Tax period #' . (int) $r['filing_period_id'], base_url('accounting/tax/show') . '?id=' . (int) $r['filing_period_id'], 'Tax remittance', 'calculator'];
                }
            }
            break;
    }
}
if ($sourceLink === null && $srcType === 'ap_payment' && can('accounts_payable', 'view')) {
    $r = db_row("SELECT id, payment_number FROM acc_ap_payments WHERE journal_entry_id = ? LIMIT 1", [$id]);
    if ($r) {
        $sourceLink = ['AP payment ' . $r['payment_number'], base_url('accounting/ap-payments/show') . '?id=' . (int) $r['id'], 'Vendor payment', 'banknotes'];
    }
}
if ($sourceLink === null && $srcType === 'bank_transaction' && can('bank_accounts', 'view')) {
    $r = db_row("SELECT id FROM acc_bank_transactions WHERE journal_entry_id = ? LIMIT 1", [$id]);
    if ($r) {
        $sourceLink = ['Bank transaction #' . (int) $r['id'], base_url('accounting/bank-transactions/show') . '?id=' . (int) $r['id'], 'Bank feed / manual entry', 'building-library'];
    }
}

// QuickBooks state for the rail (the panel itself renders further down).
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';
$qboMap = $qboConnected
    ? db_row("SELECT push_status, push_error FROM acc_qbo_journal_entry_map WHERE ff_journal_entry_id = ? LIMIT 1", [$id])
    : null;

$pageTitle = 'JE ' . $entry['entry_number'];
require_once FF_ROOT . '/includes/header.php';

// ── Header (S-RECORD-REDESIGN) ──────────────────────────────────────────────
$heroAccent = match ((string) $entry['status']) {
    'draft'    => 'warning',
    'reversed' => 'danger',
    default    => 'success',
};
$heroTitle = e($entry['entry_number'])
    . ' <span class="badge ' . e($statusBadgeClass((string) $entry['status'])) . '">' . e($entry['status']) . '</span>'
    . ' <span class="badge ' . e($typeBadgeClass((string) $entry['entry_type'])) . '">' . e(str_replace('_', ' ', (string) $entry['entry_type'])) . '</span>';
// entry_status carries the approval workflow (submitted/approved) when it has
// not reached the terminal posted/reversed state shown by `status`.
if (!empty($entry['entry_status']) && !in_array($entry['entry_status'], ['posted', 'reversed', $entry['status']], true)) {
    $heroTitle .= ' <span class="badge badge-blue">' . e($entry['entry_status']) . '</span>';
}
if ((int) ($entry['is_reversal'] ?? 0) === 1) {
    $heroTitle .= ' <span class="badge badge-red">reversal</span>';
}
$heroFacts = [
    \FleetForge\Sop\SopIcons::svg('calendar-days') . e(format_date($entry['entry_date'])),
    \FleetForge\Sop\SopIcons::svg('book-open') . e($entry['period_name']) . ' · ' . e($entry['period_status']),
];
if (!empty($entry['reference'])) {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('document-text') . 'Ref <b>' . e($entry['reference']) . '</b>';
}
if (($entry['currency'] ?? 'CAD') !== 'CAD') {
    $heroFacts[] = \FleetForge\Sop\SopIcons::svg('currency-dollar') . e($entry['currency']) . (!empty($entry['exchange_rate']) ? ' @ ' . e((string) $entry['exchange_rate']) : '');
}
?>
<?php ob_start(); /* secondary actions → the header's More menu */ ?>
    <a href="<?= base_url('accounting/journal-entries') ?>">All journal entries</a>
    <?php if ($entry['reversal_of_id'] && $entry['reversal_of_entry_number']): ?>
    <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversal_of_id']) ?>">Original entry <?= e($entry['reversal_of_entry_number']) ?></a>
    <?php endif; ?>
    <?php if ($entry['reversed_by_id'] && $entry['reversed_by_entry_number']): ?>
    <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversed_by_id']) ?>">Reversing entry <?= e($entry['reversed_by_entry_number']) ?></a>
    <?php endif; ?>
    <?php if ($qboConnected): ?>
    <a href="#qbo-sync-panel">QuickBooks sync</a>
    <?php endif; ?>
<?php $heroMore = ob_get_clean(); ?>
<?php ob_start(); ?>
    <?php if ($sourceLink !== null): ?>
    <a class="btn btn-secondary btn-sm" href="<?= e($sourceLink[1]) ?>"><?= \FleetForge\Sop\SopIcons::svg($sourceLink[3]) ?> Open <?= e($sourceLink[0]) ?></a>
    <?php endif; ?>
    <?= \FleetForge\Ui\RecordUi::more($heroMore) ?>
<?php $heroActions = ob_get_clean(); ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'entity'     => true,
    'accent'     => $heroAccent,
    'icon'       => 'book-open',
    'mark'       => (string) $entry['entry_number'],
    'crumbs'     => [['Dashboard', base_url('dashboard')], ['Accounting', base_url('accounting/dashboard')], ['Journal Entries', base_url('accounting/journal-entries')], [(string) $entry['entry_number'], null]],
    'eyebrow'    => 'Journal entry',
    'title_html' => $heroTitle,
    'facts'      => $heroFacts,
    'actions'    => $heroActions,
]) ?>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- KEY NUMBERS (S-RECORD-REDESIGN): both sides of the entry, whether they
     tie, how many lines/accounts, and the period it lands in. -->
<div class="stat-grid stat-grid--5 ff-stats">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Debits</div>
        <div class="stat-value font-mono"><?= e(format_currency($totalDebit)) ?></div>
    </div>
    <div class="stat-card stat-card--purple">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Credits</div>
        <div class="stat-value font-mono"><?= e(format_currency($totalCredit)) ?></div>
    </div>
    <div class="stat-card <?= $isBalanced ? 'stat-card--green' : 'stat-card--red' ?>">
        <span class="stat-icon <?= $isBalanced ? 'stat-icon--green' : 'stat-icon--red' ?>"><svg><use href="#icon-<?= $isBalanced ? 'check-circle' : 'exclamation-triangle' ?>"/></svg></span>
        <div class="stat-label">Balance</div>
        <div class="stat-value"<?= $isBalanced ? '' : ' style="color:var(--color-danger);"' ?>><?= $isBalanced ? 'Balanced ✓' : 'Off by ' . e(format_currency(ltrim($imbalance, '-'))) ?></div>
    </div>
    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Lines</div>
        <div class="stat-value font-mono"><?= count($lines) ?></div>
        <div class="stat-delta"><?= count($accountIds) ?> account<?= count($accountIds) === 1 ? '' : 's' ?></div>
    </div>
    <div class="stat-card <?= $entry['period_status'] === 'open' ? 'stat-card--teal' : 'stat-card--amber' ?>">
        <span class="stat-icon <?= $entry['period_status'] === 'open' ? 'stat-icon--teal' : 'stat-icon--amber' ?>"><svg><use href="#icon-<?= $entry['period_status'] === 'open' ? 'clock' : 'lock-open' ?>"/></svg></span>
        <div class="stat-label">Period</div>
        <div class="stat-value stat-value--date"><?= e($entry['period_name']) ?></div>
        <div class="stat-delta"><?= e($entry['period_status']) ?></div>
    </div>
</div>

<div class="rec-layout">
<div class="rec-main">

    <!-- ── Entry details ───────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Entry details</h3></div>
        <div class="card-body">
            <dl class="rec-dl">
                <dt>Entry date</dt>
                <dd class="font-mono"><?= e(format_date($entry['entry_date'])) ?></dd>
                <dt>Period</dt>
                <dd><?= e($entry['period_name']) ?> <span class="badge <?= e($statusBadgeClass((string) $entry['period_status'])) ?>"><?= e($entry['period_status']) ?></span></dd>
                <dt>Type</dt>
                <dd><?= e(str_replace('_', ' ', (string) $entry['entry_type'])) ?></dd>
                <dt>Source</dt>
                <dd>
                    <?php if ($sourceLink !== null): ?>
                        <a class="link" href="<?= e($sourceLink[1]) ?>"><?= e($sourceLink[0]) ?></a>
                        <span class="text-secondary">· <?= e(str_replace('_', ' ', $srcType)) ?></span>
                    <?php elseif ($srcType !== ''): ?>
                        <?= e(str_replace('_', ' ', $srcType)) ?><?php if ($srcId): ?> <span class="text-secondary">#<?= $srcId ?></span><?php endif; ?>
                    <?php else: ?>
                        <span class="text-secondary">Manual entry</span>
                    <?php endif; ?>
                </dd>
                <dt>Reference</dt>
                <dd class="font-mono"><?= $entry['reference'] ? e($entry['reference']) : '<span class="text-secondary">—</span>' ?></dd>
                <dt>Currency</dt>
                <dd><?= e($entry['currency']) ?><?php if (($entry['currency'] ?? 'CAD') !== 'CAD' && !empty($entry['exchange_rate'])): ?> <span class="text-secondary">@ <?= e((string) $entry['exchange_rate']) ?> to CAD</span><?php endif; ?></dd>
                <?php if ($entry['reversal_of_id'] && $entry['reversal_of_entry_number']): ?>
                <dt>Reverses</dt>
                <dd><a class="link font-mono" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversal_of_id']) ?>"><?= e($entry['reversal_of_entry_number']) ?></a></dd>
                <?php endif; ?>
                <?php if ($entry['reversed_by_id'] && $entry['reversed_by_entry_number']): ?>
                <dt>Reversed by</dt>
                <dd><a class="link font-mono" href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversed_by_id']) ?>"><?= e($entry['reversed_by_entry_number']) ?></a><?php if (!empty($entry['reversal_date'])): ?> <span class="text-secondary">· <?= e(format_date($entry['reversal_date'])) ?></span><?php endif; ?></dd>
                <?php endif; ?>
                <?php if ((int) ($entry['auto_reverse'] ?? 0) === 1): ?>
                <dt>Auto-reverse</dt>
                <dd><?= !empty($entry['auto_reverse_date']) ? e(format_date($entry['auto_reverse_date'])) : 'Yes' ?></dd>
                <?php endif; ?>
                <dt>Description</dt>
                <dd style="white-space:pre-wrap;"><?= $entry['description'] ? e($entry['description']) : '<span class="text-secondary">—</span>' ?></dd>
            </dl>
        </div>
    </div>

    <!-- ── Lines ───────────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h3 class="card-title">Journal lines</h3></div>
        <div class="card-body">
        <?php if (count($lines) === 0): ?>
            <p class="text-secondary" style="margin:0;font-size:0.8125rem;">No lines.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th style="text-align:center;">#</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Party</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $l): ?>
                    <?php $dr = bccomp((string) $l['debit'], '0', 2) > 0; $cr = bccomp((string) $l['credit'], '0', 2) > 0; ?>
                    <tr>
                        <td class="font-mono" style="text-align:center;"><?= (int) $l['line_number'] ?></td>
                        <td class="font-mono" style="font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                        <td><?= e($l['description'] ?? '') ?></td>
                        <td class="text-secondary" style="font-size:0.78rem;">
                            <?php // Parties link to their records where the viewer may open them. ?>
                            <?php if ($l['vendor_name']): ?>
                                V: <?php if (can('vendors', 'view')): ?><a class="link" href="<?= base_url('vendors/show') ?>?id=<?= (int) $l['vendor_id'] ?>"><?= e($l['vendor_name']) ?></a><?php else: ?><?= e($l['vendor_name']) ?><?php endif; ?>
                            <?php elseif ($l['customer_name']): ?>
                                C: <?php if (can('customers', 'view')): ?><a class="link" href="<?= base_url('customers/show') ?>?id=<?= (int) $l['customer_id'] ?>"><?= e($l['customer_name']) ?></a><?php else: ?><?= e($l['customer_name']) ?><?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                            <?php if (!empty($l['equipment_unit_number'])): ?>
                                <div>Unit <?php if (can('equipment', 'view')): ?><a class="link" href="<?= base_url('equipment/show') ?>?id=<?= (int) $l['equipment_unit_id'] ?>"><?= e($l['equipment_unit_number']) ?></a><?php else: ?><?= e($l['equipment_unit_number']) ?><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="font-mono text-right"<?= $dr ? ' style="font-weight:600;"' : ' style="color:var(--text-secondary);"' ?>><?= $dr ? e(format_currency($l['debit'])) : '—' ?></td>
                        <td class="font-mono text-right"<?= $cr ? ' style="font-weight:600;"' : ' style="color:var(--text-secondary);"' ?>><?= $cr ? e(format_currency($l['credit'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right" style="font-weight:600;">Totals<?= $isBalanced ? '' : ' <span class="badge badge-red">unbalanced</span>' ?></td>
                        <td class="font-mono text-right" style="font-weight:700;"><?= e(format_currency($totalDebit)) ?></td>
                        <td class="font-mono text-right" style="font-weight:700;"><?= e(format_currency($totalCredit)) ?></td>
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
        'entity_type' => 'journal_entry',
        'map_table'   => 'acc_qbo_journal_entry_map',
        'qbo_id_col'  => 'qbo_journal_entry_id',
        'ff_fk'       => 'ff_journal_entry_id',
        'ff_id'       => (int) $entry['id'],
        'deep_link'   => 'journal',
        'retry_url'   => base_url('api/v1/quickbooks/journal_entries/retry'),
    ];
    require FF_ROOT . '/includes/partials/qbo-sync-panel.php';
    ?>

    <!-- ── Documents ───────────────────────────────────────────────────── -->
    <?php
    $entityType = 'journal_entry';
    $entityId   = (int) $entry['id'];
    require FF_ROOT . '/includes/partials/acc-documents-section.php';
    ?>

</div><!-- /rec-main -->
<?php
// ── RAIL (S-RECORD-REDESIGN) — the entry at a glance ────────────────────────
$R = \FleetForge\Ui\RecordUi::class;
$rail = [];

$alerts = [];
if (!$isBalanced) {
    $alerts[] = ['danger', 'Debits and credits differ by <b>' . e(format_currency(ltrim($imbalance, '-'))) . '</b> — this entry does not balance.'];
}
if ($entry['status'] === 'draft') {
    $alerts[] = ['warning', 'Draft — not in the ledger until it is posted' . (can('journal_entries', 'edit') ? ' from <a href="' . e(base_url('accounting/journal-entries')) . '">Journal Entries</a>' : '') . '.'];
}
if (($entry['entry_status'] ?? '') === 'submitted') {
    $alerts[] = ['info', 'Submitted' . (!empty($entry['submitted_by_name']) ? ' by ' . e($entry['submitted_by_name']) : '') . ' — waiting for approval.'];
}
if ($entry['status'] === 'reversed' && $entry['reversed_by_id']) {
    $alerts[] = ['info', 'Reversed by <a href="' . e(base_url('accounting/journal-entries/show?id=' . (int) $entry['reversed_by_id'])) . '">' . e((string) $entry['reversed_by_entry_number']) . '</a> — both stay on the books and net to zero.'];
}
if (in_array($entry['period_status'], ['closed', 'locked'], true) && $entry['status'] !== 'reversed') {
    $alerts[] = ['info', 'Period ' . e($entry['period_name']) . ' is ' . e($entry['period_status']) . ' — corrections go in as a new entry in an open period.'];
}
if ($qboMap !== null && str_starts_with((string) ($qboMap['push_status'] ?? ''), 'failed')) {
    $alerts[] = ['danger', '<a href="#qbo-sync-panel">QuickBooks push failed</a>' . (!empty($qboMap['push_error']) ? ' — ' . e(mb_strimwidth((string) $qboMap['push_error'], 0, 90, '…')) : '') . '.'];
}
$rail[] = $R::card('Needs attention', $R::alerts($alerts, 'All clear — balanced and posted.'), ['icon' => 'exclamation-triangle']);

// Source + related entries.
$srcBody = '';
if ($sourceLink !== null) {
    $srcBody .= $R::entity($sourceLink[0], $sourceLink[1], e($sourceLink[2]), '', $sourceLink[3]);
}
$srcLinks = [];
if ($entry['reversal_of_id'] && $entry['reversal_of_entry_number']) {
    $srcLinks[] = ['Original ' . $entry['reversal_of_entry_number'], base_url('accounting/journal-entries/show?id=' . (int) $entry['reversal_of_id']), 'arrow-path', 'reversed'];
}
if ($entry['reversed_by_id'] && $entry['reversed_by_entry_number']) {
    $srcLinks[] = ['Reversal ' . $entry['reversed_by_entry_number'], base_url('accounting/journal-entries/show?id=' . (int) $entry['reversed_by_id']), 'arrow-path', 'reverses this'];
}
if ($srcLinks) {
    $srcBody .= '<div style="margin-top:' . ($srcBody !== '' ? '12px' : '0') . ';">' . $R::links($srcLinks) . '</div>';
}
if ($srcBody === '') {
    $srcBody = '<p class="rec-alerts-ok" style="margin:0;">' . ($srcType !== '' ? e(ucfirst(str_replace('_', ' ', $srcType))) . ($srcId ? ' #' . $srcId : '') : 'Entered by hand') . '</p>';
}
$rail[] = $R::card('Source', $srcBody, ['icon' => 'document-text', 'class' => 'rec-card--accent']);

// Audit trail. S-UTC-STAMPS: created_at/posted_at are UTC DATETIMEs — render in the company timezone.
$rail[] = $R::card('Audit trail', $R::kv([
    ['Created', e($entry['created_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($entry['created_at'])) . '</span>'],
    ['Submitted', !empty($entry['submitted_at']) ? e($entry['submitted_by_name'] ?? '—') . '<br><span class="text-secondary">' . e(format_datetime($entry['submitted_at'])) . '</span>' : null],
    ['Approved', !empty($entry['approved_at']) ? e($entry['approved_by_name'] ?? '—') . '<br><span class="text-secondary">' . e(format_datetime($entry['approved_at'])) . '</span>' : null],
    ['Posted', $entry['posted_at'] ? e($entry['posted_by_name'] ?? 'system') . '<br><span class="text-secondary">' . e(format_datetime($entry['posted_at'])) . '</span>' : 'Not posted'],
    ['QuickBooks', $qboConnected ? '<a href="#qbo-sync-panel">' . e($qboMap !== null ? str_replace('_', ' ', (string) $qboMap['push_status']) : 'not synced') . '</a>' : null],
]), ['icon' => 'clock']);
?>
<aside class="rec-rail" aria-label="Journal entry at a glance">
    <?= implode("\n    ", $rail) ?>
</aside>
</div><!-- /rec-layout -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
