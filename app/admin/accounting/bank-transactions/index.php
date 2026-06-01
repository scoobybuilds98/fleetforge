<?php declare(strict_types=1);

/**
 * FleetForge — Bank Transactions (dedicated list)
 *
 * @file        app/admin/accounting/bank-transactions/index.php
 * @description Standalone list of every acc_bank_transactions row across
 *              all bank accounts. Filters: bank_account_id, date range,
 *              transaction_type, status (matched/unmatched/excluded).
 *              Paginated 25/page, newest first. Each row links to
 *              bank-transactions/show.php for drill-down. Reconciliation,
 *              matching, and create actions stay on the bank-accounts/
 *              index page.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, includes/partials/accounting-nav.php
 *
 * @session     S037-CRUD — Phase B CRUD completion (spec §22.5)
 */

// dirname(__DIR__, 4): bank-transactions/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('bank_accounts', 'view');

// ── Filters ────────────────────────────────────────────────────
$bankAccountId = clean_int($_GET['bank_account_id'] ?? null);

$validTypes = ['deposit', 'withdrawal', 'transfer', 'bank_charge', 'interest', 'nsf', 'other'];
$filterType = clean_string($_GET['type'] ?? null);
if ($filterType && !in_array($filterType, $validTypes, true)) {
    $filterType = null;
}

$validStatuses = ['unmatched', 'matched', 'excluded'];
$filterStatus  = clean_string($_GET['status'] ?? null);
if ($filterStatus && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = null;
}

$dateFrom = clean_date($_GET['date_from'] ?? null);
$dateTo   = clean_date($_GET['date_to']   ?? null);

// Pagination
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// Build WHERE clause from allowlisted filters.
$where  = [];
$params = [];

if ($bankAccountId) {
    $where[]  = 't.bank_account_id = ?';
    $params[] = $bankAccountId;
}
if ($filterType) {
    $where[]  = 't.transaction_type = ?';
    $params[] = $filterType;
}
if ($filterStatus) {
    $where[]  = 't.status = ?';
    $params[] = $filterStatus;
}
if ($dateFrom) {
    $where[]  = 't.transaction_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[]  = 't.transaction_date <= ?';
    $params[] = $dateTo;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total count for pagination.
$total = db_count(
    "SELECT COUNT(*) FROM acc_bank_transactions t {$whereSQL}",
    $params
);

// Main list.
$rows = db_select(
    "SELECT t.id, t.bank_account_id, t.transaction_date, t.description,
            t.reference, t.amount, t.transaction_type, t.status,
            t.is_cleared, t.journal_entry_id,
            ba.name AS bank_account_name,
            ba.currency AS bank_account_currency,
            m.qbo_currency_snapshot, m.qbo_exchange_rate_snapshot,
            je.entry_number AS je_number, je.id AS je_id
       FROM acc_bank_transactions t
       JOIN acc_bank_accounts ba ON ba.id = t.bank_account_id
  LEFT JOIN (
            SELECT ff_bank_transaction_id,
                   MAX(qbo_currency_snapshot) AS qbo_currency_snapshot,
                   MAX(qbo_exchange_rate_snapshot) AS qbo_exchange_rate_snapshot
              FROM acc_qbo_bank_transaction_map
             WHERE pull_status = 'pulled'
             GROUP BY ff_bank_transaction_id
       ) m ON m.ff_bank_transaction_id = t.id
  LEFT JOIN acc_journal_entries je ON je.id = t.journal_entry_id
     {$whereSQL}
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$totalPages = max(1, (int) ceil($total / $perPage));

// All bank accounts for the filter dropdown.
$bankAccounts = db_select(
    "SELECT id, name FROM acc_bank_accounts WHERE is_active = 1 ORDER BY name"
);

$formatType = static function (string $t): string {
    return match ($t) {
        'deposit'      => 'Deposit',
        'withdrawal'   => 'Withdrawal',
        'transfer'     => 'Transfer',
        'bank_charge'  => 'Bank Charge',
        'interest'     => 'Interest',
        'nsf'          => 'NSF',
        'other'        => 'Other',
        default        => $t,
    };
};

$statusBadge = static function (string $s): string {
    return match ($s) {
        'matched'   => 'badge-green',
        'unmatched' => 'badge-amber',
        'excluded'  => 'badge-neutral',
        default     => 'badge-neutral',
    };
};

$typeBadge = static function (string $t): string {
    return match ($t) {
        'deposit'     => 'badge-green',
        'withdrawal'  => 'badge-amber',
        'transfer'    => 'badge-blue',
        'bank_charge' => 'badge-red',
        'interest'    => 'badge-green',
        'nsf'         => 'badge-red',
        default       => 'badge-neutral',
    };
};

// Build a "stable querystring" for pagination links — preserves filters.
$qsBase = [];
foreach (['bank_account_id', 'type', 'status', 'date_from', 'date_to'] as $k) {
    $v = $_GET[$k] ?? null;
    if ($v !== null && $v !== '') $qsBase[$k] = $v;
}
$buildPageUrl = static function (int $p) use ($qsBase): string {
    $qs = $qsBase;
    $qs['page'] = $p;
    return '?' . http_build_query($qs);
};

$pageTitle = 'Bank Transactions';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/bank-accounts') ?>">Banking</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Transactions</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Bank Transactions</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── Filter toolbar ────────────────────────────────────────── -->
<form method="GET" class="table-toolbar"
      style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="bank_account_id" class="form-select form-control-sm" style="min-width:200px;"
            onchange="this.form.submit()">
        <option value="">All bank accounts</option>
        <?php foreach ($bankAccounts as $ba): ?>
            <option value="<?= (int) $ba['id'] ?>" <?= (int) $bankAccountId === (int) $ba['id'] ? 'selected' : '' ?>>
                <?= e($ba['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="type" class="form-select form-control-sm" style="min-width:140px;"
            onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach ($validTypes as $t): ?>
            <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>>
                <?= e($formatType($t)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="status" class="form-select form-control-sm" style="min-width:140px;"
            onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach ($validStatuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>>
                <?= e(ucfirst($s)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:160px;"
           value="<?= e($dateFrom ?? '') ?>" placeholder="From"
           onchange="this.form.submit()">
    <input type="date" name="date_to" class="form-control form-control-sm" style="max-width:160px;"
           value="<?= e($dateTo ?? '') ?>" placeholder="To"
           onchange="this.form.submit()">

    <?php if ($bankAccountId || $filterType || $filterStatus || $dateFrom || $dateTo): ?>
        <a href="<?= base_url('accounting/bank-transactions/') ?>"
           class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>

    <span class="text-secondary text-sm" style="margin-left:auto;">
        <?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?>
        <?php if ($totalPages > 1): ?>
            · Page <?= $page ?> of <?= $totalPages ?>
        <?php endif; ?>
    </span>
</form>

<!-- ── Transactions table ────────────────────────────────────── -->
<?php if (empty($rows)): ?>
    <div class="card"><div class="empty-state">
        <p class="empty-state-title">No transactions match your filters</p>
        <p class="empty-state-text">Clear the filters above or visit
            <a href="<?= base_url('accounting/bank-accounts') ?>">Bank Accounts</a>
            to import or record a new transaction.</p>
    </div></div>
<?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>JE #</th>
                        <th>Reference</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $isDebit = bccomp($r['amount'], '0', 2) < 0;
                ?>
                    <tr>
                        <td class="text-sm"><?= e($r['transaction_date']) ?></td>
                        <td class="text-sm"><?= e($r['description']) ?></td>
                        <td class="text-sm">
                            <a href="<?= base_url('accounting/bank-accounts') ?>">
                                <?= e($r['bank_account_name']) ?>
                            </a>
                            <?php if (($r['bank_account_currency'] ?? 'CAD') === 'USD'): ?>
                                <span class="badge badge-no-dot badge-neutral" style="font-size:0.625rem;">USD</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-no-dot <?= $typeBadge($r['transaction_type']) ?>"
                                  style="text-transform:capitalize;">
                                <?= e($formatType($r['transaction_type'])) ?>
                            </span>
                        </td>
                        <td class="text-right font-mono <?= $isDebit ? 'text-danger' : '' ?>">
                            <?php
                            // F15: multi-currency display. For a foreign-currency row
                            // (e.g. a USD bank-account mirror), show a currency badge +
                            // the CAD-equivalent at the frozen pull-time rate so the
                            // bare foreign figure isn't read as CAD. No-op for home-
                            // currency rows (the common case today).
                            $rowCurrency = $r['qbo_currency_snapshot'] ?? $r['bank_account_currency'] ?? null;
                            $fxForeign   = \FleetForge\FxConverter::isForeign($rowCurrency);
                            ?>
                            <?php if ($fxForeign): ?>
                                <span class="badge badge-no-dot badge-neutral" style="font-size:10px;margin-right:4px;"><?= e(strtoupper((string) $rowCurrency)) ?></span>
                            <?php endif; ?>
                            <?= $isDebit ? '-' : '' ?>$<?= number_format(abs((float) $r['amount']), 2) ?>
                            <?php if ($fxForeign):
                                $fxLabel = \FleetForge\FxConverter::homeEquivalentLabel((string) $r['amount'], $rowCurrency, $r['qbo_exchange_rate_snapshot'] ?? null);
                            ?>
                                <?php if ($fxLabel !== ''): ?>
                                    <div class="text-secondary" style="font-size:10px;" title="Converted at the QBO pull-time exchange rate (frozen). Live revaluation deferred — F15.">
                                        <?= e($fxLabel) ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-no-dot <?= $statusBadge($r['status']) ?>">
                                <?= e(ucfirst($r['status'])) ?>
                            </span>
                            <?php if ((int) $r['is_cleared'] === 1): ?>
                                <span class="badge badge-no-dot badge-green" style="font-size:0.625rem;">Cleared</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <?php if (!empty($r['je_number'])): ?>
                                <a href="<?= base_url('accounting/journal-entries/show.php') ?>?id=<?= (int) $r['je_id'] ?>">
                                    <?= e($r['je_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($r['reference'] ?? '—') ?></td>
                        <td>
                            <a class="btn btn-secondary btn-xs"
                               href="<?= base_url('accounting/bank-transactions/show.php') ?>?id=<?= (int) $r['id'] ?>">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="table-pagination" style="margin-top:16px;text-align:center;">
            <?php if ($page > 1): ?>
                <a class="btn btn-secondary btn-sm" href="<?= e($buildPageUrl($page - 1)) ?>">‹ Prev</a>
            <?php else: ?>
                <button class="btn btn-secondary btn-sm" disabled>‹ Prev</button>
            <?php endif; ?>
            <span style="margin:0 12px;">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-secondary btn-sm" href="<?= e($buildPageUrl($page + 1)) ?>">Next ›</a>
            <?php else: ?>
                <button class="btn btn-secondary btn-sm" disabled>Next ›</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
