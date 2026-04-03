<?php
declare(strict_types=1);

/**
 * app/admin/users/show.php
 *
 * User detail page.
 * Server-renders user header, detail card (view + inline edit), and actions panel.
 *
 * View/Edit mode: Alpine.js toggle with D19 optimistic lock via updated_at.
 * Actions: Resend Invite, Activate, Deactivate, Suspend.
 *
 * D5: SOFT_DELETE — deleted_at IS NULL guard.
 * D19: updated_at submitted with every save.
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/update.php, api/v1/users/update_status.php, api/v1/users/invite.php
 * @decisions D5/D7/D19/D30/D32
 * @session  S017
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('users', 'view');

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
         ur.id AS role_id, ur.name AS role_name, ur.slug AS role_slug
     FROM users u
     JOIN user_roles ur ON ur.id = u.role_id
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
     x-data="userShow()"
     x-init="init()">

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

    <?php if ($canEdit && !$isSelf): ?>
    <!-- Status actions -->
    <div class="card">
        <div class="card-header" style="font-weight:600;font-size:0.875rem;">Change Status</div>
        <div class="card-body" style="padding:16px;display:flex;flex-direction:column;gap:8px;">

            <?php if (in_array($user['status'], ['inactive', 'suspended'], true)): ?>
            <button class="btn btn-secondary w-full"
                    onclick="changeStatus('active')">
                Activate
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

            <div id="status-msg" style="font-size:0.8125rem;margin-top:4px;display:none;"></div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /actions panel -->

</div><!-- /grid -->

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
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
