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
 * @session     S007, S-LEASE-DISTANCE-EDIT-ACTIVE
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
    <div class="page-header-actions">
        <?= help_button('leases') ?>
    </div>
</div>

<?php if ($isActive): ?>
<div class="card card-body" style="background:var(--color-warning-light);color:var(--color-warning-text);margin-bottom:1.5rem;">
    <strong>Active Lease — distance fields only.</strong> Only start odometer, allowance, and conversion factors can be edited while the lease is active.
    Dates, notes, add-ons, rates, and financial fields are locked.
    To change other metadata, the lease must be in <strong>pending</strong> status; rate changes use the
    <a href="<?= e(base_url('leases/show?id=' . $leaseId)) ?>#amendments" class="text-link" style="color:inherit;text-decoration:underline;">Amendments tab</a>.
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
                        <label class="form-label"<?= $isActive ? '' : ' for="po_number"' ?>>PO Number</label>
                        <?php if ($isActive): ?>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;"><?= e($lease['po_number'] ?? '—') ?></div>
                        <?php else: ?>
                        <input type="text" id="po_number" class="form-control"
                               x-model="form.po_number" maxlength="100">
                        <?php endif; ?>
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
                        <label class="form-label"<?= $isActive ? '' : ' for="end_date"' ?>>End Date</label>
                        <?php if ($isActive): ?>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;"><?= e($lease['end_date'] ?? '—') ?></div>
                        <?php else: ?>
                        <?php // [UI-AUDIT-1:M18] :min prevents picking a date before lease start. ?>
                        <input type="date" id="end_date" class="form-control"
                               x-model="form.end_date"
                               min="<?= e($lease['start_date']) ?>"
                               :class="errors.end_date ? 'is-invalid' : ''">
                        <div class="form-error" x-show="errors.end_date" x-text="errors.end_date"></div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label"<?= $isActive ? '' : ' for="minimum_end_date"' ?>>Minimum End Date</label>
                        <?php if ($isActive): ?>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;"><?= e($lease['minimum_end_date'] ?? '—') ?></div>
                        <?php else: ?>
                        <?php // [UI-AUDIT-1:M18] Same constraint on minimum_end_date. ?>
                        <input type="date" id="minimum_end_date" class="form-control"
                               x-model="form.minimum_end_date"
                               min="<?= e($lease['start_date']) ?>">
                        <?php endif; ?>
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
                        <label class="form-label"<?= $isActive ? '' : ' for="rate_notes"' ?>>Rate Notes</label>
                        <?php if ($isActive): ?>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;"><?= e($lease['rate_notes'] ?? '—') ?></div>
                        <?php else: ?>
                        <input type="text" id="rate_notes" class="form-control"
                               x-model="form.rate_notes" maxlength="5000">
                        <?php endif; ?>
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

                <!-- Odometer readings — start is editable for active leases; end is set at close -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="mileage_at_start">Starting Mileage</label>
                        <input type="number" id="mileage_at_start" class="form-control font-mono"
                               x-model="form.mileage_at_start" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ending Mileage</label>
                        <?php if ($isActive): ?>
                        <div class="form-control font-mono" style="background:var(--bg-muted);cursor:default;"
                             title="Set at lease close"><?= e($lease['mileage_at_end'] ?? '—') ?></div>
                        <div class="form-hint">Set at lease close.</div>
                        <?php else: ?>
                        <input type="number" id="mileage_at_end" class="form-control font-mono"
                               x-model="form.mileage_at_end" min="0">
                        <?php endif; ?>
                    </div>
                </div>

                <!-- S-MILEAGE-1 Model B — Mileage precharge subsection
                     Active leases: rendered read-only (precharge is locked after
                     Invoice 1 billing; cannot change on an active lease at all). -->
                <div style="border-top:1px solid var(--border-color);margin-top:24px;padding-top:24px;">
                    <?php if ($isActive): ?>
                    <div class="form-hint" style="opacity:0.7;">
                        <strong>Mileage precharge:</strong>
                        <?php if (!empty($lease['precharge_enabled'])): ?>
                            Enabled — $<?= e(number_format((float)($lease['precharge_amount'] ?? 0), 2)) ?>
                            <?php if (!empty($lease['precharge_invoiced_at'])): ?>(billed on Invoice 1)<?php endif; ?>
                        <?php else: ?>
                            Not applied
                        <?php endif; ?>
                        <span style="margin-left:8px;">— locked while lease is active.</span>
                    </div>
                    <?php else: ?>
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
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ── Section 5: Add-ons ───────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Add-ons</div>
                <?php if ($isActive): ?>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">Locked while lease is active.</div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($isActive): ?>
                <div class="form-row-3">
                    <div class="form-group">
                        <label class="form-label">Insurance</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?php if (!empty($lease['insurance_opt_in'])): ?>
                                Included — $<?= e(number_format((float)($lease['insurance_cost'] ?? 0), 2)) ?>/period
                            <?php else: ?>
                                Not included
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Warranty</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?php if (!empty($lease['warranty_opt_in'])): ?>
                                Included — $<?= e(number_format((float)($lease['warranty_cost'] ?? 0), 2)) ?>/period
                            <?php else: ?>
                                Not included
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">GPS Tracking</label>
                        <div class="form-control" style="background:var(--bg-muted);cursor:default;">
                            <?php if (!empty($lease['gps_opt_in'])): ?>
                                Included — $<?= e(number_format((float)($lease['gps_cost'] ?? 0), 2)) ?>/day
                            <?php else: ?>
                                Not included
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
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

                <!-- S-GPS-RATE-CARD: GPS opt-in toggle; rate is frozen from rate card at creation -->
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" style="display:flex;align-items:center;gap:0.5rem;">
                            <input type="checkbox" class="form-check-input" x-model="form.gps_opt_in">
                            GPS Tracking
                        </label>
                        <div x-show="form.gps_opt_in" style="margin-top:4px;">
                            <span class="text-secondary" style="font-size:0.8125rem;">
                                $<span class="font-mono" x-text="parseFloat(form.gps_cost || 0).toFixed(2)"></span>/day
                                <span style="color:var(--text-muted);">(rate card rate)</span>
                            </span>
                        </div>
                    </div>
                    <div class="form-group" x-show="form.hourly_rate && parseFloat(form.hourly_rate) > 0">
                        <label class="form-label">Hourly Rate</label>
                        <div style="margin-top:4px;">
                            <span class="text-secondary" style="font-size:0.8125rem;">
                                $<span class="font-mono" x-text="parseFloat(form.hourly_rate || 0).toFixed(4)"></span>/hr
                                <span style="color:var(--text-muted);">(rate card rate)</span>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Section 6: Notes ─────────────────────────────────── -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div class="card-header">
                <div class="card-title">Notes</div>
                <?php if ($isActive): ?>
                <div style="font-size:0.8125rem;color:var(--text-secondary);">Locked while lease is active.</div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label"<?= $isActive ? '' : ' for="notes"' ?>>Notes</label>
                    <?php if ($isActive): ?>
                    <div class="form-control" style="background:var(--bg-muted);cursor:default;min-height:3rem;white-space:pre-wrap;"><?= e($lease['notes'] ?? '') ?: '<span style="color:var(--text-muted);">—</span>' ?></div>
                    <?php else: ?>
                    <textarea id="notes" class="form-control"
                              x-model="form.notes" rows="2" maxlength="5000"></textarea>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label"<?= $isActive ? '' : ' for="internal_notes"' ?>>Internal Notes</label>
                    <?php if ($isActive): ?>
                    <div class="form-control" style="background:var(--bg-muted);cursor:default;min-height:3rem;white-space:pre-wrap;"><?= e($lease['internal_notes'] ?? '') ?: '<span style="color:var(--text-muted);">—</span>' ?></div>
                    <?php else: ?>
                    <textarea id="internal_notes" class="form-control"
                              x-model="form.internal_notes" rows="2" maxlength="5000"></textarea>
                    <?php endif; ?>
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
// S-LEASE-DISTANCE-EDIT-ACTIVE: active leases only send distance fields.
const _leaseIsActive = <?= $isActive ? 'true' : 'false' ?>;

function FF_EditLease() {
    return {
        form: {
            id:                      <?= $leaseId ?>,
            updated_at:              <?= json_encode($lease['updated_at']) ?>,
            // Distance fields — editable regardless of lease status
            mileage_at_start:        <?= json_encode($lease['mileage_at_start'] ?? '') ?>,
            estimated_mileage_km:    <?= json_encode($lease['estimated_mileage_km'] ?? $lease['estimated_mileage'] ?? '') ?>,
            estimated_mileage_miles: <?= json_encode($lease['estimated_mileage_miles'] ?? '') ?>,
            km_to_miles_conversion:  <?= (float)($lease['km_to_miles_conversion'] ?? 0.621371) ?>,
            miles_to_km_conversion:  <?= (float)($lease['miles_to_km_conversion'] ?? 1.609344) ?>,
            <?php if (!$isActive): ?>
            // Pending-only fields — locked on active leases (API returns 422 ACTIVE_LEASE_DISTANCE_ONLY)
            po_number:               <?= json_encode($lease['po_number'] ?? '') ?>,
            end_date:                <?= json_encode($lease['end_date'] ?? '') ?>,
            minimum_end_date:        <?= json_encode($lease['minimum_end_date'] ?? '') ?>,
            rate_notes:              <?= json_encode($lease['rate_notes'] ?? '') ?>,
            mileage_at_end:          <?= json_encode($lease['mileage_at_end'] ?? '') ?>,
            insurance_opt_in:        <?= $lease['insurance_opt_in'] ? 'true' : 'false' ?>,
            insurance_cost:          <?= json_encode($lease['insurance_cost'] ?? '0.00') ?>,
            warranty_opt_in:         <?= $lease['warranty_opt_in'] ? 'true' : 'false' ?>,
            warranty_cost:           <?= json_encode($lease['warranty_cost'] ?? '0.00') ?>,
            gps_opt_in:              <?= $lease['gps_opt_in'] ? 'true' : 'false' ?>,
            gps_cost:                <?= json_encode($lease['gps_cost'] ?? '1.00') ?>,
            hourly_rate:             <?= json_encode($lease['hourly_rate'] ?? null) ?>,
            notes:                   <?= json_encode($lease['notes'] ?? '') ?>,
            internal_notes:          <?= json_encode($lease['internal_notes'] ?? '') ?>,
            precharge_enabled:       <?= !empty($lease['precharge_enabled']) ? 'true' : 'false' ?>,
            precharge_amount:        <?= json_encode($lease['precharge_amount'] ?? '') ?>,
            <?php endif; ?>
        },
        errors:      {},
        submitting:  false,
        <?php if (!$isActive): ?>
        // S-MILEAGE-1: frozen once Invoice 1 has billed the precharge.
        prechargeFrozen: <?= !empty($lease['precharge_invoiced_at']) ? 'true' : 'false' ?>,
        <?php endif; ?>

        // S-LEASE-UNITS: primary unit fixed at creation — read-only on edit.
        _mileageUnit:        <?= json_encode($lease['mileage_unit'] ?? 'km') ?>,
        factor_section_open: false,

        init() {
            // S-FORM-DRAFT-AUTOSAVE: mirror in-progress edits to localStorage so
            // a Back/refresh/close never loses them; offer a restore banner on
            // return. Keyed by lease id so each lease has its own draft.
            // EXCLUSIONS: `id` + `updated_at` — updated_at is the optimistic-lock
            // token (D19); it must always reflect the freshly server-rendered
            // value, NEVER a stale token re-injected from a draft. The restore
            // only refills editable business fields, leaving updated_at intact.
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId:   'lease-edit',
                    entityId: this.form.id,
                    el:       this.$root,
                    model:    this.form,
                    exclude:  ['id', 'updated_at'],
                });
            }
        },

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
            const f = document.querySelector('form');
            FF_Validate.clear(f);
            this.errors = {};
            let ok = true;

            // Distance fields are validated for all statuses
            const distanceChecks = [
                ['mileage_at_start',        'Starting mileage cannot be negative.'],
                ['estimated_mileage_km',    'KM allowance cannot be negative.'],
                ['estimated_mileage_miles', 'Mile allowance cannot be negative.'],
            ];
            distanceChecks.forEach(([k, msg]) => {
                const v = this.form[k];
                if (v !== '' && v !== null && v !== undefined && Number(v) < 0) {
                    FF_Validate.field(f, k, msg);
                    ok = false;
                }
            });

            <?php if (!$isActive): ?>
            // Pending-only validation
            const startDate = <?= json_encode($lease['start_date']) ?>;
            if (this.form.end_date && this.form.end_date < startDate) {
                FF_Validate.field(f, 'end_date', 'End date must be after start date.');
                this.errors.end_date = 'End date must be after start date.';
                ok = false;
            }

            const pendingChecks = [
                ['mileage_at_end',   'End mileage cannot be negative.'],
                ['insurance_cost',   'Insurance cost cannot be negative.'],
                ['warranty_cost',    'Warranty cost cannot be negative.'],
            ];
            pendingChecks.forEach(([k, msg]) => {
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
            <?php endif; ?>

            if (!ok) FF_Validate.scrollToFirst(f);
            return ok;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting = true;
            const f = document.querySelector('form');

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/leases/update') ?>', this.form);
                if (r.success) {
                    // S-FORM-DRAFT-AUTOSAVE: server confirmed — wipe the draft
                    // (stop=true) BEFORE the hard redirect navigates away.
                    if (this._draft) this._draft.clear(true);
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
