<?php
declare(strict_types=1);

/**
 * app/admin/users/create.php
 *
 * Invite a new user form.
 * Submits via Alpine.js to api/v1/users/create.php,
 * then redirects to the new user's show page with a flash message.
 *
 * Fields: name (required), email (required), role_id (required),
 *         phone (optional), timezone (optional).
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/users/create.php
 * @decisions D5/D7/D30/D32
 * @session  S017
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

// All roles for the dropdown, ordered by id
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

$pageTitle = 'Invite New User';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('users') ?>" class="btn btn-secondary btn-sm">← Users</a>
    <h1 class="page-header-title">Invite New User</h1>
</div>

<div x-data="userCreate()">

<div class="card" style="max-width:640px;">

    <div class="card-body">

        <!-- Error banner -->
        <template x-if="error">
            <div class="toast toast-danger" style="position:relative;margin-bottom:16px;animation:none;">
                <span class="toast-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008z"/>
                    </svg>
                </span>
                <div class="toast-body"><div class="toast-message" x-text="error"></div></div>
            </div>
        </template>

        <div class="form-grid">

        <!-- Full Name -->
        <div class="form-group form-group--full">
            <label class="form-label" for="user-name">
                Full Name <span class="required" aria-hidden="true">*</span>
            </label>
            <input type="text"
                   id="user-name"
                   class="form-control"
                   x-model="form.name"
                   placeholder="Jane Smith"
                   maxlength="255"
                   autocomplete="off">
        </div>

        <!-- Email -->
        <div class="form-group">
            <label class="form-label" for="user-email">
                Email Address <span class="required" aria-hidden="true">*</span>
            </label>
            <input type="email"
                   id="user-email"
                   class="form-control"
                   x-model="form.email"
                   placeholder="jane@example.com"
                   maxlength="255"
                   autocomplete="off">
        </div>

        <!-- Role -->
        <div class="form-group">
            <label class="form-label" for="user-role">
                Role <span class="required" aria-hidden="true">*</span>
            </label>
            <select id="user-role" class="form-select" x-model="form.role_id">
                <option value="">— Select a role —</option>
                <?php foreach ($roles as $role): ?>
                <option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Phone -->
        <div class="form-group">
            <label class="form-label" for="user-phone">Phone</label>
            <input type="text"
                   id="user-phone"
                   class="form-control"
                   x-model="form.phone"
                   placeholder="604-555-0100"
                   maxlength="50">
        </div>

        <!-- Timezone -->
        <div class="form-group">
            <label class="form-label" for="user-timezone">Timezone</label>
            <input type="text"
                   id="user-timezone"
                   class="form-control"
                   x-model="form.timezone"
                   placeholder="America/Vancouver"
                   maxlength="100">
            <p class="text-muted" style="font-size:0.8125rem;margin:4px 0 0;">
                e.g. America/Vancouver, America/Toronto, UTC
            </p>
        </div>

        </div><!-- /form-grid -->

        <!-- Actions -->
        <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);margin-top:8px;">
            <button type="button"
                    class="btn btn-primary"
                    :disabled="saving"
                    @click="submit()">
                <span x-show="!saving">Send Invitation</span>
                <span x-show="saving">Sending…</span>
            </button>
            <a href="<?= base_url('users') ?>" class="btn btn-secondary">Cancel</a>
        </div>

    </div><!-- /card-body -->
</div><!-- /card -->

<?php
$overlayTitle    = 'User Created!';
$overlaySubtitle = 'Redirecting to user profile…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

<script>
function userCreate() {
    return {
        saving:             false,
        showSuccessOverlay: false,
        error:              null,
        form: {
            name:     '',
            email:    '',
            role_id:  '',
            phone:    '',
            timezone: '',
        },

        init() { // S-FORM-DRAFT-ROLLOUT
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'user-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        }, // S-FORM-DRAFT-ROLLOUT

        submit() {
            this.error = null;

            // Client-side guards
            if (!this.form.name.trim()) {
                this.error = 'Full name is required.';
                return;
            }
            if (!this.form.email.trim()) {
                this.error = 'Email address is required.';
                return;
            }
            if (!this.form.role_id) {
                this.error = 'Please select a role.';
                return;
            }

            const payload = {
                name:     this.form.name.trim(),
                email:    this.form.email.trim(),
                role_id:  parseInt(this.form.role_id),
                phone:    this.form.phone.trim() || null,
                timezone: this.form.timezone.trim() || null,
            };

            this.saving = true;

            FF_Api.post('<?= base_url('api/v1/users/create.php') ?>', payload)
                .then(d => {
                    if (d.success && this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT (only on confirmed success)
                    // Show success overlay then redirect to show page with flash message
                    this.showSuccessOverlay = true;
                    const _newId = d.data.id;
                    const _flash = encodeURIComponent('Invitation sent to ' + payload.email);
                    setTimeout(() => {
                        window.location = '<?= base_url('users/show') ?>?id=' + _newId + '&flash=' + _flash;
                    }, 3500);
                })
                .catch(err => {
                    this.error = err?.data?.message ?? 'Save failed. Please try again.';
                    this.saving = false;
                });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
