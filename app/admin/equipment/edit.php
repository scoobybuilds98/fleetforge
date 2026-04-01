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
 * require state machine validation and go through a dedicated endpoint
 * (built in a future session with the lease/maintenance modules).
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

$pageTitle = 'Edit Unit ' . $unit['unit_number'];
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
                        <label class="form-label">Equipment Template</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;"><?= e($unit['template_name']) ?></div>
                        <div class="form-hint">Template cannot be changed after creation.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="unit_number">Unit Number</label>
                        <input type="text" id="unit_number" class="form-control font-mono"
                               x-model="form.unit_number"
                               :class="errors.unit_number ? 'is-invalid' : ''"
                               maxlength="100">
                        <div class="form-error" x-show="errors.unit_number" x-text="errors.unit_number"></div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="vin">VIN</label>
                        <input type="text" id="vin" class="form-control font-mono"
                               x-model="form.vin" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="year">Year</label>
                        <input type="number" id="year" class="form-control font-mono"
                               x-model="form.year" min="1990" max="2030">
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="ownership_type">Ownership</label>
                        <select id="ownership_type" class="form-control form-select"
                                x-model="form.ownership_type"
                                :class="errors.ownership_type ? 'is-invalid' : ''">
                            <option value="owned">Owned</option>
                            <option value="leased">Leased</option>
                            <option value="brokered">Brokered</option>
                        </select>
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
                        <input type="number" id="length_ft" class="form-control font-mono" x-model="form.length_ft" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="width_ft">Width (ft)</label>
                        <input type="number" id="width_ft" class="form-control font-mono" x-model="form.width_ft" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="height_ft">Height (ft)</label>
                        <input type="number" id="height_ft" class="form-control font-mono" x-model="form.height_ft" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="axle_count">Axle Count</label>
                        <input type="number" id="axle_count" class="form-control font-mono" x-model="form.axle_count" min="1" max="20">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tire_size">Tire Size</label>
                        <input type="text" id="tire_size" class="form-control font-mono" x-model="form.tire_size" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="weight_capacity_lbs">Weight Capacity (lbs)</label>
                        <input type="number" id="weight_capacity_lbs" class="form-control font-mono" x-model="form.weight_capacity_lbs" min="0">
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
                        <input type="number" id="mileage" class="form-control font-mono" x-model="form.mileage" min="0">
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
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mvi_expiry">MVI Expiry</label>
                        <input type="date" id="mvi_expiry" class="form-control" x-model="form.mvi_expiry">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="insurance_expiry">Insurance Expiry</label>
                        <input type="date" id="insurance_expiry" class="form-control" x-model="form.insurance_expiry">
                    </div>
                </div>
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

        <template x-if="globalError">
            <div class="card card-body" style="background:var(--color-danger-light);color:var(--color-danger-text);margin-bottom:1rem;">
                <strong>Error:</strong> <span x-text="globalError"></span>
            </div>
        </template>

    </form>
</div>

<script>
function FF_EditUnit() {
    return {
        form: {
            id:                  <?= $unitId ?>,
            updated_at:          <?= json_encode($unit['updated_at']) ?>,
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
        errors:      {},
        globalError: null,
        submitting:  false,

        init() {},

        validate() {
            this.errors = {};
            if (!this.form.unit_number.trim()) this.errors.unit_number = 'Unit number is required.';
            if (!this.form.ownership_type)     this.errors.ownership_type = 'Ownership type is required.';
            return Object.keys(this.errors).length === 0;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            this.globalError = null;
            // Send all form fields (API ignores unchanged values, updated_at is for lock)
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/units/update') ?>', this.form);
                if (r.success) {
                    window.location.href = '<?= base_url('equipment/show') ?>?id=<?= $unitId ?>';
                } else if (r.code === 'STALE_DATA') {
                    this.globalError = r.message + ' Reload this page to get the latest version.';
                } else {
                    this.globalError = r.message || 'Failed to save changes.';
                    if (r.errors) this.errors = r.errors;
                }
            } catch(e) {
                this.globalError = 'Network error. Please try again.';
            }
            this.submitting = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
