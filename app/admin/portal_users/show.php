<?php
declare(strict_types=1);

/**
 * app/admin/portal_users/show.php
 *
 * S-USERS-CONSOLIDATE C3 — Portal user detail page. Mirrors
 * app/admin/users/show.php for the portal-user surface. Built per
 * D-B operator decision (richer than inline actions; matches the
 * established users/show.php pattern).
 *
 * Displays: linked customer (click navigates to customers/show),
 * status badge, primary/sub-user flag, email_disabled state with
 * inline reenable button, last login + login attempts + lock state,
 * recent login history from audit_log (last 10 entries scoped to
 * action='login' + entity_type='portal_user'), and the full action
 * set (password reset, status toggle, reenable email, delete for
 * non-primary).
 *
 * Permissions: settings:view to load; settings:edit for write
 * actions (matches the API gates); settings:delete (super_admin) for
 * the delete button.
 *
 * @depends  api/v1/portal_users/*, includes/auth.php, includes/header.php
 * @decisions D5 (no soft-delete on portal_users) / D7 / D-B
 * @session  S-USERS-CONSOLIDATE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('settings', 'view');

// ── Resolve portal user ─────────────────────────────────────────────────────
$puId = clean_int($_GET['id'] ?? null);
if (!$puId) {
    header('Location: ' . base_url('users?tab=portal'));
    exit;
}

$pu = db_row(
    "SELECT pu.id, pu.name, pu.email, pu.status, pu.is_primary,
            pu.email_disabled, pu.email_disabled_reason, pu.email_disabled_at,
            pu.last_login_at, pu.last_login_ip, pu.login_attempts, pu.locked_until,
            pu.invite_sent_at, pu.created_at, pu.updated_at,
            c.id AS customer_id, c.company_name, c.status AS customer_status
     FROM portal_users pu
     JOIN customers c ON c.id = pu.customer_id
     WHERE pu.id = ? AND c.deleted_at IS NULL",
    [$puId]
);

if (!$pu) {
    header('Location: ' . base_url('users?tab=portal') . '&error=not_found');
    exit;
}

$canEdit       = can('settings', 'edit');
$canSuperAdmin = can('settings', 'delete');

// Recent login history — scope to action='login' + entity_type='portal_user'
// so admin-side login events don't leak into a portal-user audit view.
$loginHistory = db_select(
    "SELECT created_at, ip_address, user_agent, notes
     FROM audit_log
     WHERE action = 'login'
       AND entity_type = 'portal_user'
       AND entity_id   = ?
     ORDER BY created_at DESC
     LIMIT 10",
    [$puId]
);

$statusBadges = [
    'active'   => 'badge-success',
    'inactive' => 'badge-neutral',
    'invited'  => 'badge-info',
];

$pageTitle = e($pu['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('users') ?>?tab=portal" class="btn btn-secondary btn-sm">← Portal Users</a>
    <h1 class="page-header-title"><?= e($pu['name']) ?></h1>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto;">
        <span class="badge <?= e($statusBadges[$pu['status']] ?? 'badge-neutral') ?>">
            <?= e(ucfirst($pu['status'])) ?>
        </span>
        <?php if ((int) $pu['is_primary'] === 1): ?>
        <span class="badge badge-info">Primary</span>
        <?php endif; ?>
        <?php if ((int) $pu['email_disabled'] === 1): ?>
        <span class="badge badge-danger" title="<?= e($pu['email_disabled_reason'] ?? 'Email auto-disabled by SES bounce handler') ?>">
            Email Disabled
        </span>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start;">

    <!-- ── Left: Detail card ──────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="font-weight:600;">Portal user details</div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px 24px;">

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Name</label>
                    <div><?= e($pu['name']) ?></div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Email</label>
                    <div><?= e($pu['email']) ?></div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Customer</label>
                    <div>
                        <a href="<?= e(base_url('customers/show?id=' . $pu['customer_id'])) ?>"
                           class="link">
                            <?= e($pu['company_name']) ?>
                        </a>
                        <span class="badge <?= $pu['customer_status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"
                              style="font-size:0.65rem;margin-left:6px;">
                            <?= e(ucfirst($pu['customer_status'])) ?>
                        </span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Role</label>
                    <div>
                        <?php if ((int) $pu['is_primary'] === 1): ?>
                            <span class="badge badge-info">Primary</span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.875rem;">Sub-user</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Last Login</label>
                    <div class="font-mono" style="font-size:0.8125rem;">
                        <?= $pu['last_login_at']
                            ? e(format_datetime($pu['last_login_at']))
                            : '<span class="text-muted">Never</span>' ?>
                        <?php if ($pu['last_login_ip']): ?>
                        <div style="font-size:0.75rem;color:var(--text-muted);">
                            from <?= e($pu['last_login_ip']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Security</label>
                    <div style="font-size:0.875rem;">
                        <?php if ($pu['locked_until'] && $pu['locked_until'] > date('Y-m-d H:i:s')): ?>
                            <span class="badge badge-danger">Locked until <?= e($pu['locked_until']) ?></span>
                        <?php elseif ((int) $pu['login_attempts'] >= 3): ?>
                            <span class="badge badge-warning">
                                <?= e((string) $pu['login_attempts']) ?> failed attempts
                            </span>
                        <?php else: ?>
                            <span class="text-muted">OK</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Invited</label>
                    <div class="font-mono" style="font-size:0.8125rem;">
                        <?= $pu['invite_sent_at'] ? e(format_datetime($pu['invite_sent_at'])) : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-size:0.75rem;color:var(--text-muted);">Created</label>
                    <div class="font-mono" style="font-size:0.8125rem;">
                        <?= e(format_datetime($pu['created_at'])) ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Right: Actions panel ───────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:12px;">

        <?php if ($canEdit && (int) $pu['email_disabled'] === 1): ?>
        <!-- Re-enable email — surfaces only when email_disabled=1 -->
        <div class="card"
             x-data="{ saving: false, msg: '', err: false }">
            <div class="card-header" style="font-weight:600;font-size:0.875rem;color:var(--color-danger);">Email Disabled</div>
            <div class="card-body" style="padding:16px;">
                <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px;">
                    <?php if ($pu['email_disabled_reason']): ?>
                        Reason: <?= e($pu['email_disabled_reason']) ?>
                    <?php else: ?>
                        Email was auto-disabled by the SES bounce handler.
                    <?php endif; ?>
                    <?php if ($pu['email_disabled_at']): ?>
                        <br><span style="font-size:0.75rem;color:var(--text-tertiary);">
                            Since <?= e(format_datetime($pu['email_disabled_at'])) ?>
                        </span>
                    <?php endif; ?>
                </p>
                <div x-show="msg" x-text="msg" x-cloak
                     :style="err ? 'color:var(--color-danger);' : 'color:var(--color-success);'"
                     style="font-size:0.8125rem;margin-bottom:8px;"></div>
                <button class="btn btn-secondary w-full"
                        :disabled="saving"
                        @click="async function() {
                            if (!(await FF_Confirm.ask('Re-enable email delivery to <?= e($pu['email']) ?>?'))) return;
                            this.saving = true; this.msg = '';
                            try {
                                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/reenable_email.php'), { id: <?= (int) $puId ?> });
                                if (r.success) {
                                    this.err = false;
                                    this.msg = 'Email re-enabled. Refreshing…';
                                    setTimeout(() => location.reload(), 800);
                                } else {
                                    this.err = true;
                                    this.msg = r.error?.message ?? 'Failed.';
                                }
                            } catch(e) { this.err = true; this.msg = 'Network error.'; }
                            this.saving = false;
                        }">
                    <span x-show="!saving">Re-enable Email</span>
                    <span x-show="saving" x-cloak>Saving…</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
        <!-- Send Password Reset -->
        <div class="card"
             x-data="{ sending: false, msg: '', err: false }">
            <div class="card-header" style="font-weight:600;font-size:0.875rem;">Password Reset</div>
            <div class="card-body" style="padding:16px;">
                <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px;">
                    Generates a new password reset link valid for 24 hours.
                    In dev, the link is written to <code style="font-size:0.7rem;">logs/mail.log</code>.
                </p>
                <div x-show="msg" x-text="msg" x-cloak
                     :style="err ? 'color:var(--color-danger);' : 'color:var(--color-success);'"
                     style="font-size:0.8125rem;margin-bottom:8px;"></div>
                <button class="btn btn-secondary w-full"
                        :disabled="sending"
                        @click="async function() {
                            if (!(await FF_Confirm.ask('Send password reset link to <?= e($pu['email']) ?>?'))) return;
                            this.sending = true; this.msg = '';
                            try {
                                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/reset_password.php'), { id: <?= (int) $puId ?> });
                                if (r.success) {
                                    this.err = false;
                                    this.msg = 'Reset link generated. Check logs/mail.log.';
                                } else {
                                    this.err = true;
                                    this.msg = r.error?.message ?? 'Failed.';
                                }
                            } catch(e) { this.err = true; this.msg = 'Network error.'; }
                            this.sending = false;
                        }">
                    <span x-show="!sending">Send Reset Link</span>
                    <span x-show="sending" x-cloak>Generating…</span>
                </button>
            </div>
        </div>

        <!-- Status toggle (active ↔ inactive) -->
        <div class="card"
             x-data="{ saving: false, msg: '', err: false }">
            <div class="card-header" style="font-weight:600;font-size:0.875rem;">Change Status</div>
            <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:8px;">
                <?php if ($pu['status'] === 'active'): ?>
                <button class="btn btn-secondary w-full"
                        :disabled="saving"
                        style="color:var(--color-warning);"
                        @click="async function() {
                            if (!(await FF_Confirm.ask('Deactivate this portal user? They will not be able to log in.'))) return;
                            this.saving = true;
                            const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/update_status.php'), { id: <?= (int) $puId ?>, status: 'inactive' });
                            if (r.success) { location.reload(); }
                            else { this.err = true; this.msg = r.error?.message ?? 'Failed.'; this.saving = false; }
                        }">Deactivate</button>
                <?php elseif ($pu['status'] === 'inactive'): ?>
                <button class="btn btn-secondary w-full"
                        :disabled="saving"
                        style="color:var(--color-success);"
                        @click="async function() {
                            this.saving = true;
                            const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/update_status.php'), { id: <?= (int) $puId ?>, status: 'active' });
                            if (r.success) { location.reload(); }
                            else { this.err = true; this.msg = r.error?.message ?? 'Failed.'; this.saving = false; }
                        }">Activate</button>
                <?php else: ?>
                <span class="text-muted" style="font-size:0.8125rem;">
                    Status is <strong><?= e($pu['status']) ?></strong>;
                    automatic on first login.
                </span>
                <?php endif; ?>
                <div x-show="msg" x-text="msg" x-cloak
                     :style="err ? 'color:var(--color-danger);' : 'color:var(--color-success);'"
                     style="font-size:0.8125rem;"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canSuperAdmin && (int) $pu['is_primary'] !== 1): ?>
        <!-- Delete (non-primary only — primary users must be deactivated) -->
        <div class="card"
             x-data="{ deleting: false, msg: '', err: false }">
            <div class="card-header" style="font-weight:600;font-size:0.875rem;color:var(--color-danger);">Danger Zone</div>
            <div class="card-body" style="padding:16px;">
                <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px;">
                    Permanently delete this portal user. portal_users does not
                    support soft-delete — this is irreversible.
                </p>
                <div x-show="msg" x-text="msg" x-cloak
                     :style="err ? 'color:var(--color-danger);' : 'color:var(--color-success);'"
                     style="font-size:0.8125rem;margin-bottom:8px;"></div>
                <button class="btn btn-danger w-full"
                        :disabled="deleting"
                        @click="async function() {
                            if (!(await FF_Confirm.ask('Permanently delete <?= e($pu['name']) ?>? This cannot be undone.'))) return;
                            this.deleting = true;
                            try {
                                const r = await FF_Api.post(FF_Api.url('/api/v1/portal_users/delete.php'), { id: <?= (int) $puId ?> });
                                if (r.success) {
                                    window.location = '<?= base_url('users') ?>?tab=portal';
                                } else {
                                    this.err = true;
                                    this.msg = r.error?.message ?? 'Failed.';
                                    this.deleting = false;
                                }
                            } catch(e) { this.err = true; this.msg = 'Network error.'; this.deleting = false; }
                        }">
                    <span x-show="!deleting">Delete Portal User</span>
                    <span x-show="deleting" x-cloak>Deleting…</span>
                </button>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /right rail -->

</div><!-- /grid -->

<!-- ── Login history ──────────────────────────────────────────────────────── -->
<?php if (!empty($loginHistory)): ?>
<div class="card" style="margin-top:16px;">
    <div class="card-header" style="font-weight:600;">Recent login activity</div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;">
            <thead>
                <tr>
                    <th>When</th>
                    <th>IP</th>
                    <th>User Agent</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loginHistory as $hist): ?>
                <tr>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= e(format_datetime($hist['created_at'])) ?></td>
                    <td class="font-mono" style="font-size:0.8125rem;"><?= e($hist['ip_address'] ?? '—') ?></td>
                    <td style="font-size:0.75rem;color:var(--text-muted);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        :title="hist.user_agent"><?= e(substr($hist['user_agent'] ?? '', 0, 80)) ?></td>
                    <td style="font-size:0.8125rem;"><?= e($hist['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
