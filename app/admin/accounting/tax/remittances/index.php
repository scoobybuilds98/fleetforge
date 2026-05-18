<?php declare(strict_types=1);

/**
 * FleetForge — Tax Remittances (dedicated list)
 *
 * @file        app/admin/accounting/tax/remittances/index.php
 * @description Standalone audit-trail list of every remittance payment
 *              recorded against a tax filing period. Filters by tax_type
 *              and year of remittance_date. No create/edit — remittances
 *              are recorded via tax/show.php → Record Remittance button.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, includes/partials/accounting-nav.php
 *
 * @session     S037-CRUD — Phase B CRUD completion (spec §22.5)
 */

// dirname(__DIR__, 5): remittances/ -> tax/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 5) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('tax_management', 'view');

// ── Filters ────────────────────────────────────────────────────
$validTaxTypes = ['gst_hst', 'pst_bc', 'pst_sk', 'pst_mb'];
$filterTaxType = clean_string($_GET['tax_type'] ?? null);
if ($filterTaxType && !in_array($filterTaxType, $validTaxTypes, true)) {
    $filterTaxType = null;
}

$filterYear = clean_int($_GET['year'] ?? null);

// Build WHERE + params for the list query.
$where  = [];
$params = [];
if ($filterTaxType) {
    $where[]  = 'p.tax_type = ?';
    $params[] = $filterTaxType;
}
if ($filterYear) {
    $where[]  = 'YEAR(r.remittance_date) = ?';
    $params[] = $filterYear;
}
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Distinct years for the filter dropdown — covers all remittances on file.
$years = db_select(
    "SELECT DISTINCT YEAR(remittance_date) AS y
       FROM acc_tax_remittances
       ORDER BY y DESC"
);

// Main list — newest first, with period + JE + filer joins.
$rows = db_select(
    "SELECT r.id, r.remittance_date, r.amount, r.payment_method,
            r.reference_number, r.notes, r.created_at,
            p.id AS period_id, p.tax_type, p.period_start, p.period_end,
            p.status AS period_status,
            je.entry_number AS je_number, je.id AS je_id,
            uf.name AS filed_by_name,
            uc.name AS created_by_name
       FROM acc_tax_remittances r
       JOIN acc_tax_filing_periods p ON p.id = r.filing_period_id
  LEFT JOIN acc_journal_entries je   ON je.id = r.journal_entry_id
  LEFT JOIN users uf                 ON uf.id = p.filed_by
  LEFT JOIN users uc                 ON uc.id = r.created_by
     {$whereSQL}
     ORDER BY r.remittance_date DESC, r.id DESC",
    $params
);

// Summary tile — total $ remitted for the visible result set.
$totalAmount = '0.00';
foreach ($rows as $r) {
    $totalAmount = bcadd($totalAmount, $r['amount'] ?? '0', 2);
}

$formatTaxType = static function (string $t): string {
    return match ($t) {
        'gst_hst' => 'GST / HST',
        'pst_bc'  => 'PST — BC',
        'pst_sk'  => 'PST — SK',
        'pst_mb'  => 'PST — MB',
        default   => $t,
    };
};

$formatMethod = static function (string $m): string {
    return match ($m) {
        'online_banking' => 'Online banking',
        'check'          => 'Check',
        'wire'           => 'Wire',
        'other'          => 'Other',
        default          => $m,
    };
};

$pageTitle = 'Tax Remittances';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/tax') ?>">Tax</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Remittances</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Tax Remittances</h1>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── Summary tile ──────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">Total Remitted (visible)</div>
        <div class="stat-value font-mono">$<?= number_format((float) $totalAmount, 2) ?></div>
    </div>
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Remittance Count</div>
        <div class="stat-value font-mono"><?= count($rows) ?></div>
    </div>
</div>

<!-- ── Filter toolbar ────────────────────────────────────────── -->
<form method="GET" class="table-toolbar"
      style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="tax_type" class="form-select form-control-sm" style="min-width:160px;"
            onchange="this.form.submit()">
        <option value="">All tax types</option>
        <?php foreach ($validTaxTypes as $tt): ?>
            <option value="<?= e($tt) ?>" <?= $filterTaxType === $tt ? 'selected' : '' ?>>
                <?= e($formatTaxType($tt)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="year" class="form-select form-control-sm" style="min-width:120px;"
            onchange="this.form.submit()">
        <option value="">All years</option>
        <?php foreach ($years as $y): ?>
            <option value="<?= e((string) $y['y']) ?>" <?= (int) $filterYear === (int) $y['y'] ? 'selected' : '' ?>>
                <?= e((string) $y['y']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <?php if ($filterTaxType || $filterYear): ?>
        <a href="<?= base_url('accounting/tax/remittances/') ?>"
           class="btn btn-secondary btn-sm">Clear filters</a>
    <?php endif; ?>

    <span class="text-secondary text-sm" style="margin-left:auto;">
        Remittances are recorded via <a href="<?= base_url('accounting/tax') ?>">Tax Management</a> → period detail → Record Remittance.
    </span>
</form>

<!-- ── Remittances table ─────────────────────────────────────── -->
<?php if (empty($rows)): ?>
    <div class="card"><div class="empty-state">
        <p class="empty-state-title">No remittances recorded</p>
        <p class="empty-state-text">Once you mark a tax period as remitted, it will appear here.</p>
    </div></div>
<?php else: ?>
    <div class="card" style="padding:0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Tax Type</th>
                        <th>Remittance Date</th>
                        <th class="text-right">Amount</th>
                        <th>Method</th>
                        <th>JE #</th>
                        <th>Filed By</th>
                        <th>Status</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-sm">
                            <a href="<?= base_url('accounting/tax/show.php') ?>?id=<?= (int) $r['period_id'] ?>">
                                <?= e($r['period_start']) ?> → <?= e($r['period_end']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-no-dot badge-blue">
                                <?= e($formatTaxType($r['tax_type'])) ?>
                            </span>
                        </td>
                        <td class="text-sm"><?= e($r['remittance_date']) ?></td>
                        <td class="text-right font-mono">$<?= number_format((float) $r['amount'], 2) ?></td>
                        <td class="text-sm"><?= e($formatMethod($r['payment_method'])) ?></td>
                        <td class="text-sm">
                            <?php if ($r['je_number']): ?>
                                <a href="<?= base_url('accounting/journal-entries/show.php') ?>?id=<?= (int) $r['je_id'] ?>">
                                    <?= e($r['je_number']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($r['filed_by_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-no-dot <?= $r['period_status'] === 'remitted' ? 'badge-green' : 'badge-neutral' ?>">
                                <?= e($r['period_status']) ?>
                            </span>
                        </td>
                        <td class="text-sm"><?= e($r['reference_number'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
