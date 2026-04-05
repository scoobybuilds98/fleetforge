<?php
declare(strict_types=1);

/**
 * app/portal/index.php
 *
 * Portal Dashboard — customer overview with KPIs, active leases,
 * outstanding invoices, and recent activity.
 *
 * Data isolation: every query filters by portal_customer_id() (Trap 8).
 */

require_once __DIR__ . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// ── KPI Data ────────────────────────────────────────────────
$activeLeases = db_count(
    "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
    [$cid]
);

$outstanding = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total, COUNT(*) AS cnt
     FROM invoices WHERE customer_id = ? AND status IN ('sent','partially_paid','overdue') AND deleted_at IS NULL",
    [$cid]
);
$outstandingBalance = $outstanding['total'] ?? '0.00';
$outstandingCount   = (int) ($outstanding['cnt'] ?? 0);

// Equipment count — join through leases (Trap 8: equipment_units has no customer_id)
$unitsOut = db_count(
    "SELECT COUNT(DISTINCT eu.id) FROM equipment_units eu
     JOIN leases l ON eu.id = l.equipment_unit_id
     WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL AND eu.deleted_at IS NULL",
    [$cid]
);

// Documents expiring in 30 days
$docsExpiring = db_count(
    "SELECT COUNT(*) FROM documents
     WHERE entity_type = 'customer' AND entity_id = ?
     AND expiration_date IS NOT NULL AND expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY
     AND deleted_at IS NULL",
    [$cid]
);

// ── Active Leases (compact list) ────────────────────────────
$leases = db_select(
    "SELECT l.id, l.contract_number, l.start_date, l.status,
            l.monthly_rate,
            eu.unit_number, et.brand, et.model, et.category
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id
     JOIN equipment_templates et ON et.id = eu.template_id
     WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL
     AND eu.deleted_at IS NULL AND et.deleted_at IS NULL
     ORDER BY l.start_date DESC
     LIMIT 10",
    [$cid]
);

// ── Outstanding Invoices ────────────────────────────────────
$invoices = db_select(
    "SELECT id, invoice_number, invoice_date, due_date, total_amount, balance_due, status
     FROM invoices
     WHERE customer_id = ? AND status IN ('sent','partially_paid','overdue') AND deleted_at IS NULL
     ORDER BY due_date ASC
     LIMIT 10",
    [$cid]
);

// ── Recent Activity ─────────────────────────────────────────
$activity = [];
try {
    // Recent invoices
    $recentInvoices = db_select(
        "SELECT 'invoice' AS type, invoice_number AS label, created_at, status
         FROM invoices WHERE customer_id = ? AND deleted_at IS NULL
         ORDER BY created_at DESC LIMIT 5",
        [$cid]
    );
    foreach ($recentInvoices as $r) {
        $activity[] = [
            'type'  => 'invoice',
            'label' => "Invoice {$r['label']} " . ($r['status'] === 'paid' ? 'paid' : 'generated'),
            'time'  => $r['created_at'],
            'icon'  => $r['status'] === 'paid' ? 'green' : 'blue',
        ];
    }

    // Recent payments
    $recentPayments = db_select(
        "SELECT p.amount, p.payment_date, p.reference_number
         FROM payments p
         WHERE p.customer_id = ? AND p.deleted_at IS NULL
         ORDER BY p.payment_date DESC LIMIT 5",
        [$cid]
    );
    foreach ($recentPayments as $r) {
        $activity[] = [
            'type'  => 'payment',
            'label' => 'Payment received' . ($r['reference_number'] ? " ({$r['reference_number']})" : '') . ' — ' . format_currency($r['amount']),
            'time'  => $r['payment_date'],
            'icon'  => 'green',
        ];
    }

    // Recent service requests
    $recentRequests = db_select(
        "SELECT subject, created_at, status
         FROM portal_service_requests WHERE customer_id = ?
         ORDER BY created_at DESC LIMIT 3",
        [$cid]
    );
    foreach ($recentRequests as $r) {
        $activity[] = [
            'type'  => 'request',
            'label' => "Service request: {$r['subject']}",
            'time'  => $r['created_at'],
            'icon'  => $r['status'] === 'resolved' ? 'green' : 'amber',
        ];
    }

    // Sort by time descending
    usort($activity, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
    $activity = array_slice($activity, 0, 8);
} catch (Throwable) {}

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- KPI Row -->
<div class="portal-kpi-grid">
    <a href="<?= e(base_url('portal/leases')) ?>" class="portal-kpi-card portal-kpi-card--blue">
        <span class="portal-kpi-label">Active Leases</span>
        <span class="portal-kpi-value"><?= e((string) $activeLeases) ?></span>
        <span class="portal-kpi-sub">Currently active</span>
    </a>
    <a href="<?= e(base_url('portal/invoices?tab=outstanding')) ?>" class="portal-kpi-card portal-kpi-card--red">
        <span class="portal-kpi-label">Outstanding Balance</span>
        <span class="portal-kpi-value"><?= e(format_currency($outstandingBalance)) ?></span>
        <span class="portal-kpi-sub"><?= e((string) $outstandingCount) ?> unpaid invoice<?= $outstandingCount !== 1 ? 's' : '' ?></span>
    </a>
    <a href="<?= e(base_url('portal/equipment')) ?>" class="portal-kpi-card portal-kpi-card--green">
        <span class="portal-kpi-label">Units Currently Out</span>
        <span class="portal-kpi-value"><?= e((string) $unitsOut) ?></span>
        <span class="portal-kpi-sub">Equipment on lease</span>
    </a>
    <a href="<?= e(base_url('portal/documents')) ?>" class="portal-kpi-card portal-kpi-card--amber">
        <span class="portal-kpi-label">Docs Expiring Soon</span>
        <span class="portal-kpi-value"><?= e((string) $docsExpiring) ?></span>
        <span class="portal-kpi-sub">Within 30 days</span>
    </a>
</div>

<!-- Two-column layout: Leases + Invoices -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- Active Leases -->
    <div class="portal-section">
        <div class="portal-section-header">
            <h2 class="portal-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                Active Leases
            </h2>
            <a href="<?= e(base_url('portal/leases')) ?>" class="portal-form-link" style="font-size:0.8125rem;">View all</a>
        </div>
        <div class="portal-section-body--flush">
            <?php if (empty($leases)): ?>
                <div class="portal-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                    <p class="portal-empty-title">No active leases</p>
                    <p class="portal-empty-text">Contact us to set up a new lease.</p>
                </div>
            <?php else: ?>
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Contract</th>
                            <th>Unit</th>
                            <th>Start</th>
                            <th class="text-right">Monthly</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leases as $l): ?>
                        <tr>
                            <td>
                                <a href="<?= e(base_url('portal/leases/view?id=' . $l['id'])) ?>" class="portal-table-link">
                                    <?= e($l['contract_number']) ?>
                                </a>
                            </td>
                            <td><?= e($l['unit_number']) ?> <span style="color:var(--text-muted);font-size:0.75rem;"><?= e($l['category']) ?></span></td>
                            <td class="font-mono"><?= e(format_date($l['start_date'])) ?></td>
                            <td class="text-right font-mono"><?= e(format_currency($l['monthly_rate'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Outstanding Invoices -->
    <div class="portal-section">
        <div class="portal-section-header">
            <h2 class="portal-section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>
                Outstanding Invoices
            </h2>
            <a href="<?= e(base_url('portal/invoices')) ?>" class="portal-form-link" style="font-size:0.8125rem;">View all</a>
        </div>
        <div class="portal-section-body--flush">
            <?php if (empty($invoices)): ?>
                <div class="portal-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <p class="portal-empty-title">All caught up!</p>
                    <p class="portal-empty-text">No outstanding invoices.</p>
                </div>
            <?php else: ?>
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Due Date</th>
                            <th class="text-right">Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $inv): ?>
                        <?php
                            $isOverdue = $inv['status'] === 'overdue';
                            $badgeClass = match($inv['status']) {
                                'overdue'        => 'badge-danger',
                                'sent'           => 'badge-info',
                                'partially_paid' => 'badge-warning',
                                default          => 'badge-neutral',
                            };
                        ?>
                        <tr>
                            <td>
                                <a href="<?= e(base_url('portal/invoices/view?id=' . $inv['id'])) ?>" class="portal-table-link">
                                    <?= e($inv['invoice_number']) ?>
                                </a>
                            </td>
                            <td class="font-mono" <?= $isOverdue ? 'style="color:var(--color-danger);font-weight:600;"' : '' ?>>
                                <?= e(format_date($inv['due_date'])) ?>
                            </td>
                            <td class="text-right font-mono" style="font-weight:600;">
                                <?= e(format_currency($inv['balance_due'])) ?>
                            </td>
                            <td>
                                <span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst(str_replace('_', ' ', $inv['status']))) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Recent Activity -->
<div class="portal-section">
    <div class="portal-section-header">
        <h2 class="portal-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            Recent Activity
        </h2>
    </div>
    <div class="portal-section-body--flush">
        <?php if (empty($activity)): ?>
            <div class="portal-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <p class="portal-empty-title">No recent activity</p>
                <p class="portal-empty-text">Activity will appear here as invoices are generated and payments received.</p>
            </div>
        <?php else: ?>
            <ul class="portal-activity-list">
                <?php foreach ($activity as $a): ?>
                <li class="portal-activity-item">
                    <div class="portal-activity-icon portal-activity-icon--<?= e($a['icon']) ?>">
                        <?php if ($a['type'] === 'payment'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <?php elseif ($a['type'] === 'invoice'): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                        <?php else: ?>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="portal-activity-text">
                        <div class="portal-activity-desc"><?= e($a['label']) ?></div>
                        <div class="portal-activity-time"><?= e(format_datetime($a['time'])) ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<style>
/* Dashboard responsive: stack two-column on mobile */
@media (max-width: 768px) {
    .portal-content > div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
