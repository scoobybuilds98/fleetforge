<?php
declare(strict_types=1);

/**
 * app/admin/audit/index.php
 *
 * Audit log read-only viewer.
 * Server-side filters + server-side pagination (no separate API endpoint).
 *
 * Filters: module, user_id, action, date_from, date_to.
 * Columns: Date/Time, User, Action (badge), Module, Entity, Label, IP.
 *
 * Pagination: PHP server-side via ?page= query param links.
 *
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @decisions D5/D7/D30/D32
 * @session  S017
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('audit', 'view');

// ── Filters from GET ─────────────────────────────────────────────────────────
$module    = clean_string($_GET['module'] ?? null);
$userId    = clean_int($_GET['user_id'] ?? null);
$action    = clean_string($_GET['action'] ?? null);
$dateFrom  = clean_string($_GET['date_from'] ?? null);
$dateTo    = clean_string($_GET['date_to'] ?? null);

// ── Build WHERE clause ───────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

// WHY: each condition is independently optional — built dynamically to avoid
//      multiple IN-checks in a single WHERE that would require UNION tricks.
if ($module)   { $where[] = 'a.module = ?';               $params[] = $module; }
if ($userId)   { $where[] = 'a.user_id = ?';              $params[] = $userId; }
if ($action)   { $where[] = 'a.action = ?';               $params[] = $action; }
if ($dateFrom) { $where[] = 'DATE(a.created_at) >= ?';    $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(a.created_at) <= ?';    $params[] = $dateTo; }

$whereSQL = implode(' AND ', $where);

// ── Dropdown data for filter selects ────────────────────────────────────────
// Distinct modules logged so far
$modules = db_select(
    "SELECT DISTINCT module FROM audit_log WHERE module IS NOT NULL ORDER BY module ASC"
);

// All non-deleted users for user filter
$users = db_select(
    "SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name ASC"
);

// Valid action ENUM values
$actionValues = [
    'create', 'update', 'delete', 'restore', 'login', 'logout',
    'export', 'status_change', 'view', 'bulk_action',
    'payment_recorded', 'invoice_sent', 'invoice_voided',
    'lease_closed', 'cron',
];

// ── Pagination ───────────────────────────────────────────────────────────────
$perPage = 50;
$page    = max(1, clean_int($_GET['page'] ?? 1) ?? 1);
$offset  = ($page - 1) * $perPage;

$total = db_count(
    "SELECT COUNT(*) FROM audit_log a WHERE $whereSQL",
    $params
);
$totalPages = max(1, (int) ceil($total / $perPage));
// Clamp page to valid range
$page = min($page, $totalPages);

// ── Fetch rows ───────────────────────────────────────────────────────────────
$rows = db_select(
    "SELECT
         a.id, a.user_id, a.user_name, a.action, a.module,
         a.entity_type, a.entity_id, a.entity_label,
         a.ip_address, a.notes, a.created_at
     FROM audit_log a
     WHERE $whereSQL
     ORDER BY a.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// ── Action badge map ─────────────────────────────────────────────────────────
$actionBadges = [
    'create'           => 'badge-success',
    'update'           => 'badge-info',
    'delete'           => 'badge-danger',
    'restore'          => 'badge-success',
    'status_change'    => 'badge-warning',
    'login'            => 'badge-neutral',
    'logout'           => 'badge-neutral',
    'export'           => 'badge-neutral',
    'view'             => 'badge-neutral',
    'bulk_action'      => 'badge-warning',
    'payment_recorded' => 'badge-success',
    'invoice_sent'     => 'badge-info',
    'invoice_voided'   => 'badge-danger',
    'lease_closed'     => 'badge-neutral',
    'cron'             => 'badge-neutral',
];

// ── Build pagination URL helper ───────────────────────────────────────────────
function audit_page_url(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

$pageTitle = 'Audit Log';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Audit Log</h1>
    <?php // S-LIST-TOOLBAR: the entry count moved to the filter toolbar below,
          // where every other list page shows it. ?>
</div>

<!-- ── FILTER TOOLBAR ────────────────────────────────────────────────────────── -->
<!-- S-LIST-TOOLBAR: was a filter card of labelled fields; now the same
     .table-toolbar shape as customers/invoices. Audit filters server-side via
     a plain GET form, so the toolbar IS the form — the selects auto-submit on
     change and the Filter button stays for the two date fields (which people
     type into rather than pick, so submitting per keystroke would thrash). -->
<form method="GET" action="" class="table-toolbar">

    <div class="table-toolbar-left table-toolbar-left--wrap">
        <select name="module" class="form-select form-control-sm"
                onchange="this.form.submit()" aria-label="Filter by module">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?= e($m['module']) ?>"
                    <?= $module === $m['module'] ? 'selected' : '' ?>>
                <?= e($m['module']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="user_id" class="form-select form-control-sm"
                onchange="this.form.submit()" aria-label="Filter by user">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= e($u['id']) ?>"
                    <?= $userId === (int)$u['id'] ? 'selected' : '' ?>>
                <?= e($u['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="action" class="form-select form-control-sm"
                onchange="this.form.submit()" aria-label="Filter by action">
            <option value="">All Actions</option>
            <?php foreach ($actionValues as $av): ?>
            <option value="<?= e($av) ?>"
                    <?= $action === $av ? 'selected' : '' ?>>
                <?= e($av) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" class="form-control form-control-sm"
               value="<?= e($dateFrom ?? '') ?>"
               title="From date" aria-label="From date">
        <input type="date" name="date_to" class="form-control form-control-sm"
               value="<?= e($dateTo ?? '') ?>"
               title="To date" aria-label="To date">

        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="<?= base_url('audit') ?>" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div class="table-toolbar-right">
        <span class="text-secondary text-sm">
            <?= number_format($total) ?> <?= $total === 1 ? 'entry' : 'entries' ?>
        </span>
    </div>

</form>

<!-- ── Table ─────────────────────────────────────────────────────────────────── -->
<div class="card">

    <?php if (empty($rows)): ?>
    <div class="card-body">
        <div class="empty-state">
            <p class="empty-state-title">No audit entries found</p>
            <p class="empty-state-text">Adjust the filters above to find entries.</p>
        </div>
    </div>
    <?php else: ?>

    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Entity</th>
                    <th>Label / Notes</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $badge = $actionBadges[$row['action']] ?? 'badge-neutral'; ?>
                <tr>
                    <td class="font-mono" style="font-size:0.8125rem;white-space:nowrap;">
                        <?= format_datetime($row['created_at']) ?>
                    </td>
                    <td>
                        <?php if ($row['user_id']): ?>
                        <?php /* S-PERM-USERS-SUPERADMIN-ONLY — gate /users/show link to super_admin only;
                                 fall back to plain text so non-super_admins still see who acted. */ ?>
                        <?php if (can('users', 'view')): ?>
                        <a href="<?= base_url('users/show') ?>?id=<?= e($row['user_id']) ?>"
                           class="link"><?= e($row['user_name'] ?? '—') ?></a>
                        <?php else: ?>
                        <?= e($row['user_name'] ?? '—') ?>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted"><?= e($row['user_name'] ?? 'system') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= e($badge) ?>"><?= e($row['action']) ?></span>
                    </td>
                    <td><?= e($row['module'] ?? '—') ?></td>
                    <td>
                        <?php
                        $entityStr = implode(' ', array_filter([
                            $row['entity_type'],
                            $row['entity_id'] ? '#' . $row['entity_id'] : '',
                        ]));
                        echo e($entityStr) ?: '—';
                        ?>
                    </td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= e($row['entity_label'] ?? $row['notes'] ?? '') ?>">
                        <?= e($row['entity_label'] ?? $row['notes'] ?? '—') ?>
                    </td>
                    <td class="font-mono" style="font-size:0.8125rem;">
                        <?= $row['ip_address'] ? e($row['ip_address']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="card-footer" style="display:flex;justify-content:center;align-items:center;gap:8px;padding:12px;">
        <?php if ($page > 1): ?>
        <a href="<?= e(audit_page_url($page - 1)) ?>"
           class="btn btn-secondary btn-sm">← Prev</a>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled>← Prev</button>
        <?php endif; ?>

        <span class="text-secondary" style="font-size:0.875rem;">
            Page <?= $page ?> of <?= $totalPages ?>
        </span>

        <?php if ($page < $totalPages): ?>
        <a href="<?= e(audit_page_url($page + 1)) ?>"
           class="btn btn-secondary btn-sm">Next →</a>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled>Next →</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /card -->

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
