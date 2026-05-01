<?php
declare(strict_types=1);

/**
 * app/admin/vendors/edit.php
 *
 * Edit Vendor form.
 * Pre-populates all fields server-side to avoid a blank-then-fill flash.
 * Submits to api/v1/vendors/update.php with D19 optimistic lock.
 *
 * [UI-AUDIT-1:M6] This page was missing from the original scaffold — the
 * vendors module shipped with create.php and show.php but no edit path,
 * making it impossible to fix typos, phone numbers, or rating changes
 * through the UI. This restores full CRUD for vendors.
 *
 * Fields mirror create.php except:
 *   - Pre-populated from the existing row via PHP json_encode().
 *   - updated_at is captured on initial load and sent back to the API
 *     so a stale edit is caught by D19 rather than silently overwriting.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php,
 *           includes/footer.php, api/v1/vendors/update.php
 * @decisions D7/D19/D30/D32
 * @session  UI-AUDIT-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// Load vendor server-side — 404 if not found.
// -----------------------------------------------------------------------
$vendorId = clean_int($_GET['id'] ?? null);
if (!$vendorId) {
    header('Location: ' . base_url('vendors'));
    exit;
}

$vendor = db_row(
    "SELECT * FROM vendors WHERE id = ? AND deleted_at IS NULL",
    [$vendorId]
);
if (!$vendor) {
    header('Location: ' . base_url('vendors'));
    exit;
}

// Decode specializations JSON for the multi-select.
$existingSpecs = [];
if (!empty($vendor['specializations'])) {
    $decoded = json_decode($vendor['specializations'], true);
    if (is_array($decoded)) {
        $existingSpecs = $decoded;
    }
}

// Build the Alpine seed — same shape as create.php so the form markup
// stays identical.
$formSeed = [
    'name'            => $vendor['name']            ?? '',
    'vendor_type'     => $vendor['vendor_type']     ?? '',
    'contact_name'    => $vendor['contact_name']    ?? '',
    'email'           => $vendor['email']           ?? '',
    'phone'           => $vendor['phone']           ?? '',
    'address'         => $vendor['address']         ?? '',
    'city'            => $vendor['city']            ?? '',
    'state'           => $vendor['state']           ?? '',
    'specializations' => $existingSpecs,
    'hourly_rate'     => $vendor['hourly_rate']     ?? '',
    'rating'          => $vendor['rating']          ?? '',
    'is_preferred'    => (bool) ($vendor['is_preferred'] ?? false),
    'notes'           => $vendor['notes']           ?? '',
];

$pageTitle = 'Edit Vendor — ' . $vendor['name'];
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('vendors/show?id=' . $vendorId) ?>" class="btn btn-secondary btn-sm">← Back</a>
    <h1 class="page-header-title">Edit Vendor</h1>
</div>

<div x-data="vendorEdit()" @submit.prevent="submit">

<div class="card" style="max-width:760px;">

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
                       maxlength="255"
                       @blur="if(form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) error = 'Invalid email format: ' + form.email; else if(error && error.startsWith('Invalid email')) error = null;">
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
                    x-ref="specSelect"
                    @change="updateSpecializations($event)">
                <?php
                // WHY: render <option selected> server-side so the user's current
                // selections are highlighted before Alpine hydrates.
                $specOptions = ['Brakes','Diesel Engine','Electrical','Exhaust','Hydraulics','HVAC','Suspension','Tires','Transmission','Welding'];
                foreach ($specOptions as $opt):
                    $sel = in_array($opt, $existingSpecs, true) ? ' selected' : '';
                ?>
                <option value="<?= e($opt) ?>"<?= $sel ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
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
                      maxlength="2000"
                      placeholder="Internal notes, access instructions, etc."></textarea>
            <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
        </div>

        <!-- ── Submit ───────────────────────────────────────────────────── -->
        <div style="display:flex;gap:12px;padding-top:16px;border-top:1px solid var(--border-default);">
            <button type="button" class="btn btn-primary"
                    :disabled="saving"
                    @click="submit()">
                <span x-show="!saving">Save Changes</span>
                <span x-show="saving">Saving…</span>
            </button>
            <a href="<?= base_url('vendors/show?id=' . $vendorId) ?>" class="btn btn-secondary">Cancel</a>
        </div>

    </div><!-- /card-body -->
</div><!-- /card -->

</div><!-- /x-data wrapper -->

<script>
function vendorEdit() {
    return {
        vendorId:    <?= (int) $vendorId ?>,
        // [D19] captured at page load — sent back to the API so the update
        // is rejected if another user saved in the meantime.
        updatedAt:   <?= json_encode($vendor['updated_at']) ?>,
        saving:      false,
        error:       null,
        form:        <?= json_encode($formSeed) ?>,

        updateSpecializations(event) {
            // Collect selected <option> values into the array.
            this.form.specializations = Array.from(event.target.selectedOptions).map(o => o.value);
        },

        async submit() {
            this.error = null;

            if (!this.form.name.trim()) {
                this.error = 'Vendor name is required.';
                return;
            }
            if (!this.form.vendor_type) {
                this.error = 'Vendor type is required.';
                return;
            }

            const payload = {
                id:              this.vendorId,
                updated_at:      this.updatedAt,
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

            try {
                const d = await FF_Api.post('<?= base_url('api/v1/vendors/update.php') ?>', payload);
                FF_Toast.success('Vendor updated.');
                // WHY: navigate to show page so the user sees the saved record.
                setTimeout(() => {
                    window.location = '<?= base_url('vendors/show') ?>?id=' + this.vendorId;
                }, 600);
            } catch (err) {
                // [D19] Detect STALE_DATA specifically so the user knows why.
                const code = err?.data?.code || err?.error?.code;
                if (code === 'STALE_DATA') {
                    this.error = 'This vendor was modified by another user. Reload the page and try again.';
                } else {
                    this.error = err?.data?.message ?? err?.message ?? 'Save failed. Please try again.';
                }
                this.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
