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

<!-- ── Reply thread (S-PORTAL-REQUEST-THREAD) ──────────────────────── -->
<?php
$thread = \FleetForge\Requests\RequestMessageService::fetchThread((int) $req['id'], true);
?>
<div class="card" style="margin-bottom:18px;">
    <div class="card-header" style="font-weight:600;display:flex;align-items:center;justify-content:space-between;">
        <span>Conversation</span>
        <span class="text-xs text-secondary" style="font-weight:400;"><?= count($thread) ?> message<?= count($thread) === 1 ? '' : 's' ?></span>
    </div>
    <div class="card-body">

        <!-- ── Original request as first thread item ──────────────────── -->
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:14px;">
            <div style="flex:0 0 auto;width:36px;height:36px;border-radius:50%;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--text-secondary);font-size:0.825rem;">
                <?= e(strtoupper(substr((string) ($req['submitted_by_name'] ?? 'C'), 0, 1))) ?>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.825rem;color:var(--text-secondary);margin-bottom:4px;">
                    <strong><?= e($req['submitted_by_name'] ?? 'Customer') ?></strong>
                    <span class="badge badge-info" style="margin-left:6px;font-size:0.7rem;">Customer</span>
                    <span style="margin-left:8px;"><?= e(substr($req['created_at'] ?? '', 0, 16)) ?></span>
                </div>
                <div style="background:var(--bg-secondary);padding:10px 14px;border-radius:8px;white-space:pre-wrap;line-height:1.55;font-size:0.9rem;">
                    <?= e($req['message']) ?>
                </div>
            </div>
        </div>

        <?php if (empty($thread)): ?>
            <div class="text-sm text-secondary" style="text-align:center;padding:14px 0;">No replies yet. Type below to start the conversation.</div>
        <?php else: foreach ($thread as $msg):
            $isAdmin = $msg['sender_type'] === 'admin';
            $align = $isAdmin ? 'row-reverse' : 'row';
            $bg = $isAdmin ? 'var(--accent-color, #2563eb)' : 'var(--bg-secondary)';
            $color = $isAdmin ? '#fff' : 'inherit';
            $initial = strtoupper(substr($msg['sender_label'], 0, 1));
        ?>
        <div style="display:flex;flex-direction:<?= $align ?>;gap:10px;align-items:flex-start;margin-bottom:12px;">
            <div style="flex:0 0 auto;width:36px;height:36px;border-radius:50%;background:<?= $isAdmin ? '#2563eb' : 'var(--bg-secondary)' ?>;color:<?= $isAdmin ? '#fff' : 'var(--text-secondary)' ?>;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:0.825rem;">
                <?= e($initial) ?>
            </div>
            <div style="flex:1;min-width:0;<?= $isAdmin ? 'text-align:right;' : '' ?>">
                <div style="font-size:0.825rem;color:var(--text-secondary);margin-bottom:4px;">
                    <strong><?= e($msg['sender_label']) ?></strong>
                    <span class="badge <?= $isAdmin ? 'badge-primary' : 'badge-info' ?>" style="margin-left:6px;font-size:0.7rem;">
                        <?= $isAdmin ? 'Staff' : 'Customer' ?>
                    </span>
                    <?php if ($msg['is_internal']): ?>
                        <span class="badge badge-warning" style="margin-left:4px;font-size:0.7rem;">Internal</span>
                    <?php endif; ?>
                    <span style="margin-left:8px;"><?= e(substr($msg['created_at'], 0, 16)) ?></span>
                </div>
                <div style="background:<?= $bg ?>;color:<?= $color ?>;padding:10px 14px;border-radius:8px;white-space:pre-wrap;line-height:1.55;font-size:0.9rem;display:inline-block;max-width:100%;text-align:left;">
                    <?= e($msg['body']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>

        <!-- ── Reply form (Alpine + FF_Api so X-CSRF-Token injects) ───── -->
        <?php if ($canRespond): ?>
            <hr style="margin:18px 0 14px;border:none;border-top:1px solid var(--border-color);">
            <div x-data="adminReplyForm(<?= (int) $req['id'] ?>, '<?= e($req['status']) ?>')">

                <div x-show="flash.message" x-cloak
                     :class="flash.type === 'success' ? 'alert alert-success' : 'alert alert-danger'"
                     style="margin-bottom:12px;"
                     x-text="flash.message"></div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label" for="reply-body">Your reply</label>
                    <textarea id="reply-body" x-model="body" class="form-control" rows="4"
                              placeholder="Type your reply to the customer..."></textarea>
                </div>

                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" for="reply-status" style="margin-bottom:4px;">Status after send</label>
                        <select id="reply-status" x-model="status" class="form-control" style="max-width:200px;">
                            <option value="open">Open</option>
                            <option value="in_review">In Review</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                </div>

                <button type="button" class="btn btn-primary btn-md" @click="save()" :disabled="saving || (!body.trim() && status === initialStatus)">
                    <span x-show="!saving">Send Reply</span>
                    <span x-show="saving" x-cloak>Sending…</span>
                </button>
                <a href="<?= base_url('requests') ?>" class="btn btn-secondary btn-md">Back to list</a>
                <span class="text-xs text-secondary" style="margin-left:8px;" x-show="!body.trim() && status === initialStatus" x-cloak>
                    Type a reply or change the status to enable.
                </span>
            </div>
        <?php endif; ?>

        <script>
        function adminReplyForm(requestId, initialStatus) {
            return {
                requestId,
                body: '',
                status: initialStatus,
                initialStatus,
                saving: false,
                flash: { type: '', message: '' },
                async save() {
                    if (!this.body.trim() && this.status === this.initialStatus) {
                        this.flash = { type: 'danger', message: 'Type a reply or change the status before sending.' };
                        return;
                    }
                    this.saving = true;
                    this.flash = { type: '', message: '' };
                    try {
                        const r = await FF_Api.post(
                            FF_Api.url('/api/v1/requests/respond.php'),
                            { id: this.requestId, response: this.body, status: this.status }
                        );
                        if (r.success) {
                            this.flash = {
                                type: 'success',
                                message: r.data?.customer_notified === true
                                    ? 'Reply sent. Customer notified. Reloading…'
                                    : 'Saved. Reloading…'
                            };
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            this.flash = { type: 'danger', message: r.error?.message || 'Send failed.' };
                        }
                    } catch (e) {
                        this.flash = { type: 'danger', message: 'Send failed: ' + (e.message || e) };
                    } finally {
                        this.saving = false;
                    }
                }
            };
        }
        </script>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
