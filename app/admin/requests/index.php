<?php declare(strict_types=1);

/**
 * app/admin/requests/index.php
 *
 * Admin-side list of portal service requests submitted by customers.
 * Mirrors /quickbooks/* admin patterns (5 KPI tiles + status filter +
 * paginated table). Read access gated by 'customers' module since
 * service requests originate from customer portal users.
 *
 * @session  S-PORTAL-REQUEST-ROUTING
 * @gate     require_permission('customers', 'view') for read;
 *           edit_permission used by respond.php
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

$canRespond = can('customers', 'edit');
$pageTitle = 'Service Requests';
require_once FF_ROOT . '/includes/header.php';

// Status filter via query string
$statusFilter = $_GET['status'] ?? 'open';
$validStatuses = ['open', 'in_review', 'resolved', 'closed', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'open';
}

// KPI rollup
$kpiRows = db_select("SELECT status, COUNT(*) AS c FROM portal_service_requests GROUP BY status");
$kpis = ['open' => 0, 'in_review' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($kpiRows as $k) {
    if (isset($kpis[$k['status']])) {
        $kpis[$k['status']] = (int) $k['c'];
    }
}

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND psr.status = ?';
    $params[] = $statusFilter;
}

$total = (int) db_count(
    "SELECT COUNT(*) FROM portal_service_requests psr WHERE {$where}",
    $params
);

$rows = db_select(
    "SELECT psr.id, psr.request_type, psr.subject, psr.status, psr.created_at, psr.resolved_at,
            psr.lease_id, psr.equipment_unit_id,
            c.id AS customer_id, c.company_name AS customer_name,
            pu.name AS submitted_by_name, pu.email AS submitted_by_email,
            assigned.name AS assigned_to_name,
            l.contract_number,
            eu.unit_number
       FROM portal_service_requests psr
  LEFT JOIN customers c ON c.id = psr.customer_id
  LEFT JOIN portal_users pu ON pu.id = psr.portal_user_id
  LEFT JOIN users assigned ON assigned.id = psr.assigned_to
  LEFT JOIN leases l ON l.id = psr.lease_id
  LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
      WHERE {$where}
      ORDER BY psr.created_at DESC, psr.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Display labels (mirror of PortalRequestNotifier::REQUEST_TYPE_LABELS)
$typeLabels = \FleetForge\Notifications\PortalRequestNotifier::REQUEST_TYPE_LABELS;
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Service Requests</span>
</nav>

<div class="page-header">
    <h1 class="page-header-title h4">Customer Service Requests</h1>
    <div class="text-secondary text-sm" style="margin-top:4px;">
        Read-only list of service requests submitted via the customer portal.
        Routing for new requests is configured in
        <a href="<?= base_url('settings') ?>?tab=portal_users">Settings &rarr; Portal Users tab</a>.
        Each notification you received in the bell links here.
    </div>
</div>

<!-- ── 4 KPI tiles ────────────────────────────────────────────── -->
<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px;">
    <div class="kpi-tile">
        <div class="kpi-label">Open</div>
        <div class="kpi-value text-warning"><?= (int) $kpis['open'] ?></div>
    </div>
    <div class="kpi-tile">
        <div class="kpi-label">In Review</div>
        <div class="kpi-value text-info"><?= (int) $kpis['in_review'] ?></div>
    </div>
    <div class="kpi-tile">
        <div class="kpi-label">Resolved</div>
        <div class="kpi-value text-success"><?= (int) $kpis['resolved'] ?></div>
    </div>
    <div class="kpi-tile">
        <div class="kpi-label">Closed</div>
        <div class="kpi-value text-secondary"><?= (int) $kpis['closed'] ?></div>
    </div>
</div>

<!-- ── Status filter pills ─────────────────────────────────────── -->
<div class="card" style="padding:12px 18px;margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <span class="text-sm text-secondary">Status:</span>
    <?php foreach (['open', 'in_review', 'resolved', 'closed', 'all'] as $s):
        $active = $s === $statusFilter;
    ?>
        <a href="?status=<?= e($s) ?>"
           class="badge <?= $active ? 'badge-primary' : 'badge-secondary' ?>"
           style="text-decoration:none;cursor:pointer;<?= $active ? '' : 'background:var(--bg-secondary);color:var(--text-secondary);' ?>">
            <?= e($s) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ── Main table ─────────────────────────────────────────────── -->
<div class="card" style="padding:0;">
    <table class="table table-striped" style="margin:0;">
        <thead>
            <tr>
                <th>#</th>
                <th>Type</th>
                <th>Customer</th>
                <th>Submitted By</th>
                <th>Subject</th>
                <th>Lease / Unit</th>
                <th>Assigned</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="text-center text-secondary" style="padding:24px;">
                    No service requests in status "<?= e($statusFilter) ?>".
                </td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <a href="<?= base_url('requests/view?id=' . (int) $r['id']) ?>">#<?= (int) $r['id'] ?></a>
                    </td>
                    <td>
                        <span class="badge badge-info"><?= e($typeLabels[$r['request_type']] ?? $r['request_type']) ?></span>
                    </td>
                    <td>
                        <?php if ($r['customer_id']): ?>
                            <a href="<?= base_url('customers/show?id=' . (int) $r['customer_id']) ?>"><?= e($r['customer_name'] ?? '—') ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="text-sm">
                        <?= e($r['submitted_by_name'] ?? '—') ?>
                        <?php if (!empty($r['submitted_by_email'])): ?>
                            <div class="text-xs text-secondary"><?= e($r['submitted_by_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= base_url('requests/view?id=' . (int) $r['id']) ?>"><?= e($r['subject']) ?></a>
                    </td>
                    <td class="text-sm">
                        <?php if (!empty($r['contract_number'])): ?>
                            <?= e($r['contract_number']) ?>
                        <?php endif; ?>
                        <?php if (!empty($r['unit_number'])): ?>
                            <div class="text-xs text-secondary">Unit <?= e($r['unit_number']) ?></div>
                        <?php endif; ?>
                        <?php if (empty($r['contract_number']) && empty($r['unit_number'])): ?>—<?php endif; ?>
                    </td>
                    <td class="text-sm"><?= e($r['assigned_to_name'] ?? '—') ?></td>
                    <td>
                        <?php
                        $statusBadge = match ($r['status']) {
                            'open'      => 'badge-warning',
                            'in_review' => 'badge-info',
                            'resolved'  => 'badge-success',
                            'closed'    => 'badge-secondary',
                            default     => 'badge-secondary',
                        };
                        ?>
                        <span class="badge <?= $statusBadge ?>"><?= e($r['status']) ?></span>
                    </td>
                    <td class="text-sm text-secondary font-mono"><?= e(substr($r['created_at'] ?? '', 0, 16)) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;">
    <div class="text-sm text-secondary">
        Showing <?= count($rows) ?> of <?= (int) $total ?>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($page > 1): ?>
            <a href="?status=<?= e($statusFilter) ?>&page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm">Prev</a>
        <?php endif; ?>
        <span class="text-sm text-secondary" style="align-self:center;">Page <?= $page ?></span>
        <?php if (count($rows) >= $perPage): ?>
            <a href="?status=<?= e($statusFilter) ?>&page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm">Next</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
