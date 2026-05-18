<?php declare(strict_types=1);

/**
 * app/admin/accounting/ap-payments/index.php
 *
 * AP Payments admin list — view-only listing of acc_ap_payments rows.
 *
 * Server-rendered table with vendor, status, and date-range filters plus
 * pagination (25 rows per page). View-only in this session — no create,
 * edit, or void controls (creation happens through the bills "Pay" flow
 * in app/admin/accounting/bills/index.php which calls
 * api/v1/accounting/ap-payments/create.php).
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §15 ("Accounts Payable → Payments"
 * sidebar link) and §20.2 (orphan AP-payment JE remediation surfaces this
 * page as Phase 3 deliverable).
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

// ── Filters from query string ────────────────────────────────────────────────
$filterVendor    = clean_int($_GET['vendor_id'] ?? null);
$filterStatus    = clean_string($_GET['status'] ?? null);
$filterDateFrom  = clean_date($_GET['date_from'] ?? null);
$filterDateTo    = clean_date($_GET['date_to'] ?? null);

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$validStatuses = ['pending', 'cleared', 'void'];
if ($filterStatus !== null && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = null;
}

// ── WHERE clause assembly ────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];
if ($filterVendor) {
    $where[] = 'ap.vendor_id = ?';
    $params[] = $filterVendor;
}
if ($filterStatus) {
    $where[] = 'ap.status = ?';
    $params[] = $filterStatus;
}
if ($filterDateFrom) {
    $where[] = 'ap.payment_date >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo) {
    $where[] = 'ap.payment_date <= ?';
    $params[] = $filterDateTo;
}
$whereSQL = implode(' AND ', $where);

// ── Pagination total ─────────────────────────────────────────────────────────
$total = (int) (db_row(
    "SELECT COUNT(*) AS n
       FROM acc_ap_payments ap
       JOIN vendors v ON v.id = ap.vendor_id AND v.deleted_at IS NULL
      WHERE {$whereSQL}",
    $params
)['n'] ?? 0);

// ── Page rows ────────────────────────────────────────────────────────────────
$rows = db_select(
    "SELECT ap.id, ap.payment_number, ap.payment_date, ap.payment_method,
            ap.reference_number, ap.check_number, ap.amount, ap.currency, ap.status,
            v.name AS vendor_name,
            ba.name AS bank_account_name,
            je.id AS je_id, je.entry_number AS je_number
       FROM acc_ap_payments ap
       JOIN vendors v ON v.id = ap.vendor_id AND v.deleted_at IS NULL
       JOIN acc_bank_accounts ba ON ba.id = ap.bank_account_id
  LEFT JOIN acc_journal_entries je ON je.id = ap.journal_entry_id
      WHERE {$whereSQL}
      ORDER BY ap.payment_date DESC, ap.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── Vendors for filter dropdown ──────────────────────────────────────────────
$vendors = db_select(
    "SELECT id, name FROM vendors WHERE deleted_at IS NULL ORDER BY name", []
);

$totalPages = (int) max(1, ceil($total / $perPage));

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'cleared' => 'badge-green',
        'pending' => 'badge-amber',
        'void'    => 'badge-red',
        default   => 'badge-neutral',
    };
};

$pageTitle = 'AP Payments';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">AP Payments</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Accounts Payable — Payments</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── Filters ───────────────────────────────────────────────────────────── -->
<form method="get" class="card" style="padding:14px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
    <div style="min-width:180px;">
        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Vendor</label>
        <select name="vendor_id" class="form-input" style="width:100%;padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            <option value="">All vendors</option>
            <?php foreach ($vendors as $v): ?>
                <option value="<?= (int) $v['id'] ?>" <?= ($filterVendor === (int) $v['id']) ? 'selected' : '' ?>>
                    <?= e($v['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="min-width:140px;">
        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">Status</label>
        <select name="status" class="form-input" style="width:100%;padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
            <option value="">All</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= e($s) ?>" <?= ($filterStatus === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">From</label>
        <input type="date" name="date_from" value="<?= e((string) $filterDateFrom) ?>" class="form-input" style="padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
    </div>
    <div>
        <label class="form-label" style="display:block;font-size:0.75rem;font-weight:600;margin-bottom:4px;color:var(--text-secondary);">To</label>
        <input type="date" name="date_to" value="<?= e((string) $filterDateTo) ?>" class="form-input" style="padding:7px 10px;border:1px solid var(--border-default);border-radius:6px;background:var(--bg-input);color:var(--text-primary);font-size:0.8125rem;">
    </div>
    <div>
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <a href="<?= base_url('accounting/ap-payments') ?>" class="btn btn-secondary btn-sm">Reset</a>
    </div>
</form>

<!-- ── Results table or empty state ──────────────────────────────────────── -->
<?php if ($total === 0): ?>
    <div class="card" style="padding:48px;text-align:center;">
        <div style="font-size:1rem;font-weight:600;color:var(--text-primary);margin-bottom:4px;">No payments recorded</div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">
            AP payments are recorded from the Bills page via the "Pay" action on an approved or partially-paid bill.
        </div>
    </div>
<?php else: ?>
    <div class="card" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Payment #</th>
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Date</th>
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Vendor</th>
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Bank Account</th>
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Method</th>
                    <th style="padding:10px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Amount</th>
                    <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Status</th>
                    <th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">JE #</th>
                    <th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="border-bottom:1px solid var(--border-default);<?= ($r['status'] === 'void') ? 'opacity:0.55;' : '' ?>">
                        <td class="font-mono" style="padding:8px 12px;font-weight:500;">
                            <a href="<?= base_url('accounting/ap-payments/show?id=' . (int) $r['id']) ?>" style="color:var(--color-accent);text-decoration:none;">
                                <?= e($r['payment_number']) ?>
                            </a>
                        </td>
                        <td class="font-mono" style="padding:8px 12px;"><?= e($r['payment_date']) ?></td>
                        <td style="padding:8px 12px;"><?= e($r['vendor_name']) ?></td>
                        <td style="padding:8px 12px;"><?= e($r['bank_account_name']) ?></td>
                        <td style="padding:8px 12px;text-transform:capitalize;"><?= e(str_replace('_', ' ', $r['payment_method'])) ?></td>
                        <td class="font-mono" style="padding:8px 12px;text-align:right;font-weight:600;">
                            <?= e('$' . number_format((float) $r['amount'], 2)) ?>
                            <?php if (($r['currency'] ?? 'CAD') !== 'CAD'): ?>
                                <span style="font-size:0.7rem;color:var(--text-secondary);"><?= e((string) $r['currency']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;text-align:center;">
                            <span class="badge <?= e($statusBadgeClass($r['status'])) ?>"><?= e($r['status']) ?></span>
                        </td>
                        <td class="font-mono" style="padding:8px 12px;">
                            <?php if ($r['je_number']): ?>
                                <a href="<?= base_url('accounting/journal-entries/show?id=' . (int) $r['je_id']) ?>" style="color:var(--color-accent);text-decoration:none;">
                                    <?= e($r['je_number']) ?>
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-secondary);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;text-align:center;">
                            <a href="<?= base_url('accounting/ap-payments/show?id=' . (int) $r['id']) ?>" class="btn btn-ghost btn-xs" title="View">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ── Pagination ──────────────────────────────────────────────── -->
        <div style="padding:12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-default);">
            <div style="font-size:0.75rem;color:var(--text-secondary);">
                Showing <?= (int) ($offset + 1) ?>–<?= (int) min($offset + $perPage, $total) ?> of <?= (int) $total ?>
            </div>
            <?php
            $qs = $_GET;
            unset($qs['page']);
            $base = http_build_query($qs);
            $prefix = $base !== '' ? '&' : '';
            ?>
            <div style="display:flex;gap:6px;">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary btn-xs" href="?<?= e($base) ?><?= e($prefix) ?>page=<?= (int) ($page - 1) ?>">Prev</a>
                <?php else: ?>
                    <button class="btn btn-secondary btn-xs" disabled>Prev</button>
                <?php endif; ?>
                <span style="font-size:0.75rem;color:var(--text-secondary);align-self:center;">Page <?= (int) $page ?> / <?= (int) $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary btn-xs" href="?<?= e($base) ?><?= e($prefix) ?>page=<?= (int) ($page + 1) ?>">Next</a>
                <?php else: ?>
                    <button class="btn btn-secondary btn-xs" disabled>Next</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
