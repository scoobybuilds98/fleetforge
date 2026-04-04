<?php
declare(strict_types=1);

/**
 * FleetForge — New Lease Form
 *
 * @file        app/admin/leases/create.php
 * @description New lease form. Customer dropdown auto-fills default rates and
 *              tax exemption from customer record. Equipment unit dropdown shows
 *              only 'available' units by default. Contract number is auto-suggested
 *              but editable. Rates section pre-fills via S019 priority lookup:
 *              1st customer_equipment_rates, 2nd rate_cards, 3rd template defaults.
 *              A source banner indicates which tier provided the rates.
 *              Submits to api/v1/leases/create → redirects to show page.
 *              Alpine component: FF_CreateLease().
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/create.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D16 (bcmath — rates as strings), D20 (FOR UPDATE on create),
 *              D30 (asset_url), D32 (CSS classes only from app.css)
 *              S019 rate priority: customer_equipment_rates → rate_cards → template
 * @session     S007, S019 (rate pre-fill upgrade)
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
// S019: include u.template_id so JS can call lookup_rates with equipment_template_id
$availableUnits = db_select(
    "SELECT u.id, u.unit_number, u.status, u.template_id,
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
                                    data-template-id="<?= (int)$u['template_id'] ?>"
                                    data-template="<?= e($u['template_name']) ?>"
                                    data-category="<?= e($u['category']) ?>"
                                    data-daily-rate="<?= e($u['default_daily_rate'] ?? '0.00') ?>"
                                    data-weekly-rate="<?= e($u['default_weekly_rate'] ?? '0.00') ?>"
                                    data-monthly-rate="<?= e($u['default_monthly_rate'] ?? '0.00') ?>"
                                    data-mileage-rate="<?= e($u['default_mileage_rate'] ?? '0.0000') ?>"
                                    data-currency="<?= e($u['default_currency'] ?? 'CAD') ?>"
                                    data-mileage-unit="<?= e($u['default_mileage_unit'] ?? 'km') ?>">
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

                <!-- Rate source banner — green=customer (locked), blue=rate card, grey=template -->
                <!-- source=customer: locked banner with explicit override escape hatch -->
                <div x-show="rateSource === 'customer'"
                     style="display:flex;align-items:center;justify-content:space-between;gap:12px;
                            padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:0.875rem;
                            background:var(--color-success-bg,#d1fae5);color:var(--color-success,#065f46);
                            border:1px solid var(--color-success-border,#6ee7b7);"
                     x-cloak>
                    <span>
                        🔒 <strong>Contracted rates</strong> —
                        <span x-text="rateSourceLabel"></span>
                        <span x-show="ratesLocked"> · Fields locked to prevent accidental changes.</span>
                        <span x-show="!ratesLocked" style="font-style:italic;opacity:.8;"> · Override active — be careful.</span>
                    </span>
                    <button type="button"
                            style="font-size:0.8125rem;white-space:nowrap;padding:3px 10px;border-radius:4px;
                                   border:1px solid currentColor;background:transparent;cursor:pointer;color:inherit;"
                            x-show="ratesLocked"
                            @click="ratesLocked = false"
                            title="Unlock to manually override contracted rates">
                        Unlock
                    </button>
                    <button type="button"
                            style="font-size:0.8125rem;white-space:nowrap;padding:3px 10px;border-radius:4px;
                                   border:1px solid currentColor;background:transparent;cursor:pointer;color:inherit;"
                            x-show="!ratesLocked"
                            @click="ratesLocked = true"
                            title="Re-lock to contracted rates">
                        Re-lock
                    </button>
                </div>
                <div x-show="rateSource === 'rate_card'"
                     style="padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:0.875rem;
                            background:var(--color-info-bg,#dbeafe);color:var(--color-info,#1e40af);
                            border:1px solid var(--color-info-border,#93c5fd);"
                     x-cloak>
                    ℹ <span x-text="rateSourceLabel"></span> · You may adjust rates below.
                </div>
                <div x-show="rateSource === 'template'"
                     style="padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:0.875rem;
                            background:var(--bg-elevated,#f8fafc);color:var(--text-secondary,#64748b);
                            border:1px solid var(--border-color,#e2e8f0);"
                     x-cloak>
                    <span x-text="rateSourceLabel"></span> · You may adjust rates below.
                </div>

                <div class="form-row-2" style="margin-bottom:1rem;">
                    <div class="form-group">
                        <label class="form-label" for="currency">Currency</label>
                        <select id="currency" class="form-control form-select"
                                x-model="form.currency"
                                :disabled="ratesLocked">
                            <option value="CAD">CAD</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mileage_unit">Mileage Unit</label>
                        <select id="mileage_unit" class="form-control form-select"
                                x-model="form.mileage_unit"
                                :disabled="ratesLocked">
                            <option value="km">Kilometres</option>
                            <option value="miles">Miles</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label" for="daily_rate">Daily Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="daily_rate" class="form-control font-mono"
                                   x-model="form.daily_rate" step="0.01" min="0" placeholder="0.00"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="weekly_rate">Weekly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="weekly_rate" class="form-control font-mono"
                                   x-model="form.weekly_rate" step="0.01" min="0" placeholder="0.00"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="monthly_rate">Monthly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="monthly_rate" class="form-control font-mono"
                                   x-model="form.monthly_rate" step="0.01" min="0" placeholder="0.00"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
                        </div>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage_rate">Mileage Rate (per mile/km)</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="mileage_rate" class="form-control font-mono"
                                   x-model="form.mileage_rate" step="0.0001" min="0" placeholder="0.0000"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
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
            mileage_unit:       'km',
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
        errors:             {},
        globalError:        null,
        submitting:         false,
        showSuccessOverlay: false,

        // Rate source tracking for banner display and field locking
        rateSource:      null,   // 'customer' | 'rate_card' | 'template' | null
        rateSourceLabel: '',
        // WHY: ratesLocked=true when source=customer — prevents accidental overwrite of
        //      contracted rates. User must explicitly click Unlock to override.
        ratesLocked:     false,

        init() {
            // Default start date to today
            this.form.start_date = new Date().toISOString().slice(0, 10);
        },

        onCustomerChange() {
            const sel = document.getElementById('customer_id');
            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;

            // Auto-fill customer defaults — currency/cycle/tax from customer record
            this.form.currency      = opt.dataset.currency      || 'CAD';
            this.form.mileage_unit  = opt.dataset.mileageUnit   || 'km';
            this.form.billing_cycle = opt.dataset.billingCycle  || 'monthly';
            this.form.gst_exempt    = opt.dataset.gstExempt === '1';
            this.form.pst_exempt    = opt.dataset.pstExempt === '1';

            if (opt.dataset.discountType && opt.dataset.discountType !== 'none') {
                this.form.discount_type  = opt.dataset.discountType;
                this.form.discount_value = opt.dataset.discountValue || '';
            }

            // S019: If a unit is already selected, re-run rate lookup with new customer
            if (this.form.equipment_unit_id) {
                this._lookupRates();
            }
        },

        onUnitChange() {
            const sel = document.getElementById('equipment_unit_id');
            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) return;

            // Reset lock state before lookup — will be re-set based on new source
            this.ratesLocked = false;
            this.rateSource  = null;

            // Priority: customer override → rate card → template
            this._currentTemplateId = parseInt(opt.dataset.templateId) || null;
            this._lookupRates();
        },

        // S019: rate priority lookup — called when customer or unit changes
        async _lookupRates() {
            const customerId   = this.form.customer_id;
            const templateId   = this._currentTemplateId;

            if (!customerId || !templateId) return;

            try {
                const r = await FF_Api.get(
                    `<?= base_url('api/v1/leases/lookup_rates') ?>?customer_id=${customerId}&equipment_template_id=${templateId}`
                );
                const d = r.data;

                // Apply rates to form
                this.form.daily_rate   = d.daily_rate   ?? '';
                this.form.weekly_rate  = d.weekly_rate  ?? '';
                this.form.monthly_rate = d.monthly_rate ?? '';
                this.form.mileage_rate = d.mileage_rate ?? '';

                // Apply currency/unit from rate source if not already set by customer
                if (d.currency)     this.form.currency     = d.currency;
                if (d.mileage_unit) this.form.mileage_unit = d.mileage_unit;

                // Set banner state
                this.rateSource      = d.source;       // 'customer' | 'rate_card' | 'template' | 'none'
                this.rateSourceLabel = d.source_label;

                // WHY: lock fields when source=customer so contracted rates can't be
                //      accidentally overwritten. Unlock/Re-lock buttons let staff override.
                this.ratesLocked = (d.source === 'customer');

                // Auto-stamp rate_notes when customer rates are locked in
                if (d.source === 'customer' && !this.form.rate_notes) {
                    this.form.rate_notes = 'Contracted rates per customer agreement';
                }

            } catch (e) {
                // Rate lookup failure is non-fatal — fall back to blank rates, no banner
                this.rateSource  = null;
                this.ratesLocked = false;
            }
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
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('leases/show') ?>?id=' + _newId; }, 3500);
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

<?php
$overlayTitle    = 'Lease Created!';
$overlaySubtitle = 'Redirecting to lease details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
