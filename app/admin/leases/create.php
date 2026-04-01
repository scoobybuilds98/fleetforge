<?php
declare(strict_types=1);

/**
 * FleetForge — New Lease Form
 *
 * @file        app/admin/leases/create.php
 * @description New lease form. Customer dropdown auto-fills default rates and
 *              tax exemption from customer record. Equipment unit dropdown shows
 *              only 'available' units by default. Contract number is auto-suggested
 *              but editable. Rates section pre-fills from customer defaults.
 *              Submits to api/v1/leases/create → redirects to show page.
 *              Alpine component: FF_CreateLease().
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/create.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D16 (bcmath — rates as strings), D20 (FOR UPDATE on create),
 *              D30 (asset_url), D32 (CSS classes only from app.css)
 * @session     S007
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'create');

// ── Server-side: load customers and available units for dropdowns ──
// Load all active customers for select
$customers = db_select(
    "SELECT id, company_name, contact_name, currency, mileage_unit,
            billing_cycle, discount_type, discount_value,
            gst_exempt, pst_exempt
     FROM customers
     WHERE status NOT IN ('inactive','suspended') AND deleted_at IS NULL
     ORDER BY company_name ASC",
    []
);

// Load available equipment units with template info
// equipment_units has no mileage_unit — use template's default_mileage_unit instead
$availableUnits = db_select(
    "SELECT u.id, u.unit_number, u.status,
            t.name AS template_name, t.category,
            t.default_daily_rate, t.default_weekly_rate,
            t.default_monthly_rate, t.default_mileage_rate,
            t.default_currency, t.default_mileage_unit
     FROM equipment_units u
     JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
     WHERE u.status = 'available' AND u.deleted_at IS NULL
     ORDER BY u.unit_number ASC",
    []
);

$pageTitle = 'New Lease';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('leases') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← Leases
        </a>
        <h1 class="page-header-title h4">New Lease</h1>
    </div>
</div>

<!-- ============================================================
     CREATE LEASE FORM (Alpine)
     ============================================================ -->
<div x-data="FF_CreateLease()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Customer & Unit ──────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Customer &amp; Equipment</div></div>
            <div class="card-body">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label required" for="customer_id">Customer</label>
                        <select id="customer_id" class="form-control form-select"
                                x-model="form.customer_id"
                                :class="errors.customer_id ? 'is-invalid' : ''"
                                @change="onCustomerChange()">
                            <option value="">— Select customer —</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                    data-currency="<?= e($c['currency']) ?>"
                                    data-mileage-unit="<?= e($c['mileage_unit']) ?>"
                                    data-billing-cycle="<?= e($c['billing_cycle']) ?>"
                                    data-gst-exempt="<?= $c['gst_exempt'] ? '1' : '0' ?>"
                                    data-pst-exempt="<?= $c['pst_exempt'] ? '1' : '0' ?>"
                                    data-discount-type="<?= e($c['discount_type']) ?>"
                                    data-discount-value="<?= e($c['discount_value']) ?>">
                                <?= e($c['company_name']) ?>
                                <?php if ($c['contact_name']): ?>(<?= e($c['contact_name']) ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-error" x-show="errors.customer_id" x-text="errors.customer_id"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required" for="equipment_unit_id">Equipment Unit</label>
                        <select id="equipment_unit_id" class="form-control form-select"
                                x-model="form.equipment_unit_id"
                                :class="errors.equipment_unit_id ? 'is-invalid' : ''"
                                @change="onUnitChange()">
                            <option value="">— Select available unit —</option>
                            <?php foreach ($availableUnits as $u): ?>
                            <option value="<?= $u['id'] ?>"
                                    data-template="<?= e($u['template_name']) ?>"
                                    data-category="<?= e($u['category']) ?>"
                                    data-daily-rate="<?= e($u['default_daily_rate'] ?? '0.00') ?>"
                                    data-weekly-rate="<?= e($u['default_weekly_rate'] ?? '0.00') ?>"
                                    data-monthly-rate="<?= e($u['default_monthly_rate'] ?? '0.00') ?>"
                                    data-mileage-rate="<?= e($u['default_mileage_rate'] ?? '0.0000') ?>"
                                    data-currency="<?= e($u['default_currency'] ?? 'CAD') ?>"
                                    data-mileage-unit="<?= e($u['default_mileage_unit'] ?? 'miles') ?>">
                                <?= e($u['unit_number']) ?> — <?= e($u['template_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Only available units shown.</div>
                        <div class="form-error" x-show="errors.equipment_unit_id" x-text="errors.equipment_unit_id"></div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label required" for="contract_number">Contract Number</label>
                        <input type="text" id="contract_number" class="form-control font-mono"
                               x-model="form.contract_number"
                               :class="errors.contract_number ? 'is-invalid' : ''"
                               placeholder="Auto-generated on save"
                               maxlength="100">
                        <div class="form-hint">Leave blank to auto-generate (CN-XXXXXX-<?= date('Y') ?>).</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="po_number">PO Number</label>
                        <input type="text" id="po_number" class="form-control"
                               x-model="form.po_number" maxlength="100"
                               placeholder="Customer purchase order #">
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 2: Dates ────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Dates</div></div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label required" for="start_date">Start Date</label>
                        <input type="date" id="start_date" class="form-control"
                               x-model="form.start_date"
                               :class="errors.start_date ? 'is-invalid' : ''">
                        <div class="form-error" x-show="errors.start_date" x-text="errors.start_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">End Date</label>
                        <input type="date" id="end_date" class="form-control"
                               x-model="form.end_date"
                               :class="errors.end_date ? 'is-invalid' : ''">
                        <div class="form-hint">Leave blank for open-ended lease.</div>
                        <div class="form-error" x-show="errors.end_date" x-text="errors.end_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="minimum_end_date">Minimum End Date</label>
                        <input type="date" id="minimum_end_date" class="form-control"
                               x-model="form.minimum_end_date">
                        <div class="form-hint">Early return fee applies before this date.</div>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="billing_cycle">Billing Cycle</label>
                        <select id="billing_cycle" class="form-control form-select"
                                x-model="form.billing_cycle">
                            <option value="monthly">Monthly (auto-invoice)</option>
                            <option value="on_close_only">On Close Only</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 3: Rates ────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Rental Rates</div></div>
            <div class="card-body">

                <div class="form-row-2" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label" for="currency">Currency</label>
                        <select id="currency" class="form-control form-select"
                                x-model="form.currency">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mileage_unit">Mileage Unit</label>
                        <select id="mileage_unit" class="form-control form-select"
                                x-model="form.mileage_unit">
                            <option value="miles">Miles</option>
                            <option value="km">Km</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="daily_rate">Daily Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="daily_rate" class="form-control font-mono"
                                   x-model="form.daily_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="weekly_rate">Weekly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="weekly_rate" class="form-control font-mono"
                                   x-model="form.weekly_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="monthly_rate">Monthly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="monthly_rate" class="form-control font-mono"
                                   x-model="form.monthly_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage_rate">Mileage Rate (per mile/km)</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="mileage_rate" class="form-control font-mono"
                                   x-model="form.mileage_rate" step="0.0001" min="0" placeholder="0.0000">
                        </div>
                        <div class="form-hint">Set 0 to disable mileage billing.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="estimated_mileage">Estimated Mileage</label>
                        <input type="number" id="estimated_mileage" class="form-control font-mono"
                               x-model="form.estimated_mileage" min="0" placeholder="0">
                        <div class="form-hint">Used for pre-charge calculation.</div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_start">Starting Mileage</label>
                        <input type="number" id="mileage_at_start" class="form-control font-mono"
                               x-model="form.mileage_at_start" min="0" placeholder="Odometer reading">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="rate_notes">Rate Notes</label>
                        <input type="text" id="rate_notes" class="form-control"
                               x-model="form.rate_notes" maxlength="5000"
                               placeholder="e.g. Special negotiated rates per agreement">
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 4: Discount & Add-ons ───────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Discount &amp; Add-ons</div></div>
            <div class="card-body">

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="discount_type">Discount Type</label>
                        <select id="discount_type" class="form-control form-select"
                                x-model="form.discount_type">
                            <option value="none">No Discount</option>
                            <option value="percentage">Percentage (%)</option>
                            <option value="flat">Flat ($)</option>
                        </select>
                    </div>
                    <div class="form-group" x-show="form.discount_type !== 'none'">
                        <label class="form-label" for="discount_value">Discount Value</label>
                        <input type="number" id="discount_value" class="form-control font-mono"
                               x-model="form.discount_value" step="0.01" min="0">
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.insurance_opt_in">
                            Insurance Add-on
                        </label>
                        <div class="input-group" x-show="form.insurance_opt_in">
                            <span class="input-group-prefix">$</span>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.insurance_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.warranty_opt_in">
                            Warranty Add-on
                        </label>
                        <div class="input-group" x-show="form.warranty_opt_in">
                            <span class="input-group-prefix">$</span>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.warranty_cost" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 5: Tax Exemption ────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Tax Exemption</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);padding:0 var(--card-padding-x) 0.5rem;">
                    Tax rates are looked up from the customer's province and frozen on the lease at creation (D11, D22).
                </div>
            </div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.gst_exempt">
                            GST Exempt
                        </label>
                        <div class="form-hint">Auto-filled from customer record. GST/HST not charged.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.pst_exempt">
                            PST Exempt
                        </label>
                        <div class="form-hint">Auto-filled from customer record. PST not charged.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 6: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" class="form-control"
                              x-model="form.notes" rows="2" maxlength="5000"
                              placeholder="Visible to customer in portal"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" class="form-control"
                              x-model="form.internal_notes" rows="2" maxlength="5000"
                              placeholder="Internal use only — not shown to customer"></textarea>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('leases') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
                <span x-show="!submitting">Create Lease</span>
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
function FF_CreateLease() {
    return {
        form: {
            customer_id:        '',
            equipment_unit_id:  '',
            contract_number:    '',
            po_number:          '',
            start_date:         '',
            end_date:           '',
            minimum_end_date:   '',
            billing_cycle:      'monthly',
            currency:           'CAD',
            mileage_unit:       'miles',
            daily_rate:         '',
            weekly_rate:        '',
            monthly_rate:       '',
            mileage_rate:       '',
            estimated_mileage:  '',
            mileage_at_start:   '',
            rate_notes:         '',
            discount_type:      'none',
            discount_value:     '',
            insurance_opt_in:   false,
            insurance_cost:     '',
            warranty_opt_in:    false,
            warranty_cost:      '',
            gst_exempt:         false,
            pst_exempt:         false,
            notes:              '',
            internal_notes:     '',
        },
        errors:      {},
        globalError: null,
        submitting:  false,

        init() {
            // Default start date to today
            this.form.start_date = new Date().toISOString().slice(0, 10);
        },

        onCustomerChange() {
            const sel = document.getElementById('customer_id');
            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;

            // Auto-fill customer defaults — rates come from unit template, but currency/cycle from customer
            this.form.currency      = opt.dataset.currency      || 'CAD';
            this.form.mileage_unit  = opt.dataset.mileageUnit   || 'miles';
            this.form.billing_cycle = opt.dataset.billingCycle  || 'monthly';
            this.form.gst_exempt    = opt.dataset.gstExempt === '1';
            this.form.pst_exempt    = opt.dataset.pstExempt === '1';

            if (opt.dataset.discountType && opt.dataset.discountType !== 'none') {
                this.form.discount_type  = opt.dataset.discountType;
                this.form.discount_value = opt.dataset.discountValue || '';
            }
        },

        onUnitChange() {
            const sel = document.getElementById('equipment_unit_id');
            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;

            // Pre-fill rates from template defaults
            this.form.daily_rate   = opt.dataset.dailyRate   || '';
            this.form.weekly_rate  = opt.dataset.weeklyRate  || '';
            this.form.monthly_rate = opt.dataset.monthlyRate || '';
            this.form.mileage_rate = opt.dataset.mileageRate || '';
        },

        validate() {
            this.errors = {};
            if (!this.form.customer_id)       this.errors.customer_id = 'Customer is required.';
            if (!this.form.equipment_unit_id) this.errors.equipment_unit_id = 'Equipment unit is required.';
            if (!this.form.start_date)        this.errors.start_date = 'Start date is required.';
            if (this.form.end_date && this.form.end_date < this.form.start_date)
                this.errors.end_date = 'End date must be on or after start date.';
            return Object.keys(this.errors).length === 0;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            this.globalError = null;

            // Build payload — omit empty strings
            const payload = {};
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });

            // Coerce numeric integers
            ['customer_id', 'equipment_unit_id', 'mileage_at_start', 'estimated_mileage'].forEach(f => {
                if (payload[f]) payload[f] = parseInt(payload[f]);
            });

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/create') ?>', payload);
                if (r.success) {
                    window.location.href = '<?= base_url('leases/show') ?>?id=' + r.data.id;
                } else {
                    this.globalError = r.message || 'Failed to create lease.';
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
