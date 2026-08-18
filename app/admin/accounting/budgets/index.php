<?php declare(strict_types=1);

/**
 * app/admin/accounting/budgets/index.php
 *
 * Budgets list with optional year filter + status badges.
 * "New Budget" button → create.php. Each row links to show.php for
 * the 12-month grid editor + variance report.
 *
 * @session S036
 */

require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('journal_entries', 'view');

$year   = clean_int($_GET['year'] ?? null);
$status = clean_string($_GET['status'] ?? null);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($year)   { $where[] = 'b.year = ?';   $params[] = $year; }
if ($status) { $where[] = 'b.status = ?'; $params[] = $status; }
$whereSql = implode(' AND ', $where);

$total = (int) (db_row(
    "SELECT COUNT(*) AS n FROM acc_budgets b WHERE {$whereSql}",
    $params
)['n'] ?? 0);

$rows = db_select(
    "SELECT b.id, b.name, b.year, b.version, b.status, b.is_active,
            b.created_at, u.name AS created_by_name,
            (SELECT COUNT(*) FROM acc_budget_lines WHERE budget_id = b.id) AS line_count
       FROM acc_budgets b
  LEFT JOIN users u ON u.id = b.created_by
      WHERE {$whereSql}
      ORDER BY b.year DESC, b.created_at DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);
$totalPages = (int) max(1, ceil($total / $perPage));

$canCreate = can('journal_entries', 'create');

$statusBadge = static function (string $s): string {
    return match ($s) {
        'active'   => 'badge-green',
        'draft'    => 'badge-neutral',
        'archived' => 'badge-red',
        default    => 'badge-neutral',
    };
};

$pageTitle = 'Budgets';
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Budgets</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Budgets</h1>
    <div class="page-header-actions">
        <?php if ($canCreate): ?>
            <a class="btn btn-primary btn-sm" href="<?= base_url('accounting/budgets/create') ?>">+ New Budget</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── FILTER TOOLBAR ────────────────────────────────────────────────────── -->
<!-- S-LIST-TOOLBAR: was a filter card of labelled fields with hand-rolled
     inline input styling; now the standard .table-toolbar shape and the shared
     .form-control/.form-select pills. Status auto-submits; Apply covers the
     free-typed year box. -->
<form method="get" class="table-toolbar">

    <div class="table-toolbar-left">
        <input type="number" name="year" value="<?= e((string) ($year ?? '')) ?>"
               class="form-control form-control-sm"
               style="min-width:110px;"
               placeholder="Year — <?= (int) date('Y') ?>"
               aria-label="Filter by year">

        <select name="status" class="form-select form-control-sm"
                onchange="this.form.submit()" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="draft"    <?= $status === 'draft'    ? 'selected' : '' ?>>Draft</option>
            <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        <a href="<?= base_url('accounting/budgets') ?>" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div class="table-toolbar-right">
        <span class="text-secondary text-sm">
            <?= number_format($total) ?> budget<?= $total === 1 ? '' : 's' ?>
        </span>
    </div>

</form>

<?php if ($total === 0): ?>
    <div class="card" style="padding:36px;text-align:center;">
        <div style="font-size:1rem;font-weight:600;margin-bottom:4px;">No Budgets</div>
        <div style="font-size:0.8125rem;color:var(--text-secondary);">Click "+ New Budget" above to create one.</div>
    </div>
<?php else: ?>
    <div class="card" style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
            <thead>
                <tr style="border-bottom:2px solid var(--border-default);">
                    <th style="padding:9px 12px;text-align:left;">Name</th>
                    <th style="padding:9px 12px;text-align:left;">Year</th>
                    <th style="padding:9px 12px;text-align:left;">Version</th>
                    <th style="padding:9px 12px;text-align:center;">Status</th>
                    <th style="padding:9px 12px;text-align:right;">Lines</th>
                    <th style="padding:9px 12px;text-align:left;">Created</th>
                    <th style="padding:9px 12px;text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr style="border-bottom:1px solid var(--border-default);">
                    <td style="padding:8px 12px;">
                        <a href="<?= base_url('accounting/budgets/show?id=' . (int) $r['id']) ?>" style="color:var(--color-accent);text-decoration:none;font-weight:500;"><?= e($r['name']) ?></a>
                        <?php if ((int) $r['is_active'] === 1): ?>
                            <span class="badge badge-blue" style="font-size:0.65rem;margin-left:4px;">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-mono" style="padding:8px 12px;"><?= (int) $r['year'] ?></td>
                    <td style="padding:8px 12px;text-transform:capitalize;"><?= e($r['version']) ?></td>
                    <td style="padding:8px 12px;text-align:center;">
                        <span class="badge <?= e($statusBadge($r['status'])) ?>"><?= e($r['status']) ?></span>
                    </td>
                    <td class="font-mono" style="padding:8px 12px;text-align:right;"><?= (int) $r['line_count'] ?></td>
                    <td style="padding:8px 12px;">
                        <?= e($r['created_by_name'] ?? 'system') ?>
                        <span style="font-size:0.7rem;color:var(--text-secondary);"> · <?= e(substr($r['created_at'], 0, 10)) ?></span>
                    </td>
                    <td style="padding:8px 12px;text-align:center;">
                        <a class="btn btn-ghost btn-xs" href="<?= base_url('accounting/budgets/show?id=' . (int) $r['id']) ?>">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding:12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--border-default);">
            <div style="font-size:0.75rem;color:var(--text-secondary);">Showing <?= (int) ($offset + 1) ?>–<?= (int) min($offset + $perPage, $total) ?> of <?= (int) $total ?></div>
            <div style="display:flex;gap:6px;">
                <?php $qs = $_GET; unset($qs['page']); $base = http_build_query($qs); $prefix = $base !== '' ? '&' : ''; ?>
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
