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
 * @session  S012, S-FORM-INTEGRITY-FIX-FK-FIELDS, S-DROPDOWN-RETROFIT-2B-FINISH-FORMS
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'create');

// Pre-populate from query string (e.g., linked from equipment/show or lease/show)
$preUnitId     = clean_int($_GET['equipment_unit_id'] ?? null);
$preLeaseId    = clean_int($_GET['lease_id'] ?? null);
$preCustomerId = clean_int($_GET['customer_id'] ?? null);

// Pre-load lease label so the picker shows the correct selected state on pre-populated load.
$preLeaseLabel = null;
if ($preLeaseId) {
    $preLease = db_row(
        "SELECT id, contract_number, company_name_snapshot, status
         FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$preLeaseId]
    );
    if ($preLease) {
        $preLeaseLabel = $preLease['contract_number'];
        if ($preLease['company_name_snapshot']) {
            $preLeaseLabel .= ' — ' . $preLease['company_name_snapshot'];
        }
    } else {
        // Pre-populated ID doesn't match a real lease — silently drop it so the
        // form doesn't submit a garbage FK.
        $preLeaseId = null;
    }
}

// S-DROPDOWN-RETROFIT-2: Equipment Unit uses FF_RecordPicker (api/v1/equipment/units/index.php).
// Pre-load label for ?equipment_unit_id=N so the picker shows the correct selected state.
$preUnitLabel = null;
if ($preUnitId) {
    $preUnit = db_row(
        "SELECT eu.id, eu.unit_number, et.name AS template_name
         FROM equipment_units eu
         LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
         WHERE eu.id = ? AND eu.deleted_at IS NULL",
        [$preUnitId]
    );
    if ($preUnit) {
        $preUnitLabel = $preUnit['unit_number'];
        if ($preUnit['template_name']) {
            $preUnitLabel .= ' — ' . $preUnit['template_name'];
        }
    } else {
        $preUnitId = null;
    }
}

// Pre-load customer label for picker pre-population when ?customer_id=N is set.
$preCustomerLabel = null;
if ($preCustomerId) {
    $preCustomerRow = db_row(
        "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
        [$preCustomerId]
    );
    if ($preCustomerRow) {
        $preCustomerLabel = $preCustomerRow['company_name'];
    } else {
        $preCustomerId = null;
    }
}

$pageTitle = 'New Damage Claim';
$helpModuleSlug = 'damage-claims';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <a href="<?= base_url('damage_claims') ?>" class="btn btn-secondary btn-sm">← Back</a>
    <h1 class="page-header-title">New Damage Claim</h1>
    <div class="page-header-actions">
        <?= help_button('damage-claims') ?>
    </div>
</div>

<div x-data="damageClaimCreate()" x-init="init()"><!-- S-FORM-DRAFT-ROLLOUT -->

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
                <!-- D-DROPDOWN-RETROFIT-PATTERN: Equipment Unit uses FF_RecordPicker.
                     Shows all non-deleted units (service context — leased/maintenance
                     units can have damage claims). Status shown in sublabel. -->
                <div class="form-group">
                    <label class="form-label">
                        Equipment Unit <span style="color:var(--color-danger)">*</span>
                    </label>
                    <?php
                    $pickerConfig   = [
                        'endpoint'    => '/api/v1/equipment/units/index.php',
                        'searchParam' => 'search',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search by unit number or type…',
                        'mapResult'   => "r => ({ id: r.id, label: r.unit_number + (r.template_name ? ' — ' + r.template_name : ''), sublabel: r.status.toUpperCase() + (r.yard_location ? ' · ' + r.yard_location : ''), raw: r })",
                    ];
                    if ($preUnitId && $preUnitLabel) {
                        $pickerConfig['initialId']    = (int) $preUnitId;
                        $pickerConfig['initialLabel'] = $preUnitLabel;
                    }
                    $pickerOnPicked  = 'form.equipment_unit_id = $event.detail.id';
                    $pickerOnCleared = "form.equipment_unit_id = ''";
                    $pickerError     = 'false';
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
                    <div class="field-error" data-error-for="equipment_unit_id"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="customer_id">Customer</label>
                    <?php
                    $pickerConfig   = [
                        'endpoint'    => '/api/v1/customers/index.php',
                        'searchParam' => 'search',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search customers…',
                        'extraParams' => 'status=active',
                        'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: r.city || '', raw: r })",
                    ];
                    if ($preCustomerId && $preCustomerLabel) {
                        $pickerConfig['initialId']    = (int) $preCustomerId;
                        $pickerConfig['initialLabel'] = $preCustomerLabel;
                    }
                    $pickerOnPicked  = "form.customer_id = \$event.detail.id; form.customer_name = ''";
                    $pickerOnCleared = "form.customer_id = ''";
                    $pickerError     = 'false';
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
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
                    <?php
                    $pickerConfig   = [
                        'endpoint'    => '/api/v1/vendors/index.php',
                        'searchParam' => 'q',
                        'resultKey'   => 'items',
                        'perPage'     => 10,
                        'placeholder' => 'Search vendors…',
                        'mapResult'   => "r => ({ id: r.id, label: r.name, sublabel: r.vendor_type || '', raw: r })",
                    ];
                    $pickerOnPicked  = 'form.vendor_id = $event.detail.id';
                    $pickerOnCleared = "form.vendor_id = ''";
                    $pickerError     = 'false';
                    require FF_ROOT . '/includes/partials/record-picker.php';
                    ?>
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

            <!-- Optional: Lease link — FF_RecordPicker (D-FK-INTEGRITY-PICKERS).
                 WHY picker not number input: a bare number field lets a typo create a
                 silent orphan (damage_claims.lease_id is a FK to leases.id). The picker
                 constrains to real leases and the API already validates existence
                 server-side as belt-and-suspenders. -->
            <div class="form-group">
                <label class="form-label">Linked Lease <span class="form-hint" style="display:inline;font-size:0.8rem;">(optional)</span></label>
                <?php
                $pickerConfig   = [
                    'endpoint'    => '/api/v1/leases/index.php',
                    'searchParam' => 'search',
                    'resultKey'   => 'items',
                    'perPage'     => 10,
                    'placeholder' => 'Search by contract #, customer, or unit…',
                    'mapResult'   => "r => ({ id: r.id, label: r.contract_number + (r.customer_display_name ? ' — ' + r.customer_display_name : ''), sublabel: (r.unit_display_number ? 'Unit ' + r.unit_display_number + ' · ' : '') + r.status, raw: r })",
                ];
                if ($preLeaseId && $preLeaseLabel) {
                    $pickerConfig['initialId']    = (int) $preLeaseId;
                    $pickerConfig['initialLabel'] = $preLeaseLabel;
                }
                $pickerOnPicked  = 'form.lease_id = $event.detail.id';
                $pickerOnCleared = "form.lease_id = ''";
                $pickerError     = 'false';
                require FF_ROOT . '/includes/partials/record-picker.php';
                ?>
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
                    <input type="number" min="0"
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
                    <input type="number" min="0"
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
                    <input type="number" min="0"
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

        init() { // S-FORM-DRAFT-ROLLOUT
            if (window.FF_FormDraft) { // S-FORM-DRAFT-ROLLOUT
                this._draft = FF_FormDraft.attach({ // S-FORM-DRAFT-ROLLOUT
                    formId: 'damage-claim-create', // S-FORM-DRAFT-ROLLOUT
                    entityId: 'new', // S-FORM-DRAFT-ROLLOUT
                    el: this.$root, // S-FORM-DRAFT-ROLLOUT
                    model: this.form, // S-FORM-DRAFT-ROLLOUT
                    version: '1', // S-FORM-DRAFT-ROLLOUT
                    exclude: ['equipment_unit_id', 'customer_id', 'lease_id', 'vendor_id'], // S-FORM-DRAFT-ROLLOUT
                }); // S-FORM-DRAFT-ROLLOUT
            } // S-FORM-DRAFT-ROLLOUT
        }, // S-FORM-DRAFT-ROLLOUT

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
                    if (this._draft) this._draft.clear(true); // S-FORM-DRAFT-ROLLOUT
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
