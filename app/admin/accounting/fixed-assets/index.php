<?php declare(strict_types=1);

/**
 * FleetForge — Fixed Assets Register
 *
 * @file        app/admin/accounting/fixed-assets/index.php
 * @description Asset register listing with KPI tiles (Total, Active, Disposed,
 *              Total NBV), filter toolbar (search, status, asset class), and a
 *              data table showing each asset's number, name, class, method,
 *              cost, accumulated depreciation, NBV, and status. A row click
 *              opens a detail modal showing the depreciation schedule, disposal
 *              record, and impairment history.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/accounting/fixed_assets/*
 *
 * @session     S029 — Fixed Assets module
 */

// dirname(__DIR__, 4): fixed-assets/ -> accounting/ -> admin/ -> app/ -> root
require_once realpath(dirname(__DIR__, 4) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('fixed_assets', 'view');

// ── KPI tiles — server-side for accuracy on initial load ───────
$totalAssets    = db_count("SELECT COUNT(*) FROM acc_fixed_assets");
$activeAssets   = db_count("SELECT COUNT(*) FROM acc_fixed_assets WHERE status = 'active'");
$disposedAssets = db_count("SELECT COUNT(*) FROM acc_fixed_assets WHERE status = 'disposed'");
$totalNbv       = db_row("SELECT SUM(net_book_value) AS nbv FROM acc_fixed_assets WHERE status IN ('active','impaired')")['nbv'] ?? '0.00';

$pageTitle = 'Fixed Assets';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ── Breadcrumb ──────────────────────────────────────────────── -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <a href="<?= base_url('accounting/dashboard') ?>">Accounting</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Fixed Assets</span>
</nav>

<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Fixed Assets Register</h1>
        <div class="text-secondary text-sm" style="margin-top:4px;">
            Track depreciable assets, their net book value, impairments and disposals.
        </div>
    </div>
    <div class="page-header-actions">
        <a href="<?= base_url('accounting/fixed-assets/payoff-report') ?>" class="btn btn-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="vertical-align:-2px;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
            Payoff Report
        </a>
        <?php if (can('fixed_assets', 'create')): ?>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-asset-create')">
            + New Asset
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- TILES-1: KPI tiles dispatch `ff-assets-filter` to set filterStatus below -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-assets-filter',{detail:{status:''}}))">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Assets</div>
        <div class="stat-value font-mono"><?= e((string) $totalAssets) ?></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-assets-filter',{detail:{status:'active'}}))">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e((string) $activeAssets) ?></div>
    </div>
    <div class="stat-card stat-card--amber" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-assets-filter',{detail:{status:'disposed'}}))">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Disposed</div>
        <div class="stat-value font-mono"><?= e((string) $disposedAssets) ?></div>
    </div>
    <!-- Total NBV drills to active+impaired subset -->
    <div class="stat-card" style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-assets-filter',{detail:{status:'active'}}))">
        <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">Total NBV (Active+Impaired)</div>
        <div class="stat-value font-mono">$<?= number_format((float) $totalNbv, 2) ?></div>
    </div>
</div>

<!-- ============================================================
     FIXED ASSETS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_FixedAssets()" x-init="init()"
     @open-asset-create.window="openCreate()"
     @ff-assets-filter.window="filterStatus = (filterStatus === $event.detail.status && filterStatus !== '') ? '' : $event.detail.status; load()">

    <!-- ── Filter toolbar (wrapped in card for visual containment) ── -->
    <div class="card" style="margin-bottom:20px;padding:14px 16px;">
        <div class="table-toolbar" style="margin-bottom:0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="text" class="form-control form-control-sm" placeholder="Search by name or asset number…"
                   x-model.debounce.300ms="search" @input="load()" style="min-width:280px;flex:1;max-width:380px;">

            <select class="form-select form-control-sm" x-model="filterStatus" @change="load()" style="min-width:140px;">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="impaired">Impaired</option>
                <option value="fully_depreciated">Fully Depreciated</option>
                <option value="disposed">Disposed</option>
            </select>

            <select class="form-select form-control-sm" x-model="filterClass" @change="load()" style="min-width:160px;">
                <option value="">All classes</option>
                <option value="fleet_equipment">Fleet Equipment</option>
                <option value="vehicles">Vehicles</option>
                <option value="office_equipment">Office Equipment</option>
                <option value="leasehold_improvements">Leasehold Improvements</option>
                <option value="land">Land</option>
                <option value="building">Building</option>
                <option value="other">Other</option>
            </select>

            <span class="text-secondary text-sm" style="margin-left:auto;" x-text="pagination.total + ' assets'"></span>
        </div>
    </div>

    <!-- ── Loading state ─────────────────────────────────────── -->
    <template x-if="loading">
        <div class="card"><div class="empty-state">Loading…</div></div>
    </template>

    <!-- ── Empty state ───────────────────────────────────────── -->
    <template x-if="!loading && assets.length === 0">
        <div class="card"><div class="empty-state">
            <p class="empty-state-title">No assets found</p>
            <p class="empty-state-text">Try clearing the filters above, or click "+ New Asset" to add one.</p>
        </div></div>
    </template>

    <!-- ── Asset table ───────────────────────────────────────── -->
    <template x-if="!loading && assets.length > 0">
        <div class="card" style="padding:0;">
            <div class="table-responsive">
<table class="data-table">
                <thead>
                    <tr>
                        <th>Asset #</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Method</th>
                        <th class="text-right">Cost</th>
                        <th class="text-right">Accum Depr</th>
                        <th class="text-right">NBV</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="a in assets" :key="a.id">
                        <tr>
                            <td class="font-mono" x-text="a.asset_number"></td>
                            <td><strong x-text="a.name"></strong>
                                <template x-if="a.equipment_unit_number">
                                    <div class="text-secondary text-xs" x-text="'Unit: ' + a.equipment_unit_number"></div>
                                </template>
                                <template x-if="parseInt(a.is_component) === 1">
                                    <span class="badge badge-blue" style="padding:1px 6px;font-size:0.625rem;margin-left:4px;" title="Component of a parent asset (ASPE 3061.18)">C</span>
                                </template>
                                <template x-if="(a.component_count || 0) > 0">
                                    <span class="badge badge-purple" style="padding:1px 6px;font-size:0.625rem;margin-left:4px;" :title="a.component_count + ' component(s) under this asset'">
                                        <span x-text="a.component_count"></span>×C
                                    </span>
                                </template>
                            </td>
                            <td x-text="formatClass(a.asset_class)"></td>
                            <td x-text="formatMethod(a.depreciation_method)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(a.acquisition_cost)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(a.accumulated_depreciation)"></td>
                            <td class="text-right font-mono" x-text="formatMoney(a.net_book_value)"></td>
                            <td><span class="badge badge-no-dot" :class="statusBadge(a.status)" x-text="formatStatus(a.status)"></span></td>
                            <td>
                                <button class="btn btn-secondary btn-xs" @click="openDetail(a.id)">View</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
</div>
        </div>
    </template>

    <!-- ── Pagination ────────────────────────────────────────── -->
    <div class="table-pagination" x-show="pagination.total_pages > 1" style="margin-top:16px;text-align:center;">
        <button class="btn btn-secondary btn-sm" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">‹ Prev</button>
        <span x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages" style="margin:0 12px;"></span>
        <button class="btn btn-secondary btn-sm" :disabled="!pagination.has_more" @click="goPage(pagination.page + 1)">Next ›</button>
    </div>

    <!-- ============================================================
         DETAIL MODAL — shows full asset info + schedule + payoff
         ============================================================ -->
    <div class="modal-backdrop" x-show="detailOpen" x-transition @click.self="detailOpen = false" style="display:none;">
        <div class="modal" style="max-width:1000px;width:96%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header">
                <h2 class="h5" x-text="detailAsset ? detailAsset.asset_number + ' — ' + detailAsset.name : ''"></h2>
                <button class="modal-close-btn" aria-label="Close" @click="detailOpen = false">×</button>
            </div>

            <!-- ── PAYOFF-1 tab bar ──────────────────────────────
                 WHY: The detail modal is now multi-tabbed (Details +
                 Payoff Analysis) so users can inspect both the
                 depreciation schedule and the operational payoff
                 numbers without leaving the context of a single row. -->
            <div class="tab-bar" x-show="!detailLoading && detailAsset" style="display:flex;gap:4px;padding:0 16px;border-bottom:1px solid var(--border-default);">
                <button type="button"
                        class="tab-button"
                        :class="{ 'tab-button-active': detailTab === 'details' }"
                        @click="detailTab = 'details'"
                        style="padding:10px 14px;background:transparent;border:none;border-bottom:2px solid transparent;color:var(--text-secondary);cursor:pointer;font-weight:500;"
                        :style="detailTab === 'details' ? 'border-bottom-color:var(--color-primary);color:var(--text-primary);' : ''">
                    Details
                </button>
                <button type="button"
                        class="tab-button"
                        :class="{ 'tab-button-active': detailTab === 'payoff' }"
                        @click="switchToPayoff()"
                        style="padding:10px 14px;background:transparent;border:none;border-bottom:2px solid transparent;color:var(--text-secondary);cursor:pointer;font-weight:500;"
                        :style="detailTab === 'payoff' ? 'border-bottom-color:var(--color-primary);color:var(--text-primary);' : ''">
                    Payoff Analysis
                </button>
            </div>

            <div class="modal-body">
                <template x-if="detailLoading">
                    <p>Loading…</p>
                </template>
                <template x-if="!detailLoading && detailAsset && detailTab === 'details'">
                    <div>
                        <!-- Two-column spec cards: Classification + Valuation -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">

                            <!-- ── Classification ── -->
                            <div class="card spec-card" style="margin-bottom:0;">
                                <div class="card-header">
                                    <div class="card-title">Classification</div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
<table class="spec-table">
                                        <tr>
                                            <td class="spec-label">Class</td>
                                            <td class="spec-value" x-text="formatClass(detailAsset.asset_class)"></td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Status</td>
                                            <td>
                                                <span class="badge badge-no-dot" :class="statusBadge(detailAsset.status)" x-text="formatStatus(detailAsset.status)"></span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Method</td>
                                            <td class="spec-value" x-text="formatMethod(detailAsset.depreciation_method)"></td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Acquired</td>
                                            <td class="spec-value font-mono" x-text="detailAsset.acquisition_date"></td>
                                        </tr>
                                        <template x-if="detailAsset.useful_life_years">
                                            <tr>
                                                <td class="spec-label">Useful Life</td>
                                                <td class="spec-value font-mono"><span x-text="detailAsset.useful_life_years"></span> years</td>
                                            </tr>
                                        </template>
                                        <template x-if="detailAsset.cra_cca_rate">
                                            <tr>
                                                <td class="spec-label">CCA Rate</td>
                                                <td class="spec-value font-mono" x-text="(parseFloat(detailAsset.cra_cca_rate) * 100).toFixed(0) + '%'"></td>
                                            </tr>
                                        </template>
                                        <template x-if="detailAsset.total_expected_units">
                                            <tr>
                                                <td class="spec-label">Total Units</td>
                                                <td class="spec-value font-mono" x-text="parseInt(detailAsset.total_expected_units).toLocaleString()"></td>
                                            </tr>
                                        </template>
                                    </table>
</div>
                                </div>
                            </div>

                            <!-- ── Valuation ── -->
                            <div class="card spec-card" style="margin-bottom:0;">
                                <div class="card-header">
                                    <div class="card-title">Valuation</div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
<table class="spec-table">
                                        <tr>
                                            <td class="spec-label">Cost</td>
                                            <td class="spec-value font-mono text-right" x-text="formatMoney(detailAsset.acquisition_cost)"></td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Salvage</td>
                                            <td class="spec-value font-mono text-right" x-text="formatMoney(detailAsset.salvage_value)"></td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Depreciable</td>
                                            <td class="spec-value font-mono text-right" x-text="formatMoney(detailAsset.depreciable_cost)"></td>
                                        </tr>
                                        <tr>
                                            <td class="spec-label">Accum Depr</td>
                                            <td class="spec-value font-mono text-right text-danger" x-text="'−' + formatMoney(detailAsset.accumulated_depreciation)"></td>
                                        </tr>
                                        <tr style="font-weight:600;background:var(--bg-surface-2);">
                                            <td class="spec-label">Net Book Value</td>
                                            <td class="spec-value font-mono text-right" x-text="formatMoney(detailAsset.net_book_value)"></td>
                                        </tr>
                                    </table>
</div>
                                </div>
                            </div>

                            <!-- ── Identification (full width if any field present) ── -->
                            <template x-if="detailAsset.serial_number || detailAsset.location || detailAsset.equipment_unit_number">
                                <div class="card spec-card" style="margin-bottom:0;grid-column:1/-1;">
                                    <div class="card-header">
                                        <div class="card-title">Identification</div>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
<table class="spec-table">
                                            <template x-if="detailAsset.serial_number">
                                                <tr>
                                                    <td class="spec-label">Serial Number</td>
                                                    <td class="spec-value font-mono" x-text="detailAsset.serial_number"></td>
                                                </tr>
                                            </template>
                                            <template x-if="detailAsset.location">
                                                <tr>
                                                    <td class="spec-label">Location</td>
                                                    <td class="spec-value" x-text="detailAsset.location"></td>
                                                </tr>
                                            </template>
                                            <template x-if="detailAsset.equipment_unit_number">
                                                <tr>
                                                    <td class="spec-label">Equipment Unit</td>
                                                    <td class="spec-value">
                                                        <span class="font-mono" x-text="detailAsset.equipment_unit_number"></span>
                                                    </td>
                                                </tr>
                                            </template>
                                        </table>
</div>
                                    </div>
                                </div>
                            </template>

                        </div>

                        <!-- Disposal record -->
                        <template x-if="detailDisposal">
                            <div class="alert alert-warning" style="margin-bottom:16px;">
                                <strong>Disposed:</strong>
                                <span x-text="detailDisposal.disposal_date"></span> —
                                <span x-text="detailDisposal.disposal_type"></span> for
                                <span class="font-mono" x-text="formatMoney(detailDisposal.proceeds)"></span>
                                (NBV: <span class="font-mono" x-text="formatMoney(detailDisposal.net_book_value_at_disposal)"></span>,
                                 Gain/Loss: <span class="font-mono" x-text="formatMoney(detailDisposal.gain_loss)"></span>)
                                <template x-if="detailDisposal.buyer_name">
                                    <div class="text-sm" style="margin-top:4px;">Buyer: <span x-text="detailDisposal.buyer_name"></span></div>
                                </template>
                            </div>
                        </template>

                        <!-- Impairment history -->
                        <template x-if="detailImpairments && detailImpairments.length > 0">
                            <div class="card spec-card" style="margin-bottom:16px;">
                                <div class="card-header">
                                    <div class="card-title">Impairment History</div>
                                </div>
                                <div class="card-body" style="padding:12px 16px;">
                                    <ul style="margin:0;padding-left:18px;">
                                        <template x-for="i in detailImpairments" :key="i.id">
                                            <li class="text-sm" style="margin-bottom:4px;">
                                                <span class="font-mono" x-text="i.impairment_date"></span> —
                                                <span class="font-mono text-danger" x-text="formatMoney(i.impairment_loss)"></span> loss
                                                <span class="text-secondary">(<span x-text="i.reason"></span>)</span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                        </template>

                        <!-- Depreciation schedule -->
                        <div class="section-header">
                            <h3 class="section-title">Depreciation Forecast</h3>
                            <span class="section-hint" x-show="detailSchedule.length > 0">Period-by-period schedule</span>
                        </div>
                        <template x-if="detailSchedule.length === 0">
                            <p class="text-secondary text-sm">No forecast available (asset is impaired, disposed, or uses a method with no fixed schedule).</p>
                        </template>
                        <template x-if="detailSchedule.length > 0">
                            <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border-default);border-radius:6px;">
                                <div class="table-responsive">
<table class="data-table" style="font-size:0.8125rem;">
                                    <thead>
                                        <tr>
                                            <th>Period</th>
                                            <th class="text-right">Opening NBV</th>
                                            <th class="text-right">Depreciation</th>
                                            <th class="text-right">Closing NBV</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(row, idx) in detailSchedule.slice(0, 60)" :key="idx">
                                            <tr>
                                                <td x-text="row.period_name"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(row.opening_nbv)"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(row.amount)"></td>
                                                <td class="text-right font-mono" x-text="formatMoney(row.closing_nbv)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
</div>
                                <template x-if="detailSchedule.length > 60">
                                    <p class="text-secondary text-xs" style="padding:8px;text-align:center;">Showing first 60 of <span x-text="detailSchedule.length"></span> periods</p>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- ============================================================
                     PAYOFF-1 — PAYOFF ANALYSIS TAB
                     ============================================================
                     Shows total invested, net revenue to date, still to recover,
                     a progress bar, three projection scenarios, an area chart of
                     monthly cumulative net revenue, and a manual override section.
                     All numbers come from /api/v1/accounting/fixed_assets/payoff.php
                     which uses bcmath throughout (D16). -->
                <template x-if="!detailLoading && detailAsset && detailTab === 'payoff'">
                    <div>
                        <!-- Not-linked warning state -->
                        <template x-if="payoffError">
                            <div class="alert alert-warning" x-text="payoffError"></div>
                        </template>

                        <!-- Loading state -->
                        <template x-if="payoffLoading && !payoff">
                            <p class="text-secondary">Calculating payoff…</p>
                        </template>

                        <template x-if="!payoffLoading && payoff && !payoffError">
                            <div>
                                <!-- Summary cards -->
                                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;">
                                    <div class="stat-card" style="padding:14px;">
                                        <div class="stat-label">Total Invested</div>
                                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.acquisition.adjusted_target)"></div>
                                        <div class="text-secondary text-xs" style="margin-top:4px;">
                                            Purchase <span class="font-mono" x-text="formatMoney(payoff.acquisition.purchase_cost)"></span>
                                            <template x-if="parseFloat(payoff.acquisition.extra_costs) > 0">
                                                <span> + extras <span class="font-mono" x-text="formatMoney(payoff.acquisition.extra_costs)"></span></span>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-card--green" style="padding:14px;">
                                        <div class="stat-label">Net Revenue To Date</div>
                                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.totals.net_revenue_to_date)"></div>
                                        <div class="text-secondary text-xs" style="margin-top:4px;">
                                            <span x-text="payoff.totals.months_since_acquisition + ' months'"></span>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-card--amber" style="padding:14px;">
                                        <div class="stat-label">Still to Recover</div>
                                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.totals.still_to_recover)"></div>
                                        <div class="text-secondary text-xs" style="margin-top:4px;">
                                            <span x-text="'Avg ' + formatMoney(payoff.selected.monthly_net) + '/mo'"></span>
                                        </div>
                                    </div>
                                    <div class="stat-card stat-card--blue" style="padding:14px;">
                                        <div class="stat-label">Progress</div>
                                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="(parseFloat(payoff.totals.progress_pct) >= 0 ? payoff.totals.progress_pct : '0.00') + '%'"></div>
                                        <template x-if="payoff.selected.date">
                                            <div class="text-secondary text-xs" style="margin-top:4px;">
                                                Paid off by <span x-text="payoff.selected.date"></span>
                                            </div>
                                        </template>
                                        <template x-if="!payoff.selected.date && parseFloat(payoff.totals.still_to_recover) <= 0">
                                            <div class="text-success text-xs" style="margin-top:4px;"><strong>Fully paid!</strong></div>
                                        </template>
                                    </div>
                                </div>

                                <!-- Progress bar -->
                                <div style="margin-bottom:20px;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                        <span class="text-sm text-secondary">Payoff progress</span>
                                        <span class="text-sm font-mono" x-text="payoff.totals.progress_pct + '%'"></span>
                                    </div>
                                    <div style="width:100%;height:12px;background:var(--bg-tertiary);border-radius:6px;overflow:hidden;border:1px solid var(--border-default);">
                                        <div :style="progressBarStyle()"></div>
                                    </div>
                                </div>

                                <!-- Scenarios -->
                                <h3 class="h6" style="margin:0 0 10px 0;">Projection Scenarios</h3>
                                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                                    <div class="card"
                                         @click="payoffPeriod = 12; reloadPayoff()"
                                         :style="(payoffPeriod === 12 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Conservative</div>
                                        <div class="text-xs text-secondary" style="margin-bottom:6px;">12-month average</div>
                                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.conservative.monthly_net) + '/mo'"></div>
                                        <div class="text-sm" style="margin-top:6px;">
                                            <template x-if="payoff.scenarios.conservative.months !== null">
                                                <span>Paid off in <strong x-text="payoff.scenarios.conservative.months"></strong> months</span>
                                            </template>
                                            <template x-if="payoff.scenarios.conservative.months === null">
                                                <span class="text-secondary">— no projection —</span>
                                            </template>
                                        </div>
                                        <template x-if="payoff.scenarios.conservative.date">
                                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.conservative.date"></div>
                                        </template>
                                    </div>
                                    <div class="card"
                                         @click="payoffPeriod = 6; reloadPayoff()"
                                         :style="(payoffPeriod === 6 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Current</div>
                                        <div class="text-xs text-secondary" style="margin-bottom:6px;">6-month average</div>
                                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.current.monthly_net) + '/mo'"></div>
                                        <div class="text-sm" style="margin-top:6px;">
                                            <template x-if="payoff.scenarios.current.months !== null">
                                                <span>Paid off in <strong x-text="payoff.scenarios.current.months"></strong> months</span>
                                            </template>
                                            <template x-if="payoff.scenarios.current.months === null">
                                                <span class="text-secondary">— no projection —</span>
                                            </template>
                                        </div>
                                        <template x-if="payoff.scenarios.current.date">
                                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.current.date"></div>
                                        </template>
                                    </div>
                                    <div class="card"
                                         @click="payoffPeriod = 3; reloadPayoff()"
                                         :style="(payoffPeriod === 3 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Optimistic</div>
                                        <div class="text-xs text-secondary" style="margin-bottom:6px;">3-month average</div>
                                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.optimistic.monthly_net) + '/mo'"></div>
                                        <div class="text-sm" style="margin-top:6px;">
                                            <template x-if="payoff.scenarios.optimistic.months !== null">
                                                <span>Paid off in <strong x-text="payoff.scenarios.optimistic.months"></strong> months</span>
                                            </template>
                                            <template x-if="payoff.scenarios.optimistic.months === null">
                                                <span class="text-secondary">— no projection —</span>
                                            </template>
                                        </div>
                                        <template x-if="payoff.scenarios.optimistic.date">
                                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.optimistic.date"></div>
                                        </template>
                                    </div>
                                </div>

                                <!-- Chart -->
                                <h3 class="h6" style="margin:0 0 10px 0;">Cumulative Net Revenue (Last 14 Months)</h3>
                                <div id="payoff-chart" style="min-height:280px;margin-bottom:20px;"></div>

                                <!-- Revenue / expense breakdown table -->
                                <h3 class="h6" style="margin:18px 0 8px 0;">Breakdown To Date</h3>
                                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px 20px;font-size:0.875rem;margin-bottom:18px;">
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">Total Revenue</span>
                                        <span class="font-mono text-success" x-text="formatMoney(payoff.totals.total_revenue)"></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">Monthly Fixed Costs</span>
                                        <span class="font-mono" x-text="formatMoney(payoff.totals.monthly_fixed) + '/mo'"></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">− Maintenance</span>
                                        <span class="font-mono text-danger" x-text="'−' + formatMoney(payoff.totals.total_maintenance)"></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">− Fixed Paid</span>
                                        <span class="font-mono text-danger" x-text="'−' + formatMoney(payoff.totals.total_fixed_paid)"></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">− Damage</span>
                                        <span class="font-mono text-danger" x-text="'−' + formatMoney(payoff.totals.total_damage)"></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;">
                                        <span class="text-secondary">− Financing Paid</span>
                                        <span class="font-mono text-danger" x-text="'−' + formatMoney(payoff.totals.total_financing_paid)"></span>
                                    </div>
                                </div>

                                <!-- Manual override section -->
                                <div class="card" style="padding:14px;border:1px dashed var(--border-default);">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                        <h3 class="h6" style="margin:0;">Manual Override</h3>
                                        <template x-if="payoff.custom_projection">
                                            <span class="badge badge-blue">Custom</span>
                                        </template>
                                    </div>
                                    <p class="text-secondary text-xs" style="margin:0 0 10px 0;">
                                        Enter your own projected monthly net revenue and any one-time upcoming costs to see a custom payoff date.
                                    </p>
                                    <div style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;">
                                        <div>
                                            <label class="text-xs text-secondary">Custom Monthly Net ($)</label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                                                   x-model="customMonthly" placeholder="e.g. 4000.00">
                                        </div>
                                        <div>
                                            <label class="text-xs text-secondary">Extra One-Time Costs ($)</label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                                                   x-model="customExtraCosts" placeholder="e.g. 2500.00">
                                        </div>
                                        <button class="btn btn-primary btn-sm" @click="applyCustom()" :disabled="payoffLoading">Apply</button>
                                        <button class="btn btn-secondary btn-sm" @click="resetCustom()" :disabled="payoffLoading">Reset</button>
                                    </div>
                                    <template x-if="payoff.custom_projection">
                                        <div style="margin-top:12px;padding:10px;background:var(--bg-tertiary);border-radius:6px;">
                                            <div class="text-sm">
                                                At <strong class="font-mono" x-text="formatMoney(payoff.custom_projection.monthly_net) + '/mo'"></strong>,
                                                <template x-if="payoff.custom_projection.months !== null">
                                                    <span>paid off in <strong x-text="payoff.custom_projection.months"></strong> months
                                                    (<span x-text="payoff.custom_projection.date"></span>).</span>
                                                </template>
                                                <template x-if="payoff.custom_projection.months === null">
                                                    <span class="text-secondary">— already fully paid or nothing to project —</span>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div class="modal-footer">
                <?php if (can('fixed_assets', 'edit')): ?>
                <button class="btn btn-secondary btn-sm"
                        x-show="detailAsset && detailAsset.status !== 'disposed'"
                        @click="openEdit()">Edit</button>
                <button class="btn btn-warning btn-sm"
                        x-show="detailAsset && detailAsset.status === 'active'"
                        @click="openDispose()">Dispose Asset</button>
                <button class="btn btn-warning btn-sm"
                        x-show="detailAsset && detailAsset.status === 'active'"
                        @click="openImpair()">Impair Asset</button>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm" @click="detailOpen = false">Close</button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         DISPOSAL MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="disposeOpen" x-transition @click.self="disposeOpen = false" style="display:none;">
        <div class="modal" style="max-width:520px;width:95%;">
            <div class="modal-header"><h2 class="h5">Dispose Asset</h2><button class="modal-close-btn" aria-label="Close" @click="disposeOpen = false">×</button></div>
            <div class="modal-body">
                <p class="text-secondary text-sm" x-show="detailAsset" x-text="'Disposing ' + (detailAsset?.asset_number ?? '') + ' — ' + (detailAsset?.name ?? '')"></p>

                <!-- ── VALID-2: form-level error banner ── -->
                <div class="form-error-banner" x-show="disposeFormError" x-text="disposeFormError" x-cloak></div>

                <div class="form-group">
                    <label>Disposal Date</label>
                    <input type="date" class="form-control form-control-sm"
                           :class="disposeErrors.disposal_date ? 'is-invalid' : ''"
                           x-model="disposeForm.disposal_date">
                    <div class="field-error" x-show="disposeErrors.disposal_date" x-text="disposeErrors.disposal_date" x-cloak></div>
                </div>
                <div class="form-group"><label>Disposal Type</label>
                    <select class="form-select form-control-sm"
                            :class="disposeErrors.disposal_type ? 'is-invalid' : ''"
                            x-model="disposeForm.disposal_type">
                        <option value="sale">Sale</option>
                        <option value="scrap">Scrap</option>
                        <option value="donation">Donation</option>
                        <option value="trade_in">Trade-In</option>
                    </select>
                    <div class="field-error" x-show="disposeErrors.disposal_type" x-text="disposeErrors.disposal_type" x-cloak></div>
                </div>
                <div class="form-group">
                    <label>Proceeds ($)</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                           :class="disposeErrors.proceeds ? 'is-invalid' : ''"
                           x-model="disposeForm.proceeds">
                    <div class="field-error" x-show="disposeErrors.proceeds" x-text="disposeErrors.proceeds" x-cloak></div>
                </div>
                <div class="form-group"><label>Buyer Name (optional)</label><input type="text" class="form-control form-control-sm" x-model="disposeForm.buyer_name"></div>
                <div class="form-group"><label>Notes</label><textarea class="form-control form-control-sm" rows="2" x-model="disposeForm.notes"></textarea></div>
                <p class="text-warning text-sm">Manager / super_admin only. This will post a JE.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="disposeOpen = false">Cancel</button>
                <button class="btn btn-danger btn-sm" @click="submitDispose()" :disabled="disposeBusy">
                    <span x-show="!disposeBusy">Dispose Asset</span><span x-show="disposeBusy">Posting…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         IMPAIRMENT MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="impairOpen" x-transition @click.self="impairOpen = false" style="display:none;">
        <div class="modal" style="max-width:520px;width:95%;">
            <div class="modal-header"><h2 class="h5">Impair Asset</h2><button class="modal-close-btn" aria-label="Close" @click="impairOpen = false">×</button></div>
            <div class="modal-body">
                <p class="text-secondary text-sm" x-show="detailAsset" x-text="'Impairing ' + (detailAsset?.asset_number ?? '') + ' — ' + (detailAsset?.name ?? '')"></p>

                <!-- ── VALID-2: form-level error banner ── -->
                <div class="form-error-banner" x-show="impairFormError" x-text="impairFormError" x-cloak></div>

                <div class="form-group">
                    <label>Impairment Date</label>
                    <input type="date" class="form-control form-control-sm"
                           :class="impairErrors.impairment_date ? 'is-invalid' : ''"
                           x-model="impairForm.impairment_date">
                    <div class="field-error" x-show="impairErrors.impairment_date" x-text="impairErrors.impairment_date" x-cloak></div>
                </div>
                <div class="form-group">
                    <label>Impairment Loss ($)</label>
                    <input type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                           :class="impairErrors.impairment_loss ? 'is-invalid' : ''"
                           x-model="impairForm.impairment_loss">
                    <div class="field-error" x-show="impairErrors.impairment_loss" x-text="impairErrors.impairment_loss" x-cloak></div>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <textarea class="form-control form-control-sm" rows="3"
                              :class="impairErrors.reason ? 'is-invalid' : ''"
                              x-model="impairForm.reason"
                              placeholder="Required — explain the impairment trigger"></textarea>
                    <div class="field-error" x-show="impairErrors.reason" x-text="impairErrors.reason" x-cloak></div>
                </div>
                <p class="text-warning text-sm">Manager / super_admin only. This will post a JE and set status to "impaired".</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="impairOpen = false">Cancel</button>
                <button class="btn btn-danger btn-sm" @click="submitImpair()" :disabled="impairBusy">
                    <span x-show="!impairBusy">Record Impairment</span><span x-show="impairBusy">Posting…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         CREATE MODAL
         ============================================================ -->
    <div class="modal-backdrop" x-show="createOpen" x-transition @click.self="createOpen = false" style="display:none;">
        <div class="modal" style="max-width:760px;width:96%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">New Fixed Asset</h2><button class="modal-close-btn" aria-label="Close" @click="createOpen = false">×</button></div>
            <div class="modal-body">

                <!-- ── VALID-2: form-level error banner ── -->
                <div class="form-error-banner" x-show="createFormError" x-text="createFormError" x-cloak></div>

                <!-- ── Core Details ─────────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Core Details</h3>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Name *</label>
                        <input type="text" class="form-control form-control-sm"
                               :class="createErrors.name ? 'is-invalid' : ''"
                               x-model="createForm.name">
                        <div class="field-error" x-show="createErrors.name" x-text="createErrors.name" x-cloak></div>
                    </div>
                    <div class="form-group"><label>Asset Class *</label>
                        <select class="form-select form-control-sm"
                                :class="createErrors.asset_class ? 'is-invalid' : ''"
                                x-model="createForm.asset_class">
                            <option value="fleet_equipment">Fleet Equipment</option>
                            <option value="vehicles">Vehicles</option>
                            <option value="office_equipment">Office Equipment</option>
                            <option value="leasehold_improvements">Leasehold Improvements</option>
                            <option value="land">Land</option>
                            <option value="building">Building</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="field-error" x-show="createErrors.asset_class" x-text="createErrors.asset_class" x-cloak></div>
                    </div>
                    <div class="form-group">
                        <label>Acquisition Date *</label>
                        <input type="date" class="form-control form-control-sm"
                               :class="createErrors.acquisition_date ? 'is-invalid' : ''"
                               x-model="createForm.acquisition_date">
                        <div class="field-error" x-show="createErrors.acquisition_date" x-text="createErrors.acquisition_date" x-cloak></div>
                    </div>
                    <div class="form-group">
                        <label>Acquisition Cost ($) *</label>
                        <input type="number" step="0.01" min="0.01" class="form-control form-control-sm"
                               :class="createErrors.acquisition_cost ? 'is-invalid' : ''"
                               x-model="createForm.acquisition_cost">
                        <div class="field-error" x-show="createErrors.acquisition_cost" x-text="createErrors.acquisition_cost" x-cloak></div>
                    </div>
                    <div class="form-group">
                        <label>Salvage Value ($)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                               :class="createErrors.salvage_value ? 'is-invalid' : ''"
                               x-model="createForm.salvage_value">
                        <div class="field-error" x-show="createErrors.salvage_value" x-text="createErrors.salvage_value" x-cloak></div>
                    </div>
                </div>

                <!-- ── Depreciation ─────────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Depreciation</h3>
                </div>
                <div class="form-group"><label>Method *</label>
                    <select class="form-select form-control-sm"
                            :class="createErrors.depreciation_method ? 'is-invalid' : ''"
                            x-model="createForm.depreciation_method">
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance (CRA CCA)</option>
                        <option value="units_of_production">Units of Production</option>
                        <option value="none">None (no depreciation)</option>
                    </select>
                    <div class="field-error" x-show="createErrors.depreciation_method" x-text="createErrors.depreciation_method" x-cloak></div>
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'straight_line'">
                    <label>Useful Life (years) *</label>
                    <input type="number" step="0.5" min="0.5" class="form-control form-control-sm"
                           :class="createErrors.useful_life_years ? 'is-invalid' : ''"
                           x-model="createForm.useful_life_years">
                    <div class="field-error" x-show="createErrors.useful_life_years" x-text="createErrors.useful_life_years" x-cloak></div>
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'declining_balance'">
                    <label>CRA CCA Rate (e.g. 0.30) *</label>
                    <input type="number" step="0.01" min="0.01" max="1" class="form-control form-control-sm"
                           :class="createErrors.cra_cca_rate ? 'is-invalid' : ''"
                           x-model="createForm.cra_cca_rate">
                    <div class="field-error" x-show="createErrors.cra_cca_rate" x-text="createErrors.cra_cca_rate" x-cloak></div>
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'units_of_production'">
                    <label>Total Expected Units *</label>
                    <input type="number" step="1" min="1" class="form-control form-control-sm"
                           :class="createErrors.total_expected_units ? 'is-invalid' : ''"
                           x-model="createForm.total_expected_units">
                    <div class="field-error" x-show="createErrors.total_expected_units" x-text="createErrors.total_expected_units" x-cloak></div>
                </div>

                <!-- ── CCA Schedule 8 (S-ACCT-CCA-1) ──────────────── -->
                <div class="section-header">
                    <h3 class="section-title">CCA — Capital Cost Allowance</h3>
                    <span class="section-hint">For T2 Schedule 8 (optional — can be assigned later)</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 14px;">
                    <div class="form-group">
                        <label>CCA Class</label>
                        <select class="form-control form-control-sm" x-model="createForm.cca_class_id">
                            <option value="">— Not assigned —</option>
                            <template x-for="c in ccaClasses" :key="c.id">
                                <option :value="c.id" x-text="'Class ' + c.class_number + ' — ' + (parseFloat(c.rate) * 100).toFixed(0) + '% ' + c.description"></option>
                            </template>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Available for Use Date</label>
                        <input type="date" class="form-control form-control-sm" x-model="createForm.available_for_use_date">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;cursor:pointer;">
                            <input type="checkbox" x-model="createForm.is_aiip_eligible">
                            AIIP eligible (default yes)
                        </label>
                    </div>
                </div>
                <p style="font-size:0.75rem;color:var(--text-secondary);margin:4px 0 12px;">
                    GVWR &gt; 11,788 kg (≈ 25,990 lbs) typically belongs in Class 16 (40% DB) or Class 55 (ZEV equivalent). Server-side validator surfaces a non-blocking warning if the chosen class doesn't match the equipment unit's weight.
                </p>

                <!-- ── GL Accounts ──────────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">GL Accounts</h3>
                    <span class="section-hint">Required for journal entries</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 14px;">
                    <?php
                    // ── Asset GL Account picker ─────────────────────────
                    $pickerId       = 'create_asset_account_id';
                    $pickerLabel    = 'Asset Acct';
                    $pickerRequired = true;
                    $pickerPlaceholder = 'Search code or name…';
                    $pickerLabelHint = 'e.g. code 1210 — Fleet Assets';
                    $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'createForm.asset_account_id'
}
JS;
                    include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                    ?>
                    <?php
                    $pickerId       = 'create_accum_depr_account_id';
                    $pickerLabel    = 'Accum Depr Acct';
                    $pickerPlaceholder = 'Search code or name…';
                    $pickerLabelHint = 'e.g. code 1220 — Accumulated Depr';
                    $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'createForm.accum_depr_account_id'
}
JS;
                    include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                    ?>
                    <?php
                    $pickerId       = 'create_depr_expense_account_id';
                    $pickerLabel    = 'Depr Expense Acct';
                    $pickerPlaceholder = 'Search code or name…';
                    $pickerLabelHint = 'e.g. code 5010 — Depreciation Expense';
                    $pickerConfig = <<<JS
{
    endpoint: '/api/v1/accounting/accounts/index.php',
    extraParams: { flat: 1, active: 1 },
    format: r => (r.code ? r.code + ' — ' : '') + (r.name || ''),
    initialId: '',
    targetPath: 'createForm.depr_expense_account_id'
}
JS;
                    include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                    ?>
                </div>

                <!-- ── Identification ───────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Identification</h3>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">
                    <?php
                    // ── Equipment Unit picker ───────────────────────────
                    $pickerId       = 'create_equipment_unit_id';
                    $pickerLabel    = 'Equipment Unit';
                    $pickerRequired = false;
                    $pickerPlaceholder = 'Search unit # or VIN…';
                    $pickerLabelHint = 'Enables Payoff Analysis';
                    $pickerConfig = <<<JS
{
    endpoint: '/api/v1/equipment/units/index.php',
    extraParams: { per_page: 25 },
    format: r => (r.unit_number || '#'+r.id) + (r.vin ? ' — VIN ' + r.vin.slice(-6) : ''),
    initialId: '',
    targetPath: 'createForm.equipment_unit_id'
}
JS;
                    include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                    ?>
                    <div class="form-group"><label>Serial Number</label><input type="text" class="form-control form-control-sm" x-model="createForm.serial_number"></div>
                    <div class="form-group" style="grid-column:1/-1;"><label>Location</label><input type="text" class="form-control form-control-sm" x-model="createForm.location"></div>
                </div>

                <!-- ── Acquisition Details (PAYOFF-1) ───────────── -->
                <div class="section-header">
                    <h3 class="section-title">Acquisition Details</h3>
                    <span class="section-hint">For payoff calculator</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0 14px;">
                    <div class="form-group"><label>GST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.purchase_tax_gst"></div>
                    <div class="form-group"><label>PST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.purchase_tax_pst"></div>
                    <div class="form-group"><label>Delivery Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.delivery_cost"></div>
                    <div class="form-group"><label>Setup Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.setup_cost"></div>
                </div>

                <!-- ── Financing (PAYOFF-1) ─────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Financing</h3>
                    <span class="section-hint">Optional</span>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" x-model="createForm.is_financed"> This asset is financed
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 14px;" x-show="createForm.is_financed">
                    <div class="form-group"><label>Monthly Payment ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.financing_monthly_payment"></div>
                    <div class="form-group"><label>Interest Rate</label><input type="number" step="0.0001" min="0" max="1" class="form-control form-control-sm" x-model="createForm.financing_interest_rate" placeholder="e.g. 0.075"></div>
                    <div class="form-group"><label>Remaining Months</label><input type="number" step="1" min="0" class="form-control form-control-sm" x-model="createForm.financing_remaining_months"></div>
                </div>

                <!-- ── Monthly Fixed Costs (PAYOFF-1) ───────────── -->
                <div class="section-header">
                    <h3 class="section-title">Monthly Fixed Costs</h3>
                    <span class="section-hint">Reduce net revenue every month</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 14px;">
                    <div class="form-group"><label>Insurance ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.monthly_insurance_cost"></div>
                    <div class="form-group"><label>Licensing ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.monthly_licensing_cost"></div>
                    <div class="form-group"><label>Registration ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.monthly_registration_cost"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="createOpen = false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="submitCreate()" :disabled="createBusy">
                    <span x-show="!createBusy">Create Asset</span><span x-show="createBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         EDIT MODAL — PAYOFF-1 addition
         ============================================================
         WHY: Before PAYOFF-1 there was no Edit modal at all (the
         original asset register was view-only after creation). The
         new payoff fields need to be editable after the fact, so we
         add a full edit form that posts to the update.php endpoint
         with the optimistic-lock updated_at field. -->
    <div class="modal-backdrop" x-show="editOpen" x-transition @click.self="editOpen = false" style="display:none;">
        <div class="modal" style="max-width:760px;width:96%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header">
                <h2 class="h5">Edit Fixed Asset</h2>
                <button class="modal-close-btn" aria-label="Close" @click="editOpen = false">×</button>
            </div>
            <div class="modal-body">

                <!-- ── VALID-2: form-level error banner ── -->
                <div class="form-error-banner" x-show="editFormError" x-text="editFormError" x-cloak></div>

                <!-- ── Core Details ─────────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Core Details</h3>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Name *</label>
                        <input type="text" class="form-control form-control-sm"
                               :class="editErrors.name ? 'is-invalid' : ''"
                               x-model="editForm.name">
                        <div class="field-error" x-show="editErrors.name" x-text="editErrors.name" x-cloak></div>
                    </div>
                    <div class="form-group"><label>Asset Class</label>
                        <select class="form-select form-control-sm"
                                :class="editErrors.asset_class ? 'is-invalid' : ''"
                                x-model="editForm.asset_class">
                            <option value="fleet_equipment">Fleet Equipment</option>
                            <option value="vehicles">Vehicles</option>
                            <option value="office_equipment">Office Equipment</option>
                            <option value="leasehold_improvements">Leasehold Improvements</option>
                            <option value="land">Land</option>
                            <option value="building">Building</option>
                            <option value="other">Other</option>
                        </select>
                        <div class="field-error" x-show="editErrors.asset_class" x-text="editErrors.asset_class" x-cloak></div>
                    </div>
                    <div class="form-group"><label>Location</label><input type="text" class="form-control form-control-sm" x-model="editForm.location"></div>
                    <div class="form-group" style="grid-column:1/-1;"><label>Description</label><textarea class="form-control form-control-sm" rows="2" x-model="editForm.description"></textarea></div>
                    <div class="form-group" style="grid-column:1/-1;"><label>Notes</label><textarea class="form-control form-control-sm" rows="2" x-model="editForm.notes"></textarea></div>
                </div>

                <!-- ── Identification ───────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Identification</h3>
                    <span class="section-hint">Link a unit to enable Payoff Analysis</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">
                    <?php
                    $pickerId       = 'edit_equipment_unit_id';
                    $pickerLabel    = 'Equipment Unit';
                    $pickerRequired = false;
                    $pickerPlaceholder = 'Search unit # or VIN…';
                    $pickerLabelHint = 'Link a unit to enable Payoff Analysis';
                    $pickerConfig = <<<JS
{
    endpoint: '/api/v1/equipment/units/index.php',
    extraParams: { per_page: 25 },
    format: r => (r.unit_number || '#'+r.id) + (r.vin ? ' — VIN ' + r.vin.slice(-6) : ''),
    initialId: '',
    targetPath: 'editForm.equipment_unit_id'
}
JS;
                    include __DIR__ . '/../../../../includes/partials/pickers/lookup_picker.php';
                    ?>
                    <div class="form-group"><label>Serial Number</label><input type="text" class="form-control form-control-sm" x-model="editForm.serial_number"></div>
                </div>

                <!-- ── Acquisition Details ──────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Acquisition Details</h3>
                    <span class="section-hint">For payoff calculator</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0 14px;">
                    <div class="form-group"><label>GST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.purchase_tax_gst"></div>
                    <div class="form-group"><label>PST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.purchase_tax_pst"></div>
                    <div class="form-group"><label>Delivery Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.delivery_cost"></div>
                    <div class="form-group"><label>Setup Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.setup_cost"></div>
                </div>

                <!-- ── Financing ────────────────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Financing</h3>
                    <span class="section-hint">Optional</span>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" x-model="editForm.is_financed"> This asset is financed
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 14px;" x-show="editForm.is_financed">
                    <div class="form-group"><label>Monthly Payment ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.financing_monthly_payment"></div>
                    <div class="form-group"><label>Interest Rate</label><input type="number" step="0.0001" min="0" max="1" class="form-control form-control-sm" x-model="editForm.financing_interest_rate" placeholder="e.g. 0.075"></div>
                    <div class="form-group"><label>Remaining Months</label><input type="number" step="1" min="0" class="form-control form-control-sm" x-model="editForm.financing_remaining_months"></div>
                </div>

                <!-- ── Monthly Fixed Costs ──────────────────────── -->
                <div class="section-header">
                    <h3 class="section-title">Monthly Fixed Costs</h3>
                    <span class="section-hint">Reduce net revenue every month</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 14px;">
                    <div class="form-group"><label>Insurance ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.monthly_insurance_cost"></div>
                    <div class="form-group"><label>Licensing ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.monthly_licensing_cost"></div>
                    <div class="form-group"><label>Registration ($/mo)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.monthly_registration_cost"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="editOpen = false">Cancel</button>
                <button class="btn btn-primary btn-sm" @click="submitEdit()" :disabled="editBusy">
                    <span x-show="!editBusy">Save Changes</span><span x-show="editBusy">Saving…</span>
                </button>
            </div>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_FixedAssets() {
    return {
        // ── Filters / state ────────────────────────────────────
        assets: [],
        loading: true,
        search: '',
        filterStatus: '',
        filterClass: '',
        pagination: { total: 0, page: 1, per_page: 25, total_pages: 0, has_more: false },

        // ── Detail ─────────────────────────────────────────────
        detailOpen: false,
        detailLoading: false,
        detailAsset: null,
        detailSchedule: [],
        detailDisposal: null,
        detailImpairments: [],
        detailTab: 'details',  // PAYOFF-1: 'details' | 'payoff'

        // ── PAYOFF-1: Payoff analysis state ────────────────────
        payoff: null,
        payoffLoading: false,
        payoffError: null,
        payoffPeriod: 6,              // 3 | 6 | 12 scenario selector
        payoffChart: null,            // ApexCharts instance
        customMonthly: '',            // manual override input
        customExtraCosts: '',         // one-time upcoming costs input

        // ── Modals ─────────────────────────────────────────────
        disposeOpen: false,
        disposeBusy: false,
        disposeForm: { disposal_date: '', disposal_type: 'sale', proceeds: '', buyer_name: '', notes: '' },
        disposeErrors: {},
        disposeFormError: '',

        impairOpen: false,
        impairBusy: false,
        impairForm: { impairment_date: '', impairment_loss: '', reason: '' },
        impairErrors: {},
        impairFormError: '',

        createOpen: false,
        createBusy: false,
        createForm: {},
        createErrors: {},
        createFormError: '',

        // S-ACCT-CCA-1: populated by loadCcaClasses() — list of CCA classes
        // for the CCA section dropdown.
        ccaClasses: [],

        editOpen: false,
        editBusy: false,
        editForm: {},
        editErrors: {},
        editFormError: '',

        // ── VALID-2: Unified error-envelope reader ─────────────
        // WHY: The API uses `{success: false, error: {code, message,
        // fields: {...}}}`. When a single field errored we want to
        // show that message; when several did we join them. Falls
        // back to the top-level error.message, then the caller's
        // fallback string.
        extractError(r, fallback) {
            if (!r || !r.error) return fallback;
            const f = r.error.fields || r.error.errors || {};
            const keys = Object.keys(f);
            if (keys.length === 1) return f[keys[0]];
            if (keys.length > 1)   return keys.map(k => f[k]).join(' ');
            return r.error.message || fallback;
        },

        // ── Init ───────────────────────────────────────────────
        async init() {
            this.resetCreateForm();
            await this.load();
            this.loadCcaClasses();
        },

        // S-ACCT-CCA-1: fetch active CCA classes for the dropdown.
        async loadCcaClasses() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/cca/classes') ?>');
                if (r.success) this.ccaClasses = r.data || [];
            } catch (e) { /* non-critical — dropdown stays empty */ }
        },

        resetCreateForm() {
            this.createForm = {
                name: '',
                asset_class: 'fleet_equipment',
                acquisition_date: new Date().toISOString().slice(0, 10),
                acquisition_cost: '',
                salvage_value: '0.00',
                depreciation_method: 'straight_line',
                useful_life_years: '',
                cra_cca_rate: '',
                // S-ACCT-CCA-1: new CCA fields
                cca_class_id: '',
                available_for_use_date: '',
                is_aiip_eligible: true,
                total_expected_units: '',
                asset_account_id: '',
                accum_depr_account_id: '',
                depr_expense_account_id: '',
                equipment_unit_id: '',
                location: '',
                serial_number: '',
                // PAYOFF-1 fields
                purchase_tax_gst: '',
                purchase_tax_pst: '',
                delivery_cost: '',
                setup_cost: '',
                is_financed: false,
                financing_monthly_payment: '',
                financing_interest_rate: '',
                financing_remaining_months: '',
                monthly_insurance_cost: '',
                monthly_licensing_cost: '',
                monthly_registration_cost: '',
            };
        },

        // ── Load ───────────────────────────────────────────────
        async load() {
            this.loading = true;
            const params = new URLSearchParams();
            if (this.search) params.set('search', this.search);
            if (this.filterStatus) params.set('status', this.filterStatus);
            if (this.filterClass) params.set('asset_class', this.filterClass);
            params.set('page', String(this.pagination.page));
            params.set('per_page', String(this.pagination.per_page));
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/fixed_assets/index.php') ?>?' + params.toString());
                if (r.success) {
                    this.assets = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load assets.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            }
            this.loading = false;
        },

        goPage(p) {
            this.pagination.page = p;
            this.load();
        },

        // ── Detail ─────────────────────────────────────────────
        async openDetail(id) {
            this.detailOpen = true;
            this.detailLoading = true;
            this.detailTab = 'details';
            this.detailAsset = null;
            this.detailSchedule = [];
            this.detailDisposal = null;
            this.detailImpairments = [];
            // PAYOFF-1: reset payoff state so previously-viewed asset's
            // numbers don't bleed into the new one on open.
            this.resetPayoffState();
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/fixed_assets/show.php') ?>?id=' + id);
                if (r.success) {
                    this.detailAsset      = r.data.asset;
                    this.detailSchedule   = r.data.schedule || [];
                    this.detailDisposal   = r.data.disposal;
                    this.detailImpairments = r.data.impairments || [];
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to load asset.');
                    this.detailOpen = false;
                }
            } catch (e) {
                FF_Toast.error('Network error.');
                this.detailOpen = false;
            }
            this.detailLoading = false;
        },

        // ── PAYOFF-1: Payoff analysis ──────────────────────────
        resetPayoffState() {
            this.payoff = null;
            this.payoffError = null;
            this.payoffLoading = false;
            this.payoffPeriod = 6;
            this.customMonthly = '';
            this.customExtraCosts = '';
            if (this.payoffChart) {
                try { this.payoffChart.destroy(); } catch (e) { /* ignore */ }
                this.payoffChart = null;
            }
        },

        // Switch to the payoff tab and lazy-load the numbers.
        async switchToPayoff() {
            this.detailTab = 'payoff';
            if (!this.payoff && !this.payoffError && this.detailAsset) {
                await this.reloadPayoff();
            } else if (this.payoff && !this.payoffError) {
                // Already have data — just re-render the chart once the
                // DOM has finished showing the tab's container element.
                this.$nextTick(() => this.renderPayoffChart());
            }
        },

        async reloadPayoff() {
            if (!this.detailAsset) return;
            this.payoffLoading = true;
            this.payoffError = null;
            const params = new URLSearchParams();
            params.set('asset_id', String(this.detailAsset.id));
            params.set('period', String(this.payoffPeriod));
            if (this.customMonthly !== '' && !isNaN(parseFloat(this.customMonthly))) {
                params.set('custom_monthly_revenue', String(this.customMonthly));
            }
            if (this.customExtraCosts !== '' && !isNaN(parseFloat(this.customExtraCosts))) {
                params.set('extra_costs', String(this.customExtraCosts));
            }
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/accounting/fixed_assets/payoff.php') ?>?' + params.toString());
                if (r.success) {
                    this.payoff = r.data;
                    this.$nextTick(() => this.renderPayoffChart());
                } else {
                    this.payoff = null;
                    this.payoffError = r.error?.message || 'Failed to load payoff data.';
                }
            } catch (e) {
                this.payoff = null;
                this.payoffError = 'Network error.';
            }
            this.payoffLoading = false;
        },

        applyCustom() {
            this.reloadPayoff();
        },

        resetCustom() {
            this.customMonthly = '';
            this.customExtraCosts = '';
            this.reloadPayoff();
        },

        // Progress bar style — colour depends on percentage
        progressBarStyle() {
            if (!this.payoff) return '';
            const pct = Math.max(0, Math.min(100, parseFloat(this.payoff.totals.progress_pct) || 0));
            let colour = 'var(--color-danger)';       // < 33%
            if (pct >= 66) colour = 'var(--color-success)'; // >= 66% green
            else if (pct >= 33) colour = 'var(--color-warning)'; // 33-65% amber
            return 'width:' + pct + '%;height:100%;background:' + colour + ';transition:width 0.4s ease;';
        },

        // Render the ApexCharts area chart. Theme-aware: rebuilt from
        // scratch on every call so theme switches pick up new colours.
        renderPayoffChart() {
            const el = document.getElementById('payoff-chart');
            if (!el || !this.payoff || !this.payoff.monthly_data) return;

            // Destroy previous instance to prevent duplicates.
            if (this.payoffChart) {
                try { this.payoffChart.destroy(); } catch (e) { /* ignore */ }
                this.payoffChart = null;
            }

            const isLight = (window.FF_Theme && FF_Theme.current() === 'light');
            const cs = getComputedStyle(document.documentElement);
            const cssVar = v => (cs.getPropertyValue(v) || '').trim();
            const primary = cssVar('--color-primary') || '#f97316';
            const success = cssVar('--color-success') || '#22c55e';
            const fgMuted = cssVar('--text-tertiary') || '#64748b';
            const border  = cssVar('--border-default') || '#1d2133';

            const categories = this.payoff.monthly_data.map(r => r.month);
            const series = [{
                name: 'Cumulative Net Revenue',
                data: this.payoff.monthly_data.map(r => parseFloat(r.cumulative_net_revenue)),
            }];

            const target = parseFloat(this.payoff.acquisition.adjusted_target) || 0;

            const opts = {
                chart: {
                    type: 'area',
                    height: 280,
                    background: 'transparent',
                    fontFamily: 'DM Sans, sans-serif',
                    toolbar: { show: false },
                    animations: { enabled: true, speed: 400 },
                },
                theme: { mode: isLight ? 'light' : 'dark' },
                colors: [primary],
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { opacityFrom: 0.35, opacityTo: 0.05 },
                },
                dataLabels: { enabled: false },
                grid: { borderColor: border },
                xaxis: {
                    categories,
                    labels: { style: { colors: fgMuted, fontFamily: 'DM Mono, monospace' } },
                    axisBorder: { color: border },
                    axisTicks: { color: border },
                },
                yaxis: {
                    labels: {
                        style: { colors: fgMuted, fontFamily: 'DM Mono, monospace' },
                        formatter: v => '$' + (Math.round(v)).toLocaleString('en-CA'),
                    },
                },
                tooltip: {
                    theme: isLight ? 'light' : 'dark',
                    y: { formatter: v => '$' + parseFloat(v).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                },
                annotations: target > 0 ? {
                    yaxis: [{
                        y: target,
                        borderColor: success,
                        strokeDashArray: 6,
                        label: {
                            borderColor: success,
                            style: { color: '#fff', background: success, fontFamily: 'DM Mono, monospace' },
                            text: 'Target $' + target.toLocaleString('en-CA'),
                            position: 'right',
                        },
                    }],
                } : {},
            };

            try {
                this.payoffChart = new ApexCharts(el, opts);
                this.payoffChart.render();
            } catch (e) {
                console.error('[FixedAssets] Payoff chart render failed', e);
            }
        },

        // ── Dispose ────────────────────────────────────────────
        openDispose() {
            this.disposeForm = { disposal_date: new Date().toISOString().slice(0,10), disposal_type: 'sale', proceeds: '', buyer_name: '', notes: '' };
            this.disposeErrors = {};
            this.disposeFormError = '';
            this.disposeOpen = true;
        },

        // VALID-2: client-side disposal form check mirrors the server.
        validateDispose() {
            this.disposeErrors = {};
            this.disposeFormError = '';
            let ok = true;
            if (!this.disposeForm.disposal_date) {
                this.disposeErrors.disposal_date = 'Disposal date is required.';
                ok = false;
            }
            const validTypes = ['sale', 'scrap', 'donation', 'trade_in'];
            if (!this.disposeForm.disposal_type) {
                this.disposeErrors.disposal_type = 'Please select a disposal type.';
                ok = false;
            } else if (!validTypes.includes(this.disposeForm.disposal_type)) {
                this.disposeErrors.disposal_type = 'Please select a valid disposal type (sale, scrap, donation, trade_in).';
                ok = false;
            }
            if (this.disposeForm.proceeds !== '' && this.disposeForm.proceeds !== null) {
                const p = parseFloat(this.disposeForm.proceeds);
                if (isNaN(p) || p < 0) {
                    this.disposeErrors.proceeds = 'Disposal proceeds cannot be negative.';
                    ok = false;
                }
            }
            if (!ok) this.disposeFormError = 'Please fix the errors below and try again.';
            return ok;
        },

        async submitDispose() {
            if (!this.detailAsset) return;
            if (!this.validateDispose()) return;
            this.disposeBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/dispose.php') ?>', {
                    asset_id: this.detailAsset.id,
                    ...this.disposeForm,
                });
                if (r.success) {
                    FF_Toast.success('Asset disposed. JE posted.');
                    this.disposeOpen = false;
                    this.detailOpen = false;
                    await this.load();
                } else {
                    // VALID-2: paint server errors into field slots
                    const fields = (r.error && (r.error.fields || r.error.errors)) || {};
                    this.disposeErrors = {};
                    for (const k in fields) {
                        if (k !== '_general') this.disposeErrors[k] = fields[k];
                    }
                    this.disposeFormError = this.extractError(r, 'Disposal failed.');
                    FF_Toast.error(this.disposeFormError);
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.disposeBusy = false;
        },

        // ── Impair ─────────────────────────────────────────────
        openImpair() {
            this.impairForm = { impairment_date: new Date().toISOString().slice(0,10), impairment_loss: '', reason: '' };
            this.impairErrors = {};
            this.impairFormError = '';
            this.impairOpen = true;
        },

        // VALID-2: client-side impairment form check.
        validateImpair() {
            this.impairErrors = {};
            this.impairFormError = '';
            let ok = true;
            if (!this.impairForm.impairment_date) {
                this.impairErrors.impairment_date = 'Impairment date is required.';
                ok = false;
            }
            if (this.impairForm.impairment_loss === '' || this.impairForm.impairment_loss === null) {
                this.impairErrors.impairment_loss = 'Impairment loss amount is required.';
                ok = false;
            } else {
                const v = parseFloat(this.impairForm.impairment_loss);
                if (isNaN(v) || v <= 0) {
                    this.impairErrors.impairment_loss = 'Impairment loss must be greater than zero.';
                    ok = false;
                }
            }
            if (!this.impairForm.reason || !this.impairForm.reason.trim()) {
                this.impairErrors.reason = 'Please provide a reason for the impairment.';
                ok = false;
            }
            if (!ok) this.impairFormError = 'Please fix the errors below and try again.';
            return ok;
        },

        async submitImpair() {
            if (!this.detailAsset) return;
            if (!this.validateImpair()) return;
            this.impairBusy = true;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/impair.php') ?>', {
                    asset_id: this.detailAsset.id,
                    ...this.impairForm,
                });
                if (r.success) {
                    FF_Toast.success('Impairment recorded. JE posted.');
                    this.impairOpen = false;
                    this.detailOpen = false;
                    await this.load();
                } else {
                    const fields = (r.error && (r.error.fields || r.error.errors)) || {};
                    this.impairErrors = {};
                    for (const k in fields) {
                        if (k !== '_general') this.impairErrors[k] = fields[k];
                    }
                    this.impairFormError = this.extractError(r, 'Impairment failed.');
                    FF_Toast.error(this.impairFormError);
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.impairBusy = false;
        },

        // ── Create ─────────────────────────────────────────────
        openCreate() {
            this.resetCreateForm();
            this.createErrors = {};
            this.createFormError = '';
            this.createOpen = true;
        },

        // VALID-2: client-side create form validation mirrors
        // api/v1/accounting/fixed_assets/create.php exactly so
        // users see identical messages before the round-trip.
        validateCreate() {
            this.createErrors = {};
            this.createFormError = '';
            let ok = true;
            const f = this.createForm;

            if (!f.name || !f.name.trim()) {
                this.createErrors.name = 'Asset name is required.';
                ok = false;
            }
            if (!f.asset_class) {
                this.createErrors.asset_class = 'Please select an asset class.';
                ok = false;
            }
            if (!f.acquisition_date) {
                this.createErrors.acquisition_date = 'Acquisition date is required.';
                ok = false;
            }

            let costNum = null;
            if (f.acquisition_cost === '' || f.acquisition_cost === null || f.acquisition_cost === undefined) {
                this.createErrors.acquisition_cost = 'Acquisition cost is required.';
                ok = false;
            } else {
                costNum = parseFloat(f.acquisition_cost);
                if (isNaN(costNum) || costNum <= 0) {
                    this.createErrors.acquisition_cost = 'Acquisition cost must be greater than zero.';
                    ok = false;
                }
            }

            if (f.salvage_value !== '' && f.salvage_value !== null && f.salvage_value !== undefined) {
                const sv = parseFloat(f.salvage_value);
                if (isNaN(sv) || sv < 0) {
                    this.createErrors.salvage_value = 'Salvage value cannot be negative.';
                    ok = false;
                } else if (costNum !== null && !isNaN(costNum) && sv > costNum) {
                    this.createErrors.salvage_value = 'Salvage value cannot exceed acquisition cost.';
                    ok = false;
                }
            }

            if (!f.asset_account_id)    { this.createErrors.asset_account_id = 'Please select an asset GL account.'; ok = false; }
            if (!f.accum_depr_account_id) { this.createErrors.accum_depr_account_id = 'Please select an accumulated depreciation account.'; ok = false; }
            if (!f.depr_expense_account_id) { this.createErrors.depr_expense_account_id = 'Please select a depreciation expense account.'; ok = false; }

            const validMethods = ['straight_line', 'declining_balance', 'units_of_production', 'none'];
            if (!validMethods.includes(f.depreciation_method)) {
                this.createErrors.depreciation_method = 'Please select a valid depreciation method.';
                ok = false;
            } else {
                if (f.depreciation_method === 'straight_line') {
                    const ul = parseFloat(f.useful_life_years);
                    if (f.useful_life_years === '' || f.useful_life_years === null || isNaN(ul) || ul <= 0) {
                        this.createErrors.useful_life_years = 'Useful life must be at least 1 year for straight-line depreciation.';
                        ok = false;
                    }
                }
                if (f.depreciation_method === 'declining_balance') {
                    const cr = parseFloat(f.cra_cca_rate);
                    if (f.cra_cca_rate === '' || f.cra_cca_rate === null || isNaN(cr) || cr <= 0) {
                        this.createErrors.cra_cca_rate = 'CCA rate is required for declining balance.';
                        ok = false;
                    } else if (cr > 100) {
                        this.createErrors.cra_cca_rate = 'CCA rate cannot exceed 100%.';
                        ok = false;
                    }
                }
                if (f.depreciation_method === 'units_of_production') {
                    const tu = parseInt(f.total_expected_units);
                    if (!tu || isNaN(tu) || tu <= 0) {
                        this.createErrors.total_expected_units = 'Total expected units must be greater than zero for units-of-production.';
                        ok = false;
                    }
                }
            }

            if (!ok) this.createFormError = 'Please fix the errors below and try again.';
            return ok;
        },

        async submitCreate() {
            if (!this.validateCreate()) return;
            this.createBusy = true;
            try {
                // Coerce is_financed checkbox to 1/0 for the API
                const payload = { ...this.createForm, is_financed: this.createForm.is_financed ? 1 : 0 };
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/create.php') ?>', payload);
                if (r.success) {
                    FF_Toast.success('Asset created: ' + r.data.asset_number);
                    this.createOpen = false;
                    await this.load();
                } else {
                    const fields = (r.error && (r.error.fields || r.error.errors)) || {};
                    this.createErrors = {};
                    for (const k in fields) {
                        if (k !== '_general') this.createErrors[k] = fields[k];
                    }
                    this.createFormError = this.extractError(r, 'Create failed.');
                    FF_Toast.error(this.createFormError);
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.createBusy = false;
        },

        // ── PAYOFF-1: Edit (modal opened from detail footer) ──
        openEdit() {
            if (!this.detailAsset) return;
            const a = this.detailAsset;
            this.editForm = {
                id: a.id,
                updated_at: a.updated_at,
                name: a.name || '',
                description: a.description || '',
                asset_class: a.asset_class || 'fleet_equipment',
                equipment_unit_id: a.equipment_unit_id || '',
                location: a.location || '',
                serial_number: a.serial_number || '',
                notes: a.notes || '',
                purchase_tax_gst: a.purchase_tax_gst || '',
                purchase_tax_pst: a.purchase_tax_pst || '',
                delivery_cost: a.delivery_cost || '',
                setup_cost: a.setup_cost || '',
                is_financed: !!parseInt(a.is_financed || 0),
                financing_monthly_payment: a.financing_monthly_payment || '',
                financing_interest_rate: a.financing_interest_rate || '',
                financing_remaining_months: a.financing_remaining_months || '',
                monthly_insurance_cost: a.monthly_insurance_cost || '',
                monthly_licensing_cost: a.monthly_licensing_cost || '',
                monthly_registration_cost: a.monthly_registration_cost || '',
            };
            this.editErrors = {};
            this.editFormError = '';
            this.editOpen = true;
        },

        // VALID-2: client-side edit form check.
        validateEdit() {
            this.editErrors = {};
            this.editFormError = '';
            let ok = true;
            const f = this.editForm;

            if (!f.name || !f.name.trim()) {
                this.editErrors.name = 'Asset name is required.';
                ok = false;
            }
            if (f.purchase_tax_gst !== '' && f.purchase_tax_gst !== null && f.purchase_tax_gst !== undefined) {
                const v = parseFloat(f.purchase_tax_gst);
                if (isNaN(v) || v < 0) { this.editErrors.purchase_tax_gst = 'GST cannot be negative.'; ok = false; }
            }
            if (f.purchase_tax_pst !== '' && f.purchase_tax_pst !== null && f.purchase_tax_pst !== undefined) {
                const v = parseFloat(f.purchase_tax_pst);
                if (isNaN(v) || v < 0) { this.editErrors.purchase_tax_pst = 'PST cannot be negative.'; ok = false; }
            }
            if (f.delivery_cost !== '' && f.delivery_cost !== null && f.delivery_cost !== undefined) {
                const v = parseFloat(f.delivery_cost);
                if (isNaN(v) || v < 0) { this.editErrors.delivery_cost = 'Delivery cost cannot be negative.'; ok = false; }
            }
            if (f.setup_cost !== '' && f.setup_cost !== null && f.setup_cost !== undefined) {
                const v = parseFloat(f.setup_cost);
                if (isNaN(v) || v < 0) { this.editErrors.setup_cost = 'Setup cost cannot be negative.'; ok = false; }
            }
            if (f.monthly_insurance_cost !== '' && f.monthly_insurance_cost !== null && f.monthly_insurance_cost !== undefined) {
                const v = parseFloat(f.monthly_insurance_cost);
                if (isNaN(v) || v < 0) { this.editErrors.monthly_insurance_cost = 'Monthly insurance cost cannot be negative.'; ok = false; }
            }
            if (f.monthly_licensing_cost !== '' && f.monthly_licensing_cost !== null && f.monthly_licensing_cost !== undefined) {
                const v = parseFloat(f.monthly_licensing_cost);
                if (isNaN(v) || v < 0) { this.editErrors.monthly_licensing_cost = 'Monthly licensing cost cannot be negative.'; ok = false; }
            }
            if (f.monthly_registration_cost !== '' && f.monthly_registration_cost !== null && f.monthly_registration_cost !== undefined) {
                const v = parseFloat(f.monthly_registration_cost);
                if (isNaN(v) || v < 0) { this.editErrors.monthly_registration_cost = 'Monthly registration cost cannot be negative.'; ok = false; }
            }
            if (f.is_financed) {
                if (f.financing_monthly_payment !== '' && f.financing_monthly_payment !== null) {
                    const v = parseFloat(f.financing_monthly_payment);
                    if (isNaN(v) || v < 0) { this.editErrors.financing_monthly_payment = 'Monthly payment cannot be negative.'; ok = false; }
                }
                if (f.financing_interest_rate !== '' && f.financing_interest_rate !== null) {
                    const v = parseFloat(f.financing_interest_rate);
                    if (isNaN(v) || v < 0) { this.editErrors.financing_interest_rate = 'Interest rate cannot be negative.'; ok = false; }
                    else if (v > 1) { this.editErrors.financing_interest_rate = 'Interest rate must be entered as a decimal (e.g. 0.075 for 7.5%).'; ok = false; }
                }
                if (f.financing_remaining_months !== '' && f.financing_remaining_months !== null) {
                    const v = parseInt(f.financing_remaining_months);
                    if (isNaN(v) || v < 0) { this.editErrors.financing_remaining_months = 'Remaining months cannot be negative.'; ok = false; }
                }
            }
            if (!ok) this.editFormError = 'Please fix the errors below and try again.';
            return ok;
        },

        async submitEdit() {
            if (!this.editForm.id) return;
            if (!this.validateEdit()) return;
            this.editBusy = true;
            try {
                const payload = { ...this.editForm, is_financed: this.editForm.is_financed ? 1 : 0 };
                const r = await FF_Api.post('<?= base_url('api/v1/accounting/fixed_assets/update.php') ?>', payload);
                if (r.success) {
                    FF_Toast.success('Asset updated.');
                    this.editOpen = false;
                    // Refresh detail view so the payoff calculator sees
                    // the new numbers on the next tab switch.
                    const keepOpenId = this.editForm.id;
                    this.detailOpen = false;
                    await this.load();
                    await this.openDetail(keepOpenId);
                } else if (r.error && r.error.code === 'STALE_DATA') {
                    // VALID-2: STALE_DATA gets a dedicated friendly message
                    this.editErrors = { updated_at: 'This asset was modified by another user. Refresh and try again.' };
                    this.editFormError = 'This asset was modified by another user. Please close and reopen.';
                    FF_Toast.error(this.editFormError);
                } else {
                    const fields = (r.error && (r.error.fields || r.error.errors)) || {};
                    this.editErrors = {};
                    for (const k in fields) {
                        if (k !== '_general') this.editErrors[k] = fields[k];
                    }
                    this.editFormError = this.extractError(r, 'Update failed.');
                    FF_Toast.error(this.editFormError);
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.editBusy = false;
        },

        // ── Display helpers ────────────────────────────────────
        formatMoney(s) {
            if (s === null || s === undefined) return '—';
            return '$' + parseFloat(s).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        formatClass(c) {
            const m = {
                fleet_equipment:'Fleet Equipment', vehicles:'Vehicles', office_equipment:'Office Equipment',
                leasehold_improvements:'Leasehold Improvements', land:'Land', building:'Building', other:'Other'
            };
            return m[c] || c;
        },
        formatMethod(m) {
            const map = { straight_line:'Straight Line', declining_balance:'Declining Balance', units_of_production:'Units of Production', none:'None' };
            return map[m] || m;
        },
        formatStatus(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace('_',' ') : ''; },
        statusBadge(s) {
            const m = { active:'badge-green', impaired:'badge-amber', disposed:'badge-neutral', fully_depreciated:'badge-blue' };
            return m[s] || 'badge-neutral';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
