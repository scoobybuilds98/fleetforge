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
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, includes/partials/accounting-nav.php,
 *          includes/partials/acc-documents-section.php
 * @session S-ACCT-FIX-DOCS
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
    "SELECT t.*, ba.name AS bank_account_name,
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

$pageTitle = 'Bank Transaction #' . $tx['id'];
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/bank-accounts') ?>">Bank Accounts</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Transaction #<?= (int) $tx['id'] ?></span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">
        Transaction #<?= (int) $tx['id'] ?>
        <span class="badge <?= e($typeBadgeClass($tx['transaction_type'])) ?>" style="margin-left:8px;font-size:0.7rem;vertical-align:middle;"><?= e(str_replace('_', ' ', $tx['transaction_type'])) ?></span>
        <span class="badge <?= e($statusBadgeClass($tx['status'])) ?>" style="margin-left:4px;font-size:0.7rem;vertical-align:middle;"><?= e($tx['status']) ?></span>
    </h1>
    <div class="page-header-actions">
        <a class="btn btn-secondary btn-sm" href="<?= base_url('accounting/bank-accounts') ?>">← Back to bank accounts</a>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<div class="card" style="padding:18px;margin-bottom:14px;">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Bank Account</div>
            <div style="font-weight:500;"><?= e($tx['bank_account_name']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Transaction Date</div>
            <div class="font-mono"><?= e($tx['transaction_date']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Amount</div>
            <div class="font-mono" style="font-size:1.05rem;font-weight:700;<?= ((float) $tx['amount'] < 0) ? 'color:var(--color-danger);' : 'color:var(--color-success);' ?>"><?= e('$' . number_format((float) $tx['amount'], 2)) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Reference</div>
            <div class="font-mono"><?= $tx['reference'] ? e($tx['reference']) : '<span style="color:var(--text-secondary);">—</span>' ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Source</div>
            <div style="text-transform:capitalize;"><?= e($tx['source']) ?></div>
        </div>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Cleared</div>
            <div>
                <?php if ((int) $tx['is_cleared'] === 1): ?>
                    <span class="badge badge-green">Cleared</span>
                    <?php if ($tx['cleared_date']): ?>
                    <span style="color:var(--text-secondary);font-size:0.78rem;margin-left:4px;font-family:var(--font-mono);"><?= e($tx['cleared_date']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:var(--text-secondary);">—</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($tx['matched_type']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Matched Against</div>
            <div style="text-transform:capitalize;"><?= e(str_replace('_', ' ', $tx['matched_type'])) ?> #<?= (int) $tx['matched_id'] ?></div>
        </div>
        <?php endif; ?>
        <?php if ($tx['matched_at']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Matched</div>
            <div><?= e($tx['matched_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($tx['matched_at']) ?></span></div>
        </div>
        <?php endif; ?>
        <?php if ($tx['je_id']): ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Journal Entry</div>
            <div><a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $tx['je_id']) ?>" style="color:var(--color-accent);text-decoration:none;font-family:var(--font-mono);"><?= e((string) $tx['je_number']) ?></a> <span class="badge <?= e($statusBadgeClass((string) $tx['je_status'])) ?>" style="font-size:0.65rem;margin-left:4px;"><?= e((string) $tx['je_status']) ?></span></div>
        </div>
        <?php endif; ?>
        <div>
            <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:2px;">Created</div>
            <div><?= e($tx['created_by_name'] ?? 'system') ?> <span style="font-size:0.75rem;color:var(--text-secondary);">— <?= e($tx['created_at']) ?></span></div>
        </div>
    </div>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Description</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($tx['description']) ?></div>
    </div>
    <?php if ($tx['notes']): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-default);">
        <div style="font-size:0.7rem;text-transform:uppercase;color:var(--text-secondary);font-weight:600;letter-spacing:0.05em;margin-bottom:4px;">Notes</div>
        <div style="white-space:pre-wrap;font-size:0.875rem;"><?= e($tx['notes']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Documents ───────────────────────────────────────────────────────── -->
<?php
$entityType = 'bank_transaction';
$entityId   = (int) $tx['id'];
require FF_ROOT . '/includes/partials/acc-documents-section.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
