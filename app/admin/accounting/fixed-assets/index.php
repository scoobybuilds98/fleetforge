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
    <h1 class="page-header-title h4">Fixed Assets Register</h1>
    <div class="page-header-actions">
        <?php if (can('fixed_assets', 'create')): ?>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-asset-create')">
            + New Asset
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require_once FF_ROOT . '/includes/partials/accounting-nav.php'; ?>

<!-- ── KPI Tiles ───────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Total Assets</div>
        <div class="stat-value font-mono"><?= e((string) $totalAssets) ?></div>
    </div>
    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono"><?= e((string) $activeAssets) ?></div>
    </div>
    <div class="stat-card stat-card--amber">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Disposed</div>
        <div class="stat-value font-mono"><?= e((string) $disposedAssets) ?></div>
    </div>
    <div class="stat-card">
        <span class="stat-icon"><svg><use href="#icon-banknotes"/></svg></span>
        <div class="stat-label">Total NBV (Active+Impaired)</div>
        <div class="stat-value font-mono">$<?= number_format((float) $totalNbv, 2) ?></div>
    </div>
</div>

<!-- ============================================================
     FIXED ASSETS — ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_FixedAssets()" x-init="init()" @open-asset-create.window="openCreate()">

    <!-- ── Filter toolbar ────────────────────────────────────── -->
    <div class="table-toolbar" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input type="text" class="form-control form-control-sm" placeholder="Search by name or asset number…"
               x-model.debounce.300ms="search" @input="load()" style="min-width:280px;">

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

        <span class="text-secondary text-sm" x-text="pagination.total + ' assets'"></span>
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
                <button class="modal-close" @click="detailOpen = false">×</button>
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
                        <!-- Summary grid -->
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px 24px;margin-bottom:18px;">
                            <div><strong>Class:</strong> <span x-text="formatClass(detailAsset.asset_class)"></span></div>
                            <div><strong>Status:</strong> <span class="badge badge-no-dot" :class="statusBadge(detailAsset.status)" x-text="formatStatus(detailAsset.status)"></span></div>
                            <div><strong>Method:</strong> <span x-text="formatMethod(detailAsset.depreciation_method)"></span></div>
                            <div><strong>Acquired:</strong> <span x-text="detailAsset.acquisition_date"></span></div>
                            <div><strong>Cost:</strong> <span class="font-mono" x-text="formatMoney(detailAsset.acquisition_cost)"></span></div>
                            <div><strong>Salvage:</strong> <span class="font-mono" x-text="formatMoney(detailAsset.salvage_value)"></span></div>
                            <div><strong>Depreciable:</strong> <span class="font-mono" x-text="formatMoney(detailAsset.depreciable_cost)"></span></div>
                            <div><strong>Accum Depr:</strong> <span class="font-mono" x-text="formatMoney(detailAsset.accumulated_depreciation)"></span></div>
                            <div><strong>NBV:</strong> <span class="font-mono" x-text="formatMoney(detailAsset.net_book_value)"></span></div>
                            <template x-if="detailAsset.useful_life_years">
                                <div><strong>Life (years):</strong> <span x-text="detailAsset.useful_life_years"></span></div>
                            </template>
                            <template x-if="detailAsset.cra_cca_rate">
                                <div><strong>CCA Rate:</strong> <span x-text="(parseFloat(detailAsset.cra_cca_rate) * 100).toFixed(0) + '%'"></span></div>
                            </template>
                            <template x-if="detailAsset.total_expected_units">
                                <div><strong>Total Units:</strong> <span class="font-mono" x-text="parseInt(detailAsset.total_expected_units).toLocaleString()"></span></div>
                            </template>
                            <template x-if="detailAsset.serial_number">
                                <div><strong>Serial #:</strong> <span class="font-mono" x-text="detailAsset.serial_number"></span></div>
                            </template>
                            <template x-if="detailAsset.location">
                                <div><strong>Location:</strong> <span x-text="detailAsset.location"></span></div>
                            </template>
                            <template x-if="detailAsset.equipment_unit_number">
                                <div><strong>Equipment Unit:</strong> <span x-text="detailAsset.equipment_unit_number"></span></div>
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
                            <div style="margin-bottom:16px;">
                                <strong>Impairments:</strong>
                                <ul style="margin-top:4px;">
                                    <template x-for="i in detailImpairments" :key="i.id">
                                        <li class="text-sm">
                                            <span x-text="i.impairment_date"></span> —
                                            <span class="font-mono" x-text="formatMoney(i.impairment_loss)"></span> loss
                                            (<span x-text="i.reason"></span>)
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>

                        <!-- Depreciation schedule -->
                        <h3 class="h6" style="margin-top:16px;margin-bottom:8px;">Depreciation Forecast</h3>
                        <template x-if="detailSchedule.length === 0">
                            <p class="text-secondary text-sm">No forecast available (asset is impaired, disposed, or uses a method with no fixed schedule).</p>
                        </template>
                        <template x-if="detailSchedule.length > 0">
                            <div style="max-height:280px;overflow-y:auto;border:1px solid var(--border-default);border-radius:6px;">
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
                        @click="openDispose()">Dispose…</button>
                <button class="btn btn-warning btn-sm"
                        x-show="detailAsset && detailAsset.status === 'active'"
                        @click="openImpair()">Impair…</button>
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
            <div class="modal-header"><h2 class="h5">Dispose Asset</h2><button class="modal-close" @click="disposeOpen = false">×</button></div>
            <div class="modal-body">
                <p class="text-secondary text-sm" x-show="detailAsset" x-text="'Disposing ' + (detailAsset?.asset_number ?? '') + ' — ' + (detailAsset?.name ?? '')"></p>
                <div class="form-group"><label>Disposal Date</label><input type="date" class="form-control form-control-sm" x-model="disposeForm.disposal_date"></div>
                <div class="form-group"><label>Disposal Type</label>
                    <select class="form-select form-control-sm" x-model="disposeForm.disposal_type">
                        <option value="sale">Sale</option>
                        <option value="scrap">Scrap</option>
                        <option value="donation">Donation</option>
                        <option value="trade_in">Trade-In</option>
                    </select>
                </div>
                <div class="form-group"><label>Proceeds ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="disposeForm.proceeds"></div>
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
            <div class="modal-header"><h2 class="h5">Impair Asset</h2><button class="modal-close" @click="impairOpen = false">×</button></div>
            <div class="modal-body">
                <p class="text-secondary text-sm" x-show="detailAsset" x-text="'Impairing ' + (detailAsset?.asset_number ?? '') + ' — ' + (detailAsset?.name ?? '')"></p>
                <div class="form-group"><label>Impairment Date</label><input type="date" class="form-control form-control-sm" x-model="impairForm.impairment_date"></div>
                <div class="form-group"><label>Impairment Loss ($)</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" x-model="impairForm.impairment_loss"></div>
                <div class="form-group"><label>Reason</label><textarea class="form-control form-control-sm" rows="3" x-model="impairForm.reason" placeholder="Required — explain the impairment trigger"></textarea></div>
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
        <div class="modal" style="max-width:740px;width:96%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">New Fixed Asset</h2><button class="modal-close" @click="createOpen = false">×</button></div>
            <div class="modal-body">
                <h3 class="h6" style="margin:0 0 10px 0;">Core Details</h3>
                <div class="form-group"><label>Name *</label><input type="text" class="form-control form-control-sm" x-model="createForm.name"></div>
                <div class="form-group"><label>Asset Class *</label>
                    <select class="form-select form-control-sm" x-model="createForm.asset_class">
                        <option value="fleet_equipment">Fleet Equipment</option>
                        <option value="vehicles">Vehicles</option>
                        <option value="office_equipment">Office Equipment</option>
                        <option value="leasehold_improvements">Leasehold Improvements</option>
                        <option value="land">Land</option>
                        <option value="building">Building</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group"><label>Acquisition Date *</label><input type="date" class="form-control form-control-sm" x-model="createForm.acquisition_date"></div>
                <div class="form-group"><label>Acquisition Cost ($) *</label><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" x-model="createForm.acquisition_cost"></div>
                <div class="form-group"><label>Salvage Value ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.salvage_value"></div>
                <div class="form-group"><label>Depreciation Method *</label>
                    <select class="form-select form-control-sm" x-model="createForm.depreciation_method">
                        <option value="straight_line">Straight Line</option>
                        <option value="declining_balance">Declining Balance (CRA CCA)</option>
                        <option value="units_of_production">Units of Production</option>
                        <option value="none">None (no depreciation)</option>
                    </select>
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'straight_line'">
                    <label>Useful Life (years) *</label>
                    <input type="number" step="0.5" min="0.5" class="form-control form-control-sm" x-model="createForm.useful_life_years">
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'declining_balance'">
                    <label>CRA CCA Rate (e.g. 0.30) *</label>
                    <input type="number" step="0.01" min="0.01" max="1" class="form-control form-control-sm" x-model="createForm.cra_cca_rate">
                </div>
                <div class="form-group" x-show="createForm.depreciation_method === 'units_of_production'">
                    <label>Total Expected Units *</label>
                    <input type="number" step="1" min="1" class="form-control form-control-sm" x-model="createForm.total_expected_units">
                </div>
                <div class="form-group"><label>Asset Account ID *</label><input type="number" class="form-control form-control-sm" x-model="createForm.asset_account_id" placeholder="e.g. 1210 fleet_cost"></div>
                <div class="form-group"><label>Accum Depr Account ID *</label><input type="number" class="form-control form-control-sm" x-model="createForm.accum_depr_account_id" placeholder="e.g. 1220 fleet_accum"></div>
                <div class="form-group"><label>Depreciation Expense Account ID *</label><input type="number" class="form-control form-control-sm" x-model="createForm.depr_expense_account_id" placeholder="e.g. 5010 depr_fleet"></div>
                <div class="form-group"><label>Equipment Unit ID (for Payoff Analysis)</label><input type="number" class="form-control form-control-sm" x-model="createForm.equipment_unit_id" placeholder="optional, numeric ID of the linked equipment_units row"></div>
                <div class="form-group"><label>Location</label><input type="text" class="form-control form-control-sm" x-model="createForm.location"></div>
                <div class="form-group"><label>Serial Number</label><input type="text" class="form-control form-control-sm" x-model="createForm.serial_number"></div>

                <!-- PAYOFF-1 — Acquisition Details -->
                <h3 class="h6" style="margin:24px 0 10px 0;">Acquisition Details <span class="text-secondary text-xs">(for Payoff Calculator)</span></h3>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0 12px;">
                    <div class="form-group"><label>GST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.purchase_tax_gst"></div>
                    <div class="form-group"><label>PST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.purchase_tax_pst"></div>
                    <div class="form-group"><label>Delivery Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.delivery_cost"></div>
                    <div class="form-group"><label>Setup Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.setup_cost"></div>
                </div>

                <!-- PAYOFF-1 — Financing -->
                <h3 class="h6" style="margin:16px 0 10px 0;">Financing <span class="text-secondary text-xs">(optional)</span></h3>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" x-model="createForm.is_financed"> This asset is financed
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 12px;" x-show="createForm.is_financed">
                    <div class="form-group"><label>Monthly Payment ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="createForm.financing_monthly_payment"></div>
                    <div class="form-group"><label>Interest Rate</label><input type="number" step="0.0001" min="0" max="1" class="form-control form-control-sm" x-model="createForm.financing_interest_rate" placeholder="e.g. 0.075"></div>
                    <div class="form-group"><label>Remaining Months</label><input type="number" step="1" min="0" class="form-control form-control-sm" x-model="createForm.financing_remaining_months"></div>
                </div>

                <!-- PAYOFF-1 — Monthly Fixed Costs -->
                <h3 class="h6" style="margin:16px 0 10px 0;">Monthly Fixed Costs <span class="text-secondary text-xs">(reduce net revenue every month)</span></h3>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 12px;">
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
        <div class="modal" style="max-width:740px;width:96%;max-height:90vh;overflow-y:auto;">
            <div class="modal-header"><h2 class="h5">Edit Fixed Asset</h2><button class="modal-close" @click="editOpen = false">×</button></div>
            <div class="modal-body">
                <h3 class="h6" style="margin:0 0 10px 0;">Core Details</h3>
                <div class="form-group"><label>Name *</label><input type="text" class="form-control form-control-sm" x-model="editForm.name"></div>
                <div class="form-group"><label>Asset Class</label>
                    <select class="form-select form-control-sm" x-model="editForm.asset_class">
                        <option value="fleet_equipment">Fleet Equipment</option>
                        <option value="vehicles">Vehicles</option>
                        <option value="office_equipment">Office Equipment</option>
                        <option value="leasehold_improvements">Leasehold Improvements</option>
                        <option value="land">Land</option>
                        <option value="building">Building</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group"><label>Description</label><textarea class="form-control form-control-sm" rows="2" x-model="editForm.description"></textarea></div>
                <div class="form-group"><label>Equipment Unit ID (for Payoff Analysis)</label>
                    <input type="number" class="form-control form-control-sm" x-model="editForm.equipment_unit_id"
                           placeholder="Numeric ID of the linked equipment_units row">
                    <p class="text-secondary text-xs" style="margin:4px 0 0 0;">Link to an equipment unit so the payoff calculator can sum revenue, maintenance, and damage automatically.</p>
                </div>
                <div class="form-group"><label>Location</label><input type="text" class="form-control form-control-sm" x-model="editForm.location"></div>
                <div class="form-group"><label>Serial Number</label><input type="text" class="form-control form-control-sm" x-model="editForm.serial_number"></div>
                <div class="form-group"><label>Notes</label><textarea class="form-control form-control-sm" rows="2" x-model="editForm.notes"></textarea></div>

                <h3 class="h6" style="margin:24px 0 10px 0;">Acquisition Details <span class="text-secondary text-xs">(for Payoff Calculator)</span></h3>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0 12px;">
                    <div class="form-group"><label>GST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.purchase_tax_gst"></div>
                    <div class="form-group"><label>PST ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.purchase_tax_pst"></div>
                    <div class="form-group"><label>Delivery Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.delivery_cost"></div>
                    <div class="form-group"><label>Setup Cost ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.setup_cost"></div>
                </div>

                <h3 class="h6" style="margin:16px 0 10px 0;">Financing <span class="text-secondary text-xs">(optional)</span></h3>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" x-model="editForm.is_financed"> This asset is financed
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 12px;" x-show="editForm.is_financed">
                    <div class="form-group"><label>Monthly Payment ($)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" x-model="editForm.financing_monthly_payment"></div>
                    <div class="form-group"><label>Interest Rate</label><input type="number" step="0.0001" min="0" max="1" class="form-control form-control-sm" x-model="editForm.financing_interest_rate" placeholder="e.g. 0.075"></div>
                    <div class="form-group"><label>Remaining Months</label><input type="number" step="1" min="0" class="form-control form-control-sm" x-model="editForm.financing_remaining_months"></div>
                </div>

                <h3 class="h6" style="margin:16px 0 10px 0;">Monthly Fixed Costs <span class="text-secondary text-xs">(reduce net revenue every month)</span></h3>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 12px;">
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

        impairOpen: false,
        impairBusy: false,
        impairForm: { impairment_date: '', impairment_loss: '', reason: '' },

        createOpen: false,
        createBusy: false,
        createForm: {},

        editOpen: false,
        editBusy: false,
        editForm: {},

        // ── Init ───────────────────────────────────────────────
        async init() {
            this.resetCreateForm();
            await this.load();
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
            this.disposeOpen = true;
        },
        async submitDispose() {
            if (!this.detailAsset) return;
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
                    FF_Toast.error(r.error?.message || 'Disposal failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.disposeBusy = false;
        },

        // ── Impair ─────────────────────────────────────────────
        openImpair() {
            this.impairForm = { impairment_date: new Date().toISOString().slice(0,10), impairment_loss: '', reason: '' };
            this.impairOpen = true;
        },
        async submitImpair() {
            if (!this.detailAsset) return;
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
                    FF_Toast.error(r.error?.message || 'Impairment failed.');
                }
            } catch (e) { FF_Toast.error('Network error.'); }
            this.impairBusy = false;
        },

        // ── Create ─────────────────────────────────────────────
        openCreate() {
            this.resetCreateForm();
            this.createOpen = true;
        },
        async submitCreate() {
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
                    FF_Toast.error(r.error?.message || 'Create failed.');
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
            this.editOpen = true;
        },
        async submitEdit() {
            if (!this.editForm.id) return;
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
                } else if (r.error?.code === 'STALE_DATA') {
                    FF_Toast.error('This asset was modified by another user. Please close and reopen.');
                } else {
                    FF_Toast.error(r.error?.message || 'Update failed.');
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
