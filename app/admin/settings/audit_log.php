<?php
declare(strict_types=1);

/**
 * app/admin/settings/audit_log.php
 *
 * Audit log viewer tab — included by settings/index.php.
 * Filterable by module, action, user, and date range.
 * Shows all audit_log entries with pagination.
 *
 * Variables inherited from parent: $canEdit, $isSuperAdmin, $csrfToken
 */

$perPage = 50;
$page    = max(1, clean_int($_GET['audit_page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filters
$filterModule = clean_string($_GET['audit_module'] ?? null);
$filterAction = clean_string($_GET['audit_action'] ?? null);
$filterUser   = clean_string($_GET['audit_user'] ?? null);
$filterFrom   = clean_string($_GET['audit_from'] ?? null);
$filterTo     = clean_string($_GET['audit_to'] ?? null);

$where  = ['1=1'];
$params = [];

if ($filterModule) {
    $where[]  = 'a.module = ?';
    $params[] = $filterModule;
}
if ($filterAction) {
    $where[]  = 'a.action = ?';
    $params[] = $filterAction;
}
if ($filterUser) {
    $where[]  = '(a.user_name LIKE ? OR a.ip_address LIKE ?)';
    $params[] = "%{$filterUser}%";
    $params[] = "%{$filterUser}%";
}
if ($filterFrom) {
    $where[]  = 'a.created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo) {
    $where[]  = 'a.created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}

$whereSQL = implode(' AND ', $where);

$totalRows = db_count(
    "SELECT COUNT(*) FROM audit_log a WHERE {$whereSQL}",
    $params
);

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$logs = db_select(
    "SELECT a.id, a.user_id, a.user_name, a.action, a.module,
            a.entity_type, a.entity_id, a.entity_label,
            a.ip_address, a.notes, a.created_at
     FROM audit_log a
     WHERE {$whereSQL}
     ORDER BY a.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Get distinct modules and actions for filter dropdowns
$modules = db_select("SELECT DISTINCT module FROM audit_log ORDER BY module ASC");
$actions = db_select("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");

$actionBadge = [
    'create'        => 'badge-success',
    'update'        => 'badge-info',
    'delete'        => 'badge-danger',
    'status_change' => 'badge-warning',
    'login'         => 'badge-neutral',
    'logout'        => 'badge-neutral',
    'cron'          => 'badge-neutral',
];

// Build filter query string for pagination links
$filterParams = array_filter([
    'tab'          => 'audit',
    'audit_module' => $filterModule,
    'audit_action' => $filterAction,
    'audit_user'   => $filterUser,
    'audit_from'   => $filterFrom,
    'audit_to'     => $filterTo,
]);
$filterQS = http_build_query($filterParams);
?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 16px;">
        <form method="GET" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="audit">
            <div class="form-group" style="margin-bottom:0;min-width:140px;">
                <label class="form-label" style="font-size:0.75rem;">Module</label>
                <select name="audit_module" class="form-control" style="font-size:0.8125rem;">
                    <option value="">All modules</option>
                    <?php foreach ($modules as $m): ?>
                    <option value="<?= e($m['module']) ?>" <?= $filterModule === $m['module'] ? 'selected' : '' ?>><?= e(ucfirst($m['module'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;min-width:130px;">
                <label class="form-label" style="font-size:0.75rem;">Action</label>
                <select name="audit_action" class="form-control" style="font-size:0.8125rem;">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;min-width:140px;">
                <label class="form-label" style="font-size:0.75rem;">User / IP</label>
                <input type="text" name="audit_user" class="form-control" style="font-size:0.8125rem;"
                       placeholder="Name or IP" value="<?= e($filterUser ?? '') ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:0.75rem;">From</label>
                <input type="date" name="audit_from" class="form-control" style="font-size:0.8125rem;"
                       value="<?= e($filterFrom ?? '') ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" style="font-size:0.75rem;">To</label>
                <input type="date" name="audit_to" class="form-control" style="font-size:0.8125rem;"
                       value="<?= e($filterTo ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <a href="<?= e(base_url('settings?tab=audit')) ?>" class="btn btn-ghost btn-sm">Clear</a>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">Audit Trail</span>
        <span style="font-size:0.8125rem;color:var(--text-muted);">
            <?= e(number_format($totalRows)) ?> entries
            <?php if ($totalPages > 1): ?> · Page <?= e((string)$page) ?> of <?= e((string)$totalPages) ?><?php endif; ?>
        </span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">No audit log entries match your filters.</div>
        <?php else: ?>
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Entity</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="font-mono" style="font-size:0.75rem;white-space:nowrap;"><?= e(format_datetime($log['created_at'])) ?></td>
                    <td style="font-size:0.8125rem;"><?= e($log['user_name'] ?? 'System') ?></td>
                    <td><span class="badge <?= e($actionBadge[$log['action']] ?? 'badge-neutral') ?>" style="font-size:0.7rem;"><?= e(ucfirst(str_replace('_', ' ', $log['action']))) ?></span></td>
                    <td style="font-size:0.8125rem;color:var(--text-secondary);"><?= e(ucfirst($log['module'])) ?></td>
                    <td style="font-size:0.8125rem;">
                        <?php if ($log['entity_label']): ?>
                            <span title="<?= e($log['entity_type'] . ' #' . ($log['entity_id'] ?? '')) ?>"><?= e($log['entity_label']) ?></span>
                        <?php elseif ($log['entity_type']): ?>
                            <span style="color:var(--text-muted);"><?= e($log['entity_type']) ?> #<?= e((string)($log['entity_id'] ?? '')) ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.75rem;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($log['notes'] ?? '') ?>">
                        <?= e($log['notes'] ?? '') ?>
                    </td>
                    <td class="font-mono" style="font-size:0.75rem;color:var(--text-muted);"><?= e($log['ip_address'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer" style="display:flex;justify-content:center;gap:4px;padding:12px;">
        <?php if ($page > 1): ?>
        <a href="<?= e(base_url('settings?' . http_build_query(array_merge($filterParams, ['audit_page' => $page - 1])))) ?>" class="btn btn-ghost btn-xs">Previous</a>
        <?php endif; ?>

        <?php
        $startPage = max(1, $page - 3);
        $endPage   = min($totalPages, $page + 3);
        for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <a href="<?= e(base_url('settings?' . http_build_query(array_merge($filterParams, ['audit_page' => $p])))) ?>"
           class="btn <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?> btn-xs"><?= e((string)$p) ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="<?= e(base_url('settings?' . http_build_query(array_merge($filterParams, ['audit_page' => $page + 1])))) ?>" class="btn btn-ghost btn-xs">Next</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
