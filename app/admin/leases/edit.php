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

        <!-- ── Section 4: Mileage (S-LEASE-UNITS) ─────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header"><div class="card-title">Mileage</div></div>
            <div class="card-body">

                <!-- Primary unit badge (static — set at creation) -->
                <div style="margin-bottom:1.25rem;display:flex;align-items:center;gap:0.75rem;font-size:0.875rem;">
                    <span style="color:var(--text-secondary);">Primary unit:</span>
                    <span style="font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;color:var(--color-primary);letter-spacing:0.05em;">
                        <?= strtoupper(e($lease['mileage_unit'] ?? 'km')) ?>
                    </span>
                    <span style="color:var(--text-muted);font-size:0.8125rem;">(to change, create an amendment)</span>
                </div>

                <!-- Mileage Rates: read-only -->
                <div style="margin-bottom:1.25rem;">
                    <div style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.625rem;">Mileage Rates <span style="font-weight:400;color:var(--text-muted);">— read-only</span></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:0.8125rem;display:flex;align-items:center;gap:5px;margin-bottom:4px;">
                                <span style="font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;<?= ($lease['mileage_unit'] ?? 'km') === 'km' ? 'color:var(--color-primary);' : '' ?>">Per KM</span>
                                <?php if (($lease['mileage_unit'] ?? 'km') === 'km'): ?>
                                <span style="font-size:0.6875rem;font-weight:700;color:var(--color-primary);">PRIMARY</span>
                                <?php endif; ?>
                            </label>
                            <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                                $<?= e(number_format((float)($lease['mileage_rate_km'] ?? $lease['mileage_rate'] ?? 0), 4)) ?>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" style="font-size:0.8125rem;display:flex;align-items:center;gap:5px;margin-bottom:4px;">
                                <span style="font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;<?= ($lease['mileage_unit'] ?? 'km') === 'miles' ? 'color:var(--color-primary);' : '' ?>">Per Mile</span>
                                <?php if (($lease['mileage_unit'] ?? 'km') === 'miles'): ?>
                                <span style="font-size:0.6875rem;font-weight:700;color:var(--color-primary);">PRIMARY</span>
                                <?php endif; ?>
                            </label>
                            <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;">
                                $<?= e(number_format((float)($lease['mileage_rate_miles'] ?? 0), 4)) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conversion factor caption + editable panel -->
                <div style="margin-bottom:1.25rem;">
                    <div style="font-family:var(--font-mono,'DM Mono',monospace);font-size:0.75rem;color:var(--text-muted);margin-bottom:0.5rem;">
                        <span x-text="'1 km = ' + Number(form.km_to_miles_conversion || 0.621371).toFixed(6) + ' mi  ·  1 mi = ' + Number(form.miles_to_km_conversion || 1.609344).toFixed(6) + ' km'"></span>
                        &nbsp;
                        <button type="button"
                                @click="showConversionEditor = !showConversionEditor"
                                style="color:var(--color-primary);background:none;border:none;cursor:pointer;font-size:0.75rem;text-decoration:underline;padding:0;font-family:inherit;">
                            <span x-text="showConversionEditor ? 'hide' : 'edit conversion'"></span>
                        </button>
                    </div>
                    <div x-show="showConversionEditor" x-cloak
                         style="padding:0.875rem;background:var(--bg-muted);border-radius:6px;border:1px solid var(--border-color);max-width:440px;">
                        <div style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.625rem;">Edit Conversion Factors</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.625rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" style="font-size:0.75rem;">1 km =</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" class="form-control font-mono"
                                           x-model.number="form.km_to_miles_conversion"
                                           @input.debounce.150ms="onKmToMilesInput()"
                                           step="0.000001" min="0.000001" placeholder="0.621371">
                                    <span style="font-size:0.8125rem;color:var(--text-secondary);white-space:nowrap;">mi</span>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" style="font-size:0.75rem;">1 mile =</label>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <input type="number" class="form-control font-mono"
                                           x-model.number="form.miles_to_km_conversion"
                                           @input.debounce.150ms="onMilesToKmInput()"
                                           step="0.000001" min="0.000001" placeholder="1.609344"
                                           :readonly="!factorsUnlinked">
                                    <span style="font-size:0.8125rem;color:var(--text-secondary);white-space:nowrap;">km</span>
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;color:var(--text-secondary);cursor:pointer;user-select:none;">
                                <input type="checkbox" class="form-check-input" x-model="factorsUnlinked">
                                Advanced: unlink factors
                            </label>
                            <span x-show="factorsUnlinked && Math.abs((1 / (form.km_to_miles_conversion || 0.621371)) - (form.miles_to_km_conversion || 1.609344)) > 0.001"
                                  style="font-size:0.75rem;color:var(--color-warning-text,#b45309);background:var(--color-warning-light,#fef3c7);padding:2px 8px;border-radius:4px;">
                                Non-reciprocal — double-check before saving
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Included Mileage Allowance: editable km + miles -->
                <div style="margin-bottom:1.25rem;">
                    <div style="font-size:0.8125rem;font-weight:600;color:var(--text-primary);margin-bottom:0.625rem;">Included Mileage Allowance</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div class="form-group" style="margin-bottom:0;<?= ($lease['mileage_unit'] ?? 'km') !== 'km' ? 'opacity:0.8;' : '' ?>">
                            <label class="form-label" style="font-size:0.8125rem;display:flex;align-items:center;gap:5px;margin-bottom:4px;">
                                <span style="font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;<?= ($lease['mileage_unit'] ?? 'km') === 'km' ? 'color:var(--color-primary);' : '' ?>">KM</span>
                                <?php if (($lease['mileage_unit'] ?? 'km') === 'km'): ?>
                                <span style="font-size:0.6875rem;font-weight:700;color:var(--color-primary);">PRIMARY</span>
                                <?php endif; ?>
                            </label>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.estimated_mileage_km"
                                   @input.debounce.150ms="onAllowanceKmInput()"
                                   min="0" step="0.001" placeholder="0">
                            <div class="form-error" x-show="errors.estimated_mileage_km" x-text="errors.estimated_mileage_km"></div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;<?= ($lease['mileage_unit'] ?? 'km') !== 'miles' ? 'opacity:0.8;' : '' ?>">
                            <label class="form-label" style="font-size:0.8125rem;display:flex;align-items:center;gap:5px;margin-bottom:4px;">
                                <span style="font-family:var(--font-mono,'DM Mono',monospace);font-weight:700;<?= ($lease['mileage_unit'] ?? 'km') === 'miles' ? 'color:var(--color-primary);' : '' ?>">Miles</span>
                                <?php if (($lease['mileage_unit'] ?? 'km') === 'miles'): ?>
                                <span style="font-size:0.6875rem;font-weight:700;color:var(--color-primary);">PRIMARY</span>
                                <?php endif; ?>
                            </label>
                            <input type="number" class="form-control font-mono"
                                   x-model="form.estimated_mileage_miles"
                                   @input.debounce.150ms="onAllowanceMilesInput()"
                                   min="0" step="0.001" placeholder="0">
                            <div class="form-error" x-show="errors.estimated_mileage_miles" x-text="errors.estimated_mileage_miles"></div>
                        </div>
                    </div>
                    <div class="form-hint" style="margin-top:4px;">Used for pre-charge calculation. Set both to 0 to disable.</div>
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
            notes:              <?= json_encode($lease['notes'] ?? '') ?>,
            internal_notes:     <?= json_encode($lease['internal_notes'] ?? '') ?>,
        },
        errors:      {},
        submitting:  false,

        // S-LEASE-UNITS: mileage_unit is fixed at creation — embed for JS logic
        _mileageUnit: <?= json_encode($lease['mileage_unit'] ?? 'km') ?>,
        allowanceKmOverridden:    false,
        allowanceMilesOverridden: false,
        factorsUnlinked:          false,
        showConversionEditor:     false,

        init() {},

        // S-LEASE-UNITS: allowance input handlers (mileage_unit is fixed server-side)
        onAllowanceKmInput() {
            if (this._mileageUnit === 'km') {
                this._recomputeFromKm();
            } else {
                this.allowanceKmOverridden = true;
            }
        },
        onAllowanceMilesInput() {
            if (this._mileageUnit === 'miles') {
                this._recomputeFromMiles();
            } else {
                this.allowanceMilesOverridden = true;
            }
        },

        // S-LEASE-UNITS: D-B auto-reciprocate conversion factors
        onKmToMilesInput() {
            const v = parseFloat(this.form.km_to_miles_conversion);
            if (!this.factorsUnlinked && v > 0) {
                this.form.miles_to_km_conversion = parseFloat((1 / v).toFixed(6));
            }
            if (this._mileageUnit === 'km') this._recomputeFromKm();
            else this._recomputeFromMiles();
        },
        onMilesToKmInput() {
            // Only active when factorsUnlinked=true
            if (this._mileageUnit === 'miles') this._recomputeFromMiles();
            else this._recomputeFromKm();
        },

        _recomputeFromKm() {
            if (this.allowanceMilesOverridden) return;
            const factor = parseFloat(this.form.km_to_miles_conversion) || 0.621371;
            const raw = this.form.estimated_mileage_km;
            if (raw === '' || raw === null) { this.form.estimated_mileage_miles = ''; return; }
            const km = parseFloat(raw);
            if (!isNaN(km) && km >= 0) this.form.estimated_mileage_miles = (km * factor).toFixed(3);
        },
        _recomputeFromMiles() {
            if (this.allowanceKmOverridden) return;
            const factor = parseFloat(this.form.miles_to_km_conversion) || 1.609344;
            const raw = this.form.estimated_mileage_miles;
            if (raw === '' || raw === null) { this.form.estimated_mileage_km = ''; return; }
            const miles = parseFloat(raw);
            if (!isNaN(miles) && miles >= 0) this.form.estimated_mileage_km = (miles * factor).toFixed(3);
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
