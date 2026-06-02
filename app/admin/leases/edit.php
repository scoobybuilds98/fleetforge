<?php
declare(strict_types=1);

/**
 * FleetForge — Edit Lease Form
 *
 * @file        app/admin/leases/edit.php
 * @description Edit lease metadata. Only allows editing fields that don't require
 *              an amendment record (dates, notes, add-ons, po_number, mileage).
 *              Rates are shown read-only — rate changes go through the
 *              Amendments tab on leases/show.php (built in S018, AMEND-1).
 *              Status changes excluded — use activate/close endpoints.
 *              Server pre-populates form via json_encode(). D19 optimistic lock:
 *              passes updated_at from initial load to update API.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/update.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D19 (optimistic lock), D30 (asset_url), D32 (CSS classes)
 * @session     S007
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'edit');

$leaseId = clean_int($_GET['id'] ?? null);
if (!$leaseId) {
    header('Location: ' . base_url('leases'));
    exit;
}

$lease = db_row(
    "SELECT l.*,
            COALESCE(c.company_name, l.company_name_snapshot) AS customer_display_name,
            COALESCE(u.unit_number, l.unit_number_snapshot)   AS unit_display_number
     FROM leases l
     LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.id = ? AND l.deleted_at IS NULL",
    [$leaseId]
);

if (!$lease) {
    header('Location: ' . base_url('leases'));
    exit;
}

// Only pending leases are fully editable — active leases have limited editing
$isActive = $lease['status'] === 'active';

$pageTitle = 'Edit ' . $lease['contract_number'];
$helpModuleSlug = 'leases';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('leases/show') ?>?id=<?= $leaseId ?>"
           class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← <?= e($lease['contract_number']) ?>
        </a>
        <h1 class="page-header-title h4">Edit <?= e($lease['contract_number']) ?></h1>
        <div class="text-secondary text-sm">
            <?= e($lease['customer_display_name']) ?> &nbsp;·&nbsp;
            Unit <?= e($lease['unit_display_number']) ?>
        </div>
    </div>
</div>

<?php if ($isActive): ?>
<div class="card card-body" style="background:var(--color-warning-light);color:var(--color-warning-text);margin-bottom:1.5rem;">
    <strong>Active Lease</strong> — Rate changes and customer/unit changes require an amendment record.
    Only dates, add-ons, notes, and mileage can be edited here.
</div>
<?php endif; ?>

<!-- ============================================================
     EDIT FORM (Alpine) — server pre-populated
     ============================================================ -->
<div x-data="FF_EditLease()" x-init="init()">

    <form @submit.prevent="submit()" novalidate>

        <!-- ── Section 1: Identity (read-only) ──────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Lease Identity</div></div>
            <div class="card-body">
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Contract Number</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['contract_number']) ?>
                        </div>
                        <div class="form-hint">Contract number cannot be changed after creation.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="po_number">PO Number</label>
                        <input type="text" id="po_number" class="form-control"
                               x-model="form.po_number" maxlength="100">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Customer</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['customer_display_name']) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['unit_display_number']) ?>
                        </div>
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
                        <label class="form-label">Start Date</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?= e($lease['start_date']) ?>
                        </div>
                        <div class="form-hint">Start date cannot be changed.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="end_date">End Date</label>
                        <?php // [UI-AUDIT-1:M18] :min prevents picking a date before lease start. ?>
                        <input type="date" id="end_date" class="form-control"
                               x-model="form.end_date"
                               min="<?= e($lease['start_date']) ?>"
                               :class="errors.end_date ? 'is-invalid' : ''">
                        <div class="form-error" x-show="errors.end_date" x-text="errors.end_date"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="minimum_end_date">Minimum End Date</label>
                        <?php // [UI-AUDIT-1:M18] Same constraint on minimum_end_date. ?>
                        <input type="date" id="minimum_end_date" class="form-control"
                               x-model="form.minimum_end_date"
                               min="<?= e($lease['start_date']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Section 3: Rates (read-only for active leases) ──── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Rental Rates</div>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">
                    <?php // [M5-FIX] Amendments are implemented in leases/show.php → Amendments tab. ?>
                    Rate changes require an amendment record — use the
                    <a href="<?= e(base_url('leases/show?id=' . $leaseId)) ?>#amendments"
                       class="text-link">Amendments tab</a>
                    on this lease.
                </div>
            </div>
            <div class="card-body">
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Daily Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['daily_rate'], 2)) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Weekly Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['weekly_rate'], 2)) ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Monthly Rate</label>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                            $<?= e(number_format((float)$lease['monthly_rate'], 2)) ?>
                        </div>
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="rate_notes">Rate Notes</label>
                        <input type="text" id="rate_notes" class="form-control"
                               x-model="form.rate_notes" maxlength="5000">
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             Section 4: Mileage & allowance (S-LEASE-UNITS)
             ──────────────────────────────────────────────────────────
             Primary unit is fixed at creation — flipping it requires an
             amendment, so the segmented control is rendered read-only-
             style (visible state, disabled interaction). Rate fields are
             also read-only (amendment workflow). Allowance + conversion
             factors stay editable with Pattern A bidirectional behavior
             matching create.php.
             ══════════════════════════════════════════════════════════ -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Mileage &amp; allowance</div></div>
            <div class="card-body">

                <!-- ── Primary unit indicator (read-only on edit) ── -->
                <div style="display:flex;justify-content:center;margin-bottom:24px;">
                    <div class="ff-segment-control"
                         role="group"
                         aria-label="Mileage unit (read-only — change via amendment)"
                         style="opacity:0.92;">
                        <div class="ff-segment-control__pill"
                             :class="{ 'ff-segment-control__pill--right': _mileageUnit === 'miles' }"></div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': _mileageUnit === 'km' }"
                             aria-disabled="true"
                             style="cursor:not-allowed;">
                            Kilometers
                        </div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': _mileageUnit === 'miles' }"
                             aria-disabled="true"
                             style="cursor:not-allowed;">
                            Miles
                        </div>
                    </div>
                </div>
                <div class="form-hint" style="text-align:center;margin-bottom:24px;">
                    Primary unit is fixed at creation — change via the
                    <a href="<?= e(base_url('leases/show?id=' . $leaseId)) ?>#amendments" class="text-link">Amendments tab</a>.
                </div>

                <!-- ── Per-unit rate (read-only) ── -->
                <div class="ff-dual-label">Per-unit rate <span style="font-weight:400;color:var(--text-muted);">— read-only</span></div>
                <div class="ff-dual-grid">
                    <div<?= ($lease['mileage_unit'] ?? 'km') !== 'km' ? ' class="ff-field-secondary"' : '' ?>>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;border-radius:0;">
                                <?= e(number_format((float)($lease['mileage_rate_km'] ?? $lease['mileage_rate'] ?? 0), 4)) ?>
                            </div>
                            <span class="input-group-suffix">/ km</span>
                        </div>
                    </div>
                    <div<?= ($lease['mileage_unit'] ?? 'km') !== 'miles' ? ' class="ff-field-secondary"' : '' ?>>
                        <div class="input-group">
                            <span class="input-group-prefix">$</span>
                            <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;border-radius:0;">
                                <?= e(number_format((float)($lease['mileage_rate_miles'] ?? 0), 4)) ?>
                            </div>
                            <span class="input-group-suffix">/ mile</span>
                        </div>
                    </div>
                </div>

                <!-- ── Allowance (editable) ── -->
                <div class="ff-dual-label">Allowance per lease</div>
                <div class="ff-dual-grid">
                    <div :class="{ 'ff-field-secondary': _mileageUnit !== 'km' }">
                        <div class="input-group">
                            <input type="number"
                                   class="form-control font-mono"
                                   step="0.001"
                                   min="0"
                                   name="estimated_mileage_km"
                                   x-model="form.estimated_mileage_km"
                                   @input.debounce.150ms="onAllowanceKmInput($event.target.value)"
                                   aria-label="Allowance in kilometers"
                                   placeholder="0">
                            <span class="input-group-suffix">km</span>
                        </div>
                        <div class="form-error" x-show="errors.estimated_mileage_km" x-text="errors.estimated_mileage_km"></div>
                    </div>
                    <div :class="{ 'ff-field-secondary': _mileageUnit !== 'miles' }">
                        <div class="input-group">
                            <input type="number"
                                   class="form-control font-mono"
                                   step="0.001"
                                   min="0"
                                   name="estimated_mileage_miles"
                                   x-model="form.estimated_mileage_miles"
                                   @input.debounce.150ms="onAllowanceMilesInput($event.target.value)"
                                   aria-label="Allowance in miles"
                                   placeholder="0">
                            <span class="input-group-suffix">miles</span>
                        </div>
                        <div class="form-error" x-show="errors.estimated_mileage_miles" x-text="errors.estimated_mileage_miles"></div>
                    </div>
                </div>
                <div class="form-hint" style="margin-top:-12px;margin-bottom:24px;">Total km included in the lease. Set 0 with a per-km rate to bill every km from 0. Leave 0 with no rate to disable mileage billing.</div>

                <!-- ── Collapsible conversion factor section ── -->
                <div style="margin-bottom:24px;">
                    <button type="button"
                            class="ff-collapsible-toggle"
                            @click="factor_section_open = !factor_section_open"
                            :aria-expanded="factor_section_open">
                        <span class="ff-collapsible-chevron"
                              :class="{ 'ff-collapsible-chevron--open': factor_section_open }">▶</span>
                        Conversion factor
                    </button>

                    <div class="ff-collapsible-content"
                         x-show="factor_section_open"
                         x-cloak>
                        <p class="form-hint" style="margin:0 0 12px 0;">
                            Conversion factors are independently editable. Default:
                            1&nbsp;km&nbsp;=&nbsp;0.621371&nbsp;mi, 1&nbsp;mile&nbsp;=&nbsp;1.609344&nbsp;km.
                        </p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <div>
                                <label class="form-label" style="font-size:0.8125rem;">1 km =</label>
                                <div class="input-group">
                                    <input type="number"
                                           class="form-control font-mono"
                                           step="0.000001"
                                           min="0.000001"
                                           name="km_to_miles_conversion"
                                           x-model="form.km_to_miles_conversion"
                                           @input.debounce.150ms="onKmToMilesFactorInput($event.target.value)"
                                           placeholder="0.621371">
                                    <span class="input-group-suffix">miles</span>
                                </div>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:0.8125rem;">1 mile =</label>
                                <div class="input-group">
                                    <input type="number"
                                           class="form-control font-mono"
                                           step="0.000001"
                                           min="0.000001"
                                           name="miles_to_km_conversion"
                                           x-model="form.miles_to_km_conversion"
                                           @input.debounce.150ms="onMilesToKmFactorInput($event.target.value)"
                                           placeholder="1.609344">
                                    <span class="input-group-suffix">km</span>
                                </div>
                            </div>
                        </div>
                        <button type="button"
                                class="ff-link-button"
                                @click="resetFactorsToDefaults()"
                                style="margin-top:12px;">
                            Reset to defaults
                        </button>
                    </div>
                </div>

                <!-- Odometer readings -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_start">Starting Mileage</label>
                        <input type="number" id="mileage_at_start" class="form-control font-mono"
                               x-model="form.mileage_at_start" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_end">Ending Mileage</label>
                        <input type="number" id="mileage_at_end" class="form-control font-mono"
                               x-model="form.mileage_at_end" min="0">
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════════
                     S-MILEAGE-1 Model B — Mileage precharge subsection
                     ──────────────────────────────────────────────────────────
                     Same controls as create.php. Becomes read-only once
                     the precharge has been billed on Invoice 1
                     (precharge_invoiced_at IS NOT NULL) — server returns
                     409 PRECHARGE_LOCKED and surfaces the error in the
                     form-error banner.
                     ══════════════════════════════════════════════════════════ -->
                <div style="border-top:1px solid var(--border-color);margin-top:24px;padding-top:24px;">
                    <label style="display:flex;align-items:center;gap:12px;cursor:pointer;"
                           :style="prechargeFrozen ? 'cursor:not-allowed;opacity:0.65;' : ''">
                        <input type="checkbox"
                               role="switch"
                               class="form-check-input"
                               x-model="form.precharge_enabled"
                               :disabled="prechargeFrozen">
                        <span>
                            <span style="font-size:0.9375rem;font-weight:600;color:var(--text-primary);letter-spacing:-0.01em;">Apply mileage precharge</span>
                            <div class="form-hint" style="margin-top:2px;">Customer pays this amount upfront on Invoice 1 and draws down against monthly mileage charges. Refunded at lease close if unused.</div>
                            <div x-show="prechargeFrozen" class="form-hint" style="margin-top:4px;color:var(--color-warning-text);">
                                Locked — Invoice 1 has already billed this precharge.
                            </div>
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
                                   :readonly="prechargeFrozen"
                                   :class="errors.precharge_amount ? 'is-invalid' : ''"
                                   placeholder="0.00">
                        </div>
                        <div class="form-error" x-show="errors.precharge_amount" x-text="errors.precharge_amount"></div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Section 5: Add-ons ───────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Add-ons</div></div>
            <div class="card-body">
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

                <!-- S-LEASE-GPS-COST: GPS tracking add-on (per-day rate) -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.gps_opt_in">
                            GPS Tracking ($/day)
                        </label>
                        <div class="input-group" x-show="form.gps_opt_in">
                            <span class="input-group-prefix">$</span>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.gps_cost" step="0.01" min="0" placeholder="1.00">
                        </div>
                    </div>
                    <div class="form-group"></div>
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
                              x-model="form.notes" rows="2" maxlength="5000"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" class="form-control"
                              x-model="form.internal_notes" rows="2" maxlength="5000"></textarea>
                </div>
            </div>
        </div>

        <!-- ── Form actions ─────────────────────────────────────── -->
        <div class="d-flex gap-3" style="justify-content:flex-end;margin-bottom:2rem;">
            <a href="<?= base_url('leases/show') ?>?id=<?= $leaseId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" :disabled="submitting">
                <span x-show="!submitting">Save Changes</span>
                <span x-show="submitting">Saving…</span>
            </button>
        </div>

        <!-- VALID-2: form-level error banner is injected by FF_Validate.banner() -->
        <div class="form-error-banner" data-form-error></div>

    </form>
</div>

<script>
function FF_EditLease() {
    return {
        form: {
            id:                 <?= $leaseId ?>,
            updated_at:         <?= json_encode($lease['updated_at']) ?>,
            po_number:          <?= json_encode($lease['po_number'] ?? '') ?>,
            end_date:           <?= json_encode($lease['end_date'] ?? '') ?>,
            minimum_end_date:   <?= json_encode($lease['minimum_end_date'] ?? '') ?>,
            rate_notes:         <?= json_encode($lease['rate_notes'] ?? '') ?>,
            mileage_at_start:   <?= json_encode($lease['mileage_at_start'] ?? '') ?>,
            mileage_at_end:     <?= json_encode($lease['mileage_at_end'] ?? '') ?>,
            // S-LEASE-UNITS: dual-unit allowance + conversion (rates are read-only on edit)
            estimated_mileage_km:    <?= json_encode($lease['estimated_mileage_km'] ?? $lease['estimated_mileage'] ?? '') ?>,
            estimated_mileage_miles: <?= json_encode($lease['estimated_mileage_miles'] ?? '') ?>,
            km_to_miles_conversion:  <?= (float)($lease['km_to_miles_conversion'] ?? 0.621371) ?>,
            miles_to_km_conversion:  <?= (float)($lease['miles_to_km_conversion'] ?? 1.609344) ?>,
            insurance_opt_in:   <?= $lease['insurance_opt_in'] ? 'true' : 'false' ?>,
            insurance_cost:     <?= json_encode($lease['insurance_cost'] ?? '0.00') ?>,
            warranty_opt_in:    <?= $lease['warranty_opt_in'] ? 'true' : 'false' ?>,
            warranty_cost:      <?= json_encode($lease['warranty_cost'] ?? '0.00') ?>,
            // S-LEASE-GPS-COST: per-day GPS rate; reads existing values
            gps_opt_in:         <?= $lease['gps_opt_in'] ? 'true' : 'false' ?>,
            gps_cost:           <?= json_encode($lease['gps_cost'] ?? '1.00') ?>,
            notes:              <?= json_encode($lease['notes'] ?? '') ?>,
            internal_notes:     <?= json_encode($lease['internal_notes'] ?? '') ?>,
            // S-MILEAGE-1 Model B: precharge toggle + amount (editable until
            // Invoice 1 bills the precharge — see prechargeFrozen below).
            precharge_enabled:  <?= !empty($lease['precharge_enabled']) ? 'true' : 'false' ?>,
            precharge_amount:   <?= json_encode($lease['precharge_amount'] ?? '') ?>,
        },
        errors:      {},
        submitting:  false,
        // S-MILEAGE-1: read-only flag mirrors update.php's PRECHARGE_LOCKED
        // server check. Set from precharge_invoiced_at — non-null = billed = frozen.
        prechargeFrozen: <?= !empty($lease['precharge_invoiced_at']) ? 'true' : 'false' ?>,

        // S-LEASE-UNITS: primary unit is fixed at creation — read-only here.
        // Pattern A bidirectional behavior matches create.php; no override
        // flags, no auto-reciprocation between factors.
        _mileageUnit:        <?= json_encode($lease['mileage_unit'] ?? 'km') ?>,
        factor_section_open: false,

        init() {},

        // Allowance handlers (3-decimal precision, DECIMAL(12,3)).
        onAllowanceKmInput(value) {
            const km = parseFloat(value);
            if (isNaN(km) || km < 0) { this.form.estimated_mileage_miles = ''; return; }
            const factor = parseFloat(this.form.km_to_miles_conversion) || 0.621371;
            this.form.estimated_mileage_miles = (Math.round(km * factor * 1000) / 1000).toFixed(3);
        },
        onAllowanceMilesInput(value) {
            const miles = parseFloat(value);
            if (isNaN(miles) || miles < 0) { this.form.estimated_mileage_km = ''; return; }
            const factor = parseFloat(this.form.miles_to_km_conversion) || 1.609344;
            this.form.estimated_mileage_km = (Math.round(miles * factor * 1000) / 1000).toFixed(3);
        },

        // Factors are independently editable — D-B.
        onKmToMilesFactorInput(value) {
            const v = parseFloat(value);
            this.form.km_to_miles_conversion = (isNaN(v) || v <= 0) ? 0.621371 : v;
        },
        onMilesToKmFactorInput(value) {
            const v = parseFloat(value);
            this.form.miles_to_km_conversion = (isNaN(v) || v <= 0) ? 1.609344 : v;
        },

        resetFactorsToDefaults() {
            this.form.km_to_miles_conversion = 0.621371;
            this.form.miles_to_km_conversion = 1.609344;
        },

        validate() {
            // VALID-2: use FF_Validate for per-field error display
            const f = document.querySelector('form');
            FF_Validate.clear(f);
            this.errors = {};
            let ok = true;
            const startDate = <?= json_encode($lease['start_date']) ?>;

            if (this.form.end_date && this.form.end_date < startDate) {
                FF_Validate.field(f, 'end_date', 'End date must be after start date.');
                this.errors.end_date = 'End date must be after start date.';
                ok = false;
            }

            const numChecks = [
                ['mileage_at_start',        'Starting mileage cannot be negative.'],
                ['mileage_at_end',          'End mileage cannot be negative.'],
                ['estimated_mileage_km',    'KM allowance cannot be negative.'],
                ['estimated_mileage_miles', 'Mile allowance cannot be negative.'],
                ['insurance_cost',          'Insurance cost cannot be negative.'],
                ['warranty_cost',           'Warranty cost cannot be negative.'],
                ['gps_cost',                'GPS cost cannot be negative.'],
            ];
            numChecks.forEach(([k, msg]) => {
                const v = this.form[k];
                if (v !== '' && v !== null && v !== undefined && Number(v) < 0) {
                    FF_Validate.field(f, k, msg);
                    ok = false;
                }
            });

            if (this.form.mileage_at_end && this.form.mileage_at_start &&
                Number(this.form.mileage_at_end) < Number(this.form.mileage_at_start)) {
                FF_Validate.field(f, 'mileage_at_end',
                    'End mileage must be greater than or equal to start mileage.');
                ok = false;
            }

            if (!ok) FF_Validate.scrollToFirst(f);
            return ok;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting  = true;
            const f = document.querySelector('form');

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/update') ?>', this.form);
                if (r.success) {
                    window.location.href = '<?= base_url('leases/show') ?>?id=<?= $leaseId ?>';
                } else if (r.error?.code === 'STALE_DATA') {
                    FF_Validate.banner(f, (r.error.message || '') + ' Reload this page to get the latest version.');
                    FF_Validate.scrollToFirst(f);
                } else if (r.error?.code === 'VALIDATION_ERROR' && r.error?.fields) {
                    FF_Validate.applyApi(f, r.error);
                } else {
                    FF_Validate.banner(f, r.error?.message || r.message || 'Failed to save changes.');
                    FF_Validate.scrollToFirst(f);
                }
            } catch(e) {
                FF_Validate.banner(f, 'Network error. Please try again.');
                FF_Validate.scrollToFirst(f);
            }
            this.submitting = false;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
