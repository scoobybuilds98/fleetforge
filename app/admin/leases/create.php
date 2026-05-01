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
// [SELECTOR-1] Fetch every non-deleted unit, NOT just available ones,
// so the user can SEE leased / maintenance units in the dropdown with
// the reason they can't be picked. ff_unit_is_selectable() decides which
// rows get the disabled attribute, and the API still 409s on the
// server side if a non-available id is somehow submitted. Sort the
// available rows first so the picker is still useful by default.
$availableUnits = db_select(
    "SELECT u.id, u.unit_number, u.status, u.template_id,
            u.samsara_vehicle_id, u.samsara_vehicle_name, u.samsara_entity_type,
            t.name AS template_name, t.category,
            t.default_daily_rate, t.default_weekly_rate,
            t.default_monthly_rate, t.default_mileage_rate,
            t.default_currency, t.default_mileage_unit
     FROM equipment_units u
     JOIN equipment_templates t ON t.id = u.template_id AND t.deleted_at IS NULL
     WHERE u.deleted_at IS NULL
     ORDER BY (u.status = 'available') DESC, u.unit_number ASC",
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
                            <?php // [SELECTOR-1] disable non-available so the option is visible-but-unpickable. ?>
                            <option value="<?= $u['id'] ?>"
                                    data-template-id="<?= (int)$u['template_id'] ?>"
                                    data-template="<?= e($u['template_name']) ?>"
                                    data-category="<?= e($u['category']) ?>"
                                    data-status="<?= e($u['status']) ?>"
                                    data-daily-rate="<?= e($u['default_daily_rate'] ?? '0.00') ?>"
                                    data-weekly-rate="<?= e($u['default_weekly_rate'] ?? '0.00') ?>"
                                    data-monthly-rate="<?= e($u['default_monthly_rate'] ?? '0.00') ?>"
                                    data-mileage-rate="<?= e($u['default_mileage_rate'] ?? '0.0000') ?>"
                                    data-currency="<?= e($u['default_currency'] ?? 'CAD') ?>"
                                    data-mileage-unit="<?= e($u['default_mileage_unit'] ?? 'km') ?>"
                                    data-samsara-linked="<?= !empty($u['samsara_vehicle_id']) ? '1' : '0' ?>"
                                    <?= ff_unit_is_selectable($u['status']) ? '' : 'disabled' ?>>
                                <?= e(ff_unit_selector_label($u)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Available units are selectable; leased / maintenance units are shown but greyed out.</div>
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
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="start_date" class="form-control"
                                   x-model="form.start_date"
                                   :class="errors.start_date ? 'is-invalid' : ''"
                                   min="<?= date('Y-m-d') ?>"
                                   x-ref="lsStartDate" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.lsStartDate.showPicker ? $refs.lsStartDate.showPicker() : $refs.lsStartDate.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                        <div class="form-error" x-show="errors.start_date" x-text="errors.start_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">End Date</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="end_date" class="form-control"
                                   x-model="form.end_date"
                                   :class="errors.end_date ? 'is-invalid' : ''"
                                   :min="form.start_date || ''"
                                   x-ref="lsEndDate" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.lsEndDate.showPicker ? $refs.lsEndDate.showPicker() : $refs.lsEndDate.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
                        <div class="form-hint">Leave blank for open-ended lease.</div>
                        <div class="form-error" x-show="errors.end_date" x-text="errors.end_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="minimum_end_date">Minimum End Date</label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="date" id="minimum_end_date" class="form-control"
                                   x-model="form.minimum_end_date"
                                   :min="form.start_date || ''"
                                   x-ref="lsMinEnd" style="flex:1;">
                            <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.lsMinEnd.showPicker ? $refs.lsMinEnd.showPicker() : $refs.lsMinEnd.click()">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                            </button>
                        </div>
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
                    <!-- ADV-BILL-1: prepay future periods at activation. Monthly only. -->
                    <div class="form-group" x-show="form.billing_cycle === 'monthly'" x-cloak>
                        <label class="form-label" for="advance_billing_periods">
                            Advance Billing Periods
                            <span style="font-weight:normal;color:var(--color-text-muted,#6b7280);font-size:0.8125rem;">
                                (extra months prepaid at activation)
                            </span>
                        </label>
                        <input id="advance_billing_periods" type="number" min="0"
                               :max="advanceBillingCap"
                               class="form-control"
                               x-model.number="form.advance_billing_periods"
                               @input="clampAdvancePeriods()" />
                        <div class="form-help" x-show="form.advance_billing_periods > 0" x-cloak>
                            Activation will generate
                            <strong x-text="(form.advance_billing_periods + 1)"></strong>
                            invoices in one batch
                            (Invoice 1 + <span x-text="form.advance_billing_periods"></span>
                            future-period prepayments).
                        </div>
                        <div class="form-help" style="color:var(--color-text-muted,#6b7280);">
                            Cap: <span x-text="advanceBillingCap"></span>.
                            Leave at 0 for normal monthly billing.
                        </div>
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

                <!-- SAMSARA-3: legacy "Starting Mileage" input was removed here.
                     It's now replaced by the Starting Odometer section below
                     (Section 6), which is decimal-precise and supports live GPS
                     fetch. The server auto-derives the legacy integer
                     mileage_at_start column from odometer_start_km so every
                     downstream consumer (close.php overage math, reports, AI
                     tools) keeps working without changes. -->
                <div class="form-group">
                    <label class="form-label" for="rate_notes">Rate Notes</label>
                    <input type="text" id="rate_notes" class="form-control"
                           x-model="form.rate_notes" maxlength="255"
                           placeholder="e.g. Special negotiated rates per agreement">
                    <div class="form-hint" style="text-align:right;" x-text="(form.rate_notes || '').length + ' / 255'"></div>
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

        <!-- ── Section 6: Starting Odometer (SAMSARA-3) ────────────
             Capture the odometer reading when the unit leaves the yard.
             If the unit is linked to Samsara, the "Fetch from Samsara"
             button pulls the current live reading; otherwise the user
             enters it manually. Starting odometer is optional — the
             lease can be created without one.
             ───────────────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Starting Odometer</div></div>
            <div class="card-body">
                <div class="form-hint" style="margin-bottom:1rem;">
                    Capture the odometer reading when the unit leaves the yard.
                    Used to track km driven across the life of the lease.
                </div>

                <div class="form-group" style="max-width:540px;">
                    <label class="form-label" for="odometer_start_km">Starting Odometer (km)</label>
                    <div style="display:flex;gap:0.5rem;align-items:flex-start;flex-wrap:wrap;">
                        <div style="flex:1 1 240px;min-width:0;">
                            <div style="display:flex;gap:0.5rem;align-items:center;">
                                <input type="number"
                                       id="odometer_start_km"
                                       class="form-control font-mono"
                                       x-model="form.odometer_start_km"
                                       @input="onOdometerEdited()"
                                       step="0.01"
                                       min="0"
                                       placeholder="e.g. 1234.56">
                                <span x-show="odometerSource === 'gps'"
                                      class="badge badge-info"
                                      title="Fetched live from Samsara">GPS</span>
                                <span x-show="odometerSource === 'manual' && form.odometer_start_km !== '' && form.odometer_start_km !== null"
                                      class="badge badge-neutral"
                                      title="Manually entered">Manual</span>
                            </div>
                        </div>
                        <button type="button"
                                class="btn btn-secondary"
                                x-show="odometerCanFetch"
                                @click="fetchStartingOdometer()"
                                :disabled="odometerFetching">
                            <span x-show="!odometerFetching">Fetch from Samsara</span>
                            <span x-show="odometerFetching">Fetching…</span>
                        </button>
                    </div>

                    <!-- Success banner after live GPS fetch -->
                    <div x-show="odometerBanner && odometerBanner.type === 'success'"
                         class="alert alert-success"
                         style="margin-top:0.75rem;padding:0.5rem 0.75rem;font-size:0.875rem;"
                         x-text="odometerBanner && odometerBanner.message">
                    </div>

                    <!-- Warning banner on fetch failure -->
                    <div x-show="odometerBanner && odometerBanner.type === 'warning'"
                         class="alert alert-warning"
                         style="margin-top:0.75rem;padding:0.5rem 0.75rem;font-size:0.875rem;"
                         x-text="odometerBanner && odometerBanner.message">
                    </div>

                    <!-- Hint when unit is not Samsara-linked -->
                    <div x-show="selectedUnit && !odometerCanFetch"
                         class="form-hint"
                         style="margin-top:0.5rem;color:var(--text-secondary);">
                        Link this unit to Samsara on the equipment page to enable live GPS odometer fetch.
                    </div>

                    <!-- Default hint -->
                    <div x-show="!selectedUnit"
                         class="form-hint" style="margin-top:0.5rem;">
                        Select a unit above to enable the Samsara fetch button.
                    </div>

                    <div class="form-error" x-show="errors.odometer_start_km" x-text="errors.odometer_start_km"></div>
                </div>
            </div>
        </div>

        <!-- ── Section 7: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" class="form-control"
                              x-model="form.notes" rows="2" maxlength="2000"
                              placeholder="Visible to customer in portal"></textarea>
                    <div class="form-hint" style="text-align:right;" x-text="(form.notes || '').length + ' / 2000'"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" class="form-control"
                              x-model="form.internal_notes" rows="2" maxlength="2000"
                              placeholder="Internal use only — not shown to customer"></textarea>
                    <div class="form-hint" style="text-align:right;" x-text="(form.internal_notes || '').length + ' / 2000'"></div>
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

        <!-- VALID-2: form-level error banner is injected by FF_Validate.banner() -->
        <div class="form-error-banner" data-form-error></div>

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
            // SAMSARA-3: mileage_at_start removed — derived from odometer_start_km on the server
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
            // SAMSARA-3: starting odometer captured at lease start
            odometer_start_km:         '',
            odometer_start_source:     null,  // 'gps' | 'manual' | null
            odometer_start_fetched_at: null,  // ISO datetime when GPS fetched
            // ADV-BILL-1: prepay future periods at activation (monthly cycle only)
            advance_billing_periods:   0,
        },
        // ADV-BILL-1: cap from billing.max_advance_periods setting (server-side
        // re-validates regardless). 24 mirrors the seed default.
        advanceBillingCap: <?= (int) settings_get('billing.max_advance_periods', '24') ?>,
        errors:             {},
        globalError:        null,
        submitting:         false,
        showSuccessOverlay: false,

        // SAMSARA-3 odometer state
        selectedUnit:     null,    // snapshot of selected option dataset — includes samsara link state
        odometerSource:   null,    // 'gps' | 'manual' — drives the badge display
        odometerCanFetch: false,   // true when selected unit is linked to Samsara
        odometerFetching: false,   // button spinner flag
        odometerBanner:   null,    // { type: 'success'|'warning', message: string }

        // Rate source tracking for banner display and field locking
        rateSource:      null,   // 'customer' | 'rate_card' | 'template' | null
        rateSourceLabel: '',
        // WHY: ratesLocked=true when source=customer — prevents accidental overwrite of
        //      contracted rates. User must explicitly click Unlock to override.
        ratesLocked:     false,

        init() {
            // Default start date to today
            this.form.start_date = new Date().toISOString().slice(0, 10);

            // ADV-BILL-1: zero out advance periods if cycle leaves monthly.
            this.$watch('form.billing_cycle', (v) => {
                if (v !== 'monthly') this.form.advance_billing_periods = 0;
            });
        },

        // ADV-BILL-1: clamp the advance-periods input to [0, cap] on every change.
        // The server re-validates, but clamping here gives instant feedback.
        clampAdvancePeriods() {
            let n = parseInt(this.form.advance_billing_periods, 10);
            if (isNaN(n) || n < 0) n = 0;
            if (n > this.advanceBillingCap) n = this.advanceBillingCap;
            this.form.advance_billing_periods = n;
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

            // SAMSARA-3: track selected unit state so the Starting Odometer
            // section knows whether to show the Fetch button. Reset odometer
            // state whenever the unit changes so stale GPS values don't
            // survive across units.
            if (!opt || !opt.value) {
                this.selectedUnit     = null;
                this.odometerCanFetch = false;
                this.odometerSource   = null;
                this.odometerBanner   = null;
                this.form.odometer_start_km         = '';
                this.form.odometer_start_source     = null;
                this.form.odometer_start_fetched_at = null;
                return;
            }
            this.selectedUnit = {
                id:             opt.value,
                templateId:     parseInt(opt.dataset.templateId) || null,
                samsaraLinked:  opt.dataset.samsaraLinked === '1',
            };
            this.odometerCanFetch = this.selectedUnit.samsaraLinked;
            // Clear any stale odometer state from a previous selection
            this.odometerSource                 = null;
            this.odometerBanner                 = null;
            this.form.odometer_start_km         = '';
            this.form.odometer_start_source     = null;
            this.form.odometer_start_fetched_at = null;

            // Reset lock state before lookup — will be re-set based on new source
            this.ratesLocked = false;
            this.rateSource  = null;

            // Priority: customer override → rate card → template
            this._currentTemplateId = this.selectedUnit.templateId;
            this._lookupRates();
        },

        // SAMSARA-3: Live-fetch the current odometer reading from Samsara
        // for the currently selected unit. Populates odometer_start_km +
        // marks source as 'gps'. Non-blocking on failure — user can still
        // enter a value manually.
        async fetchStartingOdometer() {
            if (!this.selectedUnit) {
                this.odometerBanner = { type: 'warning', message: 'Please select a unit first.' };
                return;
            }
            this.odometerFetching = true;
            this.odometerBanner   = null;
            try {
                const r = await FF_Api.get(
                    `<?= base_url('api/v1/samsara/current_odometer') ?>?equipment_unit_id=${this.selectedUnit.id}`
                );
                const d = r.data || {};

                if (d.linked === false) {
                    this.odometerCanFetch = false;
                    this.odometerBanner   = { type: 'warning', message: d.message || 'Unit not linked to Samsara.' };
                    return;
                }
                if (d.odometer_km === null || d.odometer_km === undefined) {
                    this.odometerBanner = { type: 'warning', message: d.message || 'Could not reach Samsara. Enter odometer manually.' };
                    return;
                }

                // Success — populate field and mark as GPS source
                this.form.odometer_start_km         = Number(d.odometer_km).toFixed(2);
                this.form.odometer_start_source     = 'gps';
                this.form.odometer_start_fetched_at = d.fetched_at;
                this.odometerSource                 = 'gps';

                const ts = new Date(d.fetched_at).toLocaleString('en-CA', {
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit', hour12: true,
                });
                const km = Number(d.odometer_km).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                this.odometerBanner = {
                    type:    'success',
                    message: `✓ Live odometer fetched: ${km} km from Samsara at ${ts}`,
                };
            } catch (e) {
                this.odometerBanner = { type: 'warning', message: 'Could not fetch GPS odometer. Enter starting odometer manually.' };
            } finally {
                this.odometerFetching = false;
            }
        },

        // SAMSARA-3: Fired whenever the user types into the odometer field.
        // If the current value came from a GPS fetch and the user edits it,
        // flip the badge from GPS → Manual and drop the fetched_at stamp.
        onOdometerEdited() {
            if (this.odometerSource === 'gps') {
                this.odometerSource                 = 'manual';
                this.form.odometer_start_source     = 'manual';
                this.form.odometer_start_fetched_at = null;
            } else if (this.form.odometer_start_km !== '' && this.form.odometer_start_km !== null) {
                // First manual entry — stamp the source
                this.odometerSource             = 'manual';
                this.form.odometer_start_source = 'manual';
            } else {
                this.odometerSource             = null;
                this.form.odometer_start_source = null;
            }
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
            // VALID-2: use FF_Validate for consistent, per-field error display.
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            let ok = true;

            if (!this.form.customer_id) {
                FF_Validate.field(form, 'customer_id', 'Please select a customer.');
                ok = false;
            }
            if (!this.form.equipment_unit_id) {
                FF_Validate.field(form, 'equipment_unit_id', 'Please select an equipment unit.');
                ok = false;
            }
            if (!this.form.start_date) {
                FF_Validate.field(form, 'start_date', 'Start date is required.');
                ok = false;
            }
            if (this.form.end_date && this.form.start_date &&
                this.form.end_date < this.form.start_date) {
                FF_Validate.field(form, 'end_date', 'End date must be after start date.');
                ok = false;
            }

            // Numeric rate fields: reject negatives up-front so the user gets an
            // immediate message instead of a server round-trip.
            const numChecks = [
                ['daily_rate',        'Daily rate cannot be negative.'],
                ['weekly_rate',       'Weekly rate cannot be negative.'],
                ['monthly_rate',      'Monthly rate cannot be negative.'],
                ['mileage_rate',      'Mileage rate cannot be negative.'],
                ['estimated_mileage', 'Estimated mileage cannot be negative.'],
                ['discount_value',    'Discount value cannot be negative.'],
                ['insurance_cost',    'Insurance cost cannot be negative.'],
                ['warranty_cost',     'Warranty cost cannot be negative.'],
            ];
            numChecks.forEach(([k, msg]) => {
                const v = this.form[k];
                if (v !== '' && v !== null && v !== undefined && Number(v) < 0) {
                    FF_Validate.field(form, k, msg);
                    ok = false;
                }
            });
            if (this.form.discount_type === 'percentage' &&
                Number(this.form.discount_value || 0) > 100) {
                FF_Validate.field(form, 'discount_value', 'Discount cannot exceed 100%.');
                ok = false;
            }

            // At least one rate must be > 0
            const anyRate = ['daily_rate','weekly_rate','monthly_rate','mileage_rate']
                .some(k => Number(this.form[k] || 0) > 0);
            if (!anyRate) {
                FF_Validate.field(form, 'daily_rate',
                    'At least one rate (daily, weekly, monthly, or mileage) must be greater than zero.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(form);
            return ok;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;

            const form = document.querySelector('form');

            // Build payload — omit empty strings
            const payload = {};
            Object.entries(this.form).forEach(([k, v]) => {
                if (v !== '' && v !== null && v !== undefined) payload[k] = v;
            });

            // Coerce numeric integers
            ['customer_id', 'equipment_unit_id', 'estimated_mileage'].forEach(f => {
                if (payload[f]) payload[f] = parseInt(payload[f]);
            });
            // SAMSARA-3: coerce decimal odometer so the API sees a proper number
            if (payload.odometer_start_km !== undefined) {
                payload.odometer_start_km = parseFloat(payload.odometer_start_km);
            }

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/create') ?>', payload);
                if (r.success) {
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => { window.location.href = '<?= base_url('leases/show') ?>?id=' + _newId; }, 3500);
                } else {
                    // VALID-2: server returned a validation error — show field-level messages
                    if (r.error && (r.error.code === 'VALIDATION_ERROR' || r.error.code === 'UNIT_UNAVAILABLE') && r.error.fields) {
                        FF_Validate.applyApi(form, r.error);
                    } else {
                        FF_Validate.banner(form, r.error?.message || r.message || 'Failed to create lease.');
                        FF_Validate.scrollToFirst(form);
                    }
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
$overlayTitle    = 'Lease Created!';
$overlaySubtitle = 'Redirecting to lease details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
