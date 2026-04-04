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

        <!-- Alert -->
        <div x-show="error" class="alert alert-danger" x-text="error"></div>

        <!-- Equipment Unit + Inspection Type -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Equipment Unit <span class="text-danger">*</span></label>
                <select class="form-control" x-model="form.equipment_unit_id" required>
                    <option value="">— Select unit —</option>
                    <?php foreach ($units as $u): ?>
                    <option value="<?= e($u['id']) ?>">
                        <?= e($u['unit_number']) ?><?= $u['year'] ? ' — ' . e($u['year']) : '' ?><?= $u['brand'] ? ' ' . e($u['brand']) . ' ' . e($u['model']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Inspection Type <span class="text-danger">*</span></label>
                <select class="form-control" x-model="form.inspection_type">
                    <option value="pre_lease">Pre-Lease</option>
                    <option value="post_lease">Post-Lease</option>
                    <option value="periodic">Periodic</option>
                    <option value="damage">Damage</option>
                    <option value="compliance">Compliance</option>
                </select>
            </div>
        </div>

        <!-- Lease + Inspection Date -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Linked Lease <span class="text-secondary">(optional)</span></label>
                <select class="form-control" x-model="form.lease_id">
                    <option value="">— None —</option>
                    <?php foreach ($leases as $l): ?>
                    <option value="<?= e($l['id']) ?>">
                        <?= e($l['contract_number']) ?> — <?= e($l['company_name'] ?? 'Unknown') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Inspection Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" x-model="form.inspection_date" required>
            </div>
        </div>

        <!-- Inspector -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Inspected By (Name)</label>
                <input type="text" class="form-control" placeholder="Inspector name"
                       x-model="form.inspected_by">
            </div>
            <div class="form-group">
                <label class="form-label">Inspector (User)</label>
                <select class="form-control" x-model="form.inspected_by_user_id">
                    <option value="">— Not a system user —</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= e($u['id']) ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Trailer-specific fields -->
        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Odometer / Mileage at Inspection</label>
                <input type="number" class="form-control" placeholder="km or miles"
                       x-model.number="form.mileage_at_inspection" min="0">
            </div>
            <div class="form-group">
                <label class="form-label">Reefer Hours</label>
                <input type="number" class="form-control" placeholder="hours"
                       x-model.number="form.reefer_hours" min="0">
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group">
                <label class="form-label">Fuel Level</label>
                <select class="form-control" x-model="form.fuel_level">
                    <option value="">— Not recorded —</option>
                    <option value="empty">Empty</option>
                    <option value="quarter">1/4 Tank</option>
                    <option value="half">1/2 Tank</option>
                    <option value="three_quarter">3/4 Tank</option>
                    <option value="full">Full</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">CVI Expiry Date</label>
                <input type="date" class="form-control" x-model="form.cvi_expiry">
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
        error:              '',
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

        submit() {
            this.error = '';
            if (!this.form.equipment_unit_id) { this.error = 'Equipment unit is required.'; return; }
            if (!this.form.inspection_date)   { this.error = 'Inspection date is required.'; return; }

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
                        this.error      = d.message ?? 'Failed to create inspection.';
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
