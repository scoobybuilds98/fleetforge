<?php
declare(strict_types=1);

/**
 * app/admin/users/show.php
 *
 * User detail page.
 * Server-renders user header, detail card (view + inline edit), and actions panel.
 *
 * View/Edit mode: Alpine.js toggle with D19 optimistic lock via updated_at.
 * Actions: Resend Invite, Activate, Deactivate, Suspend, Lock Out (S-USER-LOCKOUT).
 *
 * D5: SOFT_DELETE — deleted_at IS NULL guard.
 * D19: updated_at submitted with every save.
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * S-USER-LOCKOUT: "Lock Out" (super_admin only, any non-self, non-already-
 * locked status) posts to api/v1/users/lock.php with a mandatory reason.
 * The primary control surface for this feature is the dedicated Settings →
 * Lockout tab (app/admin/settings/lockout.php); this page's button is a
 * convenience for acting on a user while already viewing their profile.
 * "Unlock" reuses the existing Activate button/update_status.php path.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/update.php, api/v1/users/update_status.php,
 *           api/v1/users/invite.php, api/v1/users/lock.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S017, S-USER-LOCKOUT
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

// S-PERM-USERS-ACCESS-WALL — Users module is super_admin only.
// Render the custom developer-only access wall inside the normal
// admin shell so non-super_admin viewers see why they can't enter,
// instead of a bare 403. header.php already opens sidebar/topbar/
// <main class="page-content">; footer.php closes them.
if (!is_super_admin()) {
    $pageTitle = 'Access Restricted';
    require_once FF_ROOT . '/includes/header.php';
    ?>
    <div class="access-wall">
        <div class="access-wall-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5"
                 xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="access-wall-title">Developer Access Only</h2>
        <p class="access-wall-message">
            The Users &amp; Roles module is restricted to the Developer account.<br>
            Contact your system administrator for user management assistance.
        </p>
        <a href="<?= base_url('dashboard') ?>" class="btn btn-secondary">
            Back to Dashboard
        </a>
    </div>
    <?php
    require_once FF_ROOT . '/includes/footer.php';
    exit;
}

// ── Resolve user ─────────────────────────────────────────────────────────────
$userId = clean_int($_GET['id'] ?? null);
if (!$userId) {
    header('Location: ' . base_url('users'));
    exit;
}

$user = db_row(
    "SELECT
         u.id, u.name, u.email, u.status, u.phone, u.timezone,
         u.theme_preference, u.last_login_at, u.last_login_ip,
         u.invite_sent_at, u.created_at, u.updated_at,
         u.mfa_enabled, u.mfa_required, u.mfa_enabled_at,
         u.created_by,
         u.locked_at, u.lock_reason,
         creator.name AS created_by_name,
         locker.name AS locked_by_name,
         ur.id AS role_id, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
     LEFT JOIN users creator ON creator.id = u.created_by
     LEFT JOIN users locker ON locker.id = u.locked_by
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);

if (!$user) {
    header('Location: ' . base_url('users') . '?error=not_found');
    exit;
}

// All roles for the edit dropdown
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

// Flash message from redirect (e.g. after invite)
$flashMsg = clean_string($_GET['flash'] ?? null, 500);

$isSelf     = (current_user_id() === $userId);
$canEdit    = can('users', 'edit');
$canCreate  = can('users', 'create');

// ── Load last 10 login history entries from audit_log ────────────────────────
$loginHistory = db_select(
    "SELECT created_at, ip_address, user_agent, notes
     FROM audit_log
     WHERE action = 'login' AND user_id = ?
     ORDER BY created_at DESC
     LIMIT 10",
    [$userId]
);

// Status badge map
$statusBadges = [
    'active'    => 'badge-success',
    'invited'   => 'badge-info',
    'inactive'  => 'badge-neutral',
    'suspended' => 'badge-danger',
    'locked'    => 'badge-danger',
];
$statusLabels = [
    'active'    => 'Active',
    'invited'   => 'Invited',
    'inactive'  => 'Inactive',
    'suspended' => 'Suspended',
    'locked'    => 'Locked',
];

$pageTitle = e($user['name']);
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('users') ?>" class="btn btn-secondary btn-sm">← Users</a>
    <h1 class="page-header-title"><?= e($user['name']) ?></h1>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto;">
        <span class="badge <?= e($statusBadges[$user['status']] ?? 'badge-neutral') ?>">
            <?= e($statusLabels[$user['status']] ?? ucfirst($user['status'])) ?>
        </span>
        <span class="badge badge-neutral badge-pill"><?= e($user['role_name']) ?></span>
    </div>
</div>

<?php if ($flashMsg): ?>
<div class="toast toast-success" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
    </span>
    <div class="toast-body"><div class="toast-message"><?= e($flashMsg) ?></div></div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start;" class="user-show-layout">

<!-- ── Left: Detail card ─────────────────────────────────────────────────── -->
<div class="card"
     x-data="userShow()">

    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">User Details</span>
        <?php if ($canEdit): ?>
        <button id="btn-edit" class="btn btn-secondary btn-sm"
                x-show="!editMode"
                @click="showEdit()">Edit</button>
        <?php endif; ?>
    </div>

    <!-- Error banner (edit mode) -->
    <div x-show="editError"
         class="toast toast-danger"
         style="position:relative;margin:0 16px 0;animation:none;display:none;"
         :style="editError ? '' : 'display:none'">
        <span class="toast-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
        </span>
        <div class="toast-body"><div class="toast-message" x-text="editError"></div></div>
    </div>

    <!-- VIEW MODE -->
    <div x-show="!editMode" class="card-body">
        <dl class="detail-grid">
            <dt>Name</dt>
            <dd><?= e($user['name']) ?></dd>

            <dt>Email</dt>
            <dd><a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></dd>

            <dt>Phone</dt>
            <dd><?= $user['phone'] ? e($user['phone']) : '—' ?></dd>

            <dt>Timezone</dt>
            <dd><?= $user['timezone'] ? e($user['timezone']) : '—' ?></dd>

            <dt>Role</dt>
            <dd>
                <span class="badge badge-neutral badge-pill"><?= e($user['role_name']) ?></span>
            </dd>

            <dt>Last Login</dt>
            <dd>
                <?php if ($user['last_login_at']): ?>
                    <?= format_datetime($user['last_login_at']) ?>
                    <?php if ($user['last_login_ip']): ?>
                    <span class="text-muted font-mono" style="font-size:0.8125rem;">
                        (<?= e($user['last_login_ip']) ?>)
                    </span>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </dd>

            <?php if ($user['invite_sent_at']): ?>
            <dt>Invite Sent</dt>
            <dd><?= format_datetime($user['invite_sent_at']) ?></dd>
            <?php endif; ?>

            <dt>Created</dt>
            <dd><?= format_datetime($user['created_at']) ?></dd>
        </dl>
    </div>

    <!-- EDIT MODE -->
    <div x-show="editMode" class="card-body">
        <form @submit.prevent="saveUser()">

            <!-- D19 lock token -->
            <input type="hidden" x-ref="updatedAt" value="<?= e($user['updated_at']) ?>">

            <div class="form-group">
                <label class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" class="form-control" x-model="form.name"
                       maxlength="255" placeholder="Jane Smith">
            </div>

            <div class="form-group">
                <label class="form-label">Email <span class="required">*</span></label>
                <input type="email" class="form-control" x-model="form.email"
                       maxlength="255">
            </div>

            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" x-model="form.phone"
                       maxlength="50">
            </div>

            <div class="form-group">
                <label class="form-label">Timezone</label>
                <input type="text" class="form-control" x-model="form.timezone"
                       maxlength="100" placeholder="America/Vancouver">
            </div>

            <div class="form-group">
                <label class="form-label">Role <span class="required">*</span></label>
                <select class="form-select" x-model="form.role_id">
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
                <button type="submit" class="btn btn-primary"
                        :disabled="saving">
                    <span x-show="!saving">Save Changes</span>
                    <span x-show="saving">Saving…</span>
                </button>
                <button type="button" class="btn btn-secondary"
                        @click="cancelEdit()">Cancel</button>
            </div>
        </form>
    </div>

</div><!-- /detail card -->

<!-- ── Right: Actions panel ──────────────────────────────────────────────── -->
<div style="display:flex;flex-direction:column;gap:12px;">

    <?php if ($canCreate && $user['status'] === 'invited'): ?>
    <!-- Resend Invite -->
    <div class="card">
        <div class="card-body" style="padding:16px;">
            <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 12px;">
                This user has not yet activated their account.
            </p>
            <button id="btn-resend-invite"
                    class="btn btn-secondary w-full"
                    onclick="resendInvite()">
                Resend Invitation
            </button>
            <div id="invite-msg" style="font-size:0.8125rem;margin-top:8px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (is_super_admin() && $user['status'] === 'active' && !$isSelf): ?>
    <!-- Send Password Reset Email — super_admin only, active non-self users -->
    <div class="card">
        <div class="card-body" style="padding:16px;">
            <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 12px;">
                Send a password reset link to this user's email address. The link expires in 2 hours.
            </p>
            <button id="btn-send-reset"
                    class="btn btn-secondary w-full"
                    onclick="sendPasswordReset()">
                Send Password Reset Email
            </button>
            <div id="reset-msg" style="font-size:0.8125rem;margin-top:8px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (is_super_admin() && $user['role_slug'] !== 'super_admin'): ?>
    <!-- Manage Permissions — PERM-1 — super_admin only, never on super_admin targets -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Permissions</div>
        <div class="card-body" style="padding:16px;">
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 12px;">
                Grant or revoke individual permissions on top of this user's
                <strong><?= e($user['role_name']) ?></strong> role.
            </p>
            <a href="<?= base_url('users/permissions') ?>?user_id=<?= $userId ?>"
               class="btn btn-secondary w-full">
                Manage Permissions
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canEdit && !$isSelf): ?>
    <!-- Status actions -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Change Status</div>
        <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:8px;">

            <?php /* S-USER-LOCKOUT: 'locked' added here so the existing Activate
                     button also reactivates a locked-out user — reusing
                     update_status.php, which S-USER-LOCKOUT taught to clear
                     locked_at/locked_by/lock_reason (and the unrelated
                     login_attempts/locked_until brute-force pair) whenever the
                     prior status was 'locked'. */ ?>
            <?php if (in_array($user['status'], ['inactive', 'suspended', 'locked'], true)): ?>
            <button class="btn btn-secondary w-full"
                    onclick="changeStatus('active')">
                <?= $user['status'] === 'locked' ? 'Unlock' : 'Activate' ?>
            </button>
            <?php endif; ?>

            <?php if ($user['status'] === 'active'): ?>
            <button class="btn btn-secondary w-full"
                    onclick="changeStatus('inactive')">
                Deactivate
            </button>
            <button class="btn btn-danger w-full"
                    onclick="changeStatus('suspended')">
                Suspend
            </button>
            <?php endif; ?>

            <?php if ($user['status'] === 'suspended'): ?>
            <button class="btn btn-secondary w-full"
                    onclick="changeStatus('inactive')">
                Set Inactive
            </button>
            <?php endif; ?>

            <?php if (is_super_admin() && $user['status'] === 'locked'): ?>
            <div style="font-size:0.75rem;color:var(--text-tertiary);border-top:1px solid var(--border-color,#333);padding-top:8px;margin-top:2px;">
                Locked <?= $user['locked_at'] ? e(format_datetime($user['locked_at'])) : '' ?>
                by <?= e($user['locked_by_name'] ?? 'unknown') ?><?php if ($user['lock_reason']): ?><br>&ldquo;<?= e($user['lock_reason']) ?>&rdquo;<?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (is_super_admin() && $user['status'] !== 'locked'): ?>
            <button class="btn btn-danger w-full" style="margin-top:4px;"
                    onclick="lockUser()">
                Lock Out
            </button>
            <p style="font-size:0.75rem;color:var(--text-tertiary);margin:0;">
                Immediately blocks login and password reset, and ends any session they have open. Requires a reason.
            </p>
            <?php endif; ?>

            <div id="status-msg" style="font-size:0.8125rem;margin-top:4px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (is_super_admin() && !$isSelf): ?>
    <!-- Set Password — super_admin only, non-self users -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Set Password</div>
        <div class="card-body" style="padding:16px;"
             x-data="setPasswordForm()">
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" x-model="pwd"
                       placeholder="Min. 10 characters" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" class="form-control" x-model="confirmPwd"
                       placeholder="Repeat password" autocomplete="new-password">
            </div>
            <div x-show="msg" x-text="msg"
                 :style="isError ? 'color:var(--color-danger)' : 'color:var(--color-success)'"
                 style="font-size:0.8125rem;margin-bottom:8px;display:none;"></div>
            <button class="btn btn-secondary w-full"
                    @click="submit()"
                    :disabled="saving">
                <span x-show="!saving">Set Password</span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Two-Factor Authentication (super_admin, non-self) ─────── -->
    <?php if (is_super_admin() && !$isSelf && (int)($user['mfa_enabled'] ?? 0)): ?>
    <div class="card"
         x-data="{ disabling: false, mfaMsg: '', mfaOk: true }">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Two-Factor Authentication</div>
        <div class="card-body" style="padding:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="var(--color-success,#10b981)" style="width:16px;height:16px;flex-shrink:0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
                </svg>
                <span style="font-size:0.8125rem;color:var(--color-success,#10b981);font-weight:600;">MFA Enabled</span>
            </div>
            <?php if ($user['mfa_enabled_at']): ?>
            <div style="font-size:0.75rem;color:var(--text-tertiary);margin-bottom:10px;">
                Since <?= e(format_datetime($user['mfa_enabled_at'])) ?>
            </div>
            <?php endif; ?>
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 10px;">
                Emergency recovery only — use this if the user has lost access to both their
                authenticator app and backup codes. They will be required to set up MFA again
                on their next login if their role requires it.
            </p>
            <div x-show="mfaMsg" x-text="mfaMsg"
                 :style="mfaOk ? 'color:var(--color-success)' : 'color:var(--color-danger)'"
                 style="font-size:0.8125rem;margin-bottom:8px;display:none;"></div>
            <button class="btn btn-danger w-full"
                    :disabled="disabling"
                    @click="async function() {
                        if (!confirm('Disable MFA for this user? They will need to re-enroll if their role requires it.')) return;
                        this.disabling = true;
                        this.mfaMsg   = '';
                        const r = await fetch(window.FF_BASE_PATH + '/api/v1/users/disable_mfa.php', {
                            method:  'POST',
                            headers: {
                                'Content-Type':     'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            },
                            body: JSON.stringify({ user_id: <?= $userId ?> }),
                        });
                        const d = await r.json();
                        this.disabling = false;
                        if (d.success) { this.mfaOk = true; this.mfaMsg = 'MFA disabled. Page will refresh…'; setTimeout(() => location.reload(), 1500); }
                        else { this.mfaOk = false; this.mfaMsg = d.error?.message ?? 'Failed to disable MFA.'; }
                    }.call($data)">
                <span x-show="!disabling">Disable MFA for this User</span>
                <span x-show="disabling">Disabling…</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (is_super_admin() && !$isSelf): ?>
    <!-- Delete User — super_admin only, non-self users -->
    <div class="card">
        <div class="card-body" style="padding:16px;">
            <p style="font-size:0.8125rem;color:var(--text-secondary);margin:0 0 10px;">
                Permanently removes this user from the system. This action cannot be undone.
            </p>
            <button class="btn btn-danger w-full"
                    onclick="confirmDeleteUser()">
                Delete User
            </button>
            <div id="delete-user-msg" style="font-size:0.8125rem;margin-top:8px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /actions panel -->

</div><!-- /grid -->

<!-- ══════════════════════════════════════════════════════════════
     Login History — last 10 login attempts for this user
     ══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <span style="font-weight:600;">Login History</span>
        <span class="text-muted" style="font-size:0.8125rem;margin-left:8px;">Last 10 attempts</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($loginHistory)): ?>
        <div class="empty-state" style="padding:32px;">
            <div class="empty-state-title">No login history recorded yet.</div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
<table class="table table-hover">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>IP Address</th>
                    <th>User Agent</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loginHistory as $entry): ?>
                <?php
                    // WHY: check notes for failure/lock keywords to determine result badge
                    $notes  = strtolower($entry['notes'] ?? '');
                    $failed = str_contains($notes, 'fail') || str_contains($notes, 'lock') || str_contains($notes, 'invalid');
                ?>
                <tr>
                    <td class="font-mono" style="font-size:0.8125rem;white-space:nowrap;">
                        <?= e(format_datetime($entry['created_at'])) ?>
                    </td>
                    <td class="font-mono" style="font-size:0.8125rem;">
                        <?= $entry['ip_address'] ? e($entry['ip_address']) : '—' ?>
                    </td>
                    <td style="font-size:0.8125rem;color:var(--text-secondary);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= $entry['user_agent'] ? e(substr($entry['user_agent'], 0, 60)) : '—' ?>
                    </td>
                    <td>
                        <?php if ($failed): ?>
                            <span class="badge badge-danger">Failed</span>
                        <?php else: ?>
                            <span class="badge badge-success">Success</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
</div>
        <?php endif; ?>
    </div>
</div>

<style>
@media (max-width: 768px) {
    .user-show-layout { grid-template-columns: 1fr !important; }
}
</style>

<script>
function userShow() {
    return {
        editMode:  false,
        saving:    false,
        editError: null,
        form: {
            name:     <?= json_encode($user['name']) ?>,
            email:    <?= json_encode($user['email']) ?>,
            phone:    <?= json_encode($user['phone'] ?? '') ?>,
            timezone: <?= json_encode($user['timezone'] ?? '') ?>,
            role_id:  <?= json_encode((string)$user['role_id']) ?>,
        },

        init() {
            // Reset form to current server values
            this.form = {
                name:     <?= json_encode($user['name']) ?>,
                email:    <?= json_encode($user['email']) ?>,
                phone:    <?= json_encode($user['phone'] ?? '') ?>,
                timezone: <?= json_encode($user['timezone'] ?? '') ?>,
                role_id:  <?= json_encode((string)$user['role_id']) ?>,
            };
        },

        showEdit() {
            this.editMode  = true;
            this.editError = null;
        },

        cancelEdit() {
            this.editMode  = false;
            this.editError = null;
        },

        async saveUser() {
            this.editError = null;

            if (!this.form.name.trim()) {
                this.editError = 'Name is required.';
                return;
            }
            if (!this.form.email.trim()) {
                this.editError = 'Email is required.';
                return;
            }

            const payload = {
                id:         <?= $userId ?>,
                updated_at: this.$refs.updatedAt.value,   // D19 optimistic lock
                name:       this.form.name.trim(),
                email:      this.form.email.trim(),
                phone:      this.form.phone.trim() || null,
                timezone:   this.form.timezone.trim() || null,
                role_id:    parseInt(this.form.role_id),
            };

            this.saving = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/users/update.php') ?>', payload);
                window.location.reload();
            } catch (err) {
                this.editError = err?.data?.message ?? 'Save failed. Please try again.';
                this.saving = false;
            }
        },
    };
}

// ── Resend invite ─────────────────────────────────────────────────────────────
async function resendInvite() {
    const btn = document.getElementById('btn-resend-invite');
    const msg = document.getElementById('invite-msg');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    msg.style.display = 'none';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/invite.php') ?>', { id: <?= $userId ?> });
        msg.textContent = '✓ Invitation resent successfully.';
        msg.style.color = 'var(--color-success)';
        msg.style.display = 'block';
        btn.textContent = 'Invite Sent';
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Failed to resend invite.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Resend Invitation';
    }
}

// ── Send password reset email ─────────────────────────────────────────────────
async function sendPasswordReset() {
    const btn = document.getElementById('btn-send-reset');
    const msg = document.getElementById('reset-msg');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    msg.style.display = 'none';
    try {
        const res = await FF_Api.post('<?= base_url('api/v1/users/send_password_reset.php') ?>', { id: <?= $userId ?> });
        msg.textContent = res?.data?.message ?? '✓ Password reset email sent.';
        msg.style.color = 'var(--color-success)';
        msg.style.display = 'block';
        btn.textContent = 'Email Sent';
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Failed to send reset email.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Send Password Reset Email';
    }
}

// ── Set password (Alpine component) ──────────────────────────────────────────
function setPasswordForm() {
    return {
        pwd: '',
        confirmPwd: '',
        saving: false,
        msg: '',
        isError: false,

        async submit() {
            this.msg = '';
            if (this.pwd.length < 10) {
                this.msg = 'Password must be at least 10 characters.';
                this.isError = true;
                return;
            }
            if (this.pwd !== this.confirmPwd) {
                this.msg = 'Passwords do not match.';
                this.isError = true;
                return;
            }
            this.saving = true;
            try {
                await FF_Api.post('<?= base_url('api/v1/users/set_password.php') ?>', {
                    id:               <?= (int)$userId ?>,
                    new_password:     this.pwd,
                    confirm_password: this.confirmPwd,
                });
                this.msg = '✓ Password updated.';
                this.isError = false;
                this.pwd = '';
                this.confirmPwd = '';
            } catch (err) {
                this.msg = err?.data?.message ?? 'Failed to set password.';
                this.isError = true;
            } finally {
                this.saving = false;
            }
        },
    };
}

// ── Delete user ───────────────────────────────────────────────────────────────
function confirmDeleteUser() {
    document.getElementById('delete-user-modal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('delete-user-modal').style.display = 'none';
}
async function executeDelete() {
    const btn = document.getElementById('btn-confirm-delete');
    const msg = document.getElementById('delete-user-msg');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/delete.php') ?>', { id: <?= (int)$userId ?> });
        window.location.href = '<?= base_url('users') ?>?flash=User+deleted+successfully.';
    } catch (err) {
        closeDeleteModal();
        msg.textContent = err?.data?.message ?? 'Delete failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Delete';
    }
}

// ── Change status ─────────────────────────────────────────────────────────────
async function changeStatus(newStatus) {
    const msg = document.getElementById('status-msg');
    msg.style.display = 'none';
    try {
        await FF_Api.post('<?= base_url('api/v1/users/update_status.php') ?>', {
            id:     <?= $userId ?>,
            status: newStatus,
        });
        window.location.reload();
    } catch (err) {
        msg.textContent = err?.data?.message ?? 'Status change failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
    }
}

// ── Lock out (S-USER-LOCKOUT) ───────────────────────────────────────────────
// WHY: FF_Api.post resolves (never rejects) on a 4xx/5xx JSON error response
// -- unlike changeStatus() above, this checks res.success explicitly rather
// than relying on try/catch, which would otherwise silently treat a rejected
// lock request (e.g. "already locked") as a success.
async function lockUser() {
    const msg = document.getElementById('status-msg');
    msg.style.display = 'none';

    const reason = await FF_Confirm.askText({
        title: 'Lock out ' + <?= json_encode($user['name']) ?>,
        message: 'This immediately blocks login and password reset, and force-ends any session '
               + 'they currently have open. Reason (required):',
        confirmLabel: 'Lock Out',
        placeholder: 'e.g. Access revoked -- contract ended',
    });
    if (!reason || !reason.trim()) return;

    const res = await FF_Api.post('<?= base_url('api/v1/users/lock.php') ?>', {
        user_id: <?= $userId ?>,
        reason:  reason.trim(),
    });

    if (!res.success) {
        msg.textContent = res.error?.message ?? 'Lockout failed.';
        msg.style.color = 'var(--color-danger)';
        msg.style.display = 'block';
        return;
    }
    window.location.reload();
}
</script>

<!-- Delete User Confirmation Modal -->
<div id="delete-user-modal"
     class="modal-backdrop"
     style="display:none;"
     role="dialog" aria-modal="true" aria-labelledby="del-modal-title">
    <div class="modal-dialog modal-md">
        <div class="modal-header">
            <span class="modal-title" id="del-modal-title">Delete User</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong><?= e($user['name']) ?></strong>?</p>
            <p style="color:var(--text-secondary);font-size:0.875rem;">
                This user will be soft-deleted and will no longer be able to log in.
                This action cannot be undone via the UI.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-md" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger btn-md" id="btn-confirm-delete" onclick="executeDelete()">Delete</button>
        </div>
    </div>
</div>

<!-- ── Activity Log ───────────────────────────────────────────── -->
<div class="card" style="margin-top:24px;">
    <div class="card-header"><h3 class="card-title">Activity</h3></div>
    <div class="card-body">
        <?php
        $activityEntityType = 'user';
        $activityEntityId   = $userId;
        $activityOriginAt   = $user['created_at'];
        $activityOriginBy   = $user['created_by_name'] ?? null;
        ?>
        <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
