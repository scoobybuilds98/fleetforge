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
 *            S-DROPDOWN-RETROFIT-1: D-DROPDOWN-RETROFIT-PATTERN
 * @session  S008, S-DROPDOWN-RETROFIT-1-LEASES-INVOICES
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'create');

// S-DROPDOWN-RETROFIT-1: Lease field now uses FF_RecordPicker (queries api/v1/leases/index.php).
// Full lease context (odometer, period dates, Samsara) is fetched on-demand via
// api/v1/leases/show.php after a lease is picked. The heavy server-side preload with
// correlated subqueries is no longer needed at page load time.
//
// Pre-load only the initial label for ?lease_id=N URL param so the picker shows
// a pre-selected state when navigating from a lease show page.
$preLeaseId    = clean_int($_GET['lease_id'] ?? null);
$preLeaseLabel = null;
if ($preLeaseId) {
    $preLease = db_row(
        "SELECT id, contract_number, company_name_snapshot, unit_number_snapshot, status
         FROM leases WHERE id = ? AND deleted_at IS NULL AND status IN ('active','completed')",
        [$preLeaseId]
    );
    if ($preLease) {
        $preLeaseLabel = $preLease['contract_number'] . ' — ' . ($preLease['company_name_snapshot'] ?? '');
        if ($preLease['unit_number_snapshot']) {
            $preLeaseLabel .= ' (Unit ' . $preLease['unit_number_snapshot'] . ')';
        }
    } else {
        $preLeaseId = null; // Drop invalid/inaccessible pre-selection
    }
}

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

    <!-- Lease Selection — D-DROPDOWN-RETROFIT-PATTERN: FF_RecordPicker.
         @record-picked fires onLeasePickerSelected(raw) which populates the lease
         info card immediately, then _fetchLeaseContext(id) fetches full context
         (odometer history, period dates, Samsara link) from api/v1/leases/show.php. -->
    <div style="margin-bottom:20px;">
        <label class="form-label">Lease <span class="text-danger">*</span></label>
        <?php
        $pickerConfig   = [
            'endpoint'    => '/api/v1/leases/index.php',
            'searchParam' => 'search',
            'resultKey'   => 'items',
            'perPage'     => 10,
            'extraParams' => 'status=active',
            'placeholder' => 'Search leases by contract #, customer, or unit…',
            'mapResult'   => "r => ({ id: r.id, label: r.contract_number + ' — ' + (r.customer_display_name || ''), sublabel: 'Unit ' + (r.unit_display_number || '—') + ' · ' + r.status, raw: r })",
        ];
        if ($preLeaseId && $preLeaseLabel) {
            $pickerConfig['initialId']    = (int) $preLeaseId;
            $pickerConfig['initialLabel'] = $preLeaseLabel;
        }
        $pickerOnPicked  = 'form.lease_id = $event.detail.id; onLeasePickerSelected($event.detail.raw)';
        $pickerOnCleared = "form.lease_id = ''; onLeaseCleared()";
        $pickerError     = 'false';
        require FF_ROOT . '/includes/partials/record-picker.php';
        ?>
        <div class="field-error" data-error-for="lease_id"></div>
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

    <!-- R2 §3.6: in-order calendar-month picker (holistic leases). Lists the
         lease's billable months with status; only the next-due (first unbilled)
         month is selectable (gate 4.5); selecting it sets the period to that one
         calendar-month segment and generates only that month. -->
    <template x-if="monthsLoaded && billableMonths.length">
        <div style="margin-bottom:20px;">
            <label class="form-label">Billing Month</label>
            <div class="text-secondary text-sm" style="margin-bottom:8px;">
                Bill one calendar month at a time, in order.
                <template x-if="monthsFullyBilled">
                    <span class="text-success">This lease is fully billed through <span x-text="monthsExtent"></span> — nothing new to generate.</span>
                </template>
            </div>
            <div style="display:flex; flex-direction:column; gap:6px;">
                <template x-for="m in billableMonths" :key="m.index">
                    <div :class="{ 'ff-month-row--selected': selectedMonthIndex === m.index }"
                         style="display:flex; align-items:center; gap:12px; padding:10px 12px; border:1px solid var(--border); border-radius:8px;"
                         :style="monthSelectable(m) ? 'cursor:pointer;' : 'opacity:0.75;'"
                         @click="monthSelectable(m) && pickMonth(m.index)">
                        <input type="radio" name="ff_billing_month" class="ff-radio"
                               :checked="selectedMonthIndex === m.index"
                               :disabled="!monthSelectable(m)"
                               @change="pickMonth(m.index)" @click.stop>
                        <div style="flex:1;">
                            <div class="font-medium" x-text="m.label"></div>
                            <div class="text-secondary text-sm">
                                <span x-text="fmtDate(m.period_start)"></span> →
                                <span x-text="fmtDate(m.period_end)"></span>
                                <span x-text="'· ' + m.days + ' days'"></span>
                                <span x-show="m.is_final"> · final</span>
                            </div>
                        </div>
                        <span class="badge badge-no-dot" :class="monthStatusClass(m)" x-text="monthStatusLabel(m)"></span>
                        <a x-show="m.invoice_id"
                           :href="'<?= base_url('invoices/show') ?>?id=' + m.invoice_id"
                           class="link text-sm" @click.stop>view</a>
                    </div>
                </template>
            </div>
            <div class="text-secondary text-sm" style="margin-top:6px;">
                Earlier months must be billed first. The period fields below reflect the selected month and stay editable.
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

    <!-- Submit — R2 §3.6: primary generates the SELECTED month only (single
         segment); "Generate all due" fans out the remaining months in order
         (one atomic transaction) as an explicit choice, not the silent default. -->
    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary"
                :disabled="submitting || !form.lease_id || !form.period_start || !form.period_end || (monthsLoaded && monthsFullyBilled)">
            <span x-show="!submitting" x-text="primaryGenerateLabel()"></span>
            <span x-show="submitting">Generating…</span>
        </button>
        <template x-if="monthsLoaded && unbilledCount > 1">
            <button type="button" class="btn btn-secondary" :disabled="submitting"
                    @click="submitAllDue()"
                    x-text="'Generate all due (' + unbilledCount + ' months)'"></button>
        </template>
        <a href="<?= base_url('invoices') ?>" class="btn btn-secondary">Cancel</a>
        <template x-if="result">
            <span class="text-sm" style="color:var(--color-success);"
                  x-text="(result.invoice_count > 1 ? '✓ Created ' + result.invoice_count + ' invoices' : '✓ Created ' + result.invoice_number)"></span>
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
            single_segment:              false,  // R2 §3.6: picker bills ONE calendar-month segment
        },
        selectedLease:      null,
        days:               0,

        // R2 §3.6 in-order month picker state (api/v1/leases/billable_months.php)
        billableMonths:     [],       // [{index,label,period_start,period_end,days,billing_type,is_final,status,invoice_number,invoice_id}]
        monthsLoaded:       false,    // true once billable_months has been fetched for the selected lease
        monthsNextDue:      null,     // index of the first unbilled month (the only generatable one — gate 4.5)
        monthsFullyBilled:  false,    // every billable month already has a non-void invoice
        monthsExtent:       '',       // the known extent the months run through
        selectedMonthIndex: null,     // which month the operator has selected (drives the form period)
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
        fullyBilled:        false,    // R2 §10: lease is fully billed through the ceiling (void-then-regenerate)

        // S-INVOICE-CREATION-UX C2 / C3: pre-populate from URL ?lease_id=N.
        // The picker's initialId/initialLabel shows the correct label immediately;
        // this init call fetches full context (odometer, period dates, Samsara) from
        // api/v1/leases/show.php so the form auto-fills on page load.
        async init() {
            <?php if ($preLeaseId): ?>
            this.form.lease_id = <?= (int) $preLeaseId ?>;
            await this._fetchLeaseContext(<?= (int) $preLeaseId ?>);
            <?php endif; ?>
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
        // D-DROPDOWN-RETROFIT-PATTERN: accepts a plain context object (not a DOM option)
        // so it works with both the picker flow and the legacy init path.
        //
        //   ctx: { startDate, endDate, actualReturn, billingCycle, prevPeriodEnd }
        // R2 §10 + §3.4: pre-fill Period Start / End / Billing Days / Billing Type /
        // Invoice Type from the lease + its non-void history. Both date fields stay
        // editable. The fully-invoiced edge (latest non-void invoice already at the
        // lease ceiling) NO LONGER shows a future start / empty end / 0 days — it
        // states the lease is fully billed through <date>, shows the lease's OWN
        // dates for reference, and surfaces the void-then-regenerate recovery.
        _autoFillPeriodDatesFromCtx(ctx) {
            this.periodWarning = '';
            this.fullyBilled   = false;
            const startDate    = ctx.startDate    || '';
            const endDate      = ctx.endDate      || '';
            const actualReturn = ctx.actualReturn || '';
            const billingCycle = ctx.billingCycle || 'monthly';
            const prevPeriodEnd = ctx.prevPeriodEnd || '';
            if (!startDate) return;

            const ceiling = this._earliest(actualReturn || null, endDate || null);
            const today   = this._today();

            let periodStart;
            if (prevPeriodEnd) {
                periodStart = this._addDays(prevPeriodEnd, 1);
            } else {
                periodStart = startDate;
            }

            // ── Fully-invoiced / over-invoiced edge (R2 §10) ───────────
            // The next period would start past the lease's billable ceiling —
            // the lease is fully billed. Show the lease's own span (not a future
            // start / empty end / 0 days) and the recovery path.
            if (ceiling && periodStart > ceiling) {
                this.fullyBilled = true;
                this.form.period_start = startDate;
                this.form.period_end   = ceiling;
                this.form.billing_type = 'single_period';
                this.form.invoice_type = 'regular';
                this.updateDays();
                this.periodWarning = 'This lease is fully billed through ' + ceiling +
                    ' (the last invoice ' + (prevPeriodEnd ? 'ends on ' + prevPeriodEnd : 'reaches the lease end') +
                    '). The dates shown are the lease’s own span for reference. To re-bill a period, ' +
                    'void the relevant invoice on the lease first, then regenerate.';
                return;
            }

            this.form.period_start = periodStart;

            let periodEnd;
            if (billingCycle === 'on_close_only') {
                periodEnd = ceiling || today;
            } else if (ceiling && ceiling < today && !prevPeriodEnd) {
                periodEnd = ceiling;
            } else {
                // R2 §10: Period End = last day of Period Start's calendar month,
                // capped at the lease ceiling (LEAST(end_date, actual_return)) on
                // the final segment.
                periodEnd = this._lastDayOfMonth(periodStart);
                if (ceiling && periodEnd > ceiling) {
                    periodEnd = ceiling;
                }
            }
            this.form.period_end = periodEnd;

            // R2 §10: pre-fill Billing Type + Invoice Type from the segment shape.
            // (The generator authoritatively re-derives these per calendar-month
            // segment on fan-out; these are the operator-facing preview values.)
            const spansBeyond = !!(ceiling && periodEnd < ceiling) ||
                                (!ceiling && billingCycle === 'monthly' && periodEnd === this._lastDayOfMonth(periodStart) && periodEnd < today);
            this.form.billing_type = this._segmentBillingType(periodStart, periodEnd, spansBeyond);
            this.form.invoice_type = (ceiling && periodEnd === ceiling) ? 'final' : 'regular';
            this.updateDays();

            if (ceiling && ceiling < today && !prevPeriodEnd) {
                this.periodWarning = 'Lease ended on ' + ceiling +
                    ' and no prior invoices exist — generating will create the full calendar-month sequence (one invoice per month) up to this date. Verify before submitting.';
            } else if (ceiling && periodEnd === ceiling && billingCycle === 'monthly') {
                this.periodWarning = 'Period end capped at ' + ceiling +
                    ' (lease ' + (actualReturn ? 'returned' : 'ends') + ' on this date). A full month would have ended later.';
            }
        },
        // Last calendar day of the month that contains dateStr (Y-m-d).
        _lastDayOfMonth(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return this._ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0));
        },
        // R2 §10 billing_type for a single segment: full_month when it is a whole
        // calendar month; partial_start when it begins mid-month; partial_end when
        // it begins on the 1st but ends mid-month; single_period when this invoice
        // is the whole (non-spanning) bill.
        _segmentBillingType(periodStart, periodEnd, spansBeyond) {
            const d = new Date(periodStart + 'T00:00:00');
            const firstOfMonth = this._ymd(new Date(d.getFullYear(), d.getMonth(), 1));
            const lastOfMonth  = this._lastDayOfMonth(periodStart);
            const startsFirst  = (periodStart === firstOfMonth);
            const endsLast     = (periodEnd === lastOfMonth);
            if (startsFirst && endsLast) return 'full_month';
            if (!spansBeyond)            return 'single_period';
            if (!startsFirst)            return 'partial_start';
            return 'partial_end';
        },

        // ── R2 §3.6 in-order month picker ────────────────────────────
        // Fetch the lease's billable calendar months + per-month status from
        // api/v1/leases/billable_months.php and default-select the next-due
        // (first unbilled) month. Holistic leases only — the period-independent
        // legacy path keeps the plain period inputs.
        async _fetchBillableMonths(leaseId) {
            this.monthsLoaded       = false;
            this.billableMonths     = [];
            this.monthsNextDue      = null;
            this.monthsFullyBilled  = false;
            this.selectedMonthIndex = null;
            this.form.single_segment = false;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases/billable_months') ?>?id=' + leaseId);
                const d = r.data || {};
                if (d.engine_version !== 'holistic') return; // legacy path: no picker
                this.billableMonths    = Array.isArray(d.months) ? d.months : [];
                this.monthsNextDue     = (d.next_due_index === undefined ? null : d.next_due_index);
                this.monthsFullyBilled = !!d.fully_billed;
                this.monthsExtent      = d.extent || '';
                this.monthsLoaded      = this.billableMonths.length > 0;
                // Default = next due month (in-order). When fully billed, leave
                // the fully-billed banner from _autoFillPeriodDatesFromCtx as-is.
                if (this.monthsNextDue !== null && this.billableMonths[this.monthsNextDue]) {
                    this.pickMonth(this.monthsNextDue);
                }
            } catch (e) {
                // Non-fatal — the operator can still type a period manually.
                this.monthsLoaded = false;
            }
        },
        // Gate 4.5 (in-order only): only the next-due month is selectable; billed
        // and void months are informational, and a later month can't be picked
        // before the earlier ones are billed. Selecting sets the form to that
        // single calendar-month segment and flags single-segment generation.
        pickMonth(idx) {
            const m = this.billableMonths[idx];
            if (!m || m.status !== 'unbilled' || idx !== this.monthsNextDue) return;
            this.selectedMonthIndex  = idx;
            this.form.period_start   = m.period_start;
            this.form.period_end     = m.period_end;
            this.form.billing_type   = m.billing_type;
            this.form.invoice_type   = m.is_final ? 'final' : 'regular';
            this.form.single_segment = true;   // generate ONLY this month
            this.fullyBilled         = false;
            this.periodWarning       = '';
            this.updateDays();
        },
        monthStatusLabel(m) {
            if (m.status === 'billed') return 'Billed · ' + (m.invoice_number || '');
            if (m.status === 'void')   return 'Void · ' + (m.invoice_number || '') + ' (re-billable)';
            return (m.index === this.monthsNextDue) ? 'Next to bill' : 'Upcoming';
        },
        monthStatusClass(m) {
            if (m.status === 'billed') return 'badge-success';
            if (m.status === 'void')   return 'badge-secondary';
            return (m.index === this.monthsNextDue) ? 'badge-primary' : 'badge-no-dot';
        },
        monthSelectable(m) {
            return m.status === 'unbilled' && m.index === this.monthsNextDue;
        },
        fmtDate(s) {
            if (!s) return '';
            const d = new Date(s + 'T00:00:00');
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        },
        // Number of still-unbilled months in the picker (drives "Generate all due").
        get unbilledCount() {
            return this.billableMonths.filter(m => m.status === 'unbilled').length;
        },
        // Primary-button label: name the selected month when the picker is driving.
        primaryGenerateLabel() {
            if (this.monthsLoaded && this.monthsFullyBilled) return 'Fully billed';
            if (this.selectedMonthIndex !== null && this.billableMonths[this.selectedMonthIndex]) {
                return 'Generate ' + this.billableMonths[this.selectedMonthIndex].label;
            }
            return 'Create Invoice';
        },
        // R2 §3.6 (2.2): explicit catch-up — fan out every remaining billable month
        // in order, atomically (the server's single-db_transaction fan-out path).
        submitAllDue() {
            if (this.monthsNextDue === null) return;
            const first = this.billableMonths[this.monthsNextDue];
            let lastEnd = first.period_end;
            this.billableMonths.forEach(m => { if (m.status === 'unbilled') lastEnd = m.period_end; });
            this.form.period_start   = first.period_start;
            this.form.period_end     = lastEnd;
            this.form.single_segment = false;   // fan out the whole remaining span
            this.selectedMonthIndex  = null;
            this.fullyBilled         = false;
            this.periodWarning       = '';
            this.updateDays();
            this.submit();
        },
        // ───────────────────────────────────────────────────────────

        // D-DROPDOWN-RETROFIT-PATTERN: called by FF_RecordPicker @record-picked.
        // Sets immediate UI state from the raw lease record (from api/v1/leases/index.php),
        // then fetches full context (odometer history, period-end date, Samsara link)
        // from api/v1/leases/show.php via _fetchLeaseContext().
        async onLeasePickerSelected(raw) {
            if (!raw) return this.onLeaseCleared();

            // Immediate basic state — show the lease info card without waiting
            this.selectedLease = {
                daily:           raw.daily_rate    || '0.00',
                weekly:          raw.weekly_rate   || '0.00',
                monthly:         raw.monthly_rate  || '0.00',
                currency:        raw.currency      || 'CAD',
                start:           raw.start_date    || '',
                equipmentUnitId: raw.equipment_unit_id || null,
            };
            this._leaseStartDate = raw.start_date || '';

            // Full context fetch (odometer, period dates, Samsara)
            await this._fetchLeaseContext(raw.id);
        },

        // Fetch full lease context from api/v1/leases/show.php. Used by both
        // onLeasePickerSelected() and the init() pre-populate flow.
        async _fetchLeaseContext(leaseId) {
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/leases/show') ?>?id=${leaseId}`);
                const d = r.data || {};

                this.odoCanFetch     = !!d.samsara_vehicle_id;
                this._leaseStartOdo  = d.odometer_start_km !== null && d.odometer_start_km !== undefined
                    ? parseFloat(d.odometer_start_km) : null;
                this._leaseStartDate = d.start_date || this._leaseStartDate;

                // Ensure selectedLease is set (may not be if called from init())
                if (!this.selectedLease) {
                    this.selectedLease = {
                        daily:           d.daily_rate    || '0.00',
                        weekly:          d.weekly_rate   || '0.00',
                        monthly:         d.monthly_rate  || '0.00',
                        currency:        d.currency      || 'CAD',
                        start:           d.start_date    || '',
                        equipmentUnitId: d.equipment_unit_id || null,
                    };
                }

                // Period dates auto-fill from full context
                this._autoFillPeriodDatesFromCtx({
                    startDate:    d.start_date          || '',
                    endDate:      d.end_date            || '',
                    actualReturn: d.actual_return_date  || '',
                    billingCycle: d.billing_cycle       || 'monthly',
                    prevPeriodEnd: d.latest_invoice_period_end || '',
                });
                this.updateDays();

                // R2 §3.6: load the in-order month picker (holistic leases). If a
                // next-due month exists it overrides the auto-fill with that exact
                // calendar-month segment and flags single-segment generation.
                await this._fetchBillableMonths(leaseId);

                // Reset end side — user always fetches or enters fresh
                this.form.odometer_at_period_end_km = '';
                this.form.odometer_fetched_at       = null;
                this.odoEndSource                   = null;
                this.odoBanner                      = null;

                // Auto-populate start side
                const prevEndOdo = d.latest_invoice_odometer_km;
                if (prevEndOdo !== null && prevEndOdo !== undefined && !isNaN(Number(prevEndOdo))) {
                    this.form.odometer_at_period_start_km = Number(prevEndOdo).toFixed(2);
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
            } catch (e) {
                // Non-fatal: context fetch failed; basic selectedLease state still works.
                // Period dates and odometer fields will be blank — user can fill manually.
            }
        },

        onLeaseCleared() {
            this.selectedLease = null;
            this.odoCanFetch = false;
            this.form.odometer_at_period_start_km = '';
            this.form.odometer_at_period_end_km   = '';
            this.odoStartSource     = null;
            this.odoEndSource       = null;
            this.odoStartAutoSource = '';
            this.odoBanner          = null;
            this._leaseStartOdo     = null;
            this._leaseStartDate    = '';
            this.periodWarning      = '';
            this.fullyBilled        = false;
            // R2 §3.6 picker reset
            this.billableMonths     = [];
            this.monthsLoaded       = false;
            this.monthsNextDue      = null;
            this.monthsFullyBilled  = false;
            this.selectedMonthIndex = null;
            this.form.single_segment = false;
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
                const _url = '<?= base_url('api/v1/invoices/create') ?>';
                let r = await FF_Api.post(_url, payload);

                // PERIOD_OVERLAP: the period overlaps an existing invoice, so the
                // engine would only bill the reconciliation difference. Offer an
                // explicit confirm-and-resend instead of dead-ending the operator.
                if (!r.success && r.error?.code === 'PERIOD_OVERLAP') {
                    const ov  = r.error.overlap || {};
                    const ref = ov.invoice_number
                        ? `${ov.invoice_number} (${ov.billing_period_start} → ${ov.billing_period_end})`
                        : 'an existing invoice';
                    const ok = await FF_Confirm.ask({
                        title:        'Overlapping invoice period',
                        message:      `This period overlaps ${ref}. Only the reconciliation difference `
                                    + `will be billed, not the full period. Create it anyway?`,
                        confirmLabel: 'Reconcile anyway',
                        dangerMode:   true,
                    });
                    if (!ok) { this.submitting = false; return; }
                    payload.allow_overlap = true;
                    r = await FF_Api.post(_url, payload);
                }

                if (r.success) {
                    this.result = r.data;
                    this.showSuccessOverlay = true;
                    // R2 §3.6 (2.3): land on a view showing ALL invoices created by
                    // this action, already refreshed. A fan-out (>1 invoice) goes to
                    // the lease's invoice list (every new invoice visible, no manual
                    // refresh); a single invoice opens its own page.
                    const _count   = r.data.invoice_count || 1;
                    const _leaseId = this.form.lease_id;
                    const _firstId = r.data.id;
                    setTimeout(() => {
                        window.location.href = _count > 1
                            ? '<?= base_url('invoices') ?>?lease_id=' + _leaseId
                            : '<?= base_url('invoices/show') ?>?id=' + _firstId;
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
