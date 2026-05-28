<?php
declare(strict_types=1);

/**
 * app/portal/requests/view.php
 *
 * Portal service request thread view.
 * Shows initial message + admin response. Customer can close.
 * Trap 8: query filters by portal_customer_id().
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();
$requestId = clean_int($_GET['id'] ?? null);

if (!$requestId) {
    header('Location: ' . base_url('portal/requests'));
    exit;
}

$req = db_row(
    "SELECT psr.*, pu.name AS submitter_name,
            eu.unit_number, l.contract_number,
            u.name AS assigned_name
     FROM portal_service_requests psr
     JOIN portal_users pu ON pu.id = psr.portal_user_id
     LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
     LEFT JOIN leases l ON l.id = psr.lease_id
     LEFT JOIN users u ON u.id = psr.assigned_to
     WHERE psr.id = ? AND psr.customer_id = ?",
    [$requestId, $cid]
);

if (!$req) {
    header('Location: ' . base_url('portal/requests'));
    exit;
}

$justCreated = !empty($_GET['created']);

// Handle "Mark as Closed" action
$_csrfToken = portal_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (portal_verify_csrf($submittedCsrf)) {
        if ($_POST['action'] === 'close' && $req['status'] === 'resolved') {
            db_update('portal_service_requests', [
                'status' => 'closed',
            ], 'id = ? AND customer_id = ?', [$requestId, $cid]);
            header('Location: ' . base_url('portal/requests/view?id=' . $requestId));
            exit;
        }
    }
}

// Re-fetch after possible update
$req = db_row(
    "SELECT psr.*, pu.name AS submitter_name,
            eu.unit_number, l.contract_number,
            u.name AS assigned_name
     FROM portal_service_requests psr
     JOIN portal_users pu ON pu.id = psr.portal_user_id
     LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
     LEFT JOIN leases l ON l.id = psr.lease_id
     LEFT JOIN users u ON u.id = psr.assigned_to
     WHERE psr.id = ? AND psr.customer_id = ?",
    [$requestId, $cid]
);

$statusBadge = match($req['status']) {
    'open'      => 'badge-info',
    'in_review' => 'badge-warning',
    'resolved'  => 'badge-success',
    'closed'    => 'badge-neutral',
    default     => 'badge-neutral',
};

$typeLabel = ucfirst(str_replace('_', ' ', $req['request_type']));

$pageTitle = 'Request #' . $req['id'];
require_once dirname(__DIR__) . '/includes/header.php';
?>

<!-- Success toast for just-created -->
<?php if ($justCreated): ?>
<div class="portal-login-success" style="margin-bottom:16px;">
    Your service request has been submitted successfully. We'll get back to you soon.
</div>
<?php endif; ?>

<!-- Header -->
<div class="portal-detail-header">
    <div>
        <a href="<?= e(base_url('portal/requests')) ?>" class="portal-form-link" style="font-size:0.8125rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
            Back to Requests
        </a>
        <h1 class="portal-detail-title"><?= e($req['subject']) ?></h1>
        <p class="portal-detail-subtitle">
            <span class="badge badge-neutral"><?= e($typeLabel) ?></span>
            &nbsp; <span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst(str_replace('_', ' ', $req['status']))) ?></span>
            &nbsp; Submitted <?= e(format_datetime($req['created_at'])) ?>
        </p>
    </div>
    <div class="portal-detail-actions">
        <?php if ($req['status'] === 'resolved'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e($_csrfToken) ?>">
                <input type="hidden" name="action" value="close">
                <button type="submit" class="btn btn-secondary btn-sm">Mark as Closed</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Request Details -->
<div class="portal-detail-grid" style="grid-template-columns: 1fr 300px;">

    <!-- Thread -->
    <?php
    // Portal viewers never see internal-flagged messages.
    $thread = \FleetForge\Requests\RequestMessageService::fetchThread((int) $req['id'], false);
    ?>
    <div class="portal-section">
        <div class="portal-section-header">
            <h2 class="portal-section-title">Conversation</h2>
        </div>
        <div class="portal-thread">

            <!-- Original message (the customer's first submission) -->
            <div class="portal-thread-message portal-thread-message--customer">
                <div class="portal-thread-author">
                    <?= e($req['submitter_name']) ?>
                    <span class="portal-thread-time"><?= e(format_datetime($req['created_at'])) ?></span>
                </div>
                <div style="white-space:pre-wrap;"><?= e($req['message']) ?></div>
            </div>

            <!-- Thread of replies (admin + customer alternating) -->
            <?php foreach ($thread as $msg):
                $isAdmin = $msg['sender_type'] === 'admin';
            ?>
            <div class="portal-thread-message portal-thread-message--<?= $isAdmin ? 'admin' : 'customer' ?>">
                <div class="portal-thread-author">
                    <?= e($msg['sender_label']) ?>
                    <?php if ($isAdmin): ?>
                        <span class="badge badge-info" style="font-size:0.6rem;padding:1px 6px;">Staff</span>
                    <?php endif; ?>
                    <span class="portal-thread-time"><?= e(format_datetime($msg['created_at'])) ?></span>
                </div>
                <div style="white-space:pre-wrap;"><?= e($msg['body']) ?></div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($thread) && in_array($req['status'], ['open', 'in_review'], true)): ?>
            <div style="text-align:center;padding:18px;color:var(--text-muted);font-size:0.8125rem;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;margin-bottom:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <p>Awaiting response from our team. We typically respond within 1 business day.</p>
            </div>
            <?php endif; ?>

        </div>

        <!-- ── Portal-side reply form (Alpine + FF_Api so CSRF header injects) ── -->
        <?php if (in_array($req['status'], ['open', 'in_review', 'resolved', 'closed'], true)): ?>
        <div x-data="portalReplyForm(<?= (int) $req['id'] ?>)" style="margin-top:16px;border-top:1px solid var(--border-color);padding-top:16px;">

            <div x-show="flash.message" x-cloak
                 :class="flash.type === 'success' ? 'portal-login-success' : 'portal-login-error'"
                 style="margin-bottom:12px;"
                 x-text="flash.message"></div>

            <div class="portal-form-group" style="margin-bottom:10px;">
                <label class="portal-form-label" for="portal-reply-body">Reply</label>
                <textarea id="portal-reply-body" x-model="body"
                          class="portal-form-input portal-form-textarea"
                          placeholder="Type your reply..."
                          rows="4"></textarea>
            </div>
            <button type="button" class="btn btn-primary btn-md"
                    @click="send()" :disabled="sending || !body.trim()">
                <span x-show="!sending">Send Reply</span>
                <span x-show="sending" x-cloak>Sending…</span>
            </button>
            <?php if (in_array($req['status'], ['resolved', 'closed'], true)): ?>
                <span class="text-xs text-muted" style="margin-left:10px;">
                    Your reply will re-open this request.
                </span>
            <?php endif; ?>
        </div>

        <script>
        function portalReplyForm(requestId) {
            return {
                requestId,
                body: '',
                sending: false,
                flash: { type: '', message: '' },
                async send() {
                    if (!this.body.trim()) return;
                    this.sending = true;
                    this.flash = { type: '', message: '' };
                    try {
                        const r = await FF_Api.post(
                            FF_Api.url('/api/v1/portal/requests/reply.php'),
                            { request_id: this.requestId, body: this.body }
                        );
                        if (r.success) {
                            this.flash = { type: 'success', message: 'Reply sent. Reloading…' };
                            setTimeout(() => window.location.reload(), 900);
                        } else {
                            this.flash = { type: 'danger', message: r.error?.message || 'Send failed.' };
                        }
                    } catch (e) {
                        this.flash = { type: 'danger', message: 'Send failed: ' + (e.message || e) };
                    } finally {
                        this.sending = false;
                    }
                }
            };
        }
        </script>
        <?php endif; ?>
    </div>

    <!-- Sidebar Info -->
    <div>
        <div class="portal-info-card" style="margin-bottom:16px;">
            <div class="portal-info-card-header">Request Details</div>
            <ul class="portal-info-list">
                <li><span class="portal-info-label">Request #</span><span class="portal-info-value font-mono"><?= e((string)$req['id']) ?></span></li>
                <li><span class="portal-info-label">Type</span><span class="portal-info-value"><?= e($typeLabel) ?></span></li>
                <li><span class="portal-info-label">Status</span><span class="portal-info-value"><span class="badge <?= e($statusBadge) ?>"><?= e(ucfirst(str_replace('_', ' ', $req['status']))) ?></span></span></li>
                <li><span class="portal-info-label">Submitted</span><span class="portal-info-value font-mono" style="font-size:0.75rem;"><?= e(format_datetime($req['created_at'])) ?></span></li>
                <?php if ($req['contract_number']): ?>
                <li>
                    <span class="portal-info-label">Lease</span>
                    <span class="portal-info-value">
                        <a href="<?= e(base_url('portal/leases/view?id=' . $req['lease_id'])) ?>" class="portal-table-link"><?= e($req['contract_number']) ?></a>
                    </span>
                </li>
                <?php endif; ?>
                <?php if ($req['unit_number']): ?>
                <li><span class="portal-info-label">Unit</span><span class="portal-info-value"><?= e($req['unit_number']) ?></span></li>
                <?php endif; ?>
                <?php if ($req['assigned_name']): ?>
                <li><span class="portal-info-label">Assigned To</span><span class="portal-info-value"><?= e($req['assigned_name']) ?></span></li>
                <?php endif; ?>
                <?php if ($req['resolved_at']): ?>
                <li><span class="portal-info-label">Resolved</span><span class="portal-info-value font-mono" style="font-size:0.75rem;"><?= e(format_datetime($req['resolved_at'])) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

</div>

<style>
@media (max-width: 768px) {
    .portal-detail-grid[style*="300px"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
