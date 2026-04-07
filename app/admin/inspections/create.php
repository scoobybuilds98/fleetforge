<?php
declare(strict_types=1);

/**
 * app/admin/inspections/create.php
 *
 * New inspection form.
 * Alpine.js submits via FF_Api.post() → redirect to show on success.
 *
 * Pre-populate:
 *   ?unit_id=N    — from equipment profile "Start Inspection" button
 *   ?lease_id=N   — from lease profile "Start Pre/Post Inspection" button
 *   ?type=pre_lease|post_lease — from lease profile buttons
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/inspections/create.php
 * @decisions D7/D30/D32
 * @session  S016
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('inspections', 'create');

// ── Load equipment units for dropdown
$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.year, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY eu.unit_number ASC"
);

// ── Load active/pending leases for optional lease linkage
$leases = db_select(
    "SELECT l.id, l.contract_number, c.company_name
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     WHERE l.deleted_at IS NULL AND l.status IN ('pending','active')
     ORDER BY l.contract_number ASC"
);

// ── Load staff users for inspector dropdown
$users = db_select(
    "SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name ASC"
);

// ── Pre-populate from query params
$preUnitId  = clean_int($_GET['unit_id']  ?? null);
$preLeaseId = clean_int($_GET['lease_id'] ?? null);
$preType    = clean_string($_GET['type']  ?? null);
$validPreTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
if (!in_array($preType, $validPreTypes, true)) $preType = 'pre_lease';

$pageTitle = 'New Inspection';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('inspections') ?>" class="btn btn-secondary btn-sm">Back to Inspections</a>
    <h1 class="page-header-title">New Inspection</h1>
</div>

<div x-data="createInspection()" x-init="init()">

<div class="card" style="max-width:720px;">

    <div class="card-header">
        <h2 class="card-title">Inspection Details</h2>
    </div>

    <div class="card-body" style="display:flex;flex-direction:column;gap:20px;">

        <!-- Form-wide error banner -->
        <div x-show="formError" class="form-error-banner" x-text="formError" x-cloak></div>

        <!-- Equipment Unit + Inspection Type -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Equipment Unit <span class="text-danger">*</span></label>
                <select class="form-control" :class="errors.equipment_unit_id ? 'is-invalid' : ''"
                        x-model="form.equipment_unit_id" required>
                    <option value="">— Select unit —</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= e($u['id']) ?>">
                        <?= e($u['unit_number']) ?><?= $u['year'] ? ' — ' . e($u['year']) : '' ?><?= $u['brand'] ? ' ' . e($u['brand']) . ' ' . e($u['model']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" x-show="errors.equipment_unit_id" x-text="errors.equipment_unit_id" x-cloak></div>
            </div>
            <div class="form-group">
                <label class="form-label">Inspection Type <span class="text-danger">*</span></label>
                <select class="form-control" :class="errors.inspection_type ? 'is-invalid' : ''"
                        x-model="form.inspection_type">
                    <option value="pre_lease">Pre-Lease</option>
                    <option value="post_lease">Post-Lease</option>
                    <option value="periodic">Periodic</option>
                    <option value="damage">Damage</option>
                    <option value="compliance">Compliance</option>
                </select>
                <div class="field-error" x-show="errors.inspection_type" x-text="errors.inspection_type" x-cloak></div>
            </div>
        </div>

        <!-- Lease + Inspection Date -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Linked Lease <span class="text-secondary">(optional)</span></label>
                <select class="form-control" :class="errors.lease_id ? 'is-invalid' : ''"
                        x-model="form.lease_id">
                    <option value="">— None —</option>
                    <?php foreach ($leases as $l): ?>
                    <option value="<?= e($l['id']) ?>">
                        <?= e($l['contract_number']) ?> — <?= e($l['company_name'] ?? 'Unknown') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" x-show="errors.lease_id" x-text="errors.lease_id" x-cloak></div>
            </div>
            <div class="form-group">
                <label class="form-label">Inspection Date <span class="text-danger">*</span></label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="date" class="form-control" :class="errors.inspection_date ? 'is-invalid' : ''"
                           x-model="form.inspection_date" required
                           max="<?= date('Y-m-d') ?>"
                           x-ref="inspDate" style="flex:1;">
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.inspDate.showPicker ? $refs.inspDate.showPicker() : $refs.inspDate.click()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    </button>
                </div>
                <div class="field-error" x-show="errors.inspection_date" x-text="errors.inspection_date" x-cloak></div>
            </div>
        </div>

        <!-- Inspector -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Inspected By (Name)</label>
                <input type="text" class="form-control" :class="errors.inspected_by ? 'is-invalid' : ''"
                       placeholder="Inspector name"
                       x-model="form.inspected_by">
                <div class="field-error" x-show="errors.inspected_by" x-text="errors.inspected_by" x-cloak></div>
            </div>
            <div class="form-group">
                <label class="form-label">Inspector (User)</label>
                <select class="form-control" :class="errors.inspected_by_user_id ? 'is-invalid' : ''"
                        x-model="form.inspected_by_user_id">
                    <option value="">— Not a system user —</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" x-show="errors.inspected_by_user_id" x-text="errors.inspected_by_user_id" x-cloak></div>
            </div>
        </div>

        <!-- Trailer-specific fields -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Odometer / Mileage at Inspection</label>
                <input type="number" class="form-control" :class="errors.mileage_at_inspection ? 'is-invalid' : ''"
                       placeholder="km or miles"
                       x-model.number="form.mileage_at_inspection" min="0">
                <div class="field-error" x-show="errors.mileage_at_inspection" x-text="errors.mileage_at_inspection" x-cloak></div>
            </div>
            <div class="form-group">
                <label class="form-label">Reefer Hours</label>
                <input type="number" class="form-control" :class="errors.reefer_hours ? 'is-invalid' : ''"
                       placeholder="hours"
                       x-model.number="form.reefer_hours" min="0">
                <div class="field-error" x-show="errors.reefer_hours" x-text="errors.reefer_hours" x-cloak></div>
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Fuel Level</label>
                <select class="form-control" :class="errors.fuel_level ? 'is-invalid' : ''"
                        x-model="form.fuel_level">
                    <option value="">— Not recorded —</option>
                    <option value="empty">Empty</option>
                    <option value="quarter">1/4 Tank</option>
                    <option value="half">1/2 Tank</option>
                    <option value="three_quarter">3/4 Tank</option>
                    <option value="full">Full</option>
                </select>
                <div class="field-error" x-show="errors.fuel_level" x-text="errors.fuel_level" x-cloak></div>
            </div>
            <div class="form-group">
                <label class="form-label">CVI Expiry Date</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="date" class="form-control" :class="errors.cvi_expiry ? 'is-invalid' : ''"
                           x-model="form.cvi_expiry"
                           min="<?= date('Y-m-d') ?>"
                           x-ref="inspCviExp" style="flex:1;">
                    <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.inspCviExp.showPicker ? $refs.inspCviExp.showPicker() : $refs.inspCviExp.click()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    </button>
                </div>
                <div class="field-error" x-show="errors.cvi_expiry" x-text="errors.cvi_expiry" x-cloak></div>
            </div>
        </div>

        <!-- Clean/Dirty -->
        <div class="form-group">
            <label class="form-label">Unit Cleanliness</label>
            <div style="display:flex;gap:24px;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="is_clean" :value="1" x-model.number="form.is_clean"> Clean
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="is_clean" :value="0" x-model.number="form.is_clean"> Dirty
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="is_clean" :value="null" x-model="form.is_clean"> Not recorded
                </label>
            </div>
        </div>

        <!-- Notes -->
        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea class="form-control" rows="3"
                      placeholder="General inspection notes..."
                      x-model="form.notes"></textarea>
        </div>

    </div><!-- /card-body -->

    <div class="card-footer" style="display:flex;gap:12px;align-items:center;border-top:1px solid var(--border-color);padding-top:16px;">
        <button class="btn btn-primary" :disabled="submitting" @click="submit()">
            <span x-text="submitting ? 'Creating...' : 'Create Inspection'"></span>
        </button>
        <a href="<?= base_url('inspections') ?>" class="btn btn-secondary">Cancel</a>
    </div>
</div><!-- /card -->

<?php
$overlayTitle    = 'Inspection Created!';
$overlaySubtitle = 'Redirecting to inspection details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

<script>
function createInspection() {
    return {
        submitting:         false,
        showSuccessOverlay: false,
        formError:          '',
        errors:             {},
        form: {
            equipment_unit_id:     <?= $preUnitId  ? (int)$preUnitId  : 'null' ?>,
            inspection_type:       '<?= e($preType) ?>',
            lease_id:              <?= $preLeaseId ? (int)$preLeaseId : 'null' ?>,
            inspection_date:       new Date().toISOString().slice(0, 10),
            inspected_by:          '',
            inspected_by_user_id:  null,
            mileage_at_inspection: null,
            reefer_hours:          null,
            fuel_level:            '',
            cvi_expiry:            '',
            is_clean:              null,
            notes:                 '',
        },

        init() {
            // Convert empty pre-populated strings to null for API
        },

        validate() {
            this.errors    = {};
            this.formError = '';
            let ok = true;

            if (!this.form.equipment_unit_id) {
                this.errors.equipment_unit_id = 'Please select an equipment unit.';
                ok = false;
            }

            const validTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
            if (!this.form.inspection_type) {
                this.errors.inspection_type = 'Please select an inspection type.';
                ok = false;
            } else if (!validTypes.includes(this.form.inspection_type)) {
                this.errors.inspection_type = 'Please select a valid inspection type.';
                ok = false;
            }

            if (!this.form.inspection_date) {
                this.errors.inspection_date = 'Inspection date is required.';
                ok = false;
            }

            // Odometer must be non-negative
            if (this.form.mileage_at_inspection !== null && this.form.mileage_at_inspection !== '') {
                const mi = parseInt(this.form.mileage_at_inspection);
                if (isNaN(mi) || mi < 0) {
                    this.errors.mileage_at_inspection = 'Odometer cannot be negative.';
                    ok = false;
                }
            }

            // Reefer hours must be non-negative
            if (this.form.reefer_hours !== null && this.form.reefer_hours !== '') {
                const rh = parseInt(this.form.reefer_hours);
                if (isNaN(rh) || rh < 0) {
                    this.errors.reefer_hours = 'Reefer hours cannot be negative.';
                    ok = false;
                }
            }

            // Fuel level enum check
            if (this.form.fuel_level) {
                const validFuels = ['empty', 'quarter', 'half', 'three_quarter', 'full'];
                if (!validFuels.includes(this.form.fuel_level)) {
                    this.errors.fuel_level = 'Please select a valid fuel level.';
                    ok = false;
                }
            }

            if (!ok) this.formError = 'Please fix the errors below and try again.';
            return ok;
        },

        submit() {
            if (!this.validate()) return;

            const payload = Object.assign({}, this.form);
            // Clean up empty optional strings to null
            if (!payload.lease_id)              payload.lease_id              = null;
            if (!payload.inspected_by_user_id)  payload.inspected_by_user_id  = null;
            if (!payload.fuel_level)            payload.fuel_level            = null;
            if (!payload.cvi_expiry)            payload.cvi_expiry            = null;
            if (!payload.inspected_by)          payload.inspected_by          = null;
            if (!payload.notes)                 payload.notes                 = null;

            this.submitting = true;

            FF_Api.post('<?= base_url('api/v1/inspections/create.php') ?>', payload)
                .then(d => {
                    if (d && d.error) {
                        // Server-side field errors (VALID-2 envelope)
                        const serverFields = d.error.fields || d.error.errors || {};
                        if (serverFields && Object.keys(serverFields).length) {
                            this.errors = Object.assign({}, this.errors, serverFields);
                        }
                        this.formError  = d.error.message || d.message || 'Failed to create inspection.';
                        this.submitting = false;
                    } else {
                        this.showSuccessOverlay = true;
                        const _newId = d.data.id;
                        setTimeout(() => { window.location.href = '<?= base_url('inspections/show') ?>?id=' + _newId; }, 3500);
                    }
                });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
