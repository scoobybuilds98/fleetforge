<?php
declare(strict_types=1);

/**
 * app/admin/equipment/edit.php
 *
 * Edit equipment unit form. Pre-populates all fields server-side to avoid
 * a blank-then-fill flash. Submits to api/v1/equipment/units/update with
 * the D19 optimistic lock (passes updated_at from initial load).
 *
 * NOTE: Status changes are intentionally excluded from this form — they
 * require state machine validation and go through the dedicated
 * api/v1/equipment/units/update_status.php endpoint.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/units/update.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4
 * @decisions D19 (optimistic lock), D30, D32
 * @session S006
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'edit');

$unitId = clean_int($_GET['id'] ?? null);
if (!$unitId) {
    header('Location: ' . base_url('equipment'));
    exit;
}

$unit = db_row(
    "SELECT u.*, t.name AS template_name
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE u.id = ? AND u.deleted_at IS NULL",
    [$unitId]
);

if (!$unit) {
    header('Location: ' . base_url('equipment'));
    exit;
}

// Load yards for dropdown
$yards = db_select("SELECT name FROM yards WHERE is_active = 1 ORDER BY name", []);

// Load equipment types for the type dropdown. The type IS changeable — it's a
// live FK; the unit's stored specs and any existing lease snapshots/rates are
// independent of it (set/frozen at their own creation time). Include this
// unit's current template even if it's now inactive, so the current selection
// always renders.
$templates = db_select(
    "SELECT id, name FROM equipment_templates
      WHERE (deleted_at IS NULL AND is_active = 1) OR id = ?
      ORDER BY name ASC",
    [$unit['template_id']]
);

$pageTitle = 'Edit Unit ' . $unit['unit_number'];
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment/show') ?>?id=<?= $unitId ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← <?= e($unit['unit_number']) ?>
        </a>
        <h1 class="page-header-title h4">Edit Unit <?= e($unit['unit_number']) ?></h1>
        <div class="text-secondary text-sm"><?= e($unit['template_name']) ?></div>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
    </div>
</div>

<!-- ============================================================
     EDIT FORM (Alpine) — server pre-populated
     ============================================================ -->
<div x-data="FF_EditUnit()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Identity ─────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Unit Identity</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label required" for="template_id">Equipment Type</label>
                        <select id="template_id" name="template_id" class="form-control form-select"
                                x-model.number="form.template_id">
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>"><?= e($tpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" data-error-for="template_id"></div>
                        <div class="form-hint">Existing leases and their rates are unaffected; new leases will use the selected type's rates.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="unit_number">Unit Number</label>
                        <input type="text" id="unit_number" name="unit_number" class="form-control font-mono"
                               x-model="form.unit_number"
                               maxlength="100">
                        <div class="field-error" data-error-for="unit_number"></div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="vin">VIN</label>
                        <input type="text" id="vin" name="vin" class="form-control font-mono"
                               x-model="form.vin" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="year">Year</label>
                        <input type="number" id="year" name="year" class="form-control font-mono"
                               x-model="form.year" min="1900" max="<?= (int) date('Y') + 1 ?>">
                        <div class="field-error" data-error-for="year"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="ownership_type">Ownership</label>
                        <select id="ownership_type" name="ownership_type" class="form-control form-select"
                                x-model="form.ownership_type">
                            <option value="owned">Owned</option>
                            <option value="leased">Leased</option>
                            <option value="brokered">Brokered</option>
                        </select>
                        <div class="field-error" data-error-for="ownership_type"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 2: Location & GPS ───────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Location & GPS</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="yard_location">Yard Location</label>
                        <select id="yard_location" class="form-control form-select" x-model="form.yard_location">
                            <option value="">— No yard assigned —</option>
                            <?php foreach ($yards as $yard): ?>
                            <option value="<?= e($yard['name']) ?>"><?= e($yard['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tracking_provider">Tracking Provider</label>
                        <select id="tracking_provider" class="form-control form-select" x-model="form.tracking_provider">
                            <option value="none">None</option>
                            <option value="samsara">Samsara</option>
                        </select>
                    </div>
                </div>
                <div class="form-row-2" x-show="form.tracking_provider === 'samsara'">
                    <div class="form-group">
                        <label class="form-label" for="gps_device_id">GPS Device ID</label>
                        <input type="text" id="gps_device_id" class="form-control font-mono"
                               x-model="form.gps_device_id" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="samsara_vehicle_url">Samsara Vehicle URL</label>
                        <input type="url" id="samsara_vehicle_url" class="form-control"
                               x-model="form.samsara_vehicle_url" maxlength="500">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 3: Physical Specs ───────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Physical Specifications</div></div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="length_ft">Length (ft)</label>
                        <input type="number" min="0" id="length_ft" name="length_ft" class="form-control font-mono" x-model="form.length_ft" step="0.01">
                        <div class="field-error" data-error-for="length_ft"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="width_ft">Width (ft)</label>
                        <input type="number" min="0" id="width_ft" name="width_ft" class="form-control font-mono" x-model="form.width_ft" step="0.01">
                        <div class="field-error" data-error-for="width_ft"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="height_ft">Height (ft)</label>
                        <input type="number" min="0" id="height_ft" name="height_ft" class="form-control font-mono" x-model="form.height_ft" step="0.01">
                        <div class="field-error" data-error-for="height_ft"></div>
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="axle_count">Axle Count</label>
                        <input type="number" min="0" id="axle_count" name="axle_count" class="form-control font-mono" x-model="form.axle_count">
                        <div class="field-error" data-error-for="axle_count"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tire_size">Tire Size</label>
                        <input type="text" id="tire_size" name="tire_size" class="form-control font-mono" x-model="form.tire_size" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="weight_capacity_lbs">Weight Capacity (lbs)</label>
                        <input type="number" min="0" id="weight_capacity_lbs" name="weight_capacity_lbs" class="form-control font-mono" x-model="form.weight_capacity_lbs">
                        <div class="field-error" data-error-for="weight_capacity_lbs"></div>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="license_plate">License Plate</label>
                        <input type="text" id="license_plate" class="form-control font-mono" x-model="form.license_plate" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="license_state">Province / State</label>
                        <input type="text" id="license_state" class="form-control" x-model="form.license_state" maxlength="50">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage">Current Mileage</label>
                        <input type="number" min="0" id="mileage" name="mileage" class="form-control font-mono" x-model="form.mileage">
                        <div class="field-error" data-error-for="mileage"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="acquired_date">Acquired Date</label>
                        <input type="date" id="acquired_date" class="form-control" x-model="form.acquired_date">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 4: Compliance Dates ─────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Compliance & Expiry</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="cvi_expiry">CVI Expiry</label>
                        <input type="date" id="cvi_expiry" class="form-control" x-model="form.cvi_expiry">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="registration_expiry">Registration Expiry</label>
                        <input type="date" id="registration_expiry" class="form-control" x-model="form.registration_expiry">
                    </div>
                </div>
                <!-- MVI Expiry and Insurance Expiry inputs hidden from the editor (operator request 2026-06-17). -->
                <!-- mvi_expiry / insurance_expiry stay in the form data object (init below) so existing -->
                <!-- values round-trip unchanged on save; columns retained, just no longer UI-editable. -->
            </div>
        </div>

        <!-- ── Section 5: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" class="form-control" x-model="form.notes" rows="3" maxlength="5000"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" class="form-control" x-model="form.internal_notes" rows="2" maxlength="5000"></textarea>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('equipment/show') ?>?id=<?= $unitId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting"
                    :class="submitting ? 'is-loading' : ''">
                <span x-show="!submitting">Save Changes</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <!-- VALID-2: form-level error banner injected by FF_Validate.banner() -->
        <div class="form-error-banner" data-form-error></div>

    </form>
</div>

<script>
function FF_EditUnit() {
    return {
        form: {
            id:                  <?= $unitId ?>,
            updated_at:          <?= json_encode($unit['updated_at']) ?>,
            template_id:         <?= (int)$unit['template_id'] ?>,
            unit_number:         <?= json_encode($unit['unit_number']) ?>,
            vin:                 <?= json_encode($unit['vin'] ?? '') ?>,
            year:                <?= json_encode($unit['year'] ?? '') ?>,
            ownership_type:      <?= json_encode($unit['ownership_type']) ?>,
            yard_location:       <?= json_encode($unit['yard_location'] ?? '') ?>,
            tracking_provider:   <?= json_encode($unit['tracking_provider'] ?? 'none') ?>,
            gps_device_id:       <?= json_encode($unit['gps_device_id'] ?? '') ?>,
            samsara_vehicle_url: <?= json_encode($unit['samsara_vehicle_url'] ?? '') ?>,
            length_ft:           <?= json_encode($unit['length_ft'] ?? '') ?>,
            height_ft:           <?= json_encode($unit['height_ft'] ?? '') ?>,
            width_ft:            <?= json_encode($unit['width_ft'] ?? '') ?>,
            weight_capacity_lbs: <?= json_encode($unit['weight_capacity_lbs'] ?? '') ?>,
            axle_count:          <?= json_encode($unit['axle_count'] ?? '') ?>,
            tire_size:           <?= json_encode($unit['tire_size'] ?? '') ?>,
            license_plate:       <?= json_encode($unit['license_plate'] ?? '') ?>,
            license_state:       <?= json_encode($unit['license_state'] ?? '') ?>,
            mileage:             <?= (int)$unit['mileage'] ?>,
            acquired_date:       <?= json_encode($unit['acquired_date'] ?? '') ?>,
            cvi_expiry:          <?= json_encode($unit['cvi_expiry'] ?? '') ?>,
            registration_expiry: <?= json_encode($unit['registration_expiry'] ?? '') ?>,
            mvi_expiry:          <?= json_encode($unit['mvi_expiry'] ?? '') ?>,
            insurance_expiry:    <?= json_encode($unit['insurance_expiry'] ?? '') ?>,
            notes:               <?= json_encode($unit['notes'] ?? '') ?>,
            internal_notes:      <?= json_encode($unit['internal_notes'] ?? '') ?>,
        },
        submitting:  false,

        init() {
            // S-FORM-DRAFT-ROLLOUT: opt into the shared autosave helper. Exclude id +
            // updated_at (the D19 optimistic-lock token) so a restore can never
            // re-inject a stale token — only editable fields are persisted/restored.
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId: 'equipment-unit-edit', entityId: this.form.id,
                    el: this.$root, model: this.form, version: '1',
                    exclude: ['id', 'updated_at'],
                });
            }
        },

        // VALID-2: unified validation using FF_Validate
        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.template_id) {
                FF_Validate.field(form, 'template_id', 'Please select an equipment type.');
                ok = false;
            }
            if (!this.form.unit_number || !this.form.unit_number.trim()) {
                FF_Validate.field(form, 'unit_number', 'Unit number is required.');
                ok = false;
            }
            if (!this.form.ownership_type) {
                FF_Validate.field(form, 'ownership_type', 'Please select an ownership type (owned, leased, or brokered).');
                ok = false;
            }

            // Year 1900 – current+1
            if (this.form.year !== '' && this.form.year !== null && this.form.year !== undefined) {
                const y = parseInt(this.form.year, 10);
                const maxY = new Date().getFullYear() + 1;
                if (isNaN(y) || y < 1900 || y > maxY) {
                    FF_Validate.field(form, 'year', `Year must be between 1900 and ${maxY}.`);
                    ok = false;
                }
            }

            // Odometer >= 0
            if (this.form.mileage !== '' && this.form.mileage !== null && this.form.mileage !== undefined) {
                const m = parseInt(this.form.mileage, 10);
                if (!isNaN(m) && m < 0) {
                    FF_Validate.field(form, 'mileage', 'Odometer cannot be negative.');
                    ok = false;
                }
            }

            // Dimensions > 0 if provided
            const posDecChecks = [
                ['length_ft', 'Length'],
                ['width_ft',  'Width'],
                ['height_ft', 'Height'],
            ];
            posDecChecks.forEach(([k, label]) => {
                const v = this.form[k];
                if (v !== '' && v !== null && v !== undefined) {
                    const n = parseFloat(v);
                    if (isNaN(n) || n <= 0) {
                        FF_Validate.field(form, k, `${label} must be greater than zero.`);
                        ok = false;
                    }
                }
            });

            if (this.form.weight_capacity_lbs !== '' && this.form.weight_capacity_lbs !== null && this.form.weight_capacity_lbs !== undefined) {
                const w = parseInt(this.form.weight_capacity_lbs, 10);
                if (isNaN(w) || w <= 0) {
                    FF_Validate.field(form, 'weight_capacity_lbs', 'Weight capacity must be greater than zero.');
                    ok = false;
                }
            }

            if (this.form.axle_count !== '' && this.form.axle_count !== null && this.form.axle_count !== undefined) {
                const a = parseInt(this.form.axle_count, 10);
                if (isNaN(a) || a <= 0) {
                    FF_Validate.field(form, 'axle_count', 'Axle count must be greater than zero.');
                    ok = false;
                }
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting = true;
            const form = document.querySelector('form');
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/units/update') ?>', this.form);
                if (r.success) {
                    if (this._draft) this._draft.clear(true);   // S-FORM-DRAFT-ROLLOUT: wipe draft on confirmed save
                    window.location.href = '<?= base_url('equipment/show') ?>?id=<?= $unitId ?>';
                } else if (r.error?.code === 'STALE_DATA') {
                    FF_Validate.banner(form, (r.error?.message || 'This unit was modified by another user.') + ' Reload this page to get the latest version.');
                    FF_Validate.scrollToFirst(form);
                } else if (r.error?.code === 'VALIDATION_ERROR' && r.error?.fields) {
                    FF_Validate.applyApi(form, r.error);
                    FF_Validate.scrollToFirst(form);
                } else if (r.error?.fields) {
                    FF_Validate.applyApi(form, r.error);
                    FF_Validate.scrollToFirst(form);
                } else {
                    FF_Validate.banner(form, r.error?.message || r.message || 'Failed to save changes.');
                    FF_Validate.scrollToFirst(form);
                }
            } catch(e) {
                FF_Validate.banner(form, 'Network error. Please try again.');
                FF_Validate.scrollToFirst(form);
            }
            this.submitting = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
