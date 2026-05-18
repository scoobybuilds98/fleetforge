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
 * Edit / void / pay actions stay in index.php — this page is view-only.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php
 * @session S-ACCT-FIX-DOCS
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

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'paid'           => 'badge-green',
        'partially_paid' => 'badge-amber',
        'approved'       => 'badge-blue',
        'scheduled'      => 'badge-blue',
        'draft'          => 'badge-neutral',
        'void'           => 'badge-red',
        'posted'         => 'badge-green',
        'reversed'       => 'badge-red',
        'cleared'        => 'badge-green',
        'pending'        => 'badge-amber',
        default          => 'badge-neutral',
    };
};

$pageTitle = 'Bill ' . $bill['bill_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/bills') ?>">Bills</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($bill['bill_number']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        Bill <?= e($bill['bill_number']) ?>
        <span class="badge <?= e($statusBadgeClass($bill['status'])) ?>" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;"><?= e(str_replace('_', ' ', $bill['status'])) ?></span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/bills') ?>">← Back to list</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Vendor</div>
            <div style="font-weight:500;"><?= e($bill['vendor_name']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Bill Date</div>
            <div class="font-mono"><?= e($bill['bill_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Due Date</div>
            <div class="font-mono"><?= e($bill['due_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Total</div>
            <div class="font-mono" style="font-size:1.05rem;font-weight:700;"><?= e('$' . number_format((float) $bill['total_amount'], 2)) ?>
                <?php if (($bill['currency'] ?? 'CAD') !== 'CAD'): ?>
                    <span style="font-size:0.75rem;color:var(--text-secondary);"><?= e($bill['currency']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Paid</div>
            <div class="font-mono"><?= e('$' . number_format((float) $bill['amount_paid'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Balance Due</div>
            <div class="font-mono" style="font-weight:600;"><?= e('$' . number_format((float) $bill['balance_due'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Vendor Invoice #</div>
            <div class="font-mono"><?= $bill['vendor_bill_number'] ? e($bill['vendor_bill_number']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Period</div>
            <div><?= e($bill['period_name']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Created</div>
            <div><?= e($bill['created_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($bill['created_at']) ?></span></div>
        </div>
    </div>
    <?php if ($bill['notes']): ?>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Notes</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($bill['notes']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($bill['status'] === 'void' && $bill['void_reason']): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--color-danger);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Void Reason</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($bill['void_reason']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Line items ──────────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Line Items</div>
    <?php if (count($lines) === 0): ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">No line items.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Account</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Description</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Qty</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Unit</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr style="border-bottom:1px solid var(--border-default);">
                    <td class="font-mono" style="padding:8px 10px;font-size:0.78rem;"><?= e($l['account_code'] . ' — ' . $l['account_name']) ?></td>
                    <td style="padding:8px 10px;"><?= e($l['description'] ?? '') ?></td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;"><?= e(number_format((float) $l['quantity'], 2)) ?></td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;"><?= e('$' . number_format((float) $l['unit_cost'], 2)) ?></td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;font-weight:600;"><?= e('$' . number_format((float) $l['amount'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Payments ────────────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Payment History</div>
    <?php if (count($payments) === 0): ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">No payments recorded against this bill.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Payment #</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                    <th style="padding:9px 10px;text-align:left;font-weight:600;color:var(--text-secondary);">Method</th>
                    <th style="padding:9px 10px;text-align:right;font-weight:600;color:var(--text-secondary);">Applied</th>
                    <th style="padding:9px 10px;text-align:center;font-weight:600;color:var(--text-secondary);">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr style="border-bottom:1px solid var(--border-default);">
                    <td class="font-mono" style="padding:8px 10px;">
                        <a href="<?= base_url('accounting/ap-payments/show?id=' . (int) $p['ap_payment_id']) ?>" style="color:var(--color-accent);text-decoration:none;"><?= e($p['payment_number']) ?></a>
                    </td>
                    <td class="font-mono" style="padding:8px 10px;"><?= e($p['payment_date']) ?></td>
                    <td style="padding:8px 10px;text-transform:capitalize;"><?= e(str_replace('_', ' ', $p['payment_method'])) ?></td>
                    <td class="font-mono" style="padding:8px 10px;text-align:right;font-weight:600;"><?= e('$' . number_format((float) $p['amount_applied'], 2)) ?></td>
                    <td style="padding:8px 10px;text-align:center;"><span class="badge <?= e($statusBadgeClass($p['payment_status'])) ?>"><?= e($p['payment_status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Linked JE ───────────────────────────────────────────────────────── -->
<?php if ($bill['je_id']): ?>
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Linked Journal Entry</div>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Entry #</div>
            <div class="font-mono">
                <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $bill['je_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-weight:500;"><?= e($bill['je_number']) ?></a>
            </div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Status</div>
            <div><span class="badge <?= e($statusBadgeClass((string) $bill['je_status'])) ?>"><?= e((string) $bill['je_status']) ?></span></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'bill';
$entityId   = (int) $bill['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
