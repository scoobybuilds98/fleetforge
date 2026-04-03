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
require_permission('users', 'create');

// All roles for the dropdown, ordered by id
$roles = db_select("SELECT id, name, slug FROM user_roles ORDER BY id ASC");

$pageTitle = 'Invite New User';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('users') ?>" class="btn btn-secondary btn-sm">← Users</a>
    <h1 class="page-header-title">Invite New User</h1>
</div>

<div class="card" style="max-width:640px;"
     x-data="userCreate()">

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

        <!-- Full Name -->
        <div class="form-group">
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

<script>
function userCreate() {
    return {
        saving: false,
        error:  null,
        form: {
            name:     '',
            email:    '',
            role_id:  '',
            phone:    '',
            timezone: '',
        },

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
                    // Redirect to show page with flash message
                    const email = encodeURIComponent(payload.email);
                    window.location = '<?= base_url('users/show') ?>?id=' + d.data.id
                        + '&flash=' + encodeURIComponent('Invitation sent to ' + payload.email);
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
