<?php
declare(strict_types=1);

/**
 * FleetForge — New Lease Form
 *
 * @file        app/admin/leases/create.php
 * @description New lease form. Customer dropdown auto-fills default rates and
 *              tax exemption from customer record. Equipment unit dropdown shows
 *              only 'available' units by default. Contract number is auto-suggested
 *              but editable. Rates section pre-fills via priority lookup
 *              (S-RATES-CONSOLIDATE): 1st customer-specific rate card, 2nd global
 *              rate card, 3rd template defaults. A source banner indicates which
 *              tier provided the rates.
 *              Single-unit mileage section (S-MILEAGE-UNIT-SIMPLIFY): pill toggle
 *              selects primary unit (km/miles); one rate + one allowance; API
 *              derives counterpart columns from global conversion factors.
 *              Submits to api/v1/leases/create → redirects to show page.
 *              Alpine component: FF_CreateLease().
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/create.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D16 (bcmath — rates as strings), D20 (FOR UPDATE on create),
 *              D30 (asset_url), D32 (CSS classes only from app.css)
 *              Rate priority (S-RATES-CONSOLIDATE): customer rate card → global rate card → template
 *              S-MILEAGE-UNIT-SIMPLIFY: single rate+allowance, API derives counterpart
 *              S-DROPDOWN-RETROFIT-1: D-DROPDOWN-RETROFIT-PATTERN
 * @session     S007, S019 (rate pre-fill upgrade), S-MILEAGE-UNIT-SIMPLIFY, S-DROPDOWN-RETROFIT-1-LEASES-INVOICES
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'create');

// S-DROPDOWN-RETROFIT-1: Customer and Equipment Unit fields now use FF_RecordPicker
// (queries api/v1/customers/index.php + api/v1/equipment/units/index.php client-side).
// Server-side preloads for these dropdowns have been removed; the picker APIs supply
// all data including the fields previously stored in data-* attributes.

$pageTitle = 'New Lease';
$helpModuleSlug = 'leases';
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
    <div class="page-header-actions">
        <?= help_button('leases') ?>
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
                    <!-- D-DROPDOWN-RETROFIT-PATTERN: Customer uses FF_RecordPicker.
                         @record-picked fires onCustomerPickerSelected(raw) which reads
                         customer defaults (currency, billing_cycle, tax exemptions,
                         discount) from the raw API record instead of DOM data attrs. -->
                    <div class="form-group">
                        <label class="form-label required">Customer</label>
                        <?php
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/customers/index.php',
                            'searchParam' => 'search',
                            'resultKey'   => 'items',
                            'perPage'     => 10,
                            'placeholder' => 'Search customers…',
                            'mapResult'   => "r => ({ id: r.id, label: r.company_name + (r.contact_name ? ' (' + r.contact_name + ')' : ''), sublabel: [r.city, r.province].filter(Boolean).join(', ') + (r.status !== 'active' ? ' · ' + r.status.toUpperCase() : ''), raw: r })",
                        ];
                        $pickerOnPicked  = 'form.customer_id = $event.detail.id; onCustomerPickerSelected($event.detail.raw)';
                        $pickerOnCleared = "form.customer_id = ''";
                        $pickerError     = 'false';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="field-error" data-error-for="customer_id"></div>
                    </div>

                    <!-- D-DROPDOWN-RETROFIT-PATTERN: Equipment Unit uses FF_RecordPicker.
                         Shows available units only (status=available). Status shown in
                         sublabel so operator knows the unit is ready for a new lease.
                         The API validates on submit (409 UNIT_UNAVAILABLE) as belt-and-suspenders.
                         @record-picked fires onUnitPickerSelected(raw) which reads
                         template_id (for rate lookup) and samsara_linked (for GPS odometer). -->
                    <div class="form-group">
                        <label class="form-label required">Equipment Unit</label>
                        <?php
                        $pickerConfig   = [
                            'endpoint'    => '/api/v1/equipment/units/index.php',
                            'searchParam' => 'search',
                            'resultKey'   => 'items',
                            'perPage'     => 10,
                            'extraParams' => 'status=available',
                            'placeholder' => 'Search available units…',
                            'mapResult'   => "r => ({ id: r.id, label: r.unit_number + ' — ' + (r.template_name || ''), sublabel: (r.yard_location ? r.yard_location + ' · ' : '') + 'AVAILABLE', raw: r })",
                        ];
                        $pickerOnPicked  = 'form.equipment_unit_id = $event.detail.id; onUnitPickerSelected($event.detail.raw)';
                        $pickerOnCleared = "form.equipment_unit_id = ''; onUnitPickerCleared()";
                        $pickerError     = 'false';
                        require FF_ROOT . '/includes/partials/record-picker.php';
                        ?>
                        <div class="form-hint">Showing available units only. <a href="<?= base_url('equipment') ?>" class="text-link" target="_blank">View all equipment →</a></div>
                        <div class="field-error" data-error-for="equipment_unit_id"></div>
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
                        <!-- S-LEASE-RENTAL-DAY-TIME: expected return time -->
                        <div style="margin-top:6px;" x-show="form.end_date" x-cloak>
                            <label class="form-label" for="end_time_h" style="font-size:0.8125rem;margin-bottom:3px;">Expected Return Time <span style="font-weight:normal;color:var(--color-text-muted,#6b7280);">(optional)</span></label>
                            <div class="d-flex align-items-center" style="gap:6px;">
                                <select id="end_time_h" class="form-control" style="width:72px;"
                                        @change="form.end_time = $event.target.value ? ($event.target.value + ':' + ((form.end_time||'').slice(3,5)||'00')) : ''">
                                    <option value="">HH</option>
                                    <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                                        <option :value="String(h).padStart(2,'0')"
                                                :selected="String(h).padStart(2,'0') === (form.end_time||'').slice(0,2)"
                                                x-text="String(h).padStart(2,'0')"></option>
                                    </template>
                                </select>
                                <span style="font-weight:600">:</span>
                                <select id="end_time_m" class="form-control" style="width:72px;"
                                        @change="form.end_time = ((form.end_time||'').slice(0,2)||'00') + ':' + $event.target.value">
                                    <option value="">MM</option>
                                    <template x-for="m in Array.from({length:60},(_,i)=>i)" :key="m">
                                        <option :value="String(m).padStart(2,'0')"
                                                :selected="String(m).padStart(2,'0') === (form.end_time||'').slice(3,5)"
                                                x-text="String(m).padStart(2,'0')"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!-- S-LEASE-RENTAL-DAY-TIME: lease start time (mandatory) -->
                    <div class="form-group">
                        <label class="form-label required" for="start_time_h">Lease Start Time</label>
                        <div class="d-flex align-items-center" style="gap:6px;">
                            <select id="start_time_h" class="form-control" style="width:72px;"
                                    :class="errors.start_time ? 'is-invalid' : ''"
                                    @change="form.start_time = $event.target.value ? ($event.target.value + ':' + ((form.start_time||'').slice(3,5)||'00')) : ''">
                                <option value="">HH</option>
                                <template x-for="h in Array.from({length:24},(_,i)=>i)" :key="h">
                                    <option :value="String(h).padStart(2,'0')"
                                            :selected="String(h).padStart(2,'0') === (form.start_time||'').slice(0,2)"
                                            x-text="String(h).padStart(2,'0')"></option>
                                </template>
                            </select>
                            <span style="font-weight:600">:</span>
                            <select id="start_time_m" class="form-control" style="width:72px;"
                                    :class="errors.start_time ? 'is-invalid' : ''"
                                    @change="form.start_time = ((form.start_time||'00:00').slice(0,2)||'00') + ':' + $event.target.value">
                                <option value="">MM</option>
                                <template x-for="m in Array.from({length:60},(_,i)=>i)" :key="m">
                                    <option :value="String(m).padStart(2,'0')"
                                            :selected="String(m).padStart(2,'0') === (form.start_time||'').slice(3,5)"
                                            x-text="String(m).padStart(2,'0')"></option>
                                </template>
                            </select>
                        </div>
                        <div class="form-error" x-show="errors.start_time" x-text="errors.start_time"></div>
                        <div class="form-hint">Sets the billing cut-off for same-day returns.</div>
                    </div>
                </div>
                <div class="form-row-3">
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
                        <div class="form-help" style="color:var(--color-text-muted,#6b7280);">
                            Cap: <span x-text="advanceBillingCap"></span>.
                            Leave at 0 for normal monthly billing.
                        </div>
                        <!-- ADV-BILL-1: period preview — shows when advance > 0 and start_date set -->
                        <div x-show="form.advance_billing_periods > 0 && form.start_date" x-cloak
                             style="margin-top:12px;border:1px solid var(--color-border,#e5e7eb);border-radius:6px;overflow:hidden;">
                            <div style="padding:8px 12px;background:var(--color-surface-alt,#f9fafb);border-bottom:1px solid var(--color-border,#e5e7eb);font-size:0.8125rem;font-weight:600;color:var(--color-text-secondary,#374151);">
                                Invoices to be generated at activation
                            </div>
                            <table style="width:100%;border-collapse:collapse;">
                                <thead>
                                    <tr style="background:var(--color-surface-alt,#f9fafb);">
                                        <th style="padding:6px 12px;text-align:left;font-size:0.75rem;font-weight:600;color:var(--color-text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;">#</th>
                                        <th style="padding:6px 12px;text-align:left;font-size:0.75rem;font-weight:600;color:var(--color-text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;">Period</th>
                                        <th style="padding:6px 12px;text-align:right;font-size:0.75rem;font-weight:600;color:var(--color-text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;">Days</th>
                                        <th style="padding:6px 12px;text-align:right;font-size:0.75rem;font-weight:600;color:var(--color-text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;">Est. Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(p, idx) in advancePreviewPeriods()" :key="idx">
                                        <tr style="border-top:1px solid var(--color-border,#e5e7eb);">
                                            <td style="padding:7px 12px;font-size:0.8125rem;color:var(--color-text-muted,#6b7280);" x-text="idx + 1"></td>
                                            <td style="padding:7px 12px;font-size:0.8125rem;font-family:var(--font-mono,'DM Mono',monospace);" x-text="p.label"></td>
                                            <td style="padding:7px 12px;font-size:0.8125rem;text-align:right;color:var(--color-text-muted,#6b7280);" x-text="p.days"></td>
                                            <td style="padding:7px 12px;font-size:0.8125rem;text-align:right;font-family:var(--font-mono,'DM Mono',monospace);font-weight:600;" x-text="p.amountFmt"></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot>
                                    <tr style="border-top:2px solid var(--color-border,#e5e7eb);background:var(--color-surface-alt,#f9fafb);">
                                        <td colspan="3" style="padding:8px 12px;font-size:0.8125rem;font-weight:600;color:var(--color-text-primary,#1c1c1a);">Total to be invoiced at activation</td>
                                        <td style="padding:8px 12px;font-size:0.875rem;text-align:right;font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;" x-text="advancePreviewTotal()"></td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div style="padding:6px 12px;font-size:0.75rem;color:var(--color-text-muted,#6b7280);background:var(--color-surface-alt,#f9fafb);border-top:1px solid var(--color-border,#e5e7eb);">
                                Estimated pre-tax · add-ons and exact tax calculated at activation
                            </div>
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
                </div>

                <div class="form-row-4">
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
                    <div class="form-group">
                        <label class="form-label" for="hourly_rate">Hourly Rate</label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number" id="hourly_rate" class="form-control font-mono"
                                   x-model="form.hourly_rate" step="0.0001" min="0" placeholder="0.0000"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
                            <span class="input-group-suffix">/hr</span>
                        </div>
                        <div class="form-hint">Optional — engine/reefer hours. Pre-filled from the rate card; reveals Starting Engine Hours when set.</div>
                    </div>
                </div>

                <!-- S-LEASE-MIN-DAYS — short-lease minimum billing floor.
                     WHY: when a lease's total billable duration is shorter than N
                     days, billing replaces the daily/weekly/monthly tier ladder with
                     a flat N × daily_rate charge (mainly chassis). Frozen onto the
                     lease at creation; defaults to the global setting and is
                     overwritten by the per-equipment rate-card value when a unit's
                     rates are looked up (see _lookupRates()). Blank/0/1 = no floor. -->
                <div class="form-row-3" style="margin-top:1rem;">
                    <div class="form-group">
                        <label class="form-label" for="minimum_billing_days">Minimum Billing Days</label>
                        <input type="number" id="minimum_billing_days" class="form-control font-mono"
                               x-model="form.minimum_billing_days" step="1" min="0" placeholder="0"
                               :readonly="ratesLocked"
                               :style="ratesLocked ? 'background:var(--bg-muted,.f1f5f9);cursor:not-allowed;' : ''">
                        <div class="form-hint">Short-lease floor. If returned sooner, bills this many days × daily rate. Blank or 0 = none.</div>
                    </div>
                </div>

                <!-- S-MILEAGE-UNIT-SIMPLIFY — Single-unit mileage section
                     Operator picks a unit (KM | MILES) then enters one rate
                     and one allowance. The API derives counterpart columns
                     using the global conversion factors (Settings → General →
                     Mileage Conversion Factors) and snapshots those factors
                     into the lease at creation. -->
                <div style="border-top:1px solid var(--border-color);margin-top:1.5rem;padding-top:1.5rem;">

                    <h3 class="form-section-title" style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);margin:0 0 16px 0;letter-spacing:-0.01em;">
                        Mileage &amp; allowance
                    </h3>

                    <!-- ── Apple segmented control ── -->
                    <div style="display:flex;justify-content:center;margin-bottom:32px;">
                        <div class="ff-segment-control"
                             role="tablist"
                             aria-label="Mileage unit">
                            <div class="ff-segment-control__pill"
                                 :class="{ 'ff-segment-control__pill--right': form.mileage_unit === 'miles' }"></div>
                            <div class="ff-segment-control__option"
                                 :class="{ 'ff-segment-control__option--active': form.mileage_unit === 'km' }"
                                 @click="!ratesLocked && togglePrimaryUnit('km')"
                                 role="tab"
                                 :aria-selected="form.mileage_unit === 'km'"
                                 :aria-disabled="ratesLocked"
                                 tabindex="0"
                                 @keydown.enter.prevent="!ratesLocked && togglePrimaryUnit('km')"
                                 @keydown.space.prevent="!ratesLocked && togglePrimaryUnit('km')">
                                Kilometers
                            </div>
                            <div class="ff-segment-control__option"
                                 :class="{ 'ff-segment-control__option--active': form.mileage_unit === 'miles' }"
                                 @click="!ratesLocked && togglePrimaryUnit('miles')"
                                 role="tab"
                                 :aria-selected="form.mileage_unit === 'miles'"
                                 :aria-disabled="ratesLocked"
                                 tabindex="0"
                                 @keydown.enter.prevent="!ratesLocked && togglePrimaryUnit('miles')"
                                 @keydown.space.prevent="!ratesLocked && togglePrimaryUnit('miles')">
                                Miles
                            </div>
                        </div>
                    </div>

                    <!-- ── Rate / est-per-day / allowance — one responsive row (S-FORM-LAYOUT) ── -->
                    <div class="form-row-3">
                    <!-- ── Single rate input ── -->
                    <div class="form-group">
                        <label class="form-label" for="mileage_rate">
                            Mileage rate (per <span x-text="form.mileage_unit === 'km' ? 'km' : 'mile'"></span>)
                        </label>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <input type="number"
                                   id="mileage_rate"
                                   class="form-control font-mono"
                                   step="0.0001"
                                   min="0"
                                   name="mileage_rate"
                                   x-model="form.mileage_rate"
                                   :readonly="ratesLocked"
                                   :style="ratesLocked ? 'background:var(--bg-muted);cursor:not-allowed;' : ''"
                                   aria-label="Mileage rate"
                                   placeholder="0.0000">
                            <span class="input-group-suffix" x-text="'/ ' + (form.mileage_unit === 'km' ? 'km' : 'mile')"></span>
                        </div>
                        <div class="form-hint" style="margin-top:4px;">Set to 0 to disable mileage billing. The counterpart unit rate is derived from the global conversion factor in Settings.</div>
                        <div class="form-error" x-show="errors.mileage_rate" x-text="errors.mileage_rate"></div>
                    </div>

                    <!-- ── Estimated mileage per day (S-MILEAGE-EST-DAILY) ── -->
                    <div class="form-group">
                        <label class="form-label" for="estimated_mileage_per_day">
                            Estimated mileage per day (<span x-text="form.mileage_unit === 'km' ? 'km' : 'miles'"></span>/day)
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   id="estimated_mileage_per_day"
                                   class="form-control font-mono"
                                   step="1"
                                   min="0"
                                   name="estimated_mileage_per_day"
                                   x-model="form.estimated_mileage_per_day"
                                   aria-label="Estimated mileage per day"
                                   placeholder="0">
                            <span class="input-group-suffix" x-text="(form.mileage_unit === 'km' ? 'km' : 'miles') + '/day'"></span>
                        </div>
                        <div class="form-hint" style="margin-top:4px;">
                            Each invoice bills an <strong>estimate</strong> (days &times; this &times; rate), then trues up against the
                            <strong>actual</strong> distance driven &mdash; adding a charge or credit for the difference. Rate comes from the
                            customer rate card. Leave at 0 to bill only actual mileage.
                        </div>
                        <div class="form-error" x-show="errors.estimated_mileage_per_day" x-text="errors.estimated_mileage_per_day"></div>
                    </div>

                    <!-- ── Single allowance input (with inline km/miles toggle) ── -->
                    <div class="form-group">
                        <label class="form-label" for="estimated_mileage">Estimated mileage</label>
                        <div class="input-group">
                            <input type="number"
                                   id="estimated_mileage"
                                   class="form-control font-mono"
                                   step="1"
                                   min="0"
                                   name="estimated_mileage"
                                   x-model="form.estimated_mileage"
                                   aria-label="Estimated mileage allowance"
                                   placeholder="0">
                            <?php /* S-MILEAGE-EST-UNIT-TOGGLE: per-field km/miles toggle. Sets the SHARED
                                     lease mileage_unit (same togglePrimaryUnit the top control uses), so the
                                     estimated mileage + the rate always share one unit; disabled with ratesLocked. */ ?>
                            <span class="input-group-suffix" style="padding:0;display:inline-flex;align-items:stretch;align-self:stretch;overflow:hidden;" role="group" aria-label="Estimated mileage unit">
                                <?php /* Alpine's string :style REPLACES the whole style attribute, so the
                                         structural styles must live INSIDE the binding (not only in the static
                                         style=, which is just a no-Alpine fallback) or padding/flex/weight are lost. */ ?>
                                <span role="button" tabindex="0" :aria-pressed="form.mileage_unit === 'km'"
                                      @click="!ratesLocked && togglePrimaryUnit('km')"
                                      @keydown.enter.prevent="!ratesLocked && togglePrimaryUnit('km')"
                                      @keydown.space.prevent="!ratesLocked && togglePrimaryUnit('km')"
                                      style="display:flex;align-items:center;padding:0 12px;font-size:0.8125rem;font-weight:600;"
                                      :style="'display:flex;align-items:center;padding:0 12px;font-size:0.8125rem;font-weight:600;' + (form.mileage_unit === 'km' ? 'background:var(--color-primary);color:#fff;' : 'color:var(--text-secondary);') + (ratesLocked ? 'cursor:not-allowed;opacity:0.6;' : 'cursor:pointer;')">km</span>
                                <span role="button" tabindex="0" :aria-pressed="form.mileage_unit === 'miles'"
                                      @click="!ratesLocked && togglePrimaryUnit('miles')"
                                      @keydown.enter.prevent="!ratesLocked && togglePrimaryUnit('miles')"
                                      @keydown.space.prevent="!ratesLocked && togglePrimaryUnit('miles')"
                                      style="display:flex;align-items:center;padding:0 12px;font-size:0.8125rem;font-weight:600;border-left:1px solid var(--border-color);"
                                      :style="'display:flex;align-items:center;padding:0 12px;font-size:0.8125rem;font-weight:600;border-left:1px solid var(--border-color);' + (form.mileage_unit === 'miles' ? 'background:var(--color-primary);color:#fff;' : 'color:var(--text-secondary);') + (ratesLocked ? 'cursor:not-allowed;opacity:0.6;' : 'cursor:pointer;')">miles</span>
                            </span>
                        </div>
                        <div class="form-hint" style="margin-top:4px;">Total distance included in the lease (same unit as the rate). Leave at 0 with a rate to bill every unit from 0.</div>
                        <div class="form-error" x-show="errors.estimated_mileage" x-text="errors.estimated_mileage"></div>
                    </div>
                    </div><!-- /form-row-3 rate+per-day+allowance -->

                    <!-- ══════════════════════════════════════════════════════════
                         S-MILEAGE-1 Model B — Mileage precharge subsection
                         ──────────────────────────────────────────────────────────
                         Optional per-lease precharge: customer pays an upfront
                         amount on Invoice 1 and that amount is drawn down
                         against per-km usage on subsequent invoices. When the
                         balance hits zero, future invoices bill straight per-km.
                         At lease close, any positive remaining balance is
                         refunded (cash or account credit, picked at close).
                         Default: off — existing leases unaffected.
                         ══════════════════════════════════════════════════════════ -->
                    <div style="border-top:1px solid var(--border-color);margin-top:24px;padding-top:24px;">
                        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                            <input type="checkbox"
                                   role="switch"
                                   class="form-check-input"
                                   x-model="form.precharge_enabled">
                            <span>
                                <span style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);letter-spacing:-0.01em;">Apply mileage precharge</span>
                                <div class="form-hint" style="margin-top:2px;">Customer pays this amount upfront on Invoice 1 and draws down against monthly mileage charges. Refunded at lease close if unused.</div>
                            </span>
                        </label>

                        <div x-show="form.precharge_enabled" x-cloak style="margin-top:16px;max-width:320px;">
                            <label class="form-label" for="precharge_amount">Precharge amount</label>
                            <div class="input-group">
                                <span class="input-group-prefix">$</span>
                                <input type="number"
                                       id="precharge_amount"
                                       class="form-control font-mono"
                                       step="0.01"
                                       min="0.01"
                                       name="precharge_amount"
                                       x-model="form.precharge_amount"
                                       :class="errors.precharge_amount ? 'is-invalid' : ''"
                                       placeholder="0.00">
                            </div>
                            <div class="form-error" x-show="errors.precharge_amount" x-text="errors.precharge_amount"></div>
                        </div>
                    </div>

                </div><!-- /S-MILEAGE-UNIT-SIMPLIFY mileage section -->

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

                <!-- S-GPS-RATE-CARD: GPS opt-in toggle; rate comes from the rate card, not entered manually.
                     (Hourly Rate moved to the Rental Rates section — S-LEASE-HOURLY-IN-RATES.) -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.gps_opt_in">
                            GPS Tracking
                        </label>
                        <div x-show="form.gps_opt_in" style="margin-top:4px;">
                            <template x-if="form.gps_cost && parseFloat(form.gps_cost) > 0">
                                <span class="text-secondary" style="font-size:0.8125rem;">
                                    $<span x-text="parseFloat(form.gps_cost).toFixed(2)"></span>/day
                                    <span style="color:var(--text-muted);">(from rate card)</span>
                                </span>
                            </template>
                            <template x-if="!form.gps_cost || parseFloat(form.gps_cost) <= 0">
                                <span class="text-secondary" style="font-size:0.8125rem;">No GPS rate on this rate card</span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- ── Estimated engine hours per day (S-HOURS-EST-DAILY) ──
                     ALWAYS VISIBLE — the engine-hours parallel of "estimated mileage per
                     day", which is also always shown. Discoverability fix (S-HOURS-EST-VIS):
                     it was previously gated behind hourly_rate>0, so operators who hadn't
                     yet entered an hourly rate never saw the feature. Now it's always on
                     the form; the rate-completeness check (api create.php) still requires
                     an Hourly Rate before a per-day value can save, so it can't mis-bill. -->
                <div class="form-group" style="max-width:320px;margin-top:1rem;">
                    <label class="form-label" for="estimated_engine_hours_per_day">Estimated engine hours per day (hrs/day)</label>
                    <div class="input-group">
                        <input type="number"
                               id="estimated_engine_hours_per_day"
                               class="form-control font-mono"
                               step="0.01"
                               min="0"
                               name="estimated_engine_hours_per_day"
                               x-model="form.estimated_engine_hours_per_day"
                               aria-label="Estimated engine hours per day"
                               placeholder="0">
                        <span class="input-group-suffix">hrs/day</span>
                    </div>
                    <div class="form-hint" style="margin-top:0.5rem;">
                        For reefer / hourly-billed units. Each invoice bills an <strong>estimate</strong> (days &times; this &times; your
                        <strong>Hourly Rate</strong>), then trues up against the <strong>actual</strong> engine hours (end &minus; start) at close.
                        <span x-show="!(parseFloat(form.hourly_rate) > 0)" style="color:var(--color-warning,#c47f17);">Set an <strong>Hourly Rate</strong> (Rental Rates section above) to bill it.</span>
                        Leave at 0 to bill only actual hours entered at close.
                    </div>
                    <div class="form-error" x-show="errors.estimated_engine_hours_per_day" x-text="errors.estimated_engine_hours_per_day"></div>
                </div>

                <!-- S-LEASE-HOURLY-BILLING: manual starting engine/reefer hours (meter
                     reading). Shown only when this lease has an hourly rate (the billing
                     gate) — a reading is only meaningful once hourly billing is on. -->
                <div class="form-group" style="max-width:320px;margin-top:1rem;"
                     x-show="parseFloat(form.hourly_rate) > 0">
                    <label class="form-label" for="engine_hours_at_start">Starting Engine Hours</label>
                    <input type="number" id="engine_hours_at_start" class="form-control font-mono"
                           x-model="form.engine_hours_at_start" step="0.01" min="0"
                           placeholder="e.g. 1240.50">
                    <div class="form-hint" style="margin-top:0.5rem;">
                        Manual reading at lease start. Engine hours are billed at $<span x-text="parseFloat(form.hourly_rate || 0).toFixed(4)"></span>/hr on the closing invoice (or a manually-created invoice).
                    </div>
                    <div class="form-error" x-show="errors.engine_hours_at_start" x-text="errors.engine_hours_at_start"></div>
                </div>

                <!-- S-LEASE-SERVICE-CHARGES: one-time cartage (delivery) charge.
                     Manual amount — entered only when we deliver the unit; bills
                     once on the first invoice. No default. -->
                <div class="form-group" style="max-width:320px;margin-top:1rem;">
                    <label class="form-label" for="cartage_amount">Cartage (delivery charge)</label>
                    <div class="input-group">
                        <span class="input-group-prefix">$</span>
                        <input type="number" id="cartage_amount" class="form-control font-mono"
                               x-model="form.cartage_amount" step="0.01" min="0"
                               placeholder="0.00">
                    </div>
                    <div class="form-hint" style="margin-top:0.5rem;">
                        One-time delivery charge — leave blank if the customer picks the unit up. Bills on the first invoice.
                    </div>
                    <div class="form-error" x-show="errors.cartage_amount" x-text="errors.cartage_amount"></div>
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

        <!-- ── Section 6: Mileage Tracking (S-LEASE-MILEAGE-MODE) ───
             The 3-position toggle picks how this lease's mileage/odometer
             is sourced:
               Manual  → operator types the reading; Samsara is never queried
                         and a manual reading is never auto-overwritten.
               Off     → no mileage tracking or billing at all (default).
               Samsara → auto-fetch at activation + Samsara distance in
                         auto-billing; the "Fetch from Samsara" button live-
                         pulls the current reading.
             ───────────────────────────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Mileage Tracking</div></div>
            <div class="card-body">
                <div class="form-hint" style="margin-bottom:1rem;">
                    Choose how this lease tracks mileage. <strong>Manual</strong> = you enter
                    odometer readings by hand. <strong>Off</strong> = no mileage tracking or
                    billing. <strong>Samsara</strong> = readings come from the unit's GPS.
                </div>

                <!-- ── 3-position mileage-source segmented control ── -->
                <div style="display:flex;justify-content:center;margin-bottom:24px;">
                    <div class="ff-segment-control ff-segment-control--3"
                         role="tablist"
                         aria-label="Mileage tracking mode">
                        <div class="ff-segment-control__pill"
                             :class="{
                                 'ff-segment-control__pill--mid': form.mileage_tracking_mode === 'off',
                                 'ff-segment-control__pill--end': form.mileage_tracking_mode === 'samsara'
                             }"></div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': form.mileage_tracking_mode === 'manual' }"
                             @click="setMileageMode('manual')"
                             role="tab"
                             :aria-selected="form.mileage_tracking_mode === 'manual'"
                             tabindex="0"
                             @keydown.enter.prevent="setMileageMode('manual')"
                             @keydown.space.prevent="setMileageMode('manual')">
                            Manual
                        </div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': form.mileage_tracking_mode === 'off' }"
                             @click="setMileageMode('off')"
                             role="tab"
                             :aria-selected="form.mileage_tracking_mode === 'off'"
                             tabindex="0"
                             @keydown.enter.prevent="setMileageMode('off')"
                             @keydown.space.prevent="setMileageMode('off')">
                            Off
                        </div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': form.mileage_tracking_mode === 'samsara' }"
                             @click="setMileageMode('samsara')"
                             role="tab"
                             :aria-selected="form.mileage_tracking_mode === 'samsara'"
                             tabindex="0"
                             @keydown.enter.prevent="setMileageMode('samsara')"
                             @keydown.space.prevent="setMileageMode('samsara')">
                            Samsara
                        </div>
                    </div>
                </div>

                <!-- Off-mode notice: no mileage tracking on this lease (no rate set) -->
                <div x-show="form.mileage_tracking_mode === 'off' && !(parseFloat(form.mileage_rate) > 0)"
                     class="alert alert-warning"
                     style="margin-bottom:0;padding:0.5rem 0.75rem;font-size:0.875rem;">
                    Mileage tracking is <strong>Off</strong> — this lease won't capture an
                    odometer reading or bill any mileage. Pick <strong>Manual</strong> or
                    <strong>Samsara</strong> if this lease should track mileage.
                </div>

                <!-- S-MILEAGE-MODE-OFF-WARN: escalated notice — a mileage RATE is configured
                     but tracking is Off. This is the silent rate>0 + mode='off' trap that
                     produced lease MTTS68 / INV-2026-00150 (a $/km rate was set, the mode
                     stayed at its 'off' default, and the invoice billed $0 mileage). The
                     generic notice above read as a benign "no mileage" message; this one
                     names the contradiction so the operator can't miss it. -->
                <div x-show="form.mileage_tracking_mode === 'off' && parseFloat(form.mileage_rate) > 0"
                     class="alert alert-danger"
                     style="margin-bottom:0;padding:0.5rem 0.75rem;font-size:0.875rem;">
                    ⚠️ You set a <strong>mileage rate</strong>
                    ($<span x-text="form.mileage_rate"></span>/<span x-text="form.mileage_unit === 'km' ? 'km' : 'mile'"></span>)
                    but tracking is <strong>Off</strong> — <strong>no mileage will be billed</strong>
                    and the rate is ignored. Choose <strong>Manual</strong> (you enter the odometer)
                    or <strong>Samsara</strong> (auto) to actually bill mileage.
                </div>

                <!-- Odometer capture — hidden entirely when tracking is Off -->
                <div class="form-group" style="max-width:540px;"
                     x-show="form.mileage_tracking_mode !== 'off'">
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
                                x-show="odometerCanFetch && form.mileage_tracking_mode === 'samsara'"
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

                    <!-- Hint when unit is not Samsara-linked (Samsara mode only) -->
                    <div x-show="form.mileage_tracking_mode === 'samsara' && selectedUnit && !odometerCanFetch"
                         class="form-hint"
                         style="margin-top:0.5rem;color:var(--text-secondary);">
                        Link this unit to Samsara on the equipment page to enable live GPS odometer fetch.
                    </div>

                    <!-- Default hint (Samsara mode only) -->
                    <div x-show="form.mileage_tracking_mode === 'samsara' && !selectedUnit"
                         class="form-hint" style="margin-top:0.5rem;">
                        Select a unit above to enable the Samsara fetch button.
                    </div>

                    <!-- Manual-mode hint -->
                    <div x-show="form.mileage_tracking_mode === 'manual'"
                         class="form-hint" style="margin-top:0.5rem;">
                        Enter the starting odometer by hand. This value is authoritative — Samsara will never overwrite it.
                    </div>

                    <div class="form-error" x-show="errors.odometer_start_km" x-text="errors.odometer_start_km"></div>
                </div>
            </div>
        </div>

        <!-- ── Section 7: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Notes</div></div>
            <div class="card-body">
                <div class="form-row-2">
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
                </div><!-- /form-row-2 notes -->
            </div>
        </div>

        <!-- ── Section 8: ASPE 3065 Lease Classification ─────────── -->
        <!-- S-ACCT-LESSOR-1 (Phase D-1) — most leases stay Operating.
             Operator only expands the wizard for lease-to-own contracts.
             On submit, if Capital is selected, the new lease_id is fed
             to /api/v1/accounting/leases/classify and the result is
             shown in the success overlay before redirect. -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
                 @click="lessorPanelOpen = !lessorPanelOpen">
                <div class="card-title">ASPE 3065 Lease Classification</div>
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span class="badge"
                          :class="form.classification_choice === 'capital' ? 'badge-warning' : 'badge-neutral'"
                          x-text="form.classification_choice === 'capital' ? 'Capital wizard' : 'Operating (default)'"></span>
                    <span x-text="lessorPanelOpen ? '▾' : '▸'" style="opacity:0.6;"></span>
                </div>
            </div>
            <div class="card-body" x-show="lessorPanelOpen" x-cloak>
                <div class="form-group">
                    <label class="form-label">Lease Type</label>
                    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="radio" name="classification_choice" value="operating"
                                   x-model="form.classification_choice">
                            <span>Operating (standard rental)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="radio" name="classification_choice" value="capital"
                                   x-model="form.classification_choice">
                            <span>Potential Capital Lease — run wizard</span>
                        </label>
                    </div>
                    <div class="form-hint" style="margin-top:0.5rem;">
                        ASPE 3065.06 criteria run on submit when "Potential Capital Lease" is selected.
                        Most rentals stay Operating — pick Capital only for lease-to-own contracts.
                    </div>
                </div>

                <div x-show="form.classification_choice === 'capital'" x-cloak
                     style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                    <div class="row" style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;">
                        <div class="form-group">
                            <label class="form-label" for="lessor_term_months">Lease Term (months) *</label>
                            <input type="number" min="1" id="lessor_term_months" class="form-control"
                                   x-model="form.lessor.lease_term_months" placeholder="e.g. 60">
                            <div class="form-hint">Operator-provided. Leases table has no term column on disk.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_econ_life">Economic Life (months) *</label>
                            <input type="number" min="1" id="lessor_econ_life" class="form-control"
                                   x-model="form.lessor.economic_life_months" placeholder="e.g. 120">
                            <div class="form-hint">Remaining useful life of the asset at inception.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_fair_value">Initial Fair Value ($) *</label>
                            <input type="number" min="0.01" step="0.01" id="lessor_fair_value" class="form-control"
                                   x-model="form.lessor.initial_fair_value" placeholder="e.g. 150000.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_disc_rate_pct">Discount Rate (%) *</label>
                            <input type="number" min="0" step="0.01" id="lessor_disc_rate_pct" class="form-control"
                                   x-model="form.lessor.discount_rate_pct" placeholder="e.g. 6.50">
                            <div class="form-hint">Annual rate. Stored as decimal (6.5% → 0.0650).</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_guar_resid">Guaranteed Residual Value ($)</label>
                            <input type="number" min="0" step="0.01" id="lessor_guar_resid" class="form-control"
                                   x-model="form.lessor.guaranteed_residual_value" placeholder="0.00">
                            <div class="form-hint">Included in PV of MLP for Criterion C.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_unguar_resid">Unguaranteed Residual Value ($)</label>
                            <input type="number" min="0" step="0.01" id="lessor_unguar_resid" class="form-control"
                                   x-model="form.lessor.unguaranteed_residual_value" placeholder="0.00">
                            <div class="form-hint">Excluded from MLP; used by amortization engine.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_idc">Initial Direct Costs ($)</label>
                            <input type="number" min="0" step="0.01" id="lessor_idc" class="form-control"
                                   x-model="form.lessor.initial_direct_costs" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_bpo_amount">BPO Amount ($)</label>
                            <input type="number" min="0" step="0.01" id="lessor_bpo_amount" class="form-control"
                                   x-model="form.lessor.bpo_amount" placeholder="optional">
                            <div class="form-hint">Bargain purchase option. Triggers Criterion A.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="lessor_bpo_date">BPO Date</label>
                            <input type="date" id="lessor_bpo_date" class="form-control"
                                   x-model="form.lessor.bpo_date">
                        </div>
                        <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" id="lessor_title_transfer"
                                   x-model="form.lessor.title_transfers">
                            <label class="form-label" for="lessor_title_transfer" style="margin:0;">
                                Title transfers at end of term
                            </label>
                        </div>
                    </div>

                    <div class="row" style="display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;margin-top:0.5rem;">
                        <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" id="lessor_credit_risk"
                                   x-model="form.lessor.credit_risk_normal">
                            <label class="form-label" for="lessor_credit_risk" style="margin:0;">
                                Credit risk normal vs similar receivables (3065.07)
                            </label>
                        </div>
                        <div class="form-group" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" id="lessor_costs_est"
                                   x-model="form.lessor.costs_estimable">
                            <label class="form-label" for="lessor_costs_est" style="margin:0;">
                                Unreimbursable costs reasonably estimable (3065.08)
                            </label>
                        </div>
                    </div>

                    <!-- Inline result, populated by submit() after classify.php returns -->
                    <template x-if="lessorResult">
                        <div style="margin-top:1rem;padding:1rem;border-radius:6px;"
                             :class="lessorResult.classification === 'operating'
                                     ? 'alert alert-success'
                                     : 'alert alert-warning'">
                            <div style="font-weight:600;margin-bottom:0.5rem;"
                                 x-text="lessorResult.classification === 'operating'
                                     ? '✓ Classified as Operating Lease — no capital lease accounting required.'
                                     : (lessorResult.classification === 'sales_type'
                                        ? '⚠ Sales-Type Lease — selling profit recognized at inception.'
                                        : '⚠ Direct Financing Lease — finance income only, no selling profit.')">
                            </div>
                            <div style="font-size:0.875rem;opacity:0.85;" x-text="lessorResult.rationale"></div>
                            <table style="width:100%;margin-top:0.75rem;font-size:0.85rem;border-collapse:collapse;">
                                <thead>
                                    <tr style="border-bottom:1px solid var(--border-color);">
                                        <th style="text-align:left;padding:0.25rem;">Criterion</th>
                                        <th style="text-align:left;padding:0.25rem;">Met</th>
                                        <th style="text-align:left;padding:0.25rem;">Detail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td style="padding:0.25rem;">A — Title / BPO</td>
                                        <td x-text="lessorResult.criteria.A.met ? '✓' : '—'"></td>
                                        <td x-text="lessorResult.criteria.A.evidence"></td></tr>
                                    <tr><td style="padding:0.25rem;">B — Term ≥ 75% life</td>
                                        <td x-text="lessorResult.criteria.B.met ? '✓' : '—'"></td>
                                        <td x-text="'Ratio ' + lessorResult.criteria.B.ratio"></td></tr>
                                    <tr><td style="padding:0.25rem;">C — PV MLP ≥ 90% FV</td>
                                        <td x-text="lessorResult.criteria.C.met ? '✓' : '—'"></td>
                                        <td x-text="'Ratio ' + lessorResult.criteria.C.ratio + ' (PV=$' + lessorResult.criteria.C.pv_mlp + ')'"></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('leases') ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
                <span x-show="!submitting && form.classification_choice !== 'capital'">Create Lease</span>
                <span x-show="!submitting && form.classification_choice === 'capital'">Create Lease &amp; Run Classification</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <!-- VALID-2: form-level error banner is injected by FF_Validate.banner() -->
        <div class="form-error-banner" data-form-error></div>

    </form>

    <?php
    // S-CREATE-FEEDBACK: include INSIDE the x-data scope so Alpine
    // can bind `submitting` + `showSuccessOverlay` from FF_CreateLease.
    // Previously included AFTER </div> (outside scope) — overlay
    // bindings had no parent x-data to reach.
    $overlayTitle    = 'Lease Created!';
    $overlaySubtitle = 'Redirecting to lease details…';
    require_once FF_ROOT . '/includes/success_overlay.php';
    ?>
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
            start_time:         '',
            end_date:           '',
            end_time:           '',
            minimum_end_date:   '',
            billing_cycle:      'monthly',
            currency:           'CAD',
            mileage_unit:       'km',
            daily_rate:         '',
            weekly_rate:        '',
            monthly_rate:       '',
            // S-LEASE-MIN-DAYS: frozen short-lease floor — defaults to the global
            // setting and is overwritten by the rate-card minimum_days in _lookupRates().
            minimum_billing_days: <?= (int) settings_get('lease.minimum_billing_days', '3') ?>,
            // S-MILEAGE-UNIT-SIMPLIFY: single rate + allowance; API derives counterpart columns
            mileage_rate:             '',
            estimated_mileage:        '',
            estimated_mileage_per_day: '', // S-MILEAGE-EST-DAILY: per-day estimate (estimate + true-up billing)
            // SAMSARA-3: mileage_at_start removed — derived from odometer_start_km on the server
            rate_notes:         '',
            discount_type:      'none',
            discount_value:     '',
            insurance_opt_in:   false,
            insurance_cost:     '',
            warranty_opt_in:    false,
            warranty_cost:      '',
            // S-GPS-RATE-CARD: GPS toggle; cost auto-populated from rate card via _lookupRates()
            gps_opt_in:         true,
            gps_cost:           '',
            hourly_rate:        '',
            // S-LEASE-HOURLY-BILLING: manual starting engine/reefer hours.
            engine_hours_at_start: '',
            // S-HOURS-EST-DAILY: estimated engine hours per day (0 = off).
            estimated_engine_hours_per_day: '',
            // S-LEASE-SERVICE-CHARGES: one-time cartage (delivery) charge.
            cartage_amount:     '',
            gst_exempt:         false,
            pst_exempt:         false,
            notes:              '',
            internal_notes:     '',
            // S-LEASE-MILEAGE-MODE: per-lease mileage data source.
            // 'manual' | 'off' | 'samsara'. Default 'off' (operator must pick).
            mileage_tracking_mode: 'off',
            // SAMSARA-3: starting odometer captured at lease start
            odometer_start_km:         '',
            odometer_start_source:     null,  // 'gps' | 'manual' | null
            odometer_start_fetched_at: null,  // ISO datetime when GPS fetched
            // ADV-BILL-1: prepay future periods at activation (monthly cycle only)
            advance_billing_periods:   0,
            // S-MILEAGE-1 Model B: optional precharge toggle + amount.
            // When enabled, customer pays precharge_amount upfront on Invoice 1
            // and that balance draws down against per-km usage on subsequent
            // invoices. Off by default — existing leases unaffected.
            precharge_enabled:         false,
            precharge_amount:          '',
            // S-ACCT-LESSOR-1 Phase D-1: ASPE 3065 classification wizard.
            // Operating is the default (matches schema DEFAULT 'operating').
            // Capital expands the wizard panel + runs classify.php on submit.
            classification_choice:     'operating',
            lessor: {
                lease_term_months:           '',
                economic_life_months:        '',
                initial_fair_value:          '',
                discount_rate_pct:           '',
                guaranteed_residual_value:   '0.00',
                unguaranteed_residual_value: '0.00',
                initial_direct_costs:        '0.00',
                bpo_amount:                  '',
                bpo_date:                    '',
                title_transfers:             false,
                credit_risk_normal:          true,
                costs_estimable:             true,
            },
        },
        lessorPanelOpen: false,
        lessorResult:    null,
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

            // S-FORM-DRAFT-AUTOSAVE: mirror in-progress edits to localStorage so
            // a Back/refresh/close never loses them; offer a restore banner on
            // return. EXCLUSIONS: customer_id/equipment_unit_id are owned by the
            // FF_RecordPicker sub-components, which can't be re-displayed at
            // runtime — restoring a bare id would show an empty-looking picker
            // with a hidden value. odometer_start_source/_fetched_at are
            // transient GPS-fetch metadata that would show a stale badge.
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId:   'lease-create',
                    entityId: 'new',
                    el:       this.$root,
                    model:    this.form,
                    exclude:  ['customer_id', 'equipment_unit_id',
                               'odometer_start_km', 'odometer_start_source', 'odometer_start_fetched_at'],
                });
            }
        },

        // ADV-BILL-1: clamp the advance-periods input to [0, cap] on every change.
        // The server re-validates, but clamping here gives instant feedback.
        clampAdvancePeriods() {
            let n = parseInt(this.form.advance_billing_periods, 10);
            if (isNaN(n) || n < 0) n = 0;
            if (n > this.advanceBillingCap) n = this.advanceBillingCap;
            this.form.advance_billing_periods = n;
        },

        // ADV-BILL-1: client-side period preview — mirrors generateAdvanceBatch() period math.
        // Uses monthly_rate for full months; prorates by day for partial first period.
        // Returns array of { label, days, amount, amountFmt } — one entry per invoice.
        advancePreviewPeriods() {
            const n = parseInt(this.form.advance_billing_periods, 10);
            if (!n || n < 1 || !this.form.start_date) return [];

            const monthly  = parseFloat(this.form.monthly_rate) || 0;
            const daily    = parseFloat(this.form.daily_rate)   || 0;
            const currency = this.form.currency || 'CAD';
            const locale   = 'en-CA';
            const fmtOpts  = { style: 'currency', currency, minimumFractionDigits: 2 };
            const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

            const periods = [];
            // Parse start date without timezone shift
            const [sy, sm, sd] = this.form.start_date.split('-').map(Number);
            let pYear = sy, pMonth = sm - 1, pDay = sd; // pMonth is 0-indexed

            for (let i = 0; i <= n; i++) {
                const daysInMonth = new Date(pYear, pMonth + 1, 0).getDate();
                const endDay      = daysInMonth;
                const days        = endDay - pDay + 1;
                const isFullMonth = (pDay === 1);

                // Estimate pre-tax base amount
                let amount;
                if (isFullMonth && monthly > 0) {
                    amount = monthly;
                } else if (monthly > 0) {
                    amount = (monthly / daysInMonth) * days;
                } else {
                    amount = daily * days;
                }

                const startLabel = pDay + ' ' + monthNames[pMonth] + ' ' + pYear;
                const endLabel   = endDay + ' ' + monthNames[pMonth] + ' ' + pYear;
                const label      = (pDay === 1 && endDay === daysInMonth)
                    ? (monthNames[pMonth] + ' ' + pYear)
                    : (startLabel + ' – ' + endLabel);

                periods.push({
                    label,
                    days,
                    amount,
                    amountFmt: amount > 0
                        ? amount.toLocaleString(locale, fmtOpts)
                        : '—',
                });

                // Advance to first day of next month
                pMonth++;
                if (pMonth > 11) { pMonth = 0; pYear++; }
                pDay = 1;
            }
            return periods;
        },

        advancePreviewTotal() {
            const periods  = this.advancePreviewPeriods();
            if (!periods.length) return '—';
            const total    = periods.reduce((s, p) => s + p.amount, 0);
            const currency = this.form.currency || 'CAD';
            return total.toLocaleString('en-CA', { style: 'currency', currency, minimumFractionDigits: 2 });
        },

        // D-DROPDOWN-RETROFIT-PATTERN: called by FF_RecordPicker @record-picked.
        // Receives the raw customer record (from api/v1/customers/index.php) and
        // applies the same defaults that onCustomerChange() previously read from
        // DOM data-* attributes on the PHP-rendered <select> options.
        onCustomerPickerSelected(raw) {
            if (!raw) return;

            this.form.currency      = raw.currency      || 'CAD';
            this.form.mileage_unit  = raw.mileage_unit  || 'km';
            this.form.billing_cycle = raw.billing_cycle || 'monthly';
            this.form.gst_exempt    = raw.gst_exempt    === 1 || raw.gst_exempt === true;
            this.form.pst_exempt    = raw.pst_exempt    === 1 || raw.pst_exempt === true;

            if (raw.discount_type && raw.discount_type !== 'none') {
                this.form.discount_type  = raw.discount_type;
                this.form.discount_value = raw.discount_value || '';
            }

            // S019: if a unit is already selected, re-run rate lookup with new customer
            if (this.form.equipment_unit_id) {
                this._lookupRates();
            }
        },

        // D-DROPDOWN-RETROFIT-PATTERN: called by FF_RecordPicker @record-picked for
        // equipment unit. Receives the raw unit record (from api/v1/equipment/units/index.php).
        // Replaces the DOM-reading onUnitChange(); samsara_linked comes from the API
        // (added in S-DROPDOWN-RETROFIT-1).
        onUnitPickerSelected(raw) {
            if (!raw) return this.onUnitPickerCleared();

            // SAMSARA-3: track selected unit state for the Starting Odometer section.
            this.selectedUnit = {
                id:            raw.id,
                templateId:    raw.template_id || null,
                samsaraLinked: !!raw.samsara_linked,
            };
            this.odometerCanFetch = this.selectedUnit.samsaraLinked;
            // S-LEASE-MILEAGE-MODE: only drop a STALE GPS reading captured for
            // the previously-selected unit. A value the operator typed by hand
            // (source 'manual') is theirs and MUST survive a unit change — the
            // old code wiped it unconditionally, which is the "manual entry
            // doesn't register" bug. The banner referenced the old unit, so it
            // is cleared either way.
            this.odometerBanner = null;
            if (this.odometerSource === 'gps') {
                this.odometerSource                 = null;
                this.form.odometer_start_km         = '';
                this.form.odometer_start_source     = null;
                this.form.odometer_start_fetched_at = null;
            }

            // Reset rate lock before lookup — will be re-set based on new source
            this.ratesLocked = false;
            this.rateSource  = null;

            this._currentTemplateId = this.selectedUnit.templateId;
            this._lookupRates();
        },

        onUnitPickerCleared() {
            this.selectedUnit     = null;
            this.odometerCanFetch = false;
            this.odometerBanner   = null;
            // S-LEASE-MILEAGE-MODE: preserve an operator-typed manual reading;
            // only clear a stale GPS fetch tied to the now-deselected unit.
            if (this.odometerSource === 'gps') {
                this.odometerSource                 = null;
                this.form.odometer_start_km         = '';
                this.form.odometer_start_source     = null;
                this.form.odometer_start_fetched_at = null;
            }
            this.rateSource  = null;
            this.ratesLocked = false;
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
                    hour: '2-digit', minute: '2-digit', hour12: false,
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

        togglePrimaryUnit(newUnit) {
            // S-AUDIT-BILLING-ENGINE-1 #19: convert the populated rate with the
            // INVERSE factor when the unit flips — relabeling $X/km as $X/mile
            // mispriced the lease ×1.609 with zero warning.
            const oldUnit = this.form.mileage_unit;
            if (oldUnit !== newUnit && this.form.mileage_rate !== '' && parseFloat(this.form.mileage_rate) > 0) {
                const r = parseFloat(this.form.mileage_rate);
                // $/km = $/mile × 0.621371 ; $/mile = $/km × 1.609344 (rate = INVERSE of distance factor)
                this.form.mileage_rate = (newUnit === 'km' ? r * 0.621371 : r * 1.609344).toFixed(4);
            }
            this.form.mileage_unit = newUnit;
        },

        // S-LEASE-MILEAGE-MODE: 3-position mileage-source selector.
        //   manual  → operator enters the odometer by hand; Samsara never queried.
        //   off     → no mileage tracking/billing; clears any captured reading.
        //   samsara → auto-fetch at activation + Samsara distance in auto-billing.
        setMileageMode(mode) {
            this.form.mileage_tracking_mode = mode;
            if (mode === 'off') {
                // No mileage tracking — drop any captured starting reading.
                this.form.odometer_start_km         = '';
                this.form.odometer_start_source     = null;
                this.form.odometer_start_fetched_at = null;
                this.odometerSource = null;
                this.odometerBanner = null;
            } else if (mode === 'manual') {
                // Manual mode: an existing reading becomes operator-owned and a
                // prior GPS fetch is downgraded so it can't be treated as live.
                this.odometerBanner = null;
                if (this.form.odometer_start_km !== '' && this.form.odometer_start_km !== null) {
                    this.odometerSource                 = 'manual';
                    this.form.odometer_start_source     = 'manual';
                    this.form.odometer_start_fetched_at = null;
                } else {
                    // S-LEASE-ODO-DEFAULT-ZERO: operators start each lease at
                    // odometer 0, so pre-fill 0 for Manual mode instead of making
                    // them type it every time. Samsara mode is untouched — it
                    // fetches the real reading (a 0 there would overbill distance).
                    this.form.odometer_start_km         = '0';
                    this.odometerSource                 = 'manual';
                    this.form.odometer_start_source     = 'manual';
                    this.form.odometer_start_fetched_at = null;
                }
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
                // S-LEASE-MIN-DAYS: the per-equipment rate-card minimum_days (config
                // layer 1) overrides the global default frozen at init; when the rate
                // source supplies no minimum, fall back to the same global default.
                this.form.minimum_billing_days = (d.minimum_days != null) ? d.minimum_days : <?= (int) settings_get('lease.minimum_billing_days', '3') ?>;
                // S-GPS-RATE-CARD: GPS cost comes from the rate card, not user input
                this.form.gps_cost     = d.gps_price    ?? '';
                this.form.hourly_rate  = d.hourly_rate  ?? '';

                // S-BILLING-RATE-FIX D-D: when the rate source supplied a
                // monthly rate but no weekly, pre-fill weekly from
                // monthly/4.33 (calendar-accurate: 52 weeks / 12 months).
                // The user can override before save. Closes the upstream
                // hole that produced INV-2026-00086 zero-base-rental
                // (template id=1 had default_weekly_rate=NULL).
                if ((this.form.weekly_rate === '' || this.form.weekly_rate == null)
                    && parseFloat(this.form.monthly_rate) > 0) {
                    this.form.weekly_rate = (parseFloat(this.form.monthly_rate) / 4.33).toFixed(2);
                }

                // Apply currency/unit from the rate source.
                // S-AUDIT-BILLING-ENGINE-1 #19: the card's currency used to
                // OVERWRITE the customer's silently (item currency defaults
                // 'CAD') — a USD customer got repriced with raw CAD numbers,
                // no FX, no warning. Confirm before switching.
                if (d.currency && d.currency !== this.form.currency) {
                    const keep = this.form.currency;
                    if (confirm(`This rate card is priced in ${d.currency}, but the lease/customer currency is ${keep}. Switch the lease to ${d.currency}? (Cancel keeps ${keep} — verify the rates manually.)`)) {
                        this.form.currency = d.currency;
                    }
                }
                if (d.mileage_unit) this.form.mileage_unit = d.mileage_unit;

                // S-MILEAGE-UNIT-SIMPLIFY: set single rate in the selected unit
                this.form.mileage_rate = (parseFloat(d.mileage_rate) || 0) > 0
                    ? (parseFloat(d.mileage_rate)).toFixed(4) : '';

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
            // VALID-2 + S-LEASE-VALIDATION-UX: per-field errors PLUS an explicit
            // top-of-form summary banner naming exactly which required fields are
            // missing/invalid — a rejected submit must never look like a silent
            // "bouncing" button. IMPORTANT: the field id passed to FF_Validate.field
            // must match a real input, or the error never renders. The start/return
            // time is a split hour/minute picker (start_time_h / end_time_h), NOT a
            // `start_time` input — target the hour <select> so the message shows.
            const form = document.querySelector('form');
            FF_Validate.clear(form);
            const missing = [];
            const fail = (fieldId, label, msg) => {
                FF_Validate.field(form, fieldId, msg);
                missing.push(label);
            };

            if (!this.form.customer_id)
                fail('customer_id', 'Customer', 'Please select a customer.');
            if (!this.form.equipment_unit_id)
                fail('equipment_unit_id', 'Equipment unit', 'Please select an equipment unit.');
            if (!this.form.start_date)
                fail('start_date', 'Start date', 'Start date is required.');
            if (!this.form.start_time)
                fail('start_time_h', 'Lease start time', 'Lease start time is required — pick an hour and minute.');
            if (this.form.end_date && this.form.start_date &&
                this.form.end_date < this.form.start_date)
                fail('end_date', 'End date', 'End date must be on or after the start date.');

            // Numeric fields: reject negatives up-front with a clear field message.
            const numChecks = [
                ['daily_rate',        'Daily rate',        'Daily rate cannot be negative.'],
                ['weekly_rate',       'Weekly rate',       'Weekly rate cannot be negative.'],
                ['monthly_rate',      'Monthly rate',      'Monthly rate cannot be negative.'],
                ['mileage_rate',      'Mileage rate',      'Mileage rate cannot be negative.'],
                ['estimated_mileage', 'Estimated mileage', 'Estimated mileage cannot be negative.'],
                ['estimated_mileage_per_day', 'Estimated mileage per day', 'Estimated mileage per day cannot be negative.'],
                ['estimated_engine_hours_per_day', 'Estimated engine hours per day', 'Estimated engine hours per day cannot be negative.'],
                ['discount_value',    'Discount',          'Discount value cannot be negative.'],
                ['insurance_cost',    'Insurance cost',    'Insurance cost cannot be negative.'],
                ['warranty_cost',     'Warranty cost',     'Warranty cost cannot be negative.'],
            ];
            numChecks.forEach(([k, label, msg]) => {
                const v = this.form[k];
                if (v !== '' && v !== null && v !== undefined && Number(v) < 0) {
                    fail(k, label, msg);
                }
            });
            if (this.form.discount_type === 'percentage' &&
                Number(this.form.discount_value || 0) > 100) {
                fail('discount_value', 'Discount', 'Discount cannot exceed 100%.');
            }

            // At least one rate must be > 0.
            const anyRate = ['daily_rate','weekly_rate','monthly_rate','mileage_rate']
                .some(k => Number(this.form[k] || 0) > 0);
            if (!anyRate) {
                fail('daily_rate', 'A rate (daily, weekly, monthly, or mileage)',
                    'Enter at least one rate greater than zero.');
            }

            if (missing.length) {
                // Explicit summary so the operator sees WHAT is mandatory + missing.
                FF_Validate.banner(form, missing.join(', ') + '.', {
                    title: (missing.length === 1
                        ? 'Please fix this field before saving:'
                        : 'Please fix these ' + missing.length + ' fields before saving:'),
                });
                FF_Validate.scrollToFirst(form);
                return false;
            }
            return true;
        },

        async submit() {
            if (!this.validate()) return;

            // S-LEASE-MILEAGE-MODE: operator confirmed default is 'off'. Alert
            // before creating a lease that won't track or bill any mileage, in
            // case they forgot to pick Manual or Samsara. They can still proceed
            // — 'off' is valid for leases with no mileage component.
            //
            // S-MILEAGE-MODE-OFF-WARN: escalate the wording when a mileage RATE
            // is configured but tracking is Off — the silent rate>0 + mode='off'
            // trap that produced MTTS68 / INV-2026-00150 ($/km rate set, mode left
            // at its 'off' default, invoice billed $0 mileage). A bare "no mileage"
            // confirm read as benign; naming the rate makes the contradiction
            // explicit so the operator catches it at the decision point.
            if (this.form.mileage_tracking_mode === 'off') {
                const mr = parseFloat(this.form.mileage_rate);
                const unit = this.form.mileage_unit === 'km' ? 'km' : 'mile';
                const msg = (mr > 0)
                    ? ('⚠️ You set a mileage rate of $' + mr.toFixed(4) + '/' + unit
                        + ' but Mileage Tracking is OFF.\n\n'
                        + 'With tracking Off, NO mileage will be billed on any invoice — the rate is ignored.\n\n'
                        + 'Choose Manual (you enter the odometer) or Samsara (auto) to actually bill mileage.\n\n'
                        + 'Create the lease with mileage tracking Off anyway?')
                    : ('Mileage tracking is set to Off — this lease will not capture an odometer reading or bill any mileage.\n\n'
                        + 'Choose Manual or Samsara if this lease should track mileage.\n\n'
                        + 'Create the lease with mileage tracking Off?');
                if (!confirm(msg)) {
                    return;
                }
            }

            this.submitting  = true;

            const form = document.querySelector('form');

            // Build payload — omit empty strings
            // S-BILLING-RATE-FIX D-D: rate-tier fields are always sent. An
            // empty input becomes '0' so the API can apply the rate-tier
            // completeness invariant (D132) instead of seeing a missing key
            // that the legacy null-coalesce path silently turned into 0.00.
            // S-MILEAGE-RATE-VALIDATION D133: same coercion now extended to
            // the mileage rate fields so the API can apply D133 (mileage
            // rate-tier completeness) without a missing-key blind spot.
            const rateFields = [
                'daily_rate', 'weekly_rate', 'monthly_rate',
                'mileage_rate',
            ];
            const payload = {};
            Object.entries(this.form).forEach(([k, v]) => {
                if (rateFields.includes(k)) {
                    payload[k] = (v === '' || v === null || v === undefined) ? '0' : v;
                } else if (v !== '' && v !== null && v !== undefined) {
                    payload[k] = v;
                }
            });

            // S-LEASE-MIN-DAYS: always transmit minimum_billing_days, even when blank,
            // so a deliberate clear-to-blank reaches the API as '' (→ NULL, "no floor on
            // this lease") instead of being stripped as an absent key — which the create
            // API would re-inherit as the global default. The edit form posts the whole
            // form object, so it already preserves this clear-to-none intent.
            payload.minimum_billing_days =
                (this.form.minimum_billing_days === '' || this.form.minimum_billing_days === null || this.form.minimum_billing_days === undefined)
                    ? '' : this.form.minimum_billing_days;

            // Coerce numeric integers
            ['customer_id', 'equipment_unit_id'].forEach(f => {
                if (payload[f]) payload[f] = parseInt(payload[f]);
            });
            // SAMSARA-3: coerce decimal odometer so the API sees a proper number
            if (payload.odometer_start_km !== undefined) {
                payload.odometer_start_km = parseFloat(payload.odometer_start_km);
            }

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/create') ?>', payload);
                if (r.success) {
                    // S-FORM-DRAFT-AUTOSAVE: server confirmed creation — wipe the
                    // saved draft. stop=true also disables further autosave so the
                    // 3.5s success overlay + redirect can't resurrect it.
                    if (this._draft) this._draft.clear(true);
                    const _newId = r.data.id;

                    // S-ACCT-LESSOR-1: if capital lease was selected, run the
                    // ASPE 3065 wizard against the freshly created lease_id.
                    // Non-fatal — if classify.php fails, the lease is still
                    // created as 'operating' (the safe default) and the error
                    // is shown inline so the operator can re-run from show.php.
                    if (this.form.classification_choice === 'capital') {
                        const lessor = this.form.lessor;
                        const ratePctNum = Number(lessor.discount_rate_pct || 0);
                        const wizardPayload = {
                            lease_id:                    _newId,
                            lease_term_months:           parseInt(lessor.lease_term_months || 0),
                            economic_life_months:        parseInt(lessor.economic_life_months || 0),
                            initial_fair_value:          String(lessor.initial_fair_value || ''),
                            discount_rate:               (ratePctNum / 100).toFixed(4),
                            guaranteed_residual_value:   String(lessor.guaranteed_residual_value || '0'),
                            unguaranteed_residual_value: String(lessor.unguaranteed_residual_value || '0'),
                            initial_direct_costs:        String(lessor.initial_direct_costs || '0'),
                            title_transfers:             !!lessor.title_transfers,
                            credit_risk_normal:          !!lessor.credit_risk_normal,
                            costs_estimable:             !!lessor.costs_estimable,
                        };
                        if (lessor.bpo_amount !== '' && lessor.bpo_amount !== null) {
                            wizardPayload.bpo_amount = String(lessor.bpo_amount);
                        }
                        if (lessor.bpo_date) {
                            wizardPayload.bpo_date = lessor.bpo_date;
                        }
                        try {
                            const w = await FF_Api.post('<?= base_url('api/v1/accounting/leases/classify') ?>', wizardPayload);
                            if (w.success) {
                                this.lessorResult = w.data;
                                this.lessorPanelOpen = true;
                            } else {
                                FF_Validate.banner(form,
                                    'Lease created, but classification wizard failed: ' +
                                    (w.error?.message || 'unknown error') +
                                    '. Open the lease and re-run from its detail page.');
                            }
                        } catch(e2) {
                            FF_Validate.banner(form,
                                'Lease created, but classification wizard call errored. ' +
                                'Open the lease and re-run from its detail page.');
                        }
                    }

                    this.showSuccessOverlay = true;
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
// S-CREATE-FEEDBACK: overlay include moved INSIDE the x-data div
// above so Alpine can bind submitting + showSuccessOverlay.
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
