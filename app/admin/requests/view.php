<?php declare(strict_types=1);

/**
 * app/requests/view.php
 *
 * Admin-side single-request detail page. Operator can read the full
 * message + customer/lease/equipment refs + response thread, and respond
 * via inline form (POSTs to api/v1/requests/respond.php).
 *
 * @session  S-PORTAL-REQUEST-ROUTING
 * @gate     require_permission('customers', 'view') for read
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

$canRespond = can('customers', 'edit');
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    require_once FF_ROOT . '/includes/header.php';
    echo '<div class="alert alert-danger">Missing request id.</div>';
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

$req = db_row(
    "SELECT psr.*,
            c.id AS customer_id, c.company_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
            pu.name AS submitted_by_name, pu.email AS submitted_by_email,
            assigned.name AS assigned_to_name,
            l.contract_number, l.status AS lease_status,
            eu.unit_number,
            et.category AS equipment_category
       FROM portal_service_requests psr
  LEFT JOIN customers c ON c.id = psr.customer_id
  LEFT JOIN portal_users pu ON pu.id = psr.portal_user_id
  LEFT JOIN users assigned ON assigned.id = psr.assigned_to
  LEFT JOIN leases l ON l.id = psr.lease_id
  LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
  LEFT JOIN equipment_templates et ON et.id = eu.template_id
      WHERE psr.id = ?",
    [$id]
);

if (!$req) {
    http_response_code(404);
    require_once FF_ROOT . '/includes/header.php';
    echo '<div class="alert alert-danger">Service request #' . (int) $id . ' not found.</div>';
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

$pageTitle = 'Service Request #' . (int) $req['id'];
require_once FF_ROOT . '/includes/header.php';

$typeLabels = \FleetForge\Notifications\PortalRequestNotifier::REQUEST_TYPE_LABELS;
$typeLabel = $typeLabels[$req['request_type']] ?? $req['request_type'];

$statusBadge = match ($req['status']) {
    'open'      => 'badge-warning',
    'in_review' => 'badge-info',
    'resolved'  => 'badge-success',
    'closed'    => 'badge-secondary',
    default     => 'badge-secondary',
};
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('requests') ?>">Service Requests</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">#<?= (int) $req['id'] ?></span>
</nav>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
    <div>
        <h1 class="page-header-title h4">
            <?= e($typeLabel) ?> #<?= (int) $req['id'] ?>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            Created <?= e(substr($req['created_at'] ?? '', 0, 16)) ?>
            <?php if ($req['updated_at'] !== $req['created_at']): ?>
                &middot; Updated <?= e(substr($req['updated_at'] ?? '', 0, 16)) ?>
            <?php endif; ?>
        </div>
    </div>
    <span class="badge <?= $statusBadge ?>" style="font-size:0.875rem;"><?= e($req['status']) ?></span>
</div>

<!-- ── Top: customer + lease + equipment refs ─────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:18px;">
    <div class="card">
        <div class="card-body">
            <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Customer</div>
            <?php if ($req['customer_id']): ?>
                <a href="<?= base_url('customers/show?id=' . (int) $req['customer_id']) ?>" style="font-weight:600;"><?= e($req['customer_name'] ?? '—') ?></a>
                <?php if (!empty($req['customer_email'])): ?>
                    <div class="text-sm text-secondary"><?= e($req['customer_email']) ?></div>
                <?php endif; ?>
                <?php if (!empty($req['customer_phone'])): ?>
                    <div class="text-sm text-secondary"><?= e($req['customer_phone']) ?></div>
                <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Submitted by</div>
            <div style="font-weight:600;"><?= e($req['submitted_by_name'] ?? '—') ?></div>
            <?php if (!empty($req['submitted_by_email'])): ?>
                <div class="text-sm text-secondary"><?= e($req['submitted_by_email']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($req['contract_number'])): ?>
    <div class="card">
        <div class="card-body">
            <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Lease</div>
            <a href="<?= base_url('leases/show?id=' . (int) $req['lease_id']) ?>" style="font-weight:600;"><?= e($req['contract_number']) ?></a>
            <?php if (!empty($req['lease_status'])): ?>
                <div class="text-sm text-secondary"><?= e($req['lease_status']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($req['unit_number'])): ?>
    <div class="card">
        <div class="card-body">
            <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Equipment</div>
            <a href="<?= base_url('equipment/show?id=' . (int) $req['equipment_unit_id']) ?>" style="font-weight:600;">Unit <?= e($req['unit_number']) ?></a>
            <?php if (!empty($req['equipment_category'])): ?>
                <div class="text-sm text-secondary"><?= e($req['equipment_category']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Subject + message ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:18px;">
    <div class="card-header" style="font-weight:600;"><?= e($req['subject']) ?></div>
    <div class="card-body">
        <div style="white-space:pre-wrap;line-height:1.55;"><?= e($req['message']) ?></div>
    </div>
</div>

<!-- ── Response thread + respond form ─────────────────────────── -->
<div class="card" style="margin-bottom:18px;">
    <div class="card-header" style="font-weight:600;">Response</div>
    <div class="card-body">
        <?php if (!empty($req['response'])): ?>
            <div style="background:var(--bg-secondary);padding:12px 14px;border-radius:6px;white-space:pre-wrap;line-height:1.55;margin-bottom:14px;">
                <?= e($req['response']) ?>
            </div>
            <?php if (!empty($req['resolved_at'])): ?>
                <div class="text-xs text-secondary">
                    Resolved <?= e(substr($req['resolved_at'], 0, 16)) ?>
                    <?php if ($req['assigned_to_name']): ?> by <?= e($req['assigned_to_name']) ?><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-sm text-secondary" style="margin-bottom:14px;">No response yet.</div>
        <?php endif; ?>

        <?php if ($canRespond && in_array($req['status'], ['open', 'in_review'], true)): ?>
            <form id="respondForm" method="POST" action="<?= base_url('api/v1/requests/respond') ?>" data-ff-form>
                <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>">

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="response">Your response (optional — leave blank to just flip status)</label>
                    <textarea id="response" name="response" class="form-control" rows="5"
                              placeholder="Reply to the customer..."><?= e($req['response'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control" style="max-width:240px;">
                        <option value="open"      <?= $req['status'] === 'open'      ? 'selected' : '' ?>>Open</option>
                        <option value="in_review" <?= $req['status'] === 'in_review' ? 'selected' : '' ?>>In Review</option>
                        <option value="resolved"  <?= $req['status'] === 'resolved'  ? 'selected' : '' ?>>Resolved</option>
                        <option value="closed"    <?= $req['status'] === 'closed'    ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary btn-md">Save Response</button>
                <a href="<?= base_url('requests') ?>" class="btn btn-secondary btn-md">Back to list</a>
            </form>
        <?php elseif ($req['status'] === 'resolved' || $req['status'] === 'closed'): ?>
            <div class="text-sm text-secondary">Request is <?= e($req['status']) ?>; re-open it from the form below to edit the response.</div>
            <?php if ($canRespond): ?>
                <form method="POST" action="<?= base_url('api/v1/requests/respond') ?>" data-ff-form style="margin-top:8px;">
                    <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>">
                    <input type="hidden" name="status" value="open">
                    <button type="submit" class="btn btn-secondary btn-sm">Re-open</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
