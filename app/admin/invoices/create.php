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
<!-- S-INVOICE-DISTANCE-ENTRY: novalidate — the two mileage modes hide their
     inputs with x-show, and the browser refuses to submit (silently) when a
     HIDDEN input fails min/step. validate() + the API do the checking. -->
<form x-data="FF_InvoiceCreate()" @submit.prevent="submit()" class="card" style="padding:24px; max-width:800px;" novalidate>

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
                <!-- S-PICKER-OPEN-LEASE: "fully billed" is only ever TRUE for a lease
                     with a known end. On a still-running lease the extent is just
                     today, so say what is actually billed and that more is coming —
                     never "nothing new to generate". -->
                <template x-if="monthsFullyBilled && monthsExtentDefinitive">
                    <span class="text-success">This lease is fully billed through <span x-text="monthsExtent"></span> — nothing new to generate.</span>
                </template>
                <template x-if="monthsFullyBilled && !monthsExtentDefinitive">
                    <span class="text-secondary">Billed through <span x-text="monthsExtent"></span>. This lease is still running, so the next month becomes billable as it accrues.</span>
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

    <!-- ── SAMSARA-3 / S-INVOICE-DISTANCE-ENTRY: Mileage section ──────
         Two ways to enter this period's mileage (D-DISTANCE-ENTRY-1):
           • Distance driven (DEFAULT) — one number, the distance driven this
             period. Built for backfilling, where only the month's mileage is
             known. On a Manual lease the server adds it to the reading shown
             as "Counted from" (end = start + distance), so the odometer chain
             the next invoice / Readings tab / close count from stays intact.
             On a Samsara lease it replaces the GPS distance for this period.
           • Odometer readings — the original start/end pair. Period start
             auto-populates from the previous (non-void) invoice's end reading,
             else the lease's starting odometer; period end is typed or
             fetched live from Samsara.
         Leases with mileage tracking Off bill no mileage at all (the engine
         drops every reading), so they get a note instead of inputs.
         ──────────────────────────────────────────────────────── -->
    <template x-if="selectedLease">
        <div style="margin-bottom:20px;padding:16px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-surface-2);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:0.75rem;">
                <div style="font-weight:600;font-size:0.95rem;">Mileage</div>
                <!-- S-ODO-UNIT: entry/display unit ONLY. Readings and distances
                     are ALWAYS stored in km (the columns are *_km and the
                     billing engine computes distance in km) — picking "miles"
                     converts on the way in, it does NOT change which rate is
                     billed. That is driven by the LEASE's own mileage_unit and
                     is untouched here. Defaults to the lease's unit so the
                     operator normally never touches it. -->
                <label x-show="leaseMileageMode !== 'off'" style="display:flex;align-items:center;gap:6px;font-size:0.8rem;" class="text-secondary">
                    Enter in
                    <select class="form-control form-control-sm" style="width:auto;padding-block:4px;"
                            x-model="odoUnit" @change="onOdoUnitChanged($event)">
                        <option value="km">Kilometres (km)</option>
                        <option value="miles">Miles (mi)</option>
                    </select>
                </label>
            </div>

            <!-- Mileage tracking Off: nothing to enter (InvoiceGenerator drops
                 every reading/distance on an 'off' lease). -->
            <div x-show="leaseMileageMode === 'off'" class="form-hint">
                Mileage tracking is Off for this lease, so this invoice bills no mileage.
                To bill mileage, set the lease's mileage tracking to Manual or Samsara.
            </div>

            <div x-show="leaseMileageMode !== 'off'">
                <!-- Entry mode: Distance driven (default) | Odometer readings -->
                <div style="margin-bottom:1rem;max-width:100%;overflow-x:auto;">
                    <div class="ff-segment-control" role="tablist" aria-label="How to enter mileage">
                        <div class="ff-segment-control__pill"
                             :class="{ 'ff-segment-control__pill--right': form.mileage_entry === 'odometer' }"></div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': form.mileage_entry === 'distance' }"
                             @click="setMileageEntry('distance')"
                             role="tab" :aria-selected="form.mileage_entry === 'distance'" tabindex="0"
                             @keydown.enter.prevent="setMileageEntry('distance')"
                             @keydown.space.prevent="setMileageEntry('distance')">
                            Distance driven
                        </div>
                        <div class="ff-segment-control__option"
                             :class="{ 'ff-segment-control__option--active': form.mileage_entry === 'odometer' }"
                             @click="setMileageEntry('odometer')"
                             role="tab" :aria-selected="form.mileage_entry === 'odometer'" tabindex="0"
                             @keydown.enter.prevent="setMileageEntry('odometer')"
                             @keydown.space.prevent="setMileageEntry('odometer')">
                            Odometer readings
                        </div>
                    </div>
                </div>

                <!-- ── Distance driven ─────────────────────────────────── -->
                <div x-show="form.mileage_entry === 'distance'">
                    <label class="form-label" for="ff-inv-distance">
                        <span x-text="distanceLabel()"></span>
                        (<span x-text="odoUnitLabel"></span>)
                    </label>
                    <input type="number"
                           id="ff-inv-distance"
                           name="period_distance_km"
                           class="form-control font-mono"
                           x-model="form.period_distance"
                           step="0.01"
                           min="0"
                           placeholder="Total distance driven"
                           style="max-width:260px;">

                    <!-- Manual lease: say exactly what the distance counts from,
                         and what reading it produces. -->
                    <template x-if="leaseMileageMode === 'manual'">
                        <div>
                            <div class="form-hint" style="margin-top:0.25rem;" x-show="distanceNotice() !== 'none'" x-text="distanceAnchorHint()"></div>
                            <!-- Estimate lease, no reading yet, earlier invoices billed
                                 ESTIMATES: the true-up treats the reading as lifetime
                                 mileage, so a single month here would credit every
                                 earlier estimate back. Ask for the lifetime figure. -->
                            <div class="alert alert-warning" style="margin-top:0.5rem;padding:0.5rem 0.75rem;font-size:0.85rem;"
                                 x-show="distanceNotice() === 'lifetime'">
                                This lease bills estimated mileage, and no earlier invoice has a reading. Enter
                                <strong>all</strong> the distance driven since the lease started on
                                <span x-text="_leaseStartDate"></span> — the estimates already billed are subtracted in the
                                true-up. Entering only this month's distance would credit those estimates back.
                            </div>
                            <div class="form-hint" style="margin-top:0.25rem;" x-show="distanceNotice() === 'first'">
                                No earlier invoice on this lease has a reading, so this distance should cover all
                                driving since the lease started that hasn't been billed yet.
                            </div>
                            <div class="alert alert-warning" style="margin-top:0.5rem;padding:0.5rem 0.75rem;font-size:0.85rem;"
                                 x-show="distanceNotice() === 'none'">
                                This lease has no starting odometer and no earlier reading, so a distance has nothing to
                                count from. Enter the start reading under <strong>Odometer readings</strong>, or set the
                                lease's starting odometer (0 if its mileage counts from zero).
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
                                <div>
                                    <div class="text-xs text-secondary">New odometer reading</div>
                                    <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                                         x-text="fmtDist(distanceNewReading())"></div>
                                </div>
                                <div>
                                    <div class="text-xs text-secondary">Cumulative (since lease start)</div>
                                    <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                                         x-text="fmtDist(distanceCumulative())"></div>
                                    <div x-show="cumulativeContext" class="text-xs text-secondary" style="margin-top:2px;"
                                         x-text="cumulativeContext"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Samsara lease: the typed distance replaces GPS. -->
                    <div class="form-hint" style="margin-top:0.25rem;" x-show="leaseMileageMode === 'samsara'">
                        Replaces the Samsara GPS distance for this period. Leave it blank to bill the GPS distance.
                    </div>
                </div>

                <!-- ── Odometer readings (SAMSARA-3) ───────────────────── -->
                <div x-show="form.mileage_entry === 'odometer'">
                    <!-- Period Start Odometer -->
                    <div style="margin-bottom:1rem;">
                        <label class="form-label" for="ff-inv-odo-start">Odometer at Period Start (<span x-text="odoUnitLabel"></span>)</label>
                        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                            <input type="number"
                                   id="ff-inv-odo-start"
                                   name="odometer_at_period_start_km"
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
                        <label class="form-label" for="ff-inv-odo-end">Odometer at Period End — current (<span x-text="odoUnitLabel"></span>)</label>
                        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                            <input type="number"
                                   id="ff-inv-odo-end"
                                   name="odometer_at_period_end_km"
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
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:0.5rem;padding-top:0.75rem;border-top:1px solid var(--border-color);">
                        <div>
                            <div class="text-xs text-secondary">Period Distance</div>
                            <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                                 x-text="fmtDist(periodDistance)"></div>
                            <div x-show="periodDistanceWarning" class="text-xs" style="color:var(--color-danger);margin-top:2px;"
                                 x-text="periodDistanceWarning"></div>
                        </div>
                        <div>
                            <div class="text-xs text-secondary">Cumulative (since lease start)</div>
                            <div class="font-mono" style="font-size:1rem;font-weight:600;margin-top:2px;"
                                 x-text="fmtDist(cumulativeDistance)"></div>
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
                    <!-- The Samsara buttons read TODAY's live odometer — wrong for a
                         past period, so say so while backfilling. -->
                    <div x-show="odoCanFetch && form.period_end && form.period_end < _todayYmd()" class="form-hint" style="margin-top:0.5rem;">
                        "Fetch from Samsara" reads today's odometer, not the reading at the end of this past period.
                    </div>
                </div>
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
                :disabled="submitting || !form.lease_id || !form.period_start || !form.period_end || (monthsLoaded && monthsFullyBilled && monthsExtentDefinitive)">
            <span x-show="!submitting" x-text="primaryGenerateLabel()"></span>
            <span x-show="submitting">Generating…</span>
        </button>
        <!-- S-INVOICE-DISTANCE-ENTRY: a typed distance belongs to ONE month —
             on a fan-out it would land whole on the last month (the server
             refuses it too), so "all due" waits until the box is cleared. -->
        <template x-if="monthsLoaded && unbilledCount > 1">
            <button type="button" class="btn btn-secondary" :disabled="submitting || distanceEntered()"
                    :title="distanceEntered() ? 'Distance driven is entered one month at a time — generate the selected month, or clear the distance.' : ''"
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
            // S-INVOICE-DISTANCE-ENTRY: distance driven this period, in the
            // DISPLAY unit (odoUnit). Sent as period_distance_km (km) only in
            // "Distance driven" mode — see submit().
            period_distance:             '',
            // 'distance' (default) | 'odometer'. In `form` (not component
            // state) so a restored draft brings its mode back with its values —
            // otherwise a restored end reading sits hidden and is dropped.
            mileage_entry:               'distance',
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
        monthsExtentDefinitive: false, // S-PICKER-OPEN-LEASE: extent is a REAL end (return/end_date), not just today
        selectedMonthIndex: null,     // which month the operator has selected (drives the form period)
        submitting:         false,
        showSuccessOverlay: false,
        error:              null,
        result:             null,

        // S-ODO-UNIT: unit the operator TYPES/READS in. Storage stays km.
        odoUnit: 'km',
        get odoUnitLabel() { return this.odoUnit === 'miles' ? 'mi' : 'km'; },

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

        // S-INVOICE-DISTANCE-ENTRY (D-DISTANCE-ENTRY-1) mileage entry state
        leaseMileageMode:      null,       // lease.mileage_tracking_mode: 'manual' | 'samsara' | 'off'
        distanceAnchorFrom:    null,       // where the period-start reading came from:
                                           // 'invoice' | 'lease' | 'none' (auto-fill) | 'edited' | 'gps'
        _odoReadings:          [],         // live period-end readings, oldest first (leases/show odometer_readings)
        _odoStartAutoInv:      '',         // invoice whose end reading the auto-fill used
        _odoStartAutoDerived:  false,      // that position = last reading + distance-only months since
        _leaseEstimatePerDay:  0,          // lease.estimated_mileage_per_day (estimate model when > 0)
        _latestPeriodEnd:      '',         // latest live invoice's billing_period_end
        // The auto-filled period-start reading exactly as stored (km) and as
        // first displayed. An untouched auto-fill is sent as the stored km,
        // not re-converted from a 2dp miles display (that round-trip drifts
        // by up to 0.01 km and would put a gap in the odometer chain).
        _odoStartAutoKm:       null,
        _odoStartAutoDisplay:  null,

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
            // S-FORM-DRAFT-ROLLOUT: opt into the shared autosave helper. This form is a
            // lease-period invoice generator — no manual line-items/amounts (those are
            // server-derived) and no payment-credential fields. Exclude lease_id (owned
            // by the FF_RecordPicker, can't rehydrate at runtime) + the transient
            // odometer GPS metadata; the manual fields (po_number, notes, period,
            // odometer values, type toggles) are drafted/restored.
            if (window.FF_FormDraft) {
                this._draft = FF_FormDraft.attach({
                    formId: 'invoice-create', entityId: 'new',
                    el: this.$root, model: this.form, version: '1',
                    exclude: ['lease_id', 'odometer_source', 'odometer_fetched_at'],
                });
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
            this.monthsExtentDefinitive = false;
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
                this.monthsExtentDefinitive = !!d.extent_definitive;
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
            if (!m || (m.status !== 'unbilled' && m.status !== 'void') || idx !== this.monthsNextDue) return;
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
            if (m.status === 'void' && m.index === this.monthsNextDue) return 'Void · ' + (m.invoice_number || '') + ' — next to bill';
            if (m.status === 'void')   return 'Void · ' + (m.invoice_number || '') + ' (re-billable)';
            return (m.index === this.monthsNextDue) ? 'Next to bill' : 'Upcoming';
        },
        monthStatusClass(m) {
            if (m.status === 'billed') return 'badge-success';
            if (m.status === 'void')   return 'badge-secondary';
            return (m.index === this.monthsNextDue) ? 'badge-primary' : 'badge-no-dot';
        },
        // S-PICKER-OPEN-LEASE: 'void' is re-billable — findOverlappingInvoice()
        // ignores void invoices, so generation over that period is allowed, and
        // billable_months.php now arms next_due_index for it. Without this the
        // void-then-regenerate recovery the page itself advertises is a dead end.
        monthSelectable(m) {
            return (m.status === 'unbilled' || m.status === 'void') && m.index === this.monthsNextDue;
        },
        fmtDate(s) {
            if (!s) return '';
            const d = new Date(s + 'T00:00:00');
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        },
        // Number of still-billable months in the picker (drives "Generate all due").
        // S-PICKER-OPEN-LEASE: void segments count — they are re-billable.
        get unbilledCount() {
            return this.billableMonths.filter(m => m.status === 'unbilled' || m.status === 'void').length;
        },
        // Primary-button label: name the selected month when the picker is driving.
        primaryGenerateLabel() {
            if (this.monthsLoaded && this.monthsFullyBilled && this.monthsExtentDefinitive) return 'Fully billed';
            if (this.selectedMonthIndex !== null && this.billableMonths[this.selectedMonthIndex]) {
                return 'Generate ' + this.billableMonths[this.selectedMonthIndex].label;
            }
            return 'Create Invoice';
        },
        // R2 §3.6 (2.2): explicit catch-up — fan out every remaining billable month
        // in order, atomically (the server's single-db_transaction fan-out path).
        submitAllDue() {
            if (this.monthsNextDue === null) return;
            // S-INVOICE-DISTANCE-ENTRY: the button is disabled while a distance
            // is typed; this guards keyboard/programmatic triggers too.
            if (this.distanceEntered()) return;
            const first = this.billableMonths[this.monthsNextDue];
            let lastEnd = first.period_end;
            this.billableMonths.forEach(m => { if (m.status === 'unbilled' || m.status === 'void') lastEnd = m.period_end; });
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
                // S-INVOICE-DISTANCE-ENTRY: the lease's mileage source decides
                // what a typed distance means (manual → a reading counted from
                // the previous one; samsara → replaces GPS; off → nothing).
                this.leaseMileageMode = d.mileage_tracking_mode || null;
                // Period-start auto-fill inputs (applied once the period is
                // known, below). Null the "auto display" first so the period
                // auto-fill's updateDays() can't re-apply a previous lease's.
                this._odoStartAutoDisplay = null;
                this._odoReadings         = Array.isArray(d.odometer_readings) ? d.odometer_readings : [];
                this._leaseEstimatePerDay = parseFloat(d.estimated_mileage_per_day) || 0;
                this._latestPeriodEnd     = d.latest_invoice_period_end || '';
                // S-ODO-UNIT: default to the lease's own convention so the
                // operator normally never has to touch the selector. Set
                // before any auto-fill below so those convert correctly.
                if (d.mileage_unit === 'miles' || d.mileage_unit === 'km') {
                    this.odoUnit      = d.mileage_unit;
                    this._odoUnitPrev = d.mileage_unit;
                }
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
                // S-INVOICE-DISTANCE-ENTRY: every lease opens in Distance
                // driven mode with an empty box (same "enter fresh" rule).
                this.form.period_distance           = '';
                this.form.mileage_entry                   = 'distance';

                // Auto-populate the start side for the selected period: the last
                // live reading BEFORE it, else the lease's starting odometer.
                this._applyStartAutoFill();
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
            // S-INVOICE-DISTANCE-ENTRY reset
            this.form.period_distance    = '';
            this.form.mileage_entry            = 'distance';
            this.leaseMileageMode        = null;
            this.distanceAnchorFrom      = null;
            this._odoStartAutoKm         = null;
            this._odoStartAutoDisplay    = null;
            this._odoStartAutoInv        = '';
            this._odoStartAutoDerived    = false;
            this._odoReadings            = [];
            this._leaseEstimatePerDay    = 0;
            this._latestPeriodEnd        = '';
            this.periodWarning      = '';
            this.fullyBilled        = false;
            // R2 §3.6 picker reset
            this.billableMonths     = [];
            this.monthsLoaded       = false;
            this.monthsNextDue      = null;
            this.monthsFullyBilled  = false;
            this.monthsExtentDefinitive = false;
            this.selectedMonthIndex = null;
            this.form.single_segment = false;
        },

        // ── S-INVOICE-DISTANCE-ENTRY (D-DISTANCE-ENTRY-1) ───────────
        // Switch between typing the distance driven (default) and typing
        // odometer readings. Both modes share the period-start reading, so a
        // start corrected under "Odometer readings" is what a distance counts
        // from when the operator switches back.
        setMileageEntry(mode) {
            if (mode !== 'distance' && mode !== 'odometer') return;
            this.form.mileage_entry = mode;
        },
        /** True when a distance will actually be sent (drives the
         *  "Generate all due" lock — a distance belongs to one month). */
        distanceEntered() {
            const v = this.form.period_distance;   // read first: Alpine dep tracking
            return this.leaseMileageMode !== 'off'
                && this.form.mileage_entry === 'distance'
                && v !== '' && v !== null && !isNaN(parseFloat(v));
        },
        /** The last live period-end reading before `periodStart` — the rule
         *  the engine and the Readings tab use (CycleReadings::previousReadings).
         *  _odoReadings is oldest-first, so the last match is the latest. */
        _readingBefore(periodStart) {
            let hit = null;
            if (!periodStart) return hit;
            for (const r of this._odoReadings) {
                if (r.period_end < periodStart) hit = r;
            }
            return hit;
        },
        /** Fill the period-start reading for the CURRENT period from the last
         *  reading before it, else the lease's starting odometer, else blank. */
        _applyStartAutoFill() {
            const r = this._readingBefore(this.form.period_start);
            let km = null, from = 'none', inv = '', derived = false, hint = 'No previous odometer on file. Enter manually or fetch from Samsara.';
            if (r) {
                km = r.km; from = 'invoice'; inv = r.invoice_number || ''; derived = !!r.derived;
                // S-SAMSARA-CLOSE-DISTANCE-CHAIN: a derived position is the last
                // reading plus the GPS/typed distance of the months billed since.
                hint = derived
                    ? 'Auto-filled from the last reading plus the distance billed since (through ' + (inv || 'the previous invoice') + ').'
                    : 'Auto-filled from the end reading of ' + (inv || 'the previous invoice') + '.';
            } else if (this._leaseStartOdo !== null && !isNaN(this._leaseStartOdo)) {
                km = this._leaseStartOdo; from = 'lease';
                hint = 'Auto-filled from lease starting odometer.';
            }
            this.form.odometer_at_period_start_km = km === null ? '' : this.fromKm(km).toFixed(2);
            this._odoStartAutoKm       = km === null ? null : String(km);
            this._odoStartAutoDisplay  = this.form.odometer_at_period_start_km;
            this._odoStartAutoInv      = inv;
            this._odoStartAutoDerived  = derived;
            this.distanceAnchorFrom    = from;
            this.odoStartSource        = km === null ? null : 'manual';
            this.odoStartAutoSource    = hint;
        },
        /** After a period change, move an UNTOUCHED auto-filled start to the
         *  new period's reading (an edited or fetched start is left alone). */
        _refreshStartAutoFill() {
            if (this._odoStartAutoDisplay === null) return;   // lease context not loaded yet
            if (this.form.odometer_at_period_start_km !== this._odoStartAutoDisplay) return;
            this._applyStartAutoFill();
        },
        /** What a manual-lease distance counts from — exactly what the server
         *  will use: the start reading sent (an untouched auto-fill, an edit or
         *  a fetch), or, when it is blank, the server's own fallback (last
         *  reading before the period, else the lease's starting odometer).
         *  { value (display unit) | null, from, inv }. */
        _distanceAnchor() {
            const raw   = this.form.odometer_at_period_start_km;
            const typed = parseFloat(raw);
            const auto  = raw === this._odoStartAutoDisplay;
            const from  = this.distanceAnchorFrom;
            const r     = this._readingBefore(this.form.period_start);
            if (!isNaN(typed)) {
                if (auto) return { value: typed, from: from, inv: this._odoStartAutoInv, derived: this._odoStartAutoDerived };
                return { value: typed, from: from === 'gps' ? 'gps' : 'edited', inv: '', derived: false };
            }
            if (r) return { value: this.fromKm(r.km), from: 'invoice', inv: r.invoice_number || '', derived: !!r.derived };
            if (this._leaseStartOdo !== null && !isNaN(this._leaseStartOdo)) {
                return { value: this.fromKm(this._leaseStartOdo), from: 'lease', inv: '', derived: false };
            }
            return { value: null, from: 'none', inv: '', derived: false };
        },
        _distanceStart() {
            const a = this._distanceAnchor();
            return a.value === null ? 0 : a.value;
        },
        /** A live invoice exists before the period being billed. */
        _hasEarlierInvoice() {
            const ps = this.form.period_start;
            const months = this.billableMonths;
            if (!ps) return false;
            if (months.some(m => m.status === 'billed' && m.period_end < ps)) return true;
            return !!(this._latestPeriodEnd && this._latestPeriodEnd < ps);
        },
        /** Which notice the manual distance box needs:
         *   'lifetime' — estimate lease, no reading yet, earlier invoices billed
         *                estimates: the box takes ALL distance since lease start
         *   'first'    — no earlier reading: cover all unbilled driving
         *   'none'     — nothing to count from at all (server refuses)
         *   ''         — the normal "since last reading" case */
        distanceNotice() {
            const a        = this._distanceAnchor();
            const earlier  = this._hasEarlierInvoice();
            const estimate = this._leaseEstimatePerDay > 0;
            if (a.from === 'none') return 'none';
            if (a.from !== 'lease') return '';
            return (estimate && earlier) ? 'lifetime' : 'first';
        },
        distanceLabel() {
            const notice = this.distanceNotice();
            if (this.leaseMileageMode === 'samsara') return 'Distance driven this period';
            if (notice === 'lifetime') return 'Total distance since the lease started';
            return 'Distance driven (since last reading)';
        },
        distanceAnchorHint() {
            const a     = this._distanceAnchor();
            const start = this.fmtDist(a.value === null ? 0 : a.value);
            if (a.from === 'invoice') {
                return a.derived
                    ? 'Counted from ' + start + ' — the last reading plus the distance billed since, through ' + (a.inv || 'the previous invoice') + '.'
                    : 'Counted from ' + start + ' — the end reading of ' + (a.inv || 'the previous invoice') + '.';
            }
            if (a.from === 'lease')  return 'Counted from ' + start + ' — the lease\'s starting odometer.';
            if (a.from === 'edited') return 'Counted from ' + start + ' — the start reading entered under Odometer readings.';
            if (a.from === 'gps')    return 'Counted from ' + start + ' — the start reading fetched from Samsara.';
            return '';
        },
        /** Reading this invoice will store as its period-end odometer
         *  (start + distance) on a manual lease; null until a distance is typed. */
        distanceNewReading() {
            const start = this._distanceStart();
            const dist  = parseFloat(this.form.period_distance);
            if (isNaN(dist)) return null;
            return start + dist;
        },
        distanceCumulative() {
            const end = this.distanceNewReading();
            if (end === null) return null;
            if (this._leaseStartOdo === null || isNaN(this._leaseStartOdo)) return null;
            return end - this.fromKm(this._leaseStartOdo);
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
            const e = parseFloat(this.form.odometer_at_period_end_km);   // display unit
            if (isNaN(e)) return null;
            if (this._leaseStartOdo === null || isNaN(this._leaseStartOdo)) return null;
            // _leaseStartOdo is stored KM — bring it into the display unit
            // before subtracting, or a miles reading would be differenced
            // against a km baseline.
            const startInDisplay = this.fromKm(this._leaseStartOdo);
            if (startInDisplay === null) return null;
            return e - startInDisplay;
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
        /* ── S-ODO-UNIT conversion ──────────────────────────────────────
         * DISTANCE converts with 1 mi = 1.609344 km. Note this is the
         * OPPOSITE direction to a RATE conversion ($/km = $/mi × 0.621):
         * a rate is per-unit-distance, so it scales inversely. Getting
         * those two confused is what caused the historic 2.59× mileage
         * overcharge, so keep them strictly separate — nothing in this
         * file touches rates.
         *
         * The form fields hold values in the DISPLAY unit; km is what
         * gets stored (converted in submit()).
         */
        _MI_TO_KM: 1.609344,
        toKm(v)   { const n = parseFloat(v); if (isNaN(n)) return null;
                    return this.odoUnit === 'miles' ? n * this._MI_TO_KM : n; },
        fromKm(v) { const n = parseFloat(v); if (isNaN(n)) return null;
                    return this.odoUnit === 'miles' ? n / this._MI_TO_KM : n; },

        /** Switching units re-expresses whatever is already typed so the
         *  operator never loses input or silently changes the reading. */
        onOdoUnitChanged(ev) {
            const prev = (ev && ev.target && ev.target._ffPrevUnit) || this._odoUnitPrev || 'km';
            const next = this.odoUnit;
            if (prev === next) return;
            // An untouched auto-filled start is re-derived from the exact stored
            // km below instead of re-converting its 2dp display (1,120.00 mi →
            // 1,802.47 km when 1,802.46 is stored), so the hint and the payload
            // stay on the real reading in either unit.
            const startUntouched = this._odoStartAutoKm !== null
                && this.form.odometer_at_period_start_km === this._odoStartAutoDisplay;
            const factor = (prev === 'miles' && next === 'km') ? this._MI_TO_KM
                         : (prev === 'km' && next === 'miles') ? (1 / this._MI_TO_KM)
                         : 1;
            // S-INVOICE-DISTANCE-ENTRY: a distance converts with the same
            // factor as a reading (both are distances, never rates).
            ['odometer_at_period_start_km', 'odometer_at_period_end_km', 'period_distance'].forEach(k => {
                const n = parseFloat(this.form[k]);
                if (!isNaN(n)) this.form[k] = (n * factor).toFixed(2);
            });
            if (startUntouched) {
                this.form.odometer_at_period_start_km = this.fromKm(this._odoStartAutoKm).toFixed(2);
                this._odoStartAutoDisplay             = this.form.odometer_at_period_start_km;
            }
            this._odoUnitPrev = next;
        },
        _odoUnitPrev: 'km',

        fmtDist(v) {
            if (v === null || v === undefined || isNaN(v)) return '— ' + this.odoUnitLabel;
            const fmt = Number(v).toLocaleString('en-CA', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
            return fmt + ' ' + this.odoUnitLabel;
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
                // S-ODO-UNIT: Samsara always reports KM — display in the chosen unit.
                const km = this.fromKm(d.odometer_km).toFixed(2);
                if (target === 'start') {
                    this.form.odometer_at_period_start_km = km;
                    this.odoStartSource                    = 'gps';
                    this.odoStartAutoSource                = '';
                    this.distanceAnchorFrom                = 'gps';
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
            // Blank or not, the operator owns it now: period changes stop
            // re-filling it, and a blank one falls back server-side.
            this.distanceAnchorFrom = 'edited';
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
            // S-INVOICE-DISTANCE-ENTRY: the period-start reading follows the
            // period (last reading BEFORE it) unless the operator changed it.
            this._refreshStartAutoFill();
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
            // S-INVOICE-DISTANCE-ENTRY: check the mileage inputs of the ACTIVE
            // mode before posting (the inputs now carry name= so these — and
            // the server's 422s on the same keys — render under the field).
            if (this.leaseMileageMode !== 'off') {
                if (this.form.mileage_entry === 'distance') {
                    const v = this.form.period_distance;
                    const typed = v !== '' && v !== null;
                    const sv = this.form.odometer_at_period_start_km;
                    if (typed && (isNaN(parseFloat(v)) || parseFloat(v) < 0)) {
                        FF_Validate.field(f, 'period_distance_km', 'Distance driven must be zero or more.');
                        ok = false;
                    } else if (typed && this.leaseMileageMode === 'manual' && this.distanceNotice() === 'none') {
                        FF_Validate.field(f, 'period_distance_km', 'Nothing to count this distance from — enter the start reading under Odometer readings, or set the lease\'s starting odometer.');
                        ok = false;
                    } else if (typed && sv !== '' && sv !== null && (isNaN(parseFloat(sv)) || parseFloat(sv) < 0)) {
                        // The start input is hidden in this mode, so say where it is.
                        FF_Validate.banner(f, 'The start reading this distance counts from is not valid — fix it under Odometer readings.');
                        ok = false;
                    }
                } else if (this.periodDistance !== null && this.periodDistance < 0) {
                    FF_Validate.field(f, 'odometer_at_period_end_km', 'Ending odometer cannot be less than starting odometer.');
                    ok = false;
                }
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
            // S-INVOICE-DISTANCE-ENTRY: `period_distance` is the display-unit
            // input; the API key is period_distance_km (km). In "Distance
            // driven" mode the distance REPLACES the end reading (the API
            // refuses both). The start reading still goes along: on a manual
            // lease it is exactly what the hint says the distance counts from
            // (blank → the server counts from 0). A blank distance leaves the
            // payload as it always was with a blank end reading.
            delete payload.period_distance;
            delete payload.mileage_entry;
            if (this.leaseMileageMode !== 'off' && this.form.mileage_entry === 'distance') {
                delete payload.odometer_at_period_end_km;
                delete payload.odometer_source;
                delete payload.odometer_fetched_at;
                const dist = parseFloat(this.form.period_distance);
                if (!isNaN(dist)) {
                    // 4dp string: never a float in exponent form, and the
                    // server rounds to the stored 2dp.
                    payload.period_distance_km = this.toKm(dist).toFixed(4);
                }
            }
            // S-ODO-UNIT: the *_km columns and the billing engine are km-only.
            // Whatever unit the operator typed in, convert to km HERE — this is
            // the single boundary between display units and storage.
            ['odometer_at_period_start_km', 'odometer_at_period_end_km'].forEach(k => {
                if (payload[k] === '' || payload[k] === null || payload[k] === undefined) {
                    delete payload[k];
                } else if (k === 'odometer_at_period_start_km'
                           && this._odoStartAutoKm !== null
                           && payload[k] === this._odoStartAutoDisplay) {
                    // Untouched auto-fill: the stored km exactly (see _odoStartAutoKm).
                    payload[k] = this._odoStartAutoKm;
                } else {
                    payload[k] = this.toKm(payload[k]);
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
                    if (this._draft) this._draft.clear(true);   // S-FORM-DRAFT-ROLLOUT: wipe draft on confirmed save
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
