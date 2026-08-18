<?php declare(strict_types=1);

/**
 * app/admin/quickbooks/drift_show.php
 *
 * Individual drift event profile. Shows all details from
 * acc_qbo_drift_events + inline fix actions (resolve / accept /
 * suppress / resync / reopen) that POST to
 * api/v1/quickbooks/drift_resolve.php.
 *
 * @param  ?id  int — acc_qbo_drift_events.id (required; 404 otherwise)
 * @auth   require_permission('quickbooks', 'view')
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/quickbooks/drift_resolve.php
 * @session S-DRIFT-SHOW
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('quickbooks', 'view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    require FF_ROOT . '/app/errors/404.php';
    exit;
}

$event = db_row(
    "SELECT d.*, u.name AS resolver_name
       FROM acc_qbo_drift_events d
       LEFT JOIN users u ON u.id = d.resolved_by_user_id
      WHERE d.id = ?",
    [$id]
);

if (!$event) {
    http_response_code(404);
    require FF_ROOT . '/app/errors/404.php';
    exit;
}

$canEdit = can('quickbooks', 'edit_credentials');
$isOpen  = $event['resolved_at'] === null;

function drift_show_category_label(string $cat): string
{
    return match ($cat) {
        'push_failed'             => 'Push Failed',
        'pull_failed'             => 'Pull Failed',
        'amount_drift'            => 'Amount Drift',
        'balance_drift'           => 'Balance Drift',
        'count_mismatch'          => 'Count Mismatch',
        'field_mismatch'          => 'Field Mismatch',
        'missing_in_qbo'          => 'Missing in QBO',
        'missing_in_ff'           => 'Missing in FF',
        'stale_object_unresolved' => 'Stale Object',
        default                   => ucwords(str_replace('_', ' ', $cat)),
    };
}

function drift_show_severity_class(string $cat): string
{
    return match ($cat) {
        'push_failed', 'pull_failed', 'missing_in_qbo', 'missing_in_ff' => 'badge-danger',
        'amount_drift', 'balance_drift', 'count_mismatch', 'field_mismatch' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function drift_show_state_badge(array $ev): string
{
    if ($ev['resolved_at'] === null) {
        return '<span class="badge badge-danger">Open</span>';
    }
    return match ((string) ($ev['resolution_type'] ?? '')) {
        'resolved'   => '<span class="badge badge-success">Resolved</span>',
        'accepted'   => '<span class="badge badge-neutral">Accepted</span>',
        'suppressed' => '<span class="badge badge-neutral">Suppressed</span>',
        default      => '<span class="badge badge-neutral">Closed</span>',
    };
}

$pageTitle = 'Drift Event #' . $id;
require_once FF_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/dashboard') ?>">QuickBooks</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('quickbooks/drift') ?>">Drift</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Event #<?= $id ?></span>
</nav>

<?php require_once FF_ROOT . '/includes/partials/quickbooks-nav.php'; ?>

<div x-data="driftShow()">

<!-- flash -->
<div x-show="flash.msg" x-cloak
     :class="flash.ok ? 'alert alert-success' : 'alert alert-danger'"
     style="margin-bottom:14px;" x-text="flash.msg"></div>

<!-- ── Header ─────────────────────────────────────────────────────── -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <h1 class="page-header-title h4" style="margin:0;">Drift Event #<?= $id ?></h1>
            <span class="badge <?= e(drift_show_severity_class((string)$event['category'])) ?>">
                <?= e(drift_show_category_label((string)$event['category'])) ?>
            </span>
            <?= drift_show_state_badge($event) ?>
        </div>
        <div class="text-secondary text-sm" style="margin-top:6px;">
            <?= e(ucwords(str_replace('_', ' ', (string)$event['entity_type']))) ?>
            <?php if ($event['entity_id']): ?>
                &nbsp;·&nbsp;FF #<?= (int)$event['entity_id'] ?>
            <?php endif; ?>
            <?php if ($event['qbo_entity_id']): ?>
                &nbsp;·&nbsp;QBO #<?= e((string)$event['qbo_entity_id']) ?>
            <?php endif; ?>
            &nbsp;·&nbsp;Detected <?= e((string)$event['detected_at']) ?> UTC
        </div>
    </div>
    <a href="<?= base_url('quickbooks/drift') ?>" class="btn btn-secondary btn-sm">← Back to drift list</a>
</div>

<!-- ── Details card ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <h2 class="h6" style="margin-bottom:14px;">Drift Details</h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
            <div>
                <div class="form-label">Category</div>
                <div><?= e(drift_show_category_label((string)$event['category'])) ?></div>
            </div>
            <div>
                <div class="form-label">Source</div>
                <div class="font-mono text-sm"><?= e((string)$event['detection_source']) ?></div>
            </div>
            <div>
                <div class="form-label">Entity Type</div>
                <div><?= e(ucwords(str_replace('_', ' ', (string)$event['entity_type']))) ?></div>
            </div>
            <div>
                <div class="form-label">Environment</div>
                <div class="font-mono text-sm"><?= e((string)$event['environment']) ?></div>
            </div>
            <?php if ($event['entity_id']): ?>
            <div>
                <div class="form-label">FF Entity ID</div>
                <div class="font-mono"><?= (int)$event['entity_id'] ?></div>
            </div>
            <?php endif; ?>
            <?php if ($event['qbo_entity_id']): ?>
            <div>
                <div class="form-label">QBO Entity ID</div>
                <div class="font-mono text-sm"><?= e((string)$event['qbo_entity_id']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($event['realm_id']): ?>
            <div>
                <div class="form-label">Realm ID</div>
                <div class="font-mono text-sm"><?= e((string)$event['realm_id']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($event['queue_id']): ?>
            <div>
                <div class="form-label">Sync Queue Row</div>
                <div class="font-mono text-sm">#<?= (int)$event['queue_id'] ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- FF vs QBO value comparison -->
        <?php if ($event['ff_value'] !== null || $event['qbo_value'] !== null || $event['drift_amount'] !== null): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr<?= $event['drift_amount'] !== null ? ' 1fr' : '' ?>;gap:12px;margin-top:16px;">
            <div style="padding:12px;background:var(--bg-surface-2);border-radius:6px;">
                <div class="form-label" style="margin-bottom:4px;">FF Value</div>
                <div class="font-mono text-sm"><?= e((string)($event['ff_value'] ?? '—')) ?></div>
            </div>
            <div style="padding:12px;background:var(--bg-surface-2);border-radius:6px;">
                <div class="form-label" style="margin-bottom:4px;">QBO Value</div>
                <div class="font-mono text-sm"><?= e((string)($event['qbo_value'] ?? '—')) ?></div>
            </div>
            <?php if ($event['drift_amount'] !== null): ?>
            <div style="padding:12px;background:var(--bg-surface-2);border-radius:6px;">
                <div class="form-label" style="margin-bottom:4px;">Drift Amount</div>
                <div class="font-mono" style="font-weight:600;<?= (float)$event['drift_amount'] < 0 ? 'color:var(--color-danger);' : '' ?>">
                    <?= e((string)$event['drift_amount']) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($event['description']): ?>
        <div style="margin-top:16px;padding:12px;background:var(--bg-surface-2);border-radius:6px;">
            <div class="form-label" style="margin-bottom:6px;">Description</div>
            <div class="text-sm" style="white-space:pre-wrap;"><?= e((string)$event['description']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Resolution card (resolved events only) ─────────────────────── -->
<?php if (!$isOpen): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <h2 class="h6" style="margin-bottom:14px;">Resolution</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
            <div>
                <div class="form-label">Status</div>
                <div><?= drift_show_state_badge($event) ?></div>
            </div>
            <div>
                <div class="form-label">Resolved at</div>
                <div class="font-mono text-sm"><?= e((string)$event['resolved_at']) ?> UTC</div>
            </div>
            <?php if ($event['resolver_name']): ?>
            <div>
                <div class="form-label">Resolved by</div>
                <div><?= e((string)$event['resolver_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($event['resolution_note']): ?>
        <div style="margin-top:14px;padding:12px;background:var(--bg-surface-2);border-radius:6px;">
            <div class="form-label" style="margin-bottom:6px;">Resolution note</div>
            <div class="text-sm" style="white-space:pre-wrap;"><?= e((string)$event['resolution_note']) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Actions card ───────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <h2 class="h6" style="margin-bottom:14px;">Actions</h2>

        <?php if ($isOpen): ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <button class="btn btn-primary btn-sm" @click="openModal('resolve')">Resolve</button>
            <?php if ($canEdit): ?>
                <?php if ($event['category'] === 'push_failed' && $event['entity_id']): ?>
                <button class="btn btn-secondary btn-sm" @click="resync()" :disabled="working"
                        title="Re-enqueue the FF entity via its Pusher; drift auto-resolves on the next parity check if the push succeeds.">
                    <span x-show="!working">Re-sync</span>
                    <span x-show="working" x-cloak>Working…</span>
                </button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" @click="openModal('accept')"
                        title="Accept this divergence as intentional (terminal — never re-flagged by cron).">Accept</button>
                <button class="btn btn-secondary btn-sm" @click="openModal('suppress')"
                        title="Suppress as a known false-positive (terminal, hidden from default view).">Suppress</button>
            <?php endif; ?>
        </div>
        <div class="text-sm text-secondary">
            <strong>Resolve</strong> — annotate as fixed; drift may recur and re-open.&ensp;
            <?php if ($canEdit): ?>
            <strong>Re-sync</strong> — re-enqueue for a fresh QBO push (auto-resolves on parity if successful).&ensp;
            <strong>Accept</strong> — intentional divergence, terminal.&ensp;
            <strong>Suppress</strong> — false-positive, terminal.
            <?php endif; ?>
        </div>

        <?php else: ?>
        <?php if ($canEdit): ?>
        <div style="margin-bottom:12px;">
            <button class="btn btn-secondary btn-sm" @click="openModal('reopen')">Reopen</button>
        </div>
        <div class="text-sm text-secondary">
            <strong>Reopen</strong> — clears resolution, re-enters the open pool for the next parity check.
        </div>
        <?php else: ?>
        <div class="text-secondary text-sm">
            This event is resolved. An administrator with QuickBooks credentials access can reopen it.
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Note modal ─────────────────────────────────────────────────── -->
<div x-show="modal.show" x-cloak class="modal-overlay"
     style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);"
     @click.self="closeModal()">
    <div class="modal modal-md">
        <div class="modal-header">
            <h3 class="h5" style="margin:0;" x-text="modalTitle()"></h3>
            <button class="modal-close" @click="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="text-sm text-secondary" style="margin-bottom:10px;" x-text="modalHint()"></div>
            <label class="form-label">
                Note <span style="color:var(--color-danger);">*</span>
            </label>
            <textarea class="form-input" rows="3" x-model="modal.note"
                      placeholder="Describe the reason…"></textarea>
            <p class="text-danger text-sm" style="margin-top:4px;"
               x-show="modal.err" x-cloak x-text="modal.err"></p>
        </div>
        <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--border-color);">
            <button class="btn btn-secondary btn-sm" @click="closeModal()" :disabled="modal.running">Cancel</button>
            <button class="btn btn-primary btn-sm" @click="confirmAction()"
                    :disabled="modal.running || (modal.note || '').trim().length < 5">
                <span x-show="!modal.running" x-text="modalTitle()"></span>
                <span x-show="modal.running" x-cloak>Working…</span>
            </button>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<script>
function driftShow() {
    return {
        flash:   { msg: '', ok: true },
        working: false,
        modal:   { show: false, action: '', note: '', err: '', running: false },

        init() {},

        openModal(action) {
            this.modal = { show: true, action, note: '', err: '', running: false };
        },

        closeModal() {
            this.modal.show = false;
        },

        modalTitle() {
            const m = { resolve: 'Resolve', accept: 'Accept', suppress: 'Suppress', reopen: 'Reopen' };
            return m[this.modal.action] || 'Action';
        },

        modalHint() {
            const h = {
                resolve:  'Mark this drift event as fixed. Drift may recur and re-open automatically.',
                accept:   'Accept the divergence as intentional (terminal — never re-flagged by the drift cron).',
                suppress: 'Suppress as a known false-positive (terminal, hidden from the default drift view).',
                reopen:   'Clear the resolution — the event re-enters the open pool and the next parity check will re-evaluate it.',
            };
            return h[this.modal.action] || '';
        },

        async confirmAction() {
            const note = (this.modal.note || '').trim();
            if (note.length < 5) {
                this.modal.err = 'Note must be at least 5 characters.';
                return;
            }
            this.modal.running = true;
            this.modal.err = '';
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_resolve.php'), {
                    id: <?= $id ?>,
                    action: this.modal.action,
                    resolution_note: note,
                });
                // Envelope contract: drift_resolve.php answers
                // json_success(['action' => …]), which nests the payload under
                // `data`. Reading res.action off the envelope was always
                // undefined, so a SUCCESSFUL resolve reported "Unexpected
                // response." and never reloaded — the event was resolved
                // server-side while the UI insisted it had failed.
                const d = (res && res.data) || {};
                if (res && res.success && d.action) {
                    this.flash = { msg: 'Done — reloading…', ok: true };
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    this.modal.err = res?.error?.message || 'Unexpected response.';
                }
            } catch (err) {
                this.modal.err = (err && err.message) || 'Request failed.';
            } finally {
                this.modal.running = false;
            }
        },

        async resync() {
            if (!confirm('Re-enqueue this entity for a fresh QBO push?')) return;
            this.working = true;
            this.flash = { msg: '', ok: true };
            try {
                const res = await FF_Api.post(FF_Api.url('/api/v1/quickbooks/drift_resolve.php'), {
                    id: <?= $id ?>,
                    action: 'resync',
                });
                // Envelope contract — same class as resolve() above.
                const d = (res && res.data) || {};
                if (d.action === 'resync_enqueued') {
                    this.flash = { msg: 'Re-enqueued. The event auto-resolves on the next parity check if the push succeeds.', ok: true };
                } else if (d.action === 'resync_skipped') {
                    this.flash = { msg: 'Skipped: ' + (d.reason || '?'), ok: false };
                } else {
                    this.flash = { msg: res?.error?.message || 'Unexpected response.', ok: false };
                }
            } catch (err) {
                this.flash = { msg: (err && err.message) || 'Request failed.', ok: false };
            } finally {
                this.working = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
