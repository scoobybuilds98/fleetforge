<?php
declare(strict_types=1);

/**
 * app/admin/maintenance_work_orders/create.php
 *
 * Create a new maintenance work order.
 * Server-renders unit and vendor dropdowns.
 * Alpine.js handles the form submit via FF_Api.post() → redirect to show on success.
 *
 * Pre-populate equipment_unit_id from ?unit_id= query param (from equipment profile).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/maintenance_work_orders/create.php
 * @decisions D5/D7/D30/D32, D16 (bcmath — no billing on create), Trap 3 (no CSRF on GET)
 * @session  S015
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'create');

// Pre-populate unit if coming from equipment profile
$prefilledUnitId = clean_int($_GET['unit_id'] ?? null);

// Load equipment units for dropdown (unit_number + year + brand + model)
$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.year, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY eu.unit_number ASC"
);

// Load vendors for dropdown
$vendors = db_select(
    "SELECT id, name, vendor_type FROM vendors
     WHERE deleted_at IS NULL ORDER BY name ASC"
);

// Load users for assigned_to dropdown
$assignableUsers = db_select(
    "SELECT id, name FROM users WHERE deleted_at IS NULL ORDER BY name ASC"
);

$pageTitle = 'New Work Order';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">New Work Order</h1>
    <a href="<?= base_url('maintenance_work_orders') ?>" class="btn btn-secondary btn-sm">
        ← Back to Work Orders
    </a>
</div>

<div x-data="woCreate()" x-init="init()">

<div class="card">
    <div class="card-body">

        <form @submit.prevent="submit()" novalidate>

        <!-- Error banner -->
        <div class="form-error-banner" data-form-error></div>

        <!-- Section: Equipment & Vendor -->
        <div class="form-section">
            <h3 class="form-section-title">Equipment & Vendor</h3>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label" for="equipment_unit_id">
                        Equipment Unit <span class="text-danger">*</span>
                    </label>
                    <select id="equipment_unit_id"
                            name="equipment_unit_id"
                            class="form-select"
                            x-model="form.equipment_unit_id">
                        <option value="">— Select Unit —</option>
                        <?php foreach ($units as $unit): ?>
                        <option value="<?= e($unit['id']) ?>">
                            <?= e($unit['unit_number']) ?>
                            <?php if ($unit['year'] || $unit['brand'] || $unit['model']): ?>
                            — <?= e(trim($unit['year'] . ' ' . $unit['brand'] . ' ' . $unit['model'])) ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" data-error-for="equipment_unit_id"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="vendor_id">Vendor <span class="text-secondary">(optional)</span></label>
                    <select id="vendor_id" name="vendor_id" class="form-select" x-model="form.vendor_id">
                        <option value="">— No Vendor —</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" data-error-for="vendor_id"></div>
                </div>
            </div>
        </div>

        <!-- Section: Work Order Details -->
        <div class="form-section" style="margin-top:24px;">
            <h3 class="form-section-title">Work Order Details</h3>

            <div class="form-group">
                <label class="form-label" for="title">
                    Title <span class="text-danger">*</span>
                </label>
                <input type="text"
                       id="title"
                       name="title"
                       class="form-control"
                       x-model="form.title"
                       maxlength="500"
                       placeholder="e.g. Annual brake inspection">
                <div class="field-error" data-error-for="title"></div>
            </div>

            <div class="form-row-2" style="margin-top:12px;">
                <div class="form-group">
                    <label class="form-label" for="work_type">
                        Work Type <span class="text-danger">*</span>
                    </label>
                    <select id="work_type" name="work_type" class="form-select" x-model="form.work_type">
                        <option value="">— Select Type —</option>
                        <option value="scheduled_service">Scheduled Service</option>
                        <option value="repair">Repair</option>
                        <option value="inspection">Inspection</option>
                        <option value="tire">Tire</option>
                        <option value="electrical">Electrical</option>
                        <option value="body_damage">Body Damage</option>
                        <option value="breakdown">Breakdown</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="field-error" data-error-for="work_type"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="priority">
                        Priority <span class="text-danger">*</span>
                    </label>
                    <select id="priority" name="priority" class="form-select" x-model="form.priority">
                        <option value="">— Select Priority —</option>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="emergency">Emergency</option>
                    </select>
                    <div class="field-error" data-error-for="priority"></div>
                </div>
            </div>

            <div class="form-group" style="margin-top:12px;">
                <label class="form-label" for="description">Description</label>
                <textarea id="description"
                          name="description"
                          class="form-control"
                          rows="3"
                          maxlength="2000"
                          x-model="form.description"
                          placeholder="Describe the work to be performed…"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.description || '').length + ' / 2000'"></div>
                <div class="field-error" data-error-for="description"></div>
            </div>
        </div>

        <!-- Section: Dates & Mileage -->
        <div class="form-section" style="margin-top:24px;">
            <h3 class="form-section-title">Dates & Mileage</h3>

            <div class="form-row-3">
                <div class="form-group">
                    <label class="form-label" for="requested_date">
                        Requested Date <span class="text-danger">*</span>
                    </label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="date"
                               id="requested_date"
                               name="requested_date"
                               class="form-control"
                               x-model="form.requested_date"
                               x-ref="woReqDate" style="flex:1;">
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.woReqDate.showPicker ? $refs.woReqDate.showPicker() : $refs.woReqDate.click()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        </button>
                    </div>
                    <div class="field-error" data-error-for="requested_date"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="scheduled_date">Scheduled Date</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="date"
                               id="scheduled_date"
                               name="scheduled_date"
                               class="form-control"
                               x-model="form.scheduled_date"
                               x-ref="woSchedDate" style="flex:1;">
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.woSchedDate.showPicker ? $refs.woSchedDate.showPicker() : $refs.woSchedDate.click()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        </button>
                    </div>
                    <div class="field-error" data-error-for="scheduled_date"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="mileage_at_service">Odometer (km)</label>
                    <input type="number"
                           id="mileage_at_service"
                           name="mileage_at_service"
                           class="form-control"
                           x-model="form.mileage_at_service"
                           placeholder="e.g. 125000">
                    <div class="field-error" data-error-for="mileage_at_service"></div>
                </div>
            </div>
        </div>

        <!-- Section: Assignment & Notes -->
        <div class="form-section" style="margin-top:24px;">
            <h3 class="form-section-title">Assignment & Notes</h3>

            <div class="form-group">
                <label class="form-label" for="assigned_to">Assigned To</label>
                <select id="assigned_to" name="assigned_to" class="form-select" x-model="form.assigned_to">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($assignableUsers as $u): ?>
                    <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-error" data-error-for="assigned_to"></div>
            </div>

            <div class="form-group" style="margin-top:12px;">
                <label class="form-label" for="notes">Notes (visible to vendor)</label>
                <textarea id="notes"
                          name="notes"
                          class="form-control"
                          rows="2"
                          maxlength="2000"
                          x-model="form.notes"
                          placeholder="Instructions for the vendor or technician…"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
                <div class="field-error" data-error-for="notes"></div>
            </div>

            <div class="form-group" style="margin-top:12px;">
                <label class="form-label" for="internal_notes">Internal Notes (admin only)</label>
                <textarea id="internal_notes"
                          name="internal_notes"
                          class="form-control"
                          rows="2"
                          maxlength="2000"
                          x-model="form.internal_notes"
                          placeholder="Internal notes not shared with vendor…"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.internal_notes || '').length + ' / 2000'"></div>
                <div class="field-error" data-error-for="internal_notes"></div>
            </div>
        </div>

        <!-- Submit -->
        <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border-color);display:flex;gap:12px;">
            <button type="submit" class="btn btn-primary" :disabled="saving">
                <span x-text="saving ? 'Creating…' : 'Create Work Order'"></span>
            </button>
            <a href="<?= base_url('maintenance_work_orders') ?>" class="btn btn-secondary">Cancel</a>
        </div>

        </form>

    </div><!-- /card-body -->
</div><!-- /card -->

<?php
$overlayTitle    = 'Work Order Created!';
$overlaySubtitle = 'Redirecting to work order details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

<script>
function woCreate() {
    return {
        saving:             false,
        showSuccessOverlay: false,
        form: {
            equipment_unit_id: '<?= e((string)($prefilledUnitId ?? '')) ?>',
            vendor_id:         '',
            title:             '',
            work_type:         '',
            priority:          'medium',
            description:       '',
            requested_date:    '<?= date('Y-m-d') ?>',
            scheduled_date:    '',
            mileage_at_service:'',
            assigned_to:       '',
            notes:             '',
            internal_notes:    '',
        },

        init() {},

        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.equipment_unit_id) {
                FF_Validate.field(form, 'equipment_unit_id', 'Please select an equipment unit.');
                ok = false;
            }
            if (!this.form.title || !this.form.title.trim()) {
                FF_Validate.field(form, 'title', 'Title is required.');
                ok = false;
            }
            if (!this.form.work_type) {
                FF_Validate.field(form, 'work_type', 'Please select a work type.');
                ok = false;
            }
            if (!this.form.priority) {
                FF_Validate.field(form, 'priority', 'Please select a priority.');
                ok = false;
            }
            if (!this.form.requested_date) {
                FF_Validate.field(form, 'requested_date', 'Requested date is required.');
                ok = false;
            }

            if (this.form.mileage_at_service !== '' && this.form.mileage_at_service !== null) {
                const mi = parseInt(this.form.mileage_at_service);
                if (isNaN(mi) || mi < 0) {
                    FF_Validate.field(form, 'mileage_at_service', 'Odometer cannot be negative.');
                    ok = false;
                }
            }

            if (this.form.scheduled_date && this.form.requested_date &&
                this.form.scheduled_date < this.form.requested_date) {
                FF_Validate.field(form, 'scheduled_date', 'Scheduled date cannot be before requested date.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        submit() {
            if (!this.validate()) return;

            const form = document.querySelector('form');
            const payload = {
                equipment_unit_id:   parseInt(this.form.equipment_unit_id) || null,
                vendor_id:           this.form.vendor_id ? parseInt(this.form.vendor_id) : null,
                title:               this.form.title.trim(),
                work_type:           this.form.work_type,
                priority:            this.form.priority,
                description:         this.form.description || null,
                requested_date:      this.form.requested_date,
                scheduled_date:      this.form.scheduled_date || null,
                mileage_at_service:  this.form.mileage_at_service ? parseInt(this.form.mileage_at_service) : null,
                assigned_to:         this.form.assigned_to ? parseInt(this.form.assigned_to) : null,
                notes:               this.form.notes || null,
                internal_notes:      this.form.internal_notes || null,
            };

            this.saving = true;
            FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/create.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Failed to create work order.');
                        }
                        return;
                    }
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('maintenance_work_orders/show') ?>?id=' + _newId; }, 3500);
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                })
                .finally(() => { this.saving = false; });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
