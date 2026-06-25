<?php
declare(strict_types=1);

/**
 * app/admin/equipment/create.php
 *
 * New equipment unit form. Loads active templates for the dropdown. When a
 * template is selected, the JS component pre-fills dimension/rate defaults.
 * On submit, POSTs to api/v1/equipment/units/create and redirects to show page.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/units/create.php,
 *          api/v1/equipment/templates/index.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4 Equipment Units
 * @decisions D30, D32
 * @session S006
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'create');

$pageTitle = 'Add Equipment Unit';

// Load active templates server-side for <select> options (no flicker)
$templates = db_select(
    "SELECT id, name, category, default_daily_rate, default_weekly_rate,
            default_monthly_rate, default_mileage_rate, default_currency,
            default_mileage_unit, default_length_ft, default_height_ft,
            default_width_ft, default_weight_capacity_lbs, default_axle_count,
            default_ownership_type, default_tracking_provider
       FROM equipment_templates
      WHERE deleted_at IS NULL AND is_active = 1
      ORDER BY name ASC",
    []
);

// Load yards for yard_location dropdown
$yards = db_select("SELECT name FROM yards WHERE is_active = 1 ORDER BY name", []);

$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← Equipment
        </a>
        <h1 class="page-header-title h4">Add Equipment Unit</h1>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
    </div>
</div>

<!-- ============================================================
     CREATE FORM (Alpine)
     ============================================================ -->
<div x-data="FF_CreateUnit()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Identity ─────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Unit Identity</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label required" for="template_id">Equipment Type</label>
                        <select id="template_id" name="template_id" class="form-control form-select"
                                x-model="form.template_id"
                                @change="onTemplateChange()">
                            <option value="">— Select equipment type —</option>
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?= $tpl['id'] ?>"
                                    data-defaults="<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES) ?>">
                                <?= e($tpl['name']) ?>
                                (<?= e(str_replace('_', ' ', $tpl['category'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-error" data-error-for="template_id"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required" for="unit_number">Unit Number</label>
                        <input type="text" id="unit_number" name="unit_number" class="form-control font-mono"
                               x-model="form.unit_number"
                               placeholder="e.g. T-1042"
                               maxlength="100">
                        <div class="form-hint">Must be unique across all units.</div>
                        <div class="field-error" data-error-for="unit_number"></div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="vin">VIN</label>
                        <input type="text" id="vin" name="vin" class="form-control font-mono"
                               x-model="form.vin"
                               placeholder="17-char VIN"
                               maxlength="50"
                               @input.debounce.400ms="checkVin()">
                        <div class="field-error" data-error-for="vin"></div>
                        <!-- S-UNIT-VIN-MOVE: live "already in use" note (create has no unit yet
                             to move into — the operator picks a different VIN or fixes the holder). -->
                        <template x-if="vinConflict">
                            <div style="margin-top:6px;font-size:0.85rem;color:var(--color-warning-text, #b45309);">
                                &#9888; VIN already in use by <span x-show="vinConflict.deleted">deleted </span>unit <strong x-text="vinConflict.unit_number"></strong> — use a different VIN, or free it on that unit first.
                            </div>
                        </template>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="year">Year</label>
                        <input type="number" min="0" id="year" name="year" class="form-control font-mono"
                               x-model="form.year"
                               placeholder="e.g. <?= date('Y') ?>"
                               min="1900" max="<?= (int) date('Y') + 1 ?>">
                        <div class="field-error" data-error-for="year"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="ownership_type">Ownership</label>
                        <select id="ownership_type" name="ownership_type" class="form-control form-select"
                                x-model="form.ownership_type">
                            <option value="">— Select —</option>
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
                        <select id="yard_location" class="form-control form-select"
                                x-model="form.yard_location">
                            <option value="">— No yard assigned —</option>
                            <?php foreach ($yards as $yard): ?>
                            <option value="<?= e($yard['name']) ?>"><?= e($yard['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tracking_provider">Tracking Provider</label>
                        <select id="tracking_provider" class="form-control form-select"
                                x-model="form.tracking_provider">
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
                        <input type="number" min="0" id="length_ft" name="length_ft" class="form-control font-mono"
                               x-model="form.length_ft" step="0.01">
                        <div class="field-error" data-error-for="length_ft"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="width_ft">Width (ft)</label>
                        <input type="number" min="0" id="width_ft" name="width_ft" class="form-control font-mono"
                               x-model="form.width_ft" step="0.01">
                        <div class="field-error" data-error-for="width_ft"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="height_ft">Height (ft)</label>
                        <input type="number" min="0" id="height_ft" name="height_ft" class="form-control font-mono"
                               x-model="form.height_ft" step="0.01">
                        <div class="field-error" data-error-for="height_ft"></div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="axle_count">Axle Count</label>
                        <input type="number" min="0" id="axle_count" name="axle_count" class="form-control font-mono"
                               x-model="form.axle_count">
                        <div class="field-error" data-error-for="axle_count"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tire_size">Tire Size</label>
                        <input type="text" id="tire_size" name="tire_size" class="form-control font-mono"
                               x-model="form.tire_size" maxlength="50" placeholder="e.g. 275/70R22.5">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="weight_capacity_lbs">Weight Capacity (lbs)</label>
                        <input type="number" min="0" id="weight_capacity_lbs" name="weight_capacity_lbs" class="form-control font-mono"
                               x-model="form.weight_capacity_lbs">
                        <div class="field-error" data-error-for="weight_capacity_lbs"></div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="license_plate">License Plate</label>
                        <input type="text" id="license_plate" class="form-control font-mono"
                               x-model="form.license_plate" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="license_state">Province / State</label>
                        <input type="text" id="license_state" class="form-control"
                               x-model="form.license_state" maxlength="50" placeholder="e.g. BC">
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage">Current Mileage</label>
                        <input type="number" min="0" id="mileage" name="mileage" class="form-control font-mono"
                               x-model="form.mileage" placeholder="0">
                        <div class="field-error" data-error-for="mileage"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="acquired_date">Acquired Date</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="acquired_date" class="form-control"
                                   x-model="form.acquired_date"
                                   x-ref="eqAcqDate" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.eqAcqDate.showPicker ? $refs.eqAcqDate.showPicker() : $refs.eqAcqDate.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 4: Compliance Dates ─────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Compliance & Expiry Dates</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="cvi_expiry">CVI Expiry</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="cvi_expiry" class="form-control" x-model="form.cvi_expiry"
                                   min="<?= date('Y-m-d') ?>" x-ref="eqCviExp" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.eqCviExp.showPicker ? $refs.eqCviExp.showPicker() : $refs.eqCviExp.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cvi_interval_days">CVI Renewal Interval (days)</label>
                        <input type="number" id="cvi_interval_days" class="form-control font-mono"
                               x-model="form.cvi_interval_days" min="1" placeholder="365">
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="registration_expiry">Registration Expiry</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="registration_expiry" class="form-control" x-model="form.registration_expiry"
                                   min="<?= date('Y-m-d') ?>" x-ref="eqRegExp" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.eqRegExp.showPicker ? $refs.eqRegExp.showPicker() : $refs.eqRegExp.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mvi_expiry">MVI Expiry</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="mvi_expiry" class="form-control" x-model="form.mvi_expiry"
                                   min="<?= date('Y-m-d') ?>" x-ref="eqMviExp" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.eqMviExp.showPicker ? $refs.eqMviExp.showPicker() : $refs.eqMviExp.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="insurance_expiry">Insurance Expiry</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="insurance_expiry" class="form-control" x-model="form.insurance_expiry"
                                   min="<?= date('Y-m-d') ?>" x-ref="eqInsExp" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.eqInsExp.showPicker ? $refs.eqInsExp.showPicker() : $refs.eqInsExp.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="insurance_interval_days">Insurance Renewal Interval (days)</label>
                        <input type="number" id="insurance_interval_days" class="form-control font-mono"
                               x-model="form.insurance_interval_days" min="1" placeholder="365">
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 5: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" class="form-control" x-model="form.notes"
                                  rows="3" maxlength="2000"
                                  placeholder="General notes about this unit…"></textarea>
                        <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="internal_notes">Internal Notes (not shown to customers)</label>
                        <textarea id="internal_notes" class="form-control" x-model="form.internal_notes"
                                  rows="3" maxlength="2000"></textarea>
                        <div class="form-hint" style="text-align:right;" x-text="(form.internal_notes || '').length + ' / 2000'"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('equipment') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting"
                    :class="submitting ? 'is-loading' : ''">
                <span x-show="!submitting">Register Unit</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <!-- VALID-2: form-level error banner injected by FF_Validate.banner() -->
        <div class="form-error-banner" data-form-error></div>

    </form>
</div><!-- /x-data -->

<script>
function FF_CreateUnit() {
    // Build template defaults lookup from server-rendered options
    const templateDefaults = {};
    document.querySelectorAll('#template_id option[data-defaults]').forEach(opt => {
        try { templateDefaults[opt.value] = JSON.parse(opt.dataset.defaults); } catch(e) {}
    });

    return {
        form: {
            template_id:        '',
            unit_number:        '',
            vin:                '',
            year:               '',
            ownership_type:     '',
            yard_location:      '',
            tracking_provider:  'none',
            gps_device_id:      '',
            samsara_vehicle_url:'',
            length_ft:          '',
            height_ft:          '',
            width_ft:           '',
            weight_capacity_lbs:'',
            axle_count:         '',
            tire_size:          '',
            license_plate:      '',
            license_state:      '',
            mileage:            0,
            acquired_date:      '',
            cvi_expiry:         '',
            registration_expiry:'',
            mvi_expiry:         '',
            insurance_expiry:   '',
            cvi_interval_days:  '',
            insurance_interval_days: '',
            notes:              '',
            internal_notes:     '',
        },
        submitting:         false,
        showSuccessOverlay: false,
        // S-UNIT-VIN-MOVE: live "VIN already in use" lookup as the operator types.
        vinConflict:        null,
        async checkVin() {
            const vin = (this.form.vin || '').trim();
            if (!vin) { this.vinConflict = null; return; }
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/vin_check') ?>?vin=' + encodeURIComponent(vin));
                this.vinConflict = (r.success && r.data && r.data.taken) ? r.data : null;
            } catch (e) { this.vinConflict = null; }
        },

        init() {
            // S-FORM-DRAFT-ROLLOUT: opt into the shared autosave helper (reused, not modified).
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId: 'equipment-unit-create', entityId: 'new',
                    el: this.$root, model: this.form, version: '1',
                });
            }
        },

        // Pre-fill defaults from selected template
        onTemplateChange() {
            const defaults = templateDefaults[this.form.template_id];
            if (!defaults) return;
            if (defaults.default_length_ft)          this.form.length_ft           = defaults.default_length_ft;
            if (defaults.default_height_ft)          this.form.height_ft           = defaults.default_height_ft;
            if (defaults.default_width_ft)           this.form.width_ft            = defaults.default_width_ft;
            if (defaults.default_weight_capacity_lbs) this.form.weight_capacity_lbs = defaults.default_weight_capacity_lbs;
            if (defaults.default_axle_count)         this.form.axle_count          = defaults.default_axle_count;
            if (defaults.default_ownership_type)     this.form.ownership_type      = defaults.default_ownership_type;
            if (defaults.default_tracking_provider)  this.form.tracking_provider   = defaults.default_tracking_provider;
            if (defaults.default_cvi_interval_days)  this.form.cvi_interval_days   = defaults.default_cvi_interval_days;
            if (defaults.default_insurance_interval_days) this.form.insurance_interval_days = defaults.default_insurance_interval_days;
        },

        // VALID-2: unified validation using FF_Validate, exact spec messages
        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.template_id) {
                FF_Validate.field(form, 'template_id', 'Please select an equipment template.');
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

            // Odometer / mileage >= 0
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

            // Weight capacity > 0 if provided
            if (this.form.weight_capacity_lbs !== '' && this.form.weight_capacity_lbs !== null && this.form.weight_capacity_lbs !== undefined) {
                const w = parseInt(this.form.weight_capacity_lbs, 10);
                if (isNaN(w) || w <= 0) {
                    FF_Validate.field(form, 'weight_capacity_lbs', 'Weight capacity must be greater than zero.');
                    ok = false;
                }
            }

            // Axle count > 0 if provided
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

            const payload = {};
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });
            if (payload.year)               payload.year               = parseInt(payload.year);
            if (payload.mileage)            payload.mileage            = parseInt(payload.mileage);
            if (payload.weight_capacity_lbs) payload.weight_capacity_lbs = parseInt(payload.weight_capacity_lbs);
            if (payload.axle_count)         payload.axle_count         = parseInt(payload.axle_count);
            if (payload.cvi_interval_days)  payload.cvi_interval_days  = parseInt(payload.cvi_interval_days);
            if (payload.insurance_interval_days) payload.insurance_interval_days = parseInt(payload.insurance_interval_days);

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment/units/create') ?>', payload);
                if (r.success) {
                    if (this._draft) this._draft.clear(true);   // S-FORM-DRAFT-ROLLOUT: wipe draft on confirmed save
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('equipment/show') ?>?id=' + _newId; }, 3500);
                } else if (r.error?.code === 'VALIDATION_ERROR' && r.error?.fields) {
                    FF_Validate.applyApi(form, r.error);
                    FF_Validate.scrollToFirst(form);
                } else if (r.error?.fields) {
                    FF_Validate.applyApi(form, r.error);
                    FF_Validate.scrollToFirst(form);
                } else {
                    FF_Validate.banner(form, r.error?.message || r.message || 'Failed to create unit.');
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

<?php
$overlayTitle    = 'Unit Added!';
$overlaySubtitle = 'Redirecting to equipment details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
