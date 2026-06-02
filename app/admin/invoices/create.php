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
// S-INVOICE-CREATION-UX C2 (Issue 2): also pull end_date, actual_return_date,
// billing_cycle, and the latest non-void invoice's billing_period_end so the
// form can auto-fill period_start (last_period_end + 1 day OR lease.start_date)
// and period_end (period_start + 1 month - 1 day, capped at lease end / actual
// return date per billing_cycle).
$leases = db_select(
    "SELECT l.id, l.contract_number, l.customer_id, l.company_name_snapshot,
            l.unit_number_snapshot, l.template_name_snapshot, l.status,
            l.daily_rate, l.weekly_rate, l.monthly_rate, l.currency,
            l.start_date, l.end_date, l.actual_return_date,
            l.billing_cycle, l.discount_type, l.discount_value,
            l.gst_exempt, l.pst_exempt, l.tax_exempt,
            l.odometer_start_km, l.equipment_unit_id,
            u.samsara_vehicle_id,
            (SELECT i.odometer_at_period_end_km
               FROM invoices i
              WHERE i.lease_id = l.id AND i.deleted_at IS NULL
                AND i.odometer_at_period_end_km IS NOT NULL
              ORDER BY i.billing_period_end DESC, i.id DESC LIMIT 1) AS latest_invoice_end_odo,
            (SELECT i.billing_period_end
               FROM invoices i
              WHERE i.lease_id = l.id AND i.deleted_at IS NULL
                AND i.status != 'void'
                AND i.billing_period_end IS NOT NULL
              ORDER BY i.billing_period_end DESC, i.id DESC LIMIT 1) AS latest_invoice_period_end
     FROM leases l
     LEFT JOIN equipment_units u ON u.id = l.equipment_unit_id AND u.deleted_at IS NULL
     WHERE l.status IN ('active','completed') AND l.deleted_at IS NULL
     ORDER BY l.contract_number ASC",
    []
);

$pageTitle = 'Create Invoice';
$helpModuleSlug = 'invoices';
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
    <div class="page-header-actions">
        <?= help_button('invoices') ?>
    </div>
</div>

<!-- ============================================================
     CREATE INVOICE FORM
     ============================================================ -->
<!-- FIX #39: wrap in form tag so Enter-to-submit works -->
<form x-data="FF_InvoiceCreate()" x-init="init()" @submit.prevent="submit()" class="card" style="padding:24px; max-width:800px;">

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
                    data-end="<?= e($lease['end_date'] ?? '') ?>"
                    data-actual-return="<?= e($lease['actual_return_date'] ?? '') ?>"
                    data-billing-cycle="<?= e($lease['billing_cycle']) ?>"
                    data-equipment-unit-id="<?= (int)$lease['equipment_unit_id'] ?>"
                    data-samsara-linked="<?= !empty($lease['samsara_vehicle_id']) ? '1' : '0' ?>"
                    data-lease-start-odo="<?= e($lease['odometer_start_km'] ?? '') ?>"
                    data-lease-start-date="<?= e($lease['start_date']) ?>"
                    data-prev-end-odo="<?= e($lease['latest_invoice_end_odo'] ?? '') ?>"
                    data-prev-period-end="<?= e($lease['latest_invoice_period_end'] ?? '') ?>">
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

    <!-- S-INVOICE-CREATION-UX C2: catch-up / auto-fill warning banner.
         Shown when the auto-fill logic detects a period that crosses the
         lease end / actual return date or when the lease ended in the past
         and no prior invoices exist (single catch-up invoice scenario). -->
    <template x-if="periodWarning">
        <div class="alert alert-warning" style="margin-bottom:16px; padding:0.75rem 1rem; font-size:0.875rem;"
             x-text="periodWarning"></div>
    </template>

    <!-- S-INVOICE-BACKDATE-WARNING: reactive advisory banners that fire on
         two distinct backdate shapes the operator may not intend:
           (a) Canonical Bug 4 (PROGRESS.md:562, D163 rider): period_start
               < lease.start_date — the invoice covers time BEFORE the lease
               began. Risk: billing for pre-contract time.
           (b) Prompt's Bug 4: period_end < today — the invoice covers an
               already-past period. Risk: double-billing a period that was
               already covered by an earlier invoice.
         Both banners are amber/non-blocking advisories (operator may
         legitimately backdate — catch-up invoices, late-recorded periods).
         Server-side validation NOT added; this is UI-only soft signal. -->
    <template x-for="warn in backdateWarnings()" :key="warn.kind">
        <div class="alert alert-warning" style="margin-bottom:16px; padding:0.75rem 1rem; font-size:0.875rem;"
             x-text="warn.text"></div>
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

    <?php
    // S-CREATE-FEEDBACK: include INSIDE the x-data (form) scope so
    // Alpine can bind `submitting` + `showSuccessOverlay` from
    // FF_InvoiceCreate. Previously included AFTER </form> (outside
    // scope) — overlay bindings had no parent x-data to reach.
    $overlayTitle    = 'Invoice Created!';
    $overlaySubtitle = 'Redirecting to invoice details…';
    require_once FF_ROOT . '/includes/success_overlay.php';
    ?>
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

        // S-INVOICE-CREATION-UX C2: period auto-fill state
        periodWarning:      '',       // banner text when auto-fill hits an edge case (catch-up, capped, etc.)

        // S-INVOICE-CREATION-UX C2 / C3: prefill from URL ?lease_id=N so the
        // "Generate Invoice" button on the lease profile lands here with the
        // lease pre-selected and the period dates auto-filled.
        init() {
            const params = new URLSearchParams(window.location.search);
            const leaseIdParam = params.get('lease_id');
            if (leaseIdParam && /^\d+$/.test(leaseIdParam)) {
                this.form.lease_id = leaseIdParam;
                // Wait for the <option> elements to render before reading their data attrs.
                this.$nextTick(() => this.onLeaseChange());
            }
        },

        // ── S-INVOICE-CREATION-UX C2 date helpers ──────────────────
        // JS Date arithmetic without surprise overflow (Jan 31 + 1 month).
        _ymd(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        },
        _addDays(dateStr, n) {
            const d = new Date(dateStr + 'T00:00:00');
            d.setDate(d.getDate() + n);
            return this._ymd(d);
        },
        // S-INVOICE-BACKDATE-WARNING: today's date in YYYY-MM-DD form via the
        // same _ymd() formatter used by the auto-fill arithmetic. Local-time
        // zoned (matches what date <input type="date"> stores). Re-evaluated
        // every reactive read so day-boundary crossings during a long-open
        // form get reflected the next time period_end changes.
        _todayYmd() {
            return this._ymd(new Date());
        },
        // S-INVOICE-BACKDATE-WARNING: compute both backdate advisory banners
        // reactively. Returns an array of {kind, text} entries — one per
        // active warning. The x-for template renders one alert per entry.
        //
        //   - 'pre_lease_start' (canonical Bug 4 / D163 rider): fires when
        //     form.period_start < the lease's start_date. Uses
        //     _leaseStartDate set in onLeaseChange() from the option's
        //     data-lease-start-date attribute.
        //   - 'past_period_end' (prompt's Bug 4 framing): fires when
        //     form.period_end < today's date.
        //
        // Both checks are advisory (non-blocking). Operator may legitimately
        // backdate for catch-up invoices, late-recorded periods, etc.
        backdateWarnings() {
            const warns = [];

            // Banner (a) — period_start precedes lease.start_date
            if (this.form.period_start
                && this._leaseStartDate
                && this.form.period_start < this._leaseStartDate) {
                warns.push({
                    kind: 'pre_lease_start',
                    text: '⚠️ Period start (' + this.form.period_start
                        + ') precedes lease start date (' + this._leaseStartDate
                        + '). Confirm this is intentional — invoices should '
                        + 'not bill for time before the lease began.'
                });
            }

            // Banner (b) — period_end is in the past
            if (this.form.period_end && this.form.period_end < this._todayYmd()) {
                warns.push({
                    kind: 'past_period_end',
                    text: '⚠️ This invoice covers a past period ('
                        + (this.form.period_start || '?') + ' to '
                        + this.form.period_end + '). Verify this period '
                        + "hasn't already been billed for this lease before "
                        + 'sending.'
                });
            }

            return warns;
        },
        // period_start + 1 month - 1 day, with end-of-month clamping so
        // Jan 31 → Feb 27 (not Mar 2) and Mar 30 → Apr 29 etc.
        _addOneMonthMinusOneDay(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            const day = d.getDate();
            let targetMonth = d.getMonth() + 1;
            let targetYear  = d.getFullYear();
            if (targetMonth > 11) { targetMonth = 0; targetYear += 1; }
            const lastDayOfTargetMonth = new Date(targetYear, targetMonth + 1, 0).getDate();
            const targetDay = Math.min(day, lastDayOfTargetMonth);
            const target = new Date(targetYear, targetMonth, targetDay);
            target.setDate(target.getDate() - 1);
            return this._ymd(target);
        },
        _earliest(...dates) {
            const valid = dates.filter(d => d && typeof d === 'string' && d.length === 10);
            if (!valid.length) return null;
            return valid.reduce((a, b) => (a < b ? a : b));
        },
        _today() {
            return this._ymd(new Date());
        },

        // S-INVOICE-CREATION-UX C2: derive period_start + period_end from
        // lease shape + prior non-void invoice history.
        //
        //   period_start:
        //     - prev non-void invoice exists → prev.billing_period_end + 1 day
        //     - else                          → lease.start_date
        //
        //   period_end (by lease.billing_cycle):
        //     - 'monthly'      → period_start + 1 month - 1 day,
        //                        capped at actual_return_date OR end_date
        //     - 'on_close_only'→ actual_return_date OR end_date OR today
        //     - open-ended     → period_start + 1 month - 1 day (uncapped)
        //
        // Edge case: lease ended in the past with no prior invoices → fill
        // through end_date (or actual_return_date) as a single catch-up
        // invoice and surface a warning banner so the operator knows.
        _autoFillPeriodDates(opt) {
            this.periodWarning = '';
            const startDate    = opt.dataset.start          || '';
            const endDate      = opt.dataset.end            || '';
            const actualReturn = opt.dataset.actualReturn   || '';
            const billingCycle = opt.dataset.billingCycle   || 'monthly';
            const prevPeriodEnd = opt.dataset.prevPeriodEnd || '';
            if (!startDate) return;

            // period_start
            let periodStart;
            if (prevPeriodEnd) {
                periodStart = this._addDays(prevPeriodEnd, 1);
            } else {
                periodStart = startDate;
            }
            this.form.period_start = periodStart;

            // period_end
            const ceiling = this._earliest(actualReturn || null, endDate || null);
            const today   = this._today();

            // Defensive: if a prior invoice already covered through past the
            // lease ceiling (over-invoiced anomaly), refuse to auto-fill
            // period_end and warn loudly. Operator must enter manually.
            if (ceiling && periodStart > ceiling) {
                this.form.period_end = '';
                this.periodWarning = 'Prior invoice ended on ' + prevPeriodEnd +
                    ' which is past the lease ceiling (' + ceiling + ') — the lease appears to have been over-invoiced. Auto-fill skipped; enter the period manually after reviewing the invoice history.';
                return;
            }

            let periodEnd;
            if (billingCycle === 'on_close_only') {
                periodEnd = ceiling || today;
            } else if (ceiling && ceiling < today && !prevPeriodEnd) {
                // Catch-up: lease already ended and no prior invoices exist
                // → fill the entire span as a single catch-up invoice. The
                // alternative (1 normal month with the rest unbilled) leaves
                // a coverage gap that's easy to miss.
                periodEnd = ceiling;
            } else {
                // monthly (default)
                periodEnd = this._addOneMonthMinusOneDay(periodStart);
                if (ceiling && periodEnd > ceiling) {
                    periodEnd = ceiling;
                }
            }
            this.form.period_end = periodEnd;

            // Edge-case warnings — fired AFTER the fill so we don't block it.
            if (ceiling && ceiling < today && !prevPeriodEnd) {
                this.periodWarning = 'Lease ended on ' + ceiling +
                    ' and no prior invoices exist — this is a single catch-up invoice covering the full lease span. Multiple billing cycles would normally have been generated; verify the period before submitting.';
            } else if (ceiling && periodEnd === ceiling && billingCycle === 'monthly') {
                this.periodWarning = 'Period end capped at ' + ceiling +
                    ' (lease ' + (actualReturn ? 'returned' : 'ends') + ' on this date). A full month would have ended later.';
            }
        },
        // ───────────────────────────────────────────────────────────

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
                this.periodWarning = '';
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

            // S-INVOICE-CREATION-UX C2 (Issue 2): auto-fill period_start +
            // period_end from lease shape + prior-invoice history.
            // Both fields stay editable so operators can override for
            // off-cycle invoices.
            this._autoFillPeriodDates(opt);
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
// S-CREATE-FEEDBACK: overlay include moved INSIDE the x-data form
// above so Alpine can bind submitting + showSuccessOverlay.
?>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
