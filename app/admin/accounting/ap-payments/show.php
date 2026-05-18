<?php declare(strict_types=1);

/**
 * app/admin/accounting/ap-payments/show.php
 *
 * AP Payment detail view — read-only drill-down for a single
 * acc_ap_payments row. Displays payment header, allocations to bills
 * (linked to their detail pages), the linked journal entry, and a
 * placeholder for documents (acc_documents wiring is a separate
 * session, S-ACCT-FIX-DOCS).
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §15 (subledger drill-down)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §20.2 (Phase 3 deliverable)
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php
 * @session S-ACCT-FIX-AP
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
            ba.name AS bank_account_name,
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

// ── Allocations ──────────────────────────────────────────────────────────────
$allocations = db_select(
    "SELECT apa.id, apa.bill_id, apa.amount_applied, apa.created_at,
            b.bill_number, b.bill_date, b.total_amount, b.balance_due, b.status AS bill_status
       FROM acc_ap_payment_allocations apa
       JOIN acc_bills b ON b.id = apa.bill_id
      WHERE apa.ap_payment_id = ?
      ORDER BY apa.id ASC",
    [$id]
);

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

$pageTitle = 'AP Payment ' . $payment['payment_number'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/ap-payments') ?>">AP Payments</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current"><?= e($payment['payment_number']) ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        Payment <?= e($payment['payment_number']) ?>
        <span class="badge <?= e($statusBadgeClass($payment['status'])) ?>" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;"><?= e($payment['status']) ?></span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/ap-payments') ?>">← Back to list</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── Header / detail card ─────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Vendor</div>
            <div style="font-weight:500;"><?= e($payment['vendor_name']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Payment Date</div>
            <div class="font-mono"><?= e($payment['payment_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Amount</div>
            <div class="font-mono" style="font-size:1.05rem;font-weight:700;">
                <?= e('$' . number_format((float) $payment['amount'], 2)) ?>
                <?php if (($payment['currency'] ?? 'CAD') !== 'CAD'): ?>
                    <span style="font-size:0.75rem;color:var(--text-secondary);"><?= e((string) $payment['currency']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Bank Account</div>
            <div><?= e($payment['bank_account_name']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Method</div>
            <div style="text-transform:capitalize;"><?= e(str_replace('_', ' ', $payment['payment_method'])) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Reference #</div>
            <div class="font-mono"><?= $payment['reference_number'] ? e($payment['reference_number']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <?php if ($payment['payment_method'] === 'check'): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Check #</div>
            <div class="font-mono"><?= $payment['check_number'] ? e($payment['check_number']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <?php endif; ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Created</div>
            <div>
                <?= e($payment['created_by_name'] ?? 'system') ?>
                <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($payment['created_at']) ?></span>
            </div>
        </div>
        <?php if ($payment['status'] === 'void'): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Voided</div>
            <div>
                <?= e($payment['voided_by_name'] ?? 'system') ?>
                <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e((string) $payment['voided_at']) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($payment['notes']): ?>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Notes</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($payment['notes']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($payment['status'] === 'void' && $payment['void_reason']): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--color-danger);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Void Reason</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($payment['void_reason']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Allocations card ─────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Bill Allocations</div>
    <?php if (count($allocations) === 0): ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">No allocations recorded for this payment.</div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-default);">
                        <th style="padding:9px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Bill #</th>
                        <th style="padding:9px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Bill Date</th>
                        <th style="padding:9px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Bill Total</th>
                        <th style="padding:9px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Applied</th>
                        <th style="padding:9px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Bill Balance</th>
                        <th style="padding:9px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Bill Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allocations as $a): ?>
                        <tr style="border-bottom:1px solid var(--border-default);">
                            <td class="font-mono" style="padding:8px 12px;">
                                <a href="<?= base_url('accounting/bills/show?id=' . (int) $a['bill_id']) ?>" style="color:var(--color-accent);text-decoration:none;">
                                    <?= e($a['bill_number']) ?>
                                </a>
                            </td>
                            <td class="font-mono" style="padding:8px 12px;"><?= e($a['bill_date']) ?></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= e('$' . number_format((float) $a['total_amount'], 2)) ?></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:600;"><?= e('$' . number_format((float) $a['amount_applied'], 2)) ?></td>
                            <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= e('$' . number_format((float) $a['balance_due'], 2)) ?></td>
                            <td style="padding:8px 12px;text-align:center;">
                                <span class="badge <?= e($statusBadgeClass($a['bill_status'])) ?>"><?= e(str_replace('_', ' ', $a['bill_status'])) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ── Linked JE card ──────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:12px;">Linked Journal Entry</div>
    <?php if ($payment['je_id']): ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Entry #</div>
                <div class="font-mono">
                    <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $payment['je_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-weight:500;">
                        <?= e($payment['je_number']) ?>
                    </a>
                </div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Entry Date</div>
                <div class="font-mono"><?= e((string) $payment['je_entry_date']) ?></div>
            </div>
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Status</div>
                <div><span class="badge <?= e($statusBadgeClass((string) $payment['je_status'])) ?>"><?= e((string) $payment['je_status']) ?></span></div>
            </div>
        </div>
    <?php else: ?>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">No journal entry linked to this payment.</div>
    <?php endif; ?>
</div>

<!-- ── Documents stub ──────────────────────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="font-weight:600;font-size:0.95rem;margin-bottom:8px;">Documents</div>
    <div style="font-size:0.8125rem;color:var(--text-secondary);">Documents (coming soon)</div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
