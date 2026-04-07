<?php
declare(strict_types=1);

/**
 * app/admin/invoices/create.php
 *
 * Manual invoice creation form. Select an active/completed lease,
 * specify billing period, preview charges, and submit.
 * Submits to api/v1/invoices/create.php via Alpine.js.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D14 (inclusive days), D30 (asset_url), D32 (CSS classes)
 * @session  S008
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'create');

// Load active + completed leases for the dropdown
// SAMSARA-3: also pull odometer_start_km, equipment_unit_id, samsara_vehicle_id,
// and the last invoice's period-end odometer so the create form can
// auto-populate the period-start odometer and show the Fetch button.
$leases = db_select(
    "SELECT l.id, l.contract_number, l.customer_id, l.company_name_snapshot,
            l.unit_number_snapshot, l.template_name_snapshot, l.status,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
            l.start_date, l.billing_cycle, l.discount_type, l.discount_value,
            l.gst_exempt, l.pst_exempt, l.tax_exempt,
            l.odometer_start_km, l.equipment_unit_id,
            u.samsara_vehicle_id,
            (SELECT i.odometer_at_period_end_km
               FROM invoices i
              WHERE i.lease_id = l.id AND i.deleted_at IS NULL
                AND i.odometer_at_period_end_km IS NOT NULL
              ORDER BY i.billing_period_end DESC, i.id DESC LIMIT 1) AS latest_invoice_end_odo
     FROM leases l
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status IN ('active','completed') AND l.deleted_at IS NULL
     ORDER BY l.contract_number ASC",
    []
);

$pageTitle = 'Create Invoice';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('invoices') ?>">Invoices</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Create Invoice</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Create Invoice</h1>
    </div>
</div>

<!-- ============================================================
     CREATE INVOICE FORM
     ============================================================ -->
<!-- FIX #39: wrap in form tag so Enter-to-submit works -->
<form x-data="FF_InvoiceCreate()" @submit.prevent="submit()" class="card" style="padding:24px; max-width:800px;">

    <!-- Lease Selection -->
    <div style="margin-bottom:20px;">
        <label class="form-label">Lease <span class="text-danger">*</span></label>
        <select class="form-control" x-model="form.lease_id" @change="onLeaseChange()">
            <option value="">Select a lease…</option>
            <?php foreach ($leases as $lease): ?>
            <option value="<?= (int)$lease['id'] ?>"
                    data-daily="<?= e($lease['daily_rate']) ?>"
                    data-weekly="<?= e($lease['weekly_rate']) ?>"
                    data-monthly="<?= e($lease['monthly_rate']) ?>"
                    data-currency="<?= e($lease['currency']) ?>"
                    data-start="<?= e($lease['start_date']) ?>"
                    data-equipment-unit-id="<?= (int)$lease['equipment_unit_id'] ?>"
                    data-samsara-linked="<?= !empty($lease['samsara_vehicle_id']) ? '1' : '0' ?>"
                    data-lease-start-odo="<?= e($lease['odometer_start_km'] ?? '') ?>"
                    data-lease-start-date="<?= e($lease['start_date']) ?>"
                    data-prev-end-odo="<?= e($lease['latest_invoice_end_odo'] ?? '') ?>">
                <?= e($lease['contract_number']) ?> — <?= e($lease['company_name_snapshot']) ?>
                (Unit <?= e($lease['unit_number_snapshot']) ?>, <?= e($lease['status']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Lease info card (shown after selection) -->
    <template x-if="selectedLease">
        <div style="background:var(--bg-muted); border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px;">
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                <div>
                    <span class="text-secondary">Daily:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.daily"></span>
                </div>
                <div>
                    <span class="text-secondary">Weekly:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.weekly"></span>
                </div>
                <div>
                    <span class="text-secondary">Monthly:</span>
                    <span class="font-mono" x-text="'$' + selectedLease.monthly"></span>
                </div>
            </div>
        </div>
    </template>

    <!-- Period Dates -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div>
            <label class="form-label">Period Start <span class="text-danger">*</span></label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" class="form-control" x-model="form.period_start" @change="updateDays()"
                       x-ref="invPeriodStart" style="flex:1;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.invPeriodStart.showPicker ? $refs.invPeriodStart.showPicker() : $refs.invPeriodStart.click()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                </button>
            </div>
        </div>
        <div>
            <label class="form-label">Period End <span class="text-danger">*</span></label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" class="form-control" x-model="form.period_end" @change="updateDays()"
                       :min="form.period_start || ''"
                       x-ref="invPeriodEnd" style="flex:1;">
                <button type="button" class="btn btn-ghost btn-sm" style="padding:0 10px;height:38px;flex-shrink:0;" title="Open calendar" @click="$refs.invPeriodEnd.showPicker ? $refs.invPeriodEnd.showPicker() : $refs.invPeriodEnd.click()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Days + Billing Type -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
        <div>
            <label class="form-label">Billing Days</label>
            <input type="text" class="form-control" x-model="days" readonly
                   style="background:var(--bg-muted);">
        </div>
        <div>
            <label class="form-label">Billing Type <span class="text-danger">*</span></label>
            <select class="form-control" x-model="form.billing_type">
                <option value="partial_start">Partial Start</option>
                <option value="full_month">Full Month</option>
                <option value="partial_end">Partial End</option>
                <option value="single_period">Single Period</option>
            </select>
        </div>
    </div>

    <!-- Invoice Type -->
    <div style="margin-bottom:20px;">
        <label class="form-label">Invoice Type</label>
        <select class="form-control" x-model="form.invoice_type">
            <option value="regular">Regular</option>
            <option value="final">Final</option>
            <option value="mileage_only">Mileage Only</option>
            <option value="adjustment">Adjustment</option>
        </select>
    </div>

    <!-- ── SAMSARA-3: Odometer & Distance section ─────────────────
         Period start auto-populates from the previous invoice's
         period-end odometer (or the lease's starting odometer if
         this is the first invoice). Period end is fetched live from
         Samsara or entered manually. Period distance + cumulative
         distance are computed live from the two values.
         ──────────────────────────────────────────────────────── -->
    <template x-if="selectedLease">
        <div style="margin-bottom:20px;padding:16px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-surface-2);">
            <div style="font-weight:600;margin-bottom:0.75rem;font-size:0.95rem;">Odometer &amp; Distance</div>

            <!-- Period Start Odometer -->
            <div style="margin-bottom:1rem;">
                <label class="form-label">Odometer at Period Start (km)</label>
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="number"
                           class="form-control font-mono"
                           x-model="form.odometer_at_period_start_km"
                           @input="onOdoStartEdited()"
                           step="0.01"
                           min="0"
                           placeholder="Auto-filled from last invoice"
                           style="flex:1 1 200px;min-width:0;">
                    <span x-show="odoStartSource === 'gps'" class="badge badge-info" title="Fetched live from Samsara">GPS</span>
                    <span x-show="odoStartSource === 'manual' && form.odometer_at_period_start_km !== '' && form.odometer_at_period_start_km !== null"
                          class="badge badge-neutral" title="Manually entered">Manual</span>
                    <button type="button" class="btn btn-secondary btn-sm"
                            x-show="odoCanFetch"
                            @click="fetchOdometer('start')"
                            :disabled="odoFetching">
                        <span x-show="!(odoFetching && odoFetchTarget === 'start')">Fetch from Samsara</span>
                        <span x-show="odoFetching && odoFetchTarget === 'start'">Fetching…</span>
                    </button>
                </div>
                <div class="form-hint" style="margin-top:0.25rem;" x-show="odoStartAutoSource"
                     x-text="odoStartAutoSource"></div>
            </div>

            <!-- Period End Odometer -->
            <div style="margin-bottom:1rem;">
                <label class="form-label">Odometer at Period End — current (km)</label>
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="number"
                           class="form-control font-mono"
                           x-model="form.odometer_at_period_end_km"
                           @input="onOdoEndEdited()"
                           step="0.01"
                           min="0"
                           placeholder="Live reading"
                           style="flex:1 1 200px;min-width:0;">
                    <span x-show="odoEndSource === 'gps'" class="badge badge-info" title="Fetched live from Samsara">GPS</span>
                    <span x-show="odoEndSource === 'manual' && form.odometer_at_period_end_km !== '' && form.odometer_at_period_end_km !== null"
                          class="badge badge-neutral" title="Manually entered">Manual</span>
                    <button type="button" class="btn btn-secondary btn-sm"
                            x-show="odoCanFetch"
                            @click="fetchOdometer('end')"
                            :disabled="odoFetching">
                        <span x-show="!(odoFetching && odoFetchTarget === 'end')">Fetch from Samsara</span>
                        <span x-show="odoFetching && odoFetchTarget === 'end'">Fetching…</span>
                    </button>
                </div>
            </div>

            <!-- Distance results (live-calculated) -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:0.5rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
                <div>
                    <div class="text-xs text-secondary">Period Distance</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                         x-text="fmtKm(periodDistance)"></div>
                    <div x-show="periodDistanceWarning" class="text-xs" style="color:var(--color-danger);margin-top:2px;"
                         x-text="periodDistanceWarning"></div>
                </div>
                <div>
                    <div class="text-xs text-secondary">Cumulative (since lease start)</div>
                    <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                         x-text="fmtKm(cumulativeDistance)"></div>
                    <div x-show="cumulativeContext" class="text-xs text-secondary" style="margin-top:2px;"
                         x-text="cumulativeContext"></div>
                </div>
            </div>

            <!-- Fetch banner -->
            <div x-show="odoBanner" :class="odoBanner && odoBanner.type === 'success' ? 'alert alert-success' : 'alert alert-warning'"
                 style="margin-top:0.75rem;padding:0.5rem 0.75rem;font-size:0.875rem;"
                 x-text="odoBanner && odoBanner.message"></div>

            <!-- Hint when not Samsara-linked -->
            <div x-show="selectedLease && !odoCanFetch" class="form-hint" style="margin-top:0.5rem;">
                This lease's unit is not linked to Samsara. Enter odometer values manually.
            </div>
        </div>
    </template>

    <!-- PO Number -->
    <div style="margin-bottom:20px;">
        <label class="form-label">PO Number</label>
        <input type="text" class="form-control" x-model="form.po_number"
               placeholder="Optional" maxlength="100">
    </div>

    <!-- Notes -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
        <div>
            <label class="form-label">Notes (customer-facing)</label>
            <textarea class="form-control" x-model="form.notes" rows="3"
                      placeholder="Appears on invoice" maxlength="2000"></textarea>
        </div>
        <div>
            <label class="form-label">Internal Notes</label>
            <textarea class="form-control" x-model="form.internal_notes" rows="3"
                      placeholder="Internal only" maxlength="2000"></textarea>
        </div>
    </div>

    <!-- Error display -->
    <!-- VALID-2: form-level error banner injected by FF_Validate.banner() -->
    <div class="form-error-banner" data-form-error></div>

    <!-- Submit -->
    <div style="display:flex; gap:12px; align-items:center;">
        <button type="submit" class="btn btn-primary" :disabled="submitting || !form.lease_id || !form.period_start || !form.period_end">
            <span x-show="!submitting">Create Invoice</span>
            <span x-show="submitting">Creating…</span>
        </button>
        <a href="<?= base_url('invoices') ?>" class="btn btn-secondary">Cancel</a>
        <template x-if="result">
            <span class="text-sm" style="color:var(--color-success);" x-text="'✓ Created ' + result.invoice_number"></span>
        </template>
    </div>
</form>

<script>
function FF_InvoiceCreate() {
    return {
        form: {
            lease_id:       '',
            period_start:   '',
            period_end:     '',
            billing_type:   'partial_start',
            invoice_type:   'regular',
            po_number:      '',
            notes:          '',
            internal_notes: '',
            // SAMSARA-3 odometer fields
            odometer_at_period_start_km: '',
            odometer_at_period_end_km:   '',
            odometer_source:             null,   // 'gps' | 'manual' | null
            odometer_fetched_at:         null,   // ISO datetime when GPS fetched end value
        },
        selectedLease:      null,
        days:               0,
        submitting:         false,
        showSuccessOverlay: false,
        error:              null,
        result:             null,

        // SAMSARA-3 odometer UI state
        odoCanFetch:        false,
        odoFetching:        false,
        odoFetchTarget:     null,     // 'start' | 'end' while a fetch is in flight
        odoStartSource:     null,     // 'gps' | 'manual'
        odoEndSource:       null,     // 'gps' | 'manual'
        odoStartAutoSource: '',       // explanatory hint for the auto-populated start value
        odoBanner:          null,     // { type: 'success'|'warning', message: string }
        _leaseStartOdo:     null,     // raw lease.odometer_start_km as float, for cumulative calc
        _leaseStartDate:    '',

        onLeaseChange() {
            const sel = this.$el.closest('[x-data]').querySelector('select');
            const opt = sel.options[sel.selectedIndex];
            if (!this.form.lease_id) {
                this.selectedLease = null;
                // Reset odometer state when lease is cleared
                this.odoCanFetch = false;
                this.form.odometer_at_period_start_km = '';
                this.form.odometer_at_period_end_km   = '';
                this.odoStartSource = null;
                this.odoEndSource   = null;
                this.odoStartAutoSource = '';
                this.odoBanner = null;
                this._leaseStartOdo  = null;
                this._leaseStartDate = '';
                return;
            }
            this.selectedLease = {
                daily:    opt.dataset.daily   || '0.00',
                weekly:   opt.dataset.weekly  || '0.00',
                monthly:  opt.dataset.monthly || '0.00',
                currency: opt.dataset.currency || 'CAD',
                start:    opt.dataset.start   || '',
                equipmentUnitId: parseInt(opt.dataset.equipmentUnitId) || null,
            };
            // Default period_start to lease start if empty
            if (!this.form.period_start && this.selectedLease.start) {
                this.form.period_start = this.selectedLease.start;
            }
            this.updateDays();

            // SAMSARA-3: wire up odometer state from the lease option attrs
            this.odoCanFetch   = opt.dataset.samsaraLinked === '1';
            this._leaseStartOdo  = opt.dataset.leaseStartOdo ? parseFloat(opt.dataset.leaseStartOdo) : null;
            this._leaseStartDate = opt.dataset.leaseStartDate || '';

            // Reset end side — user always fetches or enters fresh
            this.form.odometer_at_period_end_km = '';
            this.form.odometer_fetched_at       = null;
            this.odoEndSource                   = null;
            this.odoBanner                      = null;

            // Auto-populate start side:
            //   1. Previous invoice's odometer_at_period_end_km
            //   2. Else lease.odometer_start_km
            //   3. Else leave empty
            const prevEnd = opt.dataset.prevEndOdo ? parseFloat(opt.dataset.prevEndOdo) : null;
            if (prevEnd !== null && !isNaN(prevEnd)) {
                this.form.odometer_at_period_start_km = prevEnd.toFixed(2);
                this.odoStartSource                    = 'manual';
                this.odoStartAutoSource                = 'Auto-filled from previous invoice end odometer.';
            } else if (this._leaseStartOdo !== null && !isNaN(this._leaseStartOdo)) {
                this.form.odometer_at_period_start_km = this._leaseStartOdo.toFixed(2);
                this.odoStartSource                    = 'manual';
                this.odoStartAutoSource                = 'Auto-filled from lease starting odometer.';
            } else {
                this.form.odometer_at_period_start_km = '';
                this.odoStartSource                    = null;
                this.odoStartAutoSource                = 'No previous odometer on file. Enter manually or fetch from Samsara.';
            }
        },

        // Live-calculated period distance (end - start)
        get periodDistance() {
            const s = parseFloat(this.form.odometer_at_period_start_km);
            const e = parseFloat(this.form.odometer_at_period_end_km);
            if (isNaN(s) || isNaN(e)) return null;
            return e - s;
        },
        get periodDistanceWarning() {
            const d = this.periodDistance;
            if (d !== null && d < 0) {
                return '⚠ End odometer cannot be less than start odometer';
            }
            return '';
        },
        // Live-calculated cumulative distance since lease start
        get cumulativeDistance() {
            const e = parseFloat(this.form.odometer_at_period_end_km);
            if (isNaN(e)) return null;
            if (this._leaseStartOdo === null || isNaN(this._leaseStartOdo)) return null;
            return e - this._leaseStartOdo;
        },
        get cumulativeContext() {
            if (this._leaseStartOdo === null || isNaN(this._leaseStartOdo)) {
                return '— (no starting odometer recorded for this lease)';
            }
            if (this._leaseStartDate) {
                return 'since lease start on ' + this._leaseStartDate;
            }
            return '';
        },
        fmtKm(v) {
            if (v === null || v === undefined || isNaN(v)) return '— km';
            const fmt = Number(v).toLocaleString('en-CA', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
            return fmt + ' km';
        },

        async fetchOdometer(target) {
            if (!this.selectedLease || !this.selectedLease.equipmentUnitId) {
                this.odoBanner = { type: 'warning', message: 'Please select a lease first.' };
                return;
            }
            this.odoFetching    = true;
            this.odoFetchTarget = target;
            this.odoBanner      = null;
            try {
                const r = await FF_Api.get(
                    `<?= base_url('api/v1/samsara/current_odometer') ?>?equipment_unit_id=${this.selectedLease.equipmentUnitId}`
                );
                const d = r.data || {};
                if (d.linked === false) {
                    this.odoCanFetch = false;
                    this.odoBanner   = { type: 'warning', message: d.message || 'Unit not linked to Samsara.' };
                    return;
                }
                if (d.odometer_km === null || d.odometer_km === undefined) {
                    this.odoBanner = { type: 'warning', message: d.message || 'Could not reach Samsara. Enter odometer manually.' };
                    return;
                }
                const km = Number(d.odometer_km).toFixed(2);
                if (target === 'start') {
                    this.form.odometer_at_period_start_km = km;
                    this.odoStartSource                    = 'gps';
                    this.odoStartAutoSource                = '';
                } else {
                    this.form.odometer_at_period_end_km = km;
                    this.odoEndSource                   = 'gps';
                    this.form.odometer_fetched_at       = d.fetched_at;
                    // When the end odometer comes from GPS, mark overall source as gps
                    this.form.odometer_source           = 'gps';
                }
                const kmDisplay = Number(d.odometer_km).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                this.odoBanner = { type: 'success', message: `✓ Live odometer fetched: ${kmDisplay} km from Samsara` };
            } catch (e) {
                this.odoBanner = { type: 'warning', message: 'Could not reach Samsara. Enter odometer manually.' };
            } finally {
                this.odoFetching    = false;
                this.odoFetchTarget = null;
            }
        },

        onOdoStartEdited() {
            // User edited the start odometer — mark as manual
            if (this.form.odometer_at_period_start_km !== '' && this.form.odometer_at_period_start_km !== null) {
                this.odoStartSource = 'manual';
                this.odoStartAutoSource = '';
            } else {
                this.odoStartSource = null;
            }
        },
        onOdoEndEdited() {
            // User edited the end odometer — mark as manual (overrides GPS badge)
            if (this.form.odometer_at_period_end_km !== '' && this.form.odometer_at_period_end_km !== null) {
                this.odoEndSource              = 'manual';
                this.form.odometer_source      = 'manual';
                this.form.odometer_fetched_at  = null;
            } else {
                this.odoEndSource              = null;
                this.form.odometer_source      = null;
            }
        },

        updateDays() {
            if (this.form.period_start && this.form.period_end) {
                const s = new Date(this.form.period_start + 'T00:00:00');
                const e = new Date(this.form.period_end + 'T00:00:00');
                const diff = Math.floor((e - s) / 86400000) + 1; // D14: inclusive
                this.days = diff > 0 ? diff : 0;
            } else {
                this.days = 0;
            }
        },

        validate() {
            // VALID-2
            const f = document.querySelector('form');
            FF_Validate.clear(f);
            let ok = true;
            if (!this.form.lease_id) {
                FF_Validate.field(f, 'lease_id', 'Please select a lease.');
                ok = false;
            }
            if (!this.form.period_start) {
                FF_Validate.field(f, 'period_start', 'Invoice date is required.');
                ok = false;
            }
            if (!this.form.period_end) {
                FF_Validate.field(f, 'period_end', 'Due date is required.');
                ok = false;
            }
            if (this.form.period_start && this.form.period_end &&
                this.form.period_end < this.form.period_start) {
                FF_Validate.field(f, 'period_end', 'Due date cannot be before invoice date.');
                ok = false;
            }
            if (!ok) FF_Validate.scrollToFirst(f);
            return ok;
        },

        async submit() {
            if (!this.validate()) return;
            this.submitting = true;
            this.result = null;
            const f = document.querySelector('form');

            // SAMSARA-3: build payload with odometer fields coerced to floats
            // (omit empty strings so the API sees proper null)
            const payload = { ...this.form };
            ['odometer_at_period_start_km', 'odometer_at_period_end_km'].forEach(k => {
                if (payload[k] === '' || payload[k] === null || payload[k] === undefined) {
                    delete payload[k];
                } else {
                    payload[k] = parseFloat(payload[k]);
                }
            });
            if (payload.odometer_source === null) delete payload.odometer_source;
            if (payload.odometer_fetched_at === null) delete payload.odometer_fetched_at;

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/invoices/create') ?>', payload);
                if (r.success) {
                    this.result = r.data;
                    this.showSuccessOverlay = true;
                    const _newId = r.data.id;
                    setTimeout(() => {
                        window.location.href = '<?= base_url('invoices/show') ?>?id=' + _newId;
                    }, 3500);
                } else if (r.error?.code === 'VALIDATION_ERROR' && r.error?.fields) {
                    FF_Validate.applyApi(f, r.error);
                } else {
                    FF_Validate.banner(f, r.error?.message || 'Failed to create invoice.');
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

<?php
$overlayTitle    = 'Invoice Created!';
$overlaySubtitle = 'Redirecting to invoice details…';
require_once FF_ROOT . '/includes/success_overlay.php';
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
