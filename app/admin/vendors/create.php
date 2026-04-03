<?php
declare(strict_types=1);

/**
 * app/admin/vendors/create.php
 *
 * New Vendor form.
 * Submits via Alpine.js to api/v1/vendors/create.php,
 * then redirects to the new vendor's show page.
 *
 * Fields: name (required), vendor_type (required), contact_name, email,
 *         phone, address, city, state, specializations (multi-select),
 *         hourly_rate, rating (1–5), is_preferred, notes.
 *
 * D16: hourly_rate via clean_decimal().
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/vendors/create.php
 * @decisions D7/D16/D30/D32
 * @session  S014
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'create');

$pageTitle = 'New Vendor';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('vendors') ?>" class="btn btn-secondary btn-sm">← Back</a>
    <h1 class="page-header-title">New Vendor</h1>
</div>

<div class="card" style="max-width:760px;"
     x-data="vendorCreate()"
     @submit.prevent="submit">

    <div class="card-body">

        <!-- Error banner -->
        <template x-if="error">
            <div class="alert alert-danger" x-text="error" style="margin-bottom:16px;"></div>
        </template>

        <!-- ── Identity ─────────────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:0 0 16px;">Identity</h3>

        <div class="form-row-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">
                    Vendor Name <span style="color:var(--color-danger)">*</span>
                </label>
                <input type="text" class="form-control"
                       x-model="form.name"
                       placeholder="e.g. ABC Truck Repair"
                       maxlength="255">
            </div>
            <div>
                <label class="form-label">
                    Vendor Type <span style="color:var(--color-danger)">*</span>
                </label>
                <select class="form-control" x-model="form.vendor_type">
                    <option value="">— Select type —</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="repair">Repair</option>
                    <option value="parts">Parts</option>
                    <option value="inspection">Inspection</option>
                    <option value="towing">Towing</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        <!-- ── Contact ──────────────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:24px 0 16px;">Contact</h3>

        <div class="form-row-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">Contact Name</label>
                <input type="text" class="form-control"
                       x-model="form.contact_name"
                       placeholder="Full name"
                       maxlength="255">
            </div>
            <div>
                <label class="form-label">Email</label>
                <input type="email" class="form-control"
                       x-model="form.email"
                       placeholder="vendor@example.com"
                       maxlength="255">
            </div>
        </div>

        <div class="form-row-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">Phone</label>
                <input type="text" class="form-control"
                       x-model="form.phone"
                       placeholder="604-555-0100"
                       maxlength="50">
            </div>
            <div>
                <!-- intentionally blank for grid alignment -->
            </div>
        </div>

        <!-- ── Location ─────────────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:24px 0 16px;">Location</h3>

        <div style="margin-bottom:16px;">
            <label class="form-label">Address</label>
            <input type="text" class="form-control"
                   x-model="form.address"
                   placeholder="123 Industrial Ave"
                   maxlength="500">
        </div>

        <div class="form-row-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">City</label>
                <input type="text" class="form-control"
                       x-model="form.city"
                       placeholder="Vancouver"
                       maxlength="100">
            </div>
            <div>
                <label class="form-label">Province / State</label>
                <input type="text" class="form-control"
                       x-model="form.state"
                       placeholder="BC"
                       maxlength="100">
            </div>
        </div>

        <!-- ── Rates & Rating ────────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:24px 0 16px;">Rates &amp; Rating</h3>

        <div class="form-row-2" style="margin-bottom:16px;">
            <div>
                <label class="form-label">Hourly Rate ($)</label>
                <input type="number" class="form-control"
                       x-model="form.hourly_rate"
                       placeholder="0.00"
                       min="0" step="0.01">
            </div>
            <div>
                <label class="form-label">Rating (1–5)</label>
                <select class="form-control" x-model="form.rating">
                    <option value="">— Not rated —</option>
                    <option value="1">★ 1 — Poor</option>
                    <option value="2">★★ 2 — Fair</option>
                    <option value="3">★★★ 3 — Good</option>
                    <option value="4">★★★★ 4 — Very Good</option>
                    <option value="5">★★★★★ 5 — Excellent</option>
                </select>
            </div>
        </div>

        <!-- ── Specializations ───────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:24px 0 16px;">Specializations</h3>

        <div style="margin-bottom:16px;">
            <label class="form-label">Specializations (hold Ctrl/Cmd to select multiple)</label>
            <select class="form-control" multiple size="6"
                    @change="updateSpecializations($event)">
                <option value="Brakes">Brakes</option>
                <option value="Diesel Engine">Diesel Engine</option>
                <option value="Electrical">Electrical</option>
                <option value="Exhaust">Exhaust</option>
                <option value="Hydraulics">Hydraulics</option>
                <option value="HVAC">HVAC</option>
                <option value="Suspension">Suspension</option>
                <option value="Tires">Tires</option>
                <option value="Transmission">Transmission</option>
                <option value="Welding">Welding</option>
            </select>
            <p class="text-secondary" style="margin:4px 0 0;font-size:0.8125rem;">
                Selected: <span x-text="form.specializations.length ? form.specializations.join(', ') : 'none'"></span>
            </p>
        </div>

        <!-- ── Preferred & Notes ─────────────────────────────────────────── -->
        <h3 style="font-size:0.875rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
                   color:var(--text-secondary);margin:24px 0 16px;">Settings &amp; Notes</h3>

        <div style="margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" x-model="form.is_preferred">
                <span class="form-label" style="margin:0;">Mark as Preferred Vendor</span>
            </label>
        </div>

        <div style="margin-bottom:24px;">
            <label class="form-label">Notes</label>
            <textarea class="form-control" rows="4"
                      x-model="form.notes"
                      placeholder="Internal notes, access instructions, etc."></textarea>
        </div>

        <!-- ── Submit ───────────────────────────────────────────────────── -->
        <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
            <button type="button" class="btn btn-primary"
                    :disabled="saving"
                    @click="submit()">
                <span x-show="!saving">Create Vendor</span>
                <span x-show="saving">Saving…</span>
            </button>
            <a href="<?= base_url('vendors') ?>" class="btn btn-secondary">Cancel</a>
        </div>

    </div><!-- /card-body -->
</div><!-- /card -->

<script>
function vendorCreate() {
    return {
        saving: false,
        error:  null,
        form: {
            name:            '',
            vendor_type:     '',
            contact_name:    '',
            email:           '',
            phone:           '',
            address:         '',
            city:            '',
            state:           '',
            specializations: [],
            hourly_rate:     '',
            rating:          '',
            is_preferred:    false,
            notes:           '',
        },

        updateSpecializations(event) {
            // Collect selected <option> values into the array
            this.form.specializations = Array.from(event.target.selectedOptions).map(o => o.value);
        },

        submit() {
            this.error = null;

            // Client-side guard for required fields
            if (!this.form.name.trim()) {
                this.error = 'Vendor name is required.';
                return;
            }
            if (!this.form.vendor_type) {
                this.error = 'Vendor type is required.';
                return;
            }

            const payload = {
                name:            this.form.name.trim(),
                vendor_type:     this.form.vendor_type,
                contact_name:    this.form.contact_name.trim() || null,
                email:           this.form.email.trim() || null,
                phone:           this.form.phone.trim() || null,
                address:         this.form.address.trim() || null,
                city:            this.form.city.trim() || null,
                state:           this.form.state.trim() || null,
                specializations: this.form.specializations,
                hourly_rate:     this.form.hourly_rate || null,
                rating:          this.form.rating ? parseInt(this.form.rating) : null,
                is_preferred:    this.form.is_preferred ? 1 : 0,
                notes:           this.form.notes.trim() || null,
            };

            this.saving = true;

            // FF_Api sends JSON + X-CSRF-Token + X-Requested-With automatically
            FF_Api.post('<?= base_url('api/v1/vendors/create.php') ?>', payload)
                .then(d => {
                    window.location = '<?= base_url('vendors/show') ?>?id=' + d.data.id;
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
