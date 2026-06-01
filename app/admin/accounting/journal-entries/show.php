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
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php
 * @session S-ACCT-FIX-DOCS
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
            ro.entry_number AS reversal_of_entry_number,
            rb.entry_number AS reversed_by_entry_number
       FROM acc_journal_entries je
       JOIN acc_periods p ON p.id = je.period_id
  LEFT JOIN users u ON u.id = je.created_by
  LEFT JOIN users pu ON pu.id = je.posted_by
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

$lines = db_select(
    "SELECT l.*, a.code AS account_code, a.name AS account_name,
            v.name AS vendor_name, c.name AS customer_name
       FROM acc_journal_entry_lines l
       JOIN acc_accounts a ON a.id = l.account_id
  LEFT JOIN vendors v ON v.id = l.vendor_id
  LEFT JOIN customers c ON c.id = l.customer_id
      WHERE l.journal_entry_id = ?
      ORDER BY l.line_number, l.id",
    [$id]
);

$totalDebit = 0.0;
$totalCredit = 0.0;
foreach ($lines as $l) {
    $totalDebit += (float) $l['debit'];
    $totalCredit += (float) $l['credit'];
}

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'posted'   => 'badge-green',
        'draft'    => 'badge-neutral',
        'reversed' => 'badge-red',
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

$pageTitle = 'JE ' . $entry['entry_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/journal-entries') ?>">Journal Entries</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($entry['entry_number']) ?></span>
</nav>

<?php
// F8 (S-QBO-ENTITY-SHOW-RICH-PANEL-PAYDOWN): shared QuickBooks Sync rich panel.
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

<div class="page-header">
    <h1 class="page-header-title h4">
        <?= e($entry['entry_number']) ?>
        <span class="badge <?= e($statusBadgeClass($entry['status'])) ?>" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;"><?= e($entry['status']) ?></span>
        <span class="badge <?= e($typeBadgeClass($entry['entry_type'])) ?>" style="margin-left:4px;font-size:0.7rem;vertical-align:middle;"><?= e($entry['entry_type']) ?></span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/journal-entries') ?>">← Back to list</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Entry Date</div>
            <div class="font-mono"><?= e($entry['entry_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Period</div>
            <div><?= e($entry['period_name']) ?> <span class="badge <?= e($statusBadgeClass($entry['period_status'])) ?>" style="font-size:0.65rem;margin-left:4px;"><?= e($entry['period_status']) ?></span></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Currency</div>
            <div><?= e($entry['currency']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Source</div>
            <div><?= $entry['source_type'] ? e($entry['source_type']) : '<span style="color:var(--text-secondary);">—</span>' ?><?php if ($entry['source_id']): ?> <span style="color:var(--text-secondary);">#<?= (int) $entry['source_id'] ?></span><?php endif; ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Reference</div>
            <div class="font-mono"><?= $entry['reference'] ? e($entry['reference']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Created</div>
            <div><?= e($entry['created_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($entry['created_at']) ?></span></div>
        </div>
        <?php if ($entry['posted_at']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Posted</div>
            <div><?= e($entry['posted_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($entry['posted_at']) ?></span></div>
        </div>
        <?php endif; ?>
        <?php if ($entry['reversal_of_id'] && $entry['reversal_of_entry_number']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Reverses</div>
            <div><a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversal_of_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-family:var(--font-mono);"><?= e($entry['reversal_of_entry_number']) ?></a></div>
        </div>
        <?php endif; ?>
        <?php if ($entry['reversed_by_id'] && $entry['reversed_by_entry_number']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Reversed By</div>
            <div><a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $entry['reversed_by_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-family:var(--font-mono);"><?= e($entry['reversed_by_entry_number']) ?></a></div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($entry['description']): ?>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Description</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($entry['description']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Lines ───────────────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Journal Lines</div>
    <?php if (count($lines) === 0): ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">No lines.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:9px 10px;text-align:center;font-weight:600;color:var(--text-secondary);">#</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Account</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Description</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Party</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Debit</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Credit</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr style="border-bottom:1px solid var(--border-default);">
                    <td class="font-mono" style="padding:8px 10px;text-align:center;"><?= (int) $l['line_number'] ?></td>
                    <td class="font-mono" style="padding:8px 10px;font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                    <td style="padding:8px 10px;"><?= e($l['description'] ?? '') ?></td>
                    <td style="padding:8px 10px;color:var(--text-secondary);font-size:0.78rem;">
                        <?php if ($l['vendor_name']): ?>V: <?= e($l['vendor_name']) ?><?php elseif ($l['customer_name']): ?>C: <?= e($l['customer_name']) ?><?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;<?= ((float) $l['debit'] > 0) ? 'font-weight:600;' : 'color:var(--text-secondary);' ?>"><?= (float) $l['debit'] > 0 ? e('$' . number_format((float) $l['debit'], 2)) : '—' ?></td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;<?= ((float) $l['credit'] > 0) ? 'font-weight:600;' : 'color:var(--text-secondary);' ?>"><?= (float) $l['credit'] > 0 ? e('$' . number_format((float) $l['credit'], 2)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid var(--border-default);">
                    <td colspan="4" style="padding:9px 10px;text-align:right;font-weight:600;">Totals</td>
                    <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;"><?= e('$' . number_format($totalDebit, 2)) ?></td>
                    <td class="font-mono" style="padding:9px 10px;text-align:right;font-weight:700;"><?= e('$' . number_format($totalCredit, 2)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'journal_entry';
$entityId   = (int) $entry['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
