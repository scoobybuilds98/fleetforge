<?php
declare(strict_types=1);

/**
 * app/portal/requests/index.php
 *
 * Portal service request list.
 * Trap 8: all queries filter by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

$requests = db_select(
    "SELECT psr.id, psr.request_type, psr.subject, psr.status,
            psr.created_at, psr.updated_at, psr.resolved_at
     FROM portal_service_requests psr
     WHERE psr.customer_id = ?
     ORDER BY psr.created_at DESC
     LIMIT 100",
    [$cid]
);

$pageTitle = 'Service Requests';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <div style="font-size:0.875rem;color:var(--text-secondary);">
        <?= e(count($requests)) ?> request<?= count($requests) !== 1 ? 's' : '' ?>
    </div>
    <a href="<?= e(base_url('portal/requests/create')) ?>" class="btn btn-primary btn-sm">
        New Request
    </a>
</div>

<div class="portal-section">
    <div class="portal-section-body--flush">
        <?php if (empty($requests)): ?>
            <div class="portal-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                <p class="portal-empty-title">No service requests</p>
                <p class="portal-empty-text">Submit a request for lease extensions, billing inquiries, damage reports, and more.</p>
            </div>
        <?php else: ?>
            <table class="portal-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <?php
                    $badgeClass = match($r['status']) {
                        'open'      => 'badge-info',
                        'in_review' => 'badge-warning',
                        'resolved'  => 'badge-success',
                        'closed'    => 'badge-neutral',
                        default     => 'badge-neutral',
                    };
                    $typeLabel = ucfirst(str_replace('_', ' ', $r['request_type']));
                    ?>
                    <tr>
                        <td class="font-mono">#<?= e((string)$r['id']) ?></td>
                        <td><span class="badge badge-neutral"><?= e($typeLabel) ?></span></td>
                        <td>
                            <a href="<?= e(base_url('portal/requests/view?id=' . $r['id'])) ?>" class="portal-table-link">
                                <?= e($r['subject']) ?>
                            </a>
                        </td>
                        <td><span class="badge <?= e($badgeClass) ?>"><?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?></span></td>
                        <td class="font-mono"><?= e(format_datetime($r['created_at'])) ?></td>
                        <td class="font-mono"><?= e(format_datetime($r['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
