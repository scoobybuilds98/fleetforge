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

// Load equipment units for dropdown — brand/model from template (unit has no make/model column).
// [SELECTOR-1] Pull status so the option label shows it inline and
// the disabled attribute can mirror the SERVICE-context predicate.
// A leased / in-maintenance unit can absolutely have a damage claim
// (in fact that's the most common scenario), so only decommissioned
// + inactive are blocked.
$units = db_select(
    "SELECT eu.id, eu.unit_number, eu.status, eu.year, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY (eu.status IN ('available','on_lease','reserved','maintenance')) DESC,
              eu.unit_number ASC"
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

<div x-data="damageClaimCreate()">

<div class="card" style="max-width:760px;">

    <div class="card-header">
        <h2 class="card-title">Claim Details</h2>
    </div>

    <div class="card-body">

        <form @submit.prevent="submit()" novalidate>

            <!-- Error banner -->
            <div class="form-error-banner" data-form-error></div>

            <!-- Section: Who / What -->
            <div class="form-row form-row-2" style="margin-bottom:0;">
                <div class="form-group">
                    <label class="form-label" for="equipment_unit_id">
                        Equipment Unit <span style="color:var(--color-danger)">*</span>
                    </label>
                    <select id="equipment_unit_id"
                            name="equipment_unit_id"
                            class="form-select"
                            x-model="form.equipment_unit_id">
                        <option value="">— Select unit —</option>
                        <?php foreach ($units as $u): ?>
                        <?php // [SELECTOR-1] service context — only decommissioned/inactive disabled. ?>
                        <option value="<?= e($u['id']) ?>"
                                data-status="<?= e($u['status']) ?>"
                                <?= ff_unit_is_selectable($u['status'], 'service') ? '' : 'disabled' ?>>
                            <?= e(ff_unit_selector_label($u)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">Decommissioned and inactive units cannot have new claims.</div>
                    <div class="field-error" data-error-for="equipment_unit_id"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="customer_id">Customer</label>
                    <select id="customer_id" name="customer_id" class="form-select" x-model="form.customer_id"
                            @change="if(form.customer_id) form.customer_name = ''">
                        <option value="">— Select existing —</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= e($c['id']) ?>"><?= e($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="customer_name" class="form-control" style="margin-top:6px;"
                           placeholder="Or type customer name…"
                           x-model="form.customer_name"
                           maxlength="255"
                           @input="if(form.customer_name) form.customer_id = ''">
                    <div class="field-error" data-error-for="customer_id"></div>
                </div>
            </div>

            <div class="form-row form-row-2" style="margin-bottom:0;">
                <div class="form-group">
                    <label class="form-label" for="vendor_id">Vendor Sent To</label>
                    <select id="vendor_id" name="vendor_id" class="form-select" x-model="form.vendor_id">
                        <option value="">— None —</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= e($v['id']) ?>"><?= e($v['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="field-error" data-error-for="vendor_id"></div>
                </div>
                <div class="form-group"><!-- intentionally blank --></div>
            </div>

            <div class="form-row form-row-2">
                <div class="form-group">
                    <label class="form-label" for="severity">
                        Severity <span style="color:var(--color-danger)">*</span>
                    </label>
                    <select id="severity"
                            name="severity"
                            class="form-select"
                            x-model="form.severity">
                        <option value="">— Select —</option>
                        <option value="minor">Minor</option>
                        <option value="moderate">Moderate</option>
                        <option value="major">Major</option>
                        <option value="total_loss">Total Loss</option>
                    </select>
                    <div class="field-error" data-error-for="severity"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="damage_location">Damage Location</label>
                    <input type="text"
                           id="damage_location"
                           name="damage_location"
                           class="form-control"
                           placeholder="e.g. Front bumper, driver-side door…"
                           x-model="form.damage_location"
                           maxlength="255">
                    <div class="field-error" data-error-for="damage_location"></div>
                </div>
            </div>

            <!-- Optional: Lease link -->
            <div class="form-group">
                <label class="form-label" for="lease_id">Linked Lease ID <span class="form-hint" style="display:inline;font-size:0.8rem;">(optional)</span></label>
                <input type="number"
                       id="lease_id"
                       name="lease_id"
                       class="form-control"
                       style="max-width:200px;"
                       placeholder="Lease ID"
                       x-model.number="form.lease_id">
                <div class="field-error" data-error-for="lease_id"></div>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label class="form-label" for="description">
                    Description <span style="color:var(--color-danger)">*</span>
                </label>
                <textarea id="description"
                          name="description"
                          class="form-control"
                          rows="4"
                          maxlength="2000"
                          placeholder="Describe the damage in detail…"
                          x-model="form.description"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.description || '').length + ' / 2000'"></div>
                <div class="field-error" data-error-for="description"></div>
            </div>

            <!-- Financial estimates -->
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label class="form-label" for="estimated_repair_cost">Est. Repair Cost ($)</label>
                    <input type="number"
                           id="estimated_repair_cost"
                           name="estimated_repair_cost"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           x-model="form.estimated_repair_cost">
                    <div class="field-error" data-error-for="estimated_repair_cost"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="customer_liable_amount">Customer Liable ($)</label>
                    <input type="number"
                           id="customer_liable_amount"
                           name="customer_liable_amount"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           x-model="form.customer_liable_amount">
                    <div class="field-error" data-error-for="customer_liable_amount"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="insurance_claim_amount">Insurance Claim ($)</label>
                    <input type="number"
                           id="insurance_claim_amount"
                           name="insurance_claim_amount"
                           class="form-control"
                           placeholder="0.00"
                           step="0.01"
                           x-model="form.insurance_claim_amount">
                    <div class="field-error" data-error-for="insurance_claim_amount"></div>
                </div>
            </div>

            <!-- Internal notes -->
            <div class="form-group">
                <label class="form-label" for="notes">Internal Notes <span class="form-hint" style="display:inline;font-size:0.8rem;">(optional)</span></label>
                <textarea id="notes"
                          name="notes"
                          class="form-control"
                          rows="3"
                          maxlength="2000"
                          placeholder="Internal notes visible only to staff…"
                          x-model="form.notes"></textarea>
                <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
                <div class="field-error" data-error-for="notes"></div>
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
</div><!-- /card -->

<?php
$overlayTitle    = 'Damage Claim Created!';
$overlaySubtitle = 'Redirecting to claim details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

</div><!-- /x-data wrapper -->

<script>
function damageClaimCreate() {
    return {
        submitting:         false,
        showSuccessOverlay: false,
        form: {
            equipment_unit_id:     <?= $preUnitId     ? $preUnitId     : "''" ?>,
            customer_id:           <?= $preCustomerId ? $preCustomerId : "''" ?>,
            customer_name:         '',
            lease_id:              <?= $preLeaseId    ? $preLeaseId    : "''" ?>,
            vendor_id:             '',
            severity:              '',
            damage_location:       '',
            description:           '',
            estimated_repair_cost: '',
            customer_liable_amount:'',
            insurance_claim_amount:'',
            notes:                 '',
        },

        validate() {
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.equipment_unit_id) {
                FF_Validate.field(form, 'equipment_unit_id', 'Please select an equipment unit.');
                ok = false;
            }
            if (!this.form.severity) {
                FF_Validate.field(form, 'severity', 'Please select a severity.');
                ok = false;
            }
            if (!this.form.description || !this.form.description.trim()) {
                FF_Validate.field(form, 'description', 'Description is required.');
                ok = false;
            }

            const moneyFields = {
                estimated_repair_cost:  'Estimated repair cost',
                customer_liable_amount: 'Customer liable amount',
                insurance_claim_amount: 'Insurance claim amount',
            };
            for (const [field, label] of Object.entries(moneyFields)) {
                const raw = this.form[field];
                if (raw === '' || raw === null || raw === undefined) continue;
                const n = parseFloat(raw);
                if (isNaN(n) || n < 0) {
                    FF_Validate.field(form, field, `${label} cannot be negative.`);
                    ok = false;
                }
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        submit() {
            if (!this.validate()) return;

            const form = document.querySelector('form');
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
                estimated_repair_cost:  this.form.estimated_repair_cost  !== '' ? String(this.form.estimated_repair_cost)  : null,
                customer_liable_amount: this.form.customer_liable_amount !== '' ? String(this.form.customer_liable_amount) : null,
                insurance_claim_amount: this.form.insurance_claim_amount !== '' ? String(this.form.insurance_claim_amount) : null,
            };

            FF_Api.post('<?= base_url('api/v1/damage_claims/create.php') ?>', payload)
                .then(r => {
                    if (!r.success) {
                        if (r.error && r.error.code === 'VALIDATION_ERROR') {
                            FF_Validate.applyApi(form, r.error);
                        } else {
                            FF_Validate.banner(form, (r.error && r.error.message) || 'Failed to create damage claim.');
                        }
                        this.submitting = false;
                        return;
                    }
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => { window.location = '<?= base_url('damage_claims/show') ?>?id=' + _newId; }, 3500);
                })
                .catch(() => {
                    FF_Validate.banner(form, 'Network error. Please try again.');
                    this.submitting = false;
                });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
