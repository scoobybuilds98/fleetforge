<?php
declare(strict_types=1);

/**
 * app/portal/requests/view.php
 *
 * Customer portal — one request as a conversation (S-PORTAL-REDESIGN).
 *
 * The original message, then every non-internal reply
 * (RequestMessageService::fetchThread — internal staff notes never show),
 * a composer that posts to api/v1/portal/requests/reply (a reply to a
 * resolved/closed request re-opens it), and "Close request" once resolved.
 *
 * Fixed here: requests answered before the thread table existed kept their
 * staff reply only in psr.response, which this page never showed — the
 * customer saw "awaiting response" forever. It now shows as the staff reply
 * when the thread has no staff message. The request row is read once (was
 * twice), and the close action is audited.
 *
 * Trap 8: the request must belong to portal_customer_id().
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

use FleetForge\Requests\RequestMessageService;

$cid       = portal_customer_id();
$requestId = clean_int($_GET['id'] ?? null);
if (!$requestId) {
    header('Location: ' . pt_url('requests'));
    exit;
}

$load = static fn () => db_row(
    "SELECT psr.*, pu.name AS submitter_name, eu.unit_number, l.contract_number, l.id AS lease_ref,
            u.name AS assigned_name
       FROM portal_service_requests psr
       LEFT JOIN portal_users pu ON pu.id = psr.portal_user_id
       LEFT JOIN equipment_units eu ON eu.id = psr.equipment_unit_id
       LEFT JOIN leases l ON l.id = psr.lease_id
       LEFT JOIN users u ON u.id = psr.assigned_to
      WHERE psr.id = ? AND psr.customer_id = ?",
    [$requestId, $cid]
);

$req = $load();
if (!$req) {
    header('Location: ' . pt_url('requests'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close'
    && portal_verify_csrf((string) ($_POST['csrf_token'] ?? '')) && $req['status'] === 'resolved') {
    db_update('portal_service_requests', ['status' => 'closed'], 'id = ? AND customer_id = ?', [$requestId, $cid]);
    try {
        db_insert('audit_log', [
            'user_id' => null, 'user_name' => 'portal:' . portal_user_id(), 'action' => 'update',
            'module' => 'portal', 'entity_type' => 'portal_service_request', 'entity_id' => $requestId,
            'entity_label' => mb_substr((string) $req['subject'], 0, 255), 'notes' => 'Customer closed the resolved request.',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    } catch (\Throwable $e) {
        error_log('[portal/requests/view close audit] ' . $e->getMessage());
    }
    header('Location: ' . pt_url('requests/view?id=' . $requestId . '&closed=1'));
    exit;
}

$thread = RequestMessageService::fetchThread($requestId, false);
$hasStaffMsg = (bool) array_filter($thread, static fn ($m) => $m['sender_type'] === 'admin');
if (!$hasStaffMsg && trim((string) ($req['response'] ?? '')) !== '') {
    // Legacy single reply (pre S-PORTAL-REQUEST-THREAD) — show it where it belongs.
    $thread[] = [
        'id' => 0, 'sender_type' => 'admin', 'sender_label' => (string) ($req['assigned_name'] ?: 'Our team'),
        'body' => (string) $req['response'], 'is_internal' => 0,
        'created_at' => (string) ($req['resolved_at'] ?: $req['updated_at']),
    ];
}

$types = pt_request_types();
[$typeLabel, $typeIcon] = [$types[$req['request_type']][0] ?? 'Request', $types[$req['request_type']][1] ?? 'chat-bubble-left-ellipsis'];
[$sl, $st] = match ($req['status']) {
    'open'      => ['Open', 'info'],
    'in_review' => ['In progress', 'warning'],
    'resolved'  => ['Resolved', 'success'],
    'closed'    => ['Closed', 'neutral'],
    default     => [ucfirst((string) $req['status']), 'neutral'],
};
$me = portal_user_id();

$pageTitle = $req['subject'];
require_once dirname(__DIR__) . '/includes/header.php';

$actions = '';
if ($req['status'] === 'resolved') {
    $actions = '<form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="' . e(portal_csrf_token()) . '"><input type="hidden" name="action" value="close">'
        . '<button type="submit" class="pt-btn pt-btn--secondary">' . pt_icon('check-circle') . ' Close request</button></form>';
}
echo pt_page_head([
    'back'    => ['Requests', pt_url('requests')],
    'title'   => (string) $req['subject'],
    'meta'    => pt_badge($sl, $st) . '<span>' . e($typeLabel) . '</span><span>#' . (int) $req['id'] . ' · opened ' . e(format_datetime($req['created_at'], 'M j, Y')) . '</span>',
    'actions' => $actions,
]);
?>

<?php if (!empty($_GET['created'])): ?>
    <div class="pt-note pt-note--success" style="margin-bottom:18px"><?= pt_icon('check-circle') ?><span><strong>Request sent.</strong> Our team has been notified — you'll see their reply here and get a notification.</span></div>
<?php elseif (!empty($_GET['closed'])): ?>
    <div class="pt-note pt-note--success" style="margin-bottom:18px"><?= pt_icon('check-circle') ?><span>Request closed. Thanks for letting us know.</span></div>
<?php endif; ?>

<div class="pt-grid pt-grid--main">
    <section class="pt-card">
        <div class="pt-thread">
            <div class="pt-msg pt-msg--<?= (int) $req['portal_user_id'] === $me ? 'me' : 'them' ?>">
                <span class="pt-avatar"><?= e(pt_initials((string) ($req['submitter_name'] ?: 'You'))) ?></span>
                <div>
                    <div class="pt-msg-meta"><strong><?= e((int) $req['portal_user_id'] === $me ? 'You' : (string) $req['submitter_name']) ?></strong><span><?= e(format_datetime($req['created_at'], 'M j, g:i A')) ?></span></div>
                    <div class="pt-msg-bubble"><?= e((string) $req['message']) ?></div>
                </div>
            </div>
            <?php foreach ($thread as $m):
                $staff = $m['sender_type'] === 'admin';
            ?>
                <div class="pt-msg pt-msg--<?= $staff ? 'them' : 'me' ?>">
                    <span class="pt-avatar" style="<?= $staff ? 'background:var(--bg-surface-2);color:var(--text-secondary)' : '' ?>"><?= e(pt_initials($m['sender_label'])) ?></span>
                    <div>
                        <div class="pt-msg-meta"><strong><?= e($staff ? $m['sender_label'] : 'You') ?></strong><?php if ($staff): ?><span class="pt-tag" style="height:18px;font-size:11px">Staff</span><?php endif; ?><span><?= e(format_datetime($m['created_at'], 'M j, g:i A')) ?></span></div>
                        <div class="pt-msg-bubble"><?= e($m['body']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$thread && in_array($req['status'], ['open', 'in_review'], true)): ?>
                <div class="pt-note" style="align-self:center"><?= pt_icon('clock') ?><span>Our team has your request. Their reply will appear here.</span></div>
            <?php endif; ?>
        </div>

        <form class="pt-composer" x-data="{
                body: '', sending: false, error: '',
                async send() {
                    this.error = '';
                    if (!this.body.trim()) return;
                    this.sending = true;
                    const r = await PT.post('api/v1/portal/requests/reply', { request_id: <?= (int) $requestId ?>, body: this.body });
                    if (r && r.success) { window.location.reload(); return; }
                    this.sending = false;
                    this.error = (r && r.error && r.error.message) || 'Your reply could not be sent. Please try again.';
                }
            }" @submit.prevent="send()">
            <label class="pt-label" for="rq-reply"><?= in_array($req['status'], ['resolved', 'closed'], true) ? 'Something else? Replying re-opens this request' : 'Reply' ?></label>
            <textarea id="rq-reply" class="pt-textarea" rows="3" maxlength="5000" x-model="body" placeholder="Write a reply…" @keydown.meta.enter="send()" @keydown.ctrl.enter="send()"></textarea>
            <div class="pt-note pt-note--danger" x-show="error" x-cloak><?= pt_icon('exclamation-triangle') ?><span x-text="error"></span></div>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                <span class="pt-hint">⌘/Ctrl + Enter to send</span>
                <button type="submit" class="pt-btn pt-btn--primary" :disabled="sending || !body.trim()"><span class="pt-spin" x-show="sending" x-cloak></span><?= pt_icon('paper-airplane') ?> Send</button>
            </div>
        </form>
    </section>

    <aside class="pt-stack">
        <section class="pt-card">
            <div class="pt-card-head"><h2 class="pt-card-title"><?= pt_icon($typeIcon) ?> Details</h2></div>
            <div class="pt-card-body" style="padding-top:6px">
                <dl class="pt-kv">
                    <div class="pt-kv-row"><dt>Status</dt><dd><?= pt_badge($sl, $st) ?></dd></div>
                    <div class="pt-kv-row"><dt>Type</dt><dd><?= e($typeLabel) ?></dd></div>
                    <?php if ($req['contract_number']): ?><div class="pt-kv-row"><dt>Lease</dt><dd><a class="pt-link" href="<?= e(pt_url('leases/view?id=' . (int) $req['lease_ref'])) ?>"><?= e($req['contract_number']) ?></a></dd></div><?php endif; ?>
                    <?php if ($req['unit_number']): ?><div class="pt-kv-row"><dt>Unit</dt><dd><?= e($req['unit_number']) ?></dd></div><?php endif; ?>
                    <div class="pt-kv-row"><dt>Opened by</dt><dd><?= e((string) ($req['submitter_name'] ?: '—')) ?></dd></div>
                    <?php if ($req['assigned_name']): ?><div class="pt-kv-row"><dt>Handled by</dt><dd><?= e($req['assigned_name']) ?></dd></div><?php endif; ?>
                    <?php if ($req['resolved_at']): ?><div class="pt-kv-row"><dt>Resolved</dt><dd><?= e(format_datetime($req['resolved_at'], 'M j, Y')) ?></dd></div><?php endif; ?>
                </dl>
            </div>
        </section>
    </aside>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
