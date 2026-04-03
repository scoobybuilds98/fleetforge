<?php
declare(strict_types=1);

/**
 * app/admin/damage_claims/create.php
 *
 * New Damage Claim form.
 * Submits via Alpine.js to api/v1/damage_claims/create.php,
 * then redirects to the new claim's show page.
 *
 * Required: equipment_unit_id, description, severity.
 * Optional: customer_id, lease_id, damage_location, estimated_repair_cost,
 *           customer_liable_amount, insurance_claim_amount, notes.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/damage_claims/create.php
 * @decisions D5/D16/D30/D32
 * @session  S012
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'create');

// Pre-populate from query string (e.g., linked from equipment/show or lease/show)
$preUnitId    = clean_int($_GET['equipment_unit_id'] ?? null);
$preLeaseId   = clean_int($_GET['lease_id'] ?? null);
$preCustomerId = clean_int($_GET['customer_id'] ?? null);

// Load equipment units for dropdown — brand/model from template (unit has no make/model column)
$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.year, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY eu.unit_number ASC"
);

// Load active customers for dropdown
$customers = db_select(
    "SELECT id, company_name
     FROM customers
     WHERE status = 'active' AND deleted_at IS NULL
     ORDER BY company_name ASC"
);

// Load vendors for dropdown
$vendors = db_select(
    "SELECT id, name, vendor_type
     FROM vendors
     WHERE deleted_at IS NULL
     ORDER BY name ASC"
);

$pageTitle = 'New Damage Claim';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('damage_claims') ?>" class="btn btn-secondary btn-sm">← Back</a>
    <h1 class="page-header-title">New Damage Claim</h1>
</div>

<div class="card" x-data="damageClaimCreate()" style="max-width:760px;">

    <div class="card-header">
        <h2 class="card-title">Claim Details</h2>
    </div>

    <div class="card-body">

        <!-- Error banner -->
        <div x-show="error" class="alert alert-danger" style="margin-bottom:20px;" x-text="error"></div>

        <form @submit.prevent="submit()">

            <!-- Section: Who / What -->
            <div class="form-row form-row-2" style="margin-bottom:0;">
                <div class="form-group">
                    <label class="form-label" for="equipment_unit_id">
                        Equipment Unit <span class="form-label .required" style="color:var(--color-danger)">*</span>
                    </label>
                    <select id="equipment_unit_id"
                            class="form-select"
                            x-model="form.equipment_unit_id"
                            :class="{ 'is-invalid': errors.equipment_unit_id }"
                            required>
                        <option value="">— Select unit —</option>
                        <?php foreach ($units as $u): ?>
                        <option value="<?= e($u['id']) ?>">
                            <?= e($u['unit_number']) ?><?= $u['year'] ? ' · ' . e($u['year']) : '' ?><?= $u['brand'] ? ' · ' . e($u['brand']) : '' ?><?= $u['model'] ? ' ' . e($u['model']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-error" x-show="errors.equipment_unit_id" x-text="errors.equipment_unit_id"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Customer</label>
                    <select class="form-select" x-model="form.customer_id"
                            @change="if(form.customer_id) form.customer_name = ''">
                        <option value="">— Select existing —</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= e($c['id']) ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="form-control" style="margin-top:6px;"
                           placeholder="Or type customer name…"
                           x-model="form.customer_name"
                           maxlength="255"
                           @input="if(form.customer_name) form.customer_id = ''">
                </div>
            </div>

            <div class="form-row form-row-2" style="margin-bottom:0;">
                <div class="form-group">
                    <label class="form-label" for="vendor_id">Vendor Sent To</label>
                    <select id="vendor_id" class="form-select" x-model="form.vendor_id">
                        <option value="">— None —</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><!-- intentionally blank --></div>
            </div>

            <div class="form-row form-row-2">
                <div class="form-group">
                    <label class="form-label" for="severity">
                        Severity <span style="color:var(--color-danger)">*</span>
                    </label>
                    <select id="severity"
                            class="form-select"
                            x-model="form.severity"
                            :class="{ 'is-invalid': errors.severity }"
                            required>
                        <option value="">— Select —</option>
                        <option value="minor">Minor</option>
                        <option value="moderate">Moderate</option>
                        <option value="major">Major</option>
                        <option value="total_loss">Total Loss</option>
                    </select>
                    <div class="form-error" x-show="errors.severity" x-text="errors.severity"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="damage_location">Damage Location</label>
                    <input type="text"
                           id="damage_location"
                           class="form-control"
                           placeholder="e.g. Front bumper, driver-side door…"
                           x-model="form.damage_location"
                           maxlength="255">
                </div>
            </div>

            <!-- Optional: Lease link -->
            <div class="form-group">
                <label class="form-label" for="lease_id">Linked Lease ID <span class="form-hint" style="display:inline;font-size:0.8rem;">(optional)</span></label>
                <input type="number"
                       id="lease_id"
                       class="form-control"
                       style="max-width:200px;"
                       placeholder="Lease ID"
                       x-model.number="form.lease_id"
                       min="1">
            </div>

            <!-- Description -->
            <div class="form-group">
                <label class="form-label" for="description">
                    Description <span style="color:var(--color-danger)">*</span>
                </label>
                <textarea id="description"
                          class="form-control"
                          rows="4"
                          placeholder="Describe the damage in detail…"
                          x-model="form.description"
                          :class="{ 'is-invalid': errors.description }"
                          required></textarea>
                <div class="form-error" x-show="errors.description" x-text="errors.description"></div>
            </div>

            <!-- Financial estimates -->
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label class="form-label" for="estimated_repair_cost">Est. Repair Cost ($)</label>
                    <input type="number"
                           id="estimated_repair_cost"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           min="0"
                           x-model="form.estimated_repair_cost">
                </div>
                <div class="form-group">
                    <label class="form-label" for="customer_liable_amount">Customer Liable ($)</label>
                    <input type="number"
                           id="customer_liable_amount"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           min="0"
                           x-model="form.customer_liable_amount">
                </div>
                <div class="form-group">
                    <label class="form-label" for="insurance_claim_amount">Insurance Claim ($)</label>
                    <input type="number"
                           id="insurance_claim_amount"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           min="0"
                           x-model="form.insurance_claim_amount">
                </div>
            </div>

            <!-- Internal notes -->
            <div class="form-group">
                <label class="form-label" for="notes">Internal Notes <span class="form-hint" style="display:inline;font-size:0.8rem;">(optional)</span></label>
                <textarea id="notes"
                          class="form-control"
                          rows="3"
                          placeholder="Internal notes visible only to staff…"
                          x-model="form.notes"></textarea>
            </div>

            <!-- Submit row -->
            <div style="display:flex;gap:12px;padding-top:8px;border-top:1px solid var(--border-color);margin-top:8px;">
                <button type="submit" class="btn btn-primary" :disabled="submitting">
                    <span x-text="submitting ? 'Creating…' : 'Create Claim'"></span>
                </button>
                <a href="<?= base_url('damage_claims') ?>" class="btn btn-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>

<script>
function damageClaimCreate() {
    return {
        submitting: false,
        error: '',
        errors: {},
        form: {
            equipment_unit_id:     <?= $preUnitId     ? $preUnitId     : 'null' ?>,
            customer_id:           <?= $preCustomerId ? $preCustomerId : 'null' ?>,
            customer_name:         '',
            lease_id:              <?= $preLeaseId    ? $preLeaseId    : 'null' ?>,
            vendor_id:             null,
            severity:              '',
            damage_location:       '',
            description:           '',
            estimated_repair_cost: '',
            customer_liable_amount:'',
            insurance_claim_amount:'',
            notes:                 '',
        },

        submit() {
            this.error  = '';
            this.errors = {};

            if (!this.form.equipment_unit_id) {
                this.errors.equipment_unit_id = 'Equipment unit is required.';
            }
            if (!this.form.severity) {
                this.errors.severity = 'Severity is required.';
            }
            if (!this.form.description.trim()) {
                this.errors.description = 'Description is required.';
            }
            if (Object.keys(this.errors).length) return;

            this.submitting = true;

            const payload = {
                equipment_unit_id:      this.form.equipment_unit_id ? parseInt(this.form.equipment_unit_id) : null,
                customer_id:            this.form.customer_id        ? parseInt(this.form.customer_id)       : null,
                customer_name:          this.form.customer_name.trim() || null,
                lease_id:               this.form.lease_id           ? parseInt(this.form.lease_id)          : null,
                vendor_id:              this.form.vendor_id          ? parseInt(this.form.vendor_id)         : null,
                severity:               this.form.severity,
                damage_location:        this.form.damage_location  || null,
                description:            this.form.description,
                notes:                  this.form.notes            || null,
                estimated_repair_cost:  this.form.estimated_repair_cost  ? String(this.form.estimated_repair_cost)  : null,
                customer_liable_amount: this.form.customer_liable_amount ? String(this.form.customer_liable_amount) : null,
                insurance_claim_amount: this.form.insurance_claim_amount ? String(this.form.insurance_claim_amount) : null,
            };

            FF_Api.post('<?= base_url('api/v1/damage_claims/create.php') ?>', payload)
                .then(d => {
                    window.location = '<?= base_url('damage_claims/show') ?>?id=' + d.data.id;
                })
                .catch(err => {
                    this.error = err.message ?? 'An error occurred. Please try again.';
                    if (err.fields) this.errors = err.fields;
                    this.submitting = false;
                });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
