<?php
declare(strict_types=1);
/**
 * app/admin/reports/index.php
 *
 * Reports Module — 4-tab report centre: Financial, Fleet, Customer, Compliance.
 * Each tab has sub-views, KPI tiles, ApexCharts visualisations, data tables,
 * CSV export, and print support.  All data fetched from api/v1/reports/ endpoints.
 *
 * Required by: sidebar navigation, dashboard "Active Revenue" KPI tile drilldown
 * Requires: includes/auth.php, includes/header.php, app.css, app.js, ApexCharts
 *
 * Spec ref:  §7.10 Reports, §9 Charts (Reports Charts #1–#12), PROGRESS.md S021
 * Decisions: D7 (base_url), D17 (PSR-4), D32 (only confirmed CSS classes),
 *            D30 (asset_url for static assets)
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('reports', 'view');

$pageTitle   = 'Reports';
$canExport   = can('reports', 'view');   // all viewers can export CSV
$canSchedule = can('reports', 'create'); // managers+ can schedule reports
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reports</h1>
        <p class="page-subtitle">Financial, fleet, customer, and compliance analytics</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-secondary btn-sm" onclick="window.print()">
            <svg width="14" height="14"><use href="#icon-printer"/></svg> Print
        </button>
    </div>
</div>

<div x-data="FF_Reports()" x-init="init()" id="reports-root">

    <!-- ── Date range bar (shared by Financial, Fleet, Customer) ── -->
    <div class="card mb-4 no-print" id="date-range-bar">
        <div class="card-body" style="padding:12px 16px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:600;color:var(--text-secondary);white-space:nowrap;">DATE RANGE</span>

                <!-- Preset buttons -->
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                    <template x-for="p in presetOptions" :key="p.value">
                        <button class="btn btn-sm"
                            :class="preset === p.value ? 'btn-primary' : 'btn-secondary'"
                            @click="setPreset(p.value)"
                            x-text="p.label">
                        </button>
                    </template>
                </div>

                <!-- Custom date inputs -->
                <template x-if="preset === 'custom'">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="date" class="form-control" style="width:150px;height:30px;padding:4px 8px;font-size:12px;"
                            x-model="customFrom" :max="customTo || ''" placeholder="From">
                        <span style="color:var(--text-muted);font-size:12px;">→</span>
                        <input type="date" class="form-control" style="width:150px;height:30px;padding:4px 8px;font-size:12px;"
                            x-model="customTo" :min="customFrom || ''" placeholder="To">
                        <button class="btn btn-primary btn-sm" @click="runReport()">Run</button>
                    </div>
                </template>

                <!-- Loading indicator -->
                <span x-show="isLoading()" style="font-size:12px;color:var(--text-muted);">
                    <span class="spinner" style="width:12px;height:12px;display:inline-block;border:2px solid var(--border-color);border-top-color:var(--color-accent);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:4px;"></span>
                    Loading...
                </span>

                <!-- Resolved date display -->
                <span x-show="resolvedFrom && resolvedTo && !isLoading()"
                      style="margin-left:auto;font-size:11px;color:var(--text-muted);font-family:var(--font-mono);"
                      x-text="resolvedFrom + ' → ' + resolvedTo">
                </span>
            </div>
        </div>
    </div>

    <!-- ── Main tab bar ── -->
    <div class="tab-bar no-print" style="margin-bottom:20px;">
        <button class="tab-btn" :class="{active: mainTab==='financial'}"  @click="switchMainTab('financial')">
            <svg width="14" height="14"><use href="#icon-banknotes"/></svg> Financial
        </button>
        <button class="tab-btn" :class="{active: mainTab==='fleet'}"      @click="switchMainTab('fleet')">
            <svg width="14" height="14"><use href="#icon-truck"/></svg> Fleet
        </button>
        <button class="tab-btn" :class="{active: mainTab==='customer'}"   @click="switchMainTab('customer')">
            <svg width="14" height="14"><use href="#icon-user-group"/></svg> Customers
        </button>
        <button class="tab-btn" :class="{active: mainTab==='compliance'}" @click="switchMainTab('compliance')">
            <svg width="14" height="14"><use href="#icon-shield-check"/></svg> Compliance
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         FINANCIAL TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='financial'">

        <!-- KPI tiles -->
        <template x-if="tabs.financial.kpis">
            <div class="stat-grid mb-4">
                <div class="stat-card stat-card--green">
                    <div class="stat-label">Gross Revenue</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.gross_revenue)"></div>
                    <div class="stat-delta" x-text="tabs.financial.kpis.invoice_count + ' invoices'"></div>
                </div>
                <div class="stat-card stat-card--blue">
                    <div class="stat-label">Collected</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.total_collected)"></div>
                    <div class="stat-delta" x-text="tabs.financial.kpis.collection_rate + '% rate'"></div>
                </div>
                <div class="stat-card stat-card--amber">
                    <div class="stat-label">Outstanding</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.total_outstanding)"></div>
                    <div class="stat-delta" x-text="tabs.financial.kpis.overdue_count + ' overdue'"></div>
                </div>
                <div class="stat-card stat-card--red">
                    <div class="stat-label">Overdue Amount</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.overdue_amount)"></div>
                </div>
                <div class="stat-card stat-card--purple">
                    <div class="stat-label">Avg Invoice</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.avg_invoice_value)"></div>
                    <div class="stat-delta" x-text="tabs.financial.kpis.unique_customers + ' customers'"></div>
                </div>
                <div class="stat-card stat-card--teal">
                    <div class="stat-label">Tax Collected</div>
                    <div class="stat-value font-mono" x-text="money(tabs.financial.kpis.total_tax)"></div>
                    <div class="stat-delta" x-text="tabs.financial.kpis.paid_count + ' paid'"></div>
                </div>
            </div>
        </template>

        <!-- Loading state for KPIs -->
        <template x-if="tabs.financial.loading && !tabs.financial.kpis">
            <div class="stat-grid mb-4">
                <template x-for="i in 6"><div class="stat-card"><div class="skeleton-bar" style="width:60%;height:12px;margin-bottom:8px;"></div><div class="skeleton-bar" style="width:80%;height:24px;"></div></div></template>
            </div>
        </template>

        <!-- Sub-tab bar -->
        <div class="tab-bar no-print" style="margin-bottom:16px;">
            <button class="tab-btn" :class="{active:tabs.financial.view==='period'}"         @click="switchView('financial','period')">By Period</button>
            <button class="tab-btn" :class="{active:tabs.financial.view==='customer'}"        @click="switchView('financial','customer')">By Customer</button>
            <button class="tab-btn" :class="{active:tabs.financial.view==='equipment_type'}"  @click="switchView('financial','equipment_type')">By Type</button>
            <button class="tab-btn" :class="{active:tabs.financial.view==='ar_aging'}"        @click="switchView('financial','ar_aging')">AR Aging</button>
            <button class="tab-btn" :class="{active:tabs.financial.view==='collection'}"      @click="switchView('financial','collection')">Collection Rate</button>
            <button class="tab-btn" :class="{active:tabs.financial.view==='status'}"          @click="switchView('financial','status')">Invoice Status</button>
        </div>

        <!-- Section loading skeleton -->
        <template x-if="tabs.financial.viewLoading">
            <div class="card"><div class="card-body"><div class="skeleton-chart" style="height:260px;"></div></div></div>
        </template>

        <!-- ── By Period ── -->
        <div x-show="tabs.financial.view==='period' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Revenue by Month</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','period')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body">
                    <div id="chart-rev-period" style="height:280px;"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body" style="overflow-x:auto;">
                    <table class="table">
                        <thead><tr>
                            <th>Period</th><th class="text-right">Invoices</th>
                            <th class="text-right">Net Revenue</th><th class="text-right">Tax</th>
                            <th class="text-right">Gross Revenue</th><th class="text-right">Collected</th>
                            <th class="text-right">Outstanding</th><th class="text-right">Customers</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in viewData('financial')" :key="r.period">
                                <tr>
                                    <td x-text="r.period_label"></td>
                                    <td class="text-right font-mono" x-text="r.invoice_count"></td>
                                    <td class="text-right font-mono" x-text="money(r.net_revenue)"></td>
                                    <td class="text-right font-mono" x-text="money(r.tax_total)"></td>
                                    <td class="text-right font-mono" x-text="money(r.gross_revenue)"></td>
                                    <td class="text-right font-mono" x-text="money(r.collected)"></td>
                                    <td class="text-right font-mono" :class="r.outstanding > 0 ? 'text-danger' : ''" x-text="money(r.outstanding)"></td>
                                    <td class="text-right" x-text="r.unique_customers"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <template x-if="!viewData('financial').length && !tabs.financial.viewLoading">
                        <div class="empty-state"><p class="empty-state-title">No revenue data for this period</p></div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ── By Customer ── -->
        <div x-show="tabs.financial.view==='customer' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Top Customers by Revenue</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','customer')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-rev-customer" style="height:320px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>Customer</th><th>Status</th>
                        <th class="text-right">Invoices</th><th class="text-right">Leases</th>
                        <th class="text-right">Net Revenue</th><th class="text-right">Gross Revenue</th>
                        <th class="text-right">Collected</th><th class="text-right">Outstanding</th>
                        <th class="text-right">Avg Invoice</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in viewData('financial')" :key="r.customer_id">
                            <tr>
                                <td class="text-muted" x-text="i+1"></td>
                                <td x-text="r.company_name"></td>
                                <td><span class="badge" :class="'badge-' + customerBadge(r.customer_status)" x-text="r.customer_status || '—'"></span></td>
                                <td class="text-right" x-text="r.invoice_count"></td>
                                <td class="text-right" x-text="r.lease_count"></td>
                                <td class="text-right font-mono" x-text="money(r.net_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.gross_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.collected)"></td>
                                <td class="text-right font-mono" :class="r.outstanding > 0 ? 'text-danger' : ''" x-text="money(r.outstanding)"></td>
                                <td class="text-right font-mono" x-text="money(r.avg_invoice)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── By Equipment Type ── -->
        <div x-show="tabs.financial.view==='equipment_type' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Revenue by Equipment Type</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','equipment_type')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-rev-type" style="height:260px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Equipment Type</th><th class="text-right">Units</th>
                        <th class="text-right">Leases</th><th class="text-right">Invoices</th>
                        <th class="text-right">Net Revenue</th><th class="text-right">Gross Revenue</th>
                        <th class="text-right">Collected</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('financial')" :key="r.equipment_type">
                            <tr>
                                <td x-text="r.equipment_type"></td>
                                <td class="text-right" x-text="r.unit_count"></td>
                                <td class="text-right" x-text="r.lease_count"></td>
                                <td class="text-right" x-text="r.invoice_count"></td>
                                <td class="text-right font-mono" x-text="money(r.net_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.gross_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.collected)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── AR Aging ── -->
        <div x-show="tabs.financial.view==='ar_aging' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">AR Aging <span style="font-weight:400;font-size:12px;color:var(--text-muted);" x-text="'as of ' + (resolvedTo || '')"></span></span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','ar_aging')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body">
                    <!-- Bucket summary tiles -->
                    <template x-if="tabs.financial.viewData.ar_aging && tabs.financial.viewData.ar_aging.totals && tabs.financial.viewData.ar_aging.totals.buckets">
                        <div class="stat-grid mb-3">
                            <template x-for="(b, key) in tabs.financial.viewData.ar_aging.totals.buckets" :key="key">
                                <div class="stat-card">
                                    <div class="stat-label" x-text="b.label"></div>
                                    <div class="stat-value font-mono" x-text="money(b.amount)"></div>
                                    <div class="stat-delta" x-text="b.count + ' invoice' + (b.count !== 1 ? 's' : '')"></div>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div id="chart-aging" style="height:220px;"></div>
                </div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Invoice #</th><th>Customer</th><th>Invoice Date</th>
                        <th>Due Date</th><th class="text-right">Total</th>
                        <th class="text-right">Balance Due</th><th class="text-right">Days Overdue</th><th>Bucket</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('financial')" :key="r.id">
                            <tr>
                                <td class="font-mono" x-text="r.invoice_number"></td>
                                <td x-text="r.company_name"></td>
                                <td class="font-mono" x-text="r.invoice_date"></td>
                                <td class="font-mono" x-text="r.due_date"></td>
                                <td class="text-right font-mono" x-text="money(r.total_amount)"></td>
                                <td class="text-right font-mono text-danger" x-text="money(r.balance_due)"></td>
                                <td class="text-right font-mono" :class="r.days_overdue > 60 ? 'text-danger' : r.days_overdue > 0 ? 'text-warning' : ''"
                                    x-text="Math.max(0, r.days_overdue) + 'd'"></td>
                                <td><span class="badge" :class="agingBadge(r.aging_bucket)" x-text="r.aging_bucket.replace('_','-')"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Collection Rate ── -->
        <div x-show="tabs.financial.view==='collection' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Payment Collection Rate</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','collection')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-collection" style="height:280px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Period</th><th class="text-right">Invoices</th>
                        <th class="text-right">Fully Paid</th><th class="text-right">Invoiced</th>
                        <th class="text-right">Collected</th><th class="text-right">Outstanding</th>
                        <th class="text-right">Collection %</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('financial')" :key="r.period">
                            <tr>
                                <td x-text="r.period_label"></td>
                                <td class="text-right" x-text="r.invoice_count"></td>
                                <td class="text-right" x-text="r.fully_paid_count"></td>
                                <td class="text-right font-mono" x-text="money(r.invoiced)"></td>
                                <td class="text-right font-mono" x-text="money(r.collected)"></td>
                                <td class="text-right font-mono" x-text="money(r.outstanding)"></td>
                                <td class="text-right font-mono" :class="r.collection_rate < 80 ? 'text-danger' : r.collection_rate < 95 ? 'text-warning' : 'text-success'"
                                    x-text="r.collection_rate + '%'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Invoice Status ── -->
        <div x-show="tabs.financial.view==='status' && !tabs.financial.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Invoice Status Distribution</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('revenue','status')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="display:flex;gap:20px;flex-wrap:wrap;">
                    <div id="chart-inv-status" style="height:260px;flex:1;min-width:260px;"></div>
                    <div style="flex:1;min-width:200px;overflow-x:auto;">
                        <table class="table">
                            <thead><tr><th>Status</th><th class="text-right">Count</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
                            <tbody>
                                <template x-for="r in viewData('financial')" :key="r.status">
                                    <tr>
                                        <td><span class="badge" :class="invStatusBadge(r.status)" x-text="r.status"></span></td>
                                        <td class="text-right" x-text="r.invoice_count"></td>
                                        <td class="text-right font-mono" x-text="money(r.total_amount)"></td>
                                        <td class="text-right font-mono" x-text="money(r.balance_due)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /financial tab -->

    <!-- ════════════════════════════════════════════════════════════
         FLEET TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='fleet'">

        <template x-if="tabs.fleet.kpis">
            <div class="stat-grid mb-4">
                <div class="stat-card stat-card--blue">
                    <div class="stat-label">Avg Utilization</div>
                    <div class="stat-value font-mono" x-text="tabs.fleet.kpis.avg_utilization + '%'"></div>
                    <div class="stat-delta" x-text="tabs.fleet.kpis.period_days + '-day period'"></div>
                </div>
                <div class="stat-card stat-card--green">
                    <div class="stat-label">Total Units</div>
                    <div class="stat-value font-mono" x-text="tabs.fleet.kpis.total_units"></div>
                    <div class="stat-delta" x-text="tabs.fleet.kpis.active_units + ' active'"></div>
                </div>
                <div class="stat-card stat-card--amber">
                    <div class="stat-label">Idle Units</div>
                    <div class="stat-value font-mono" x-text="tabs.fleet.kpis.idle_units"></div>
                    <div class="stat-delta">0 days leased in period</div>
                </div>
                <div class="stat-card stat-card--green">
                    <div class="stat-label">Fleet Revenue</div>
                    <div class="stat-value font-mono" x-text="money(tabs.fleet.kpis.total_revenue)"></div>
                </div>
                <div class="stat-card stat-card--red">
                    <div class="stat-label">Maintenance Cost</div>
                    <div class="stat-value font-mono" x-text="money(tabs.fleet.kpis.total_maint_cost)"></div>
                </div>
                <div class="stat-card" :class="tabs.fleet.kpis.total_roi >= 0 ? 'stat-card--green' : 'stat-card--red'">
                    <div class="stat-label">Fleet ROI</div>
                    <div class="stat-value font-mono" x-text="money(tabs.fleet.kpis.total_roi)"></div>
                    <div class="stat-delta">Revenue – Maintenance</div>
                </div>
            </div>
        </template>

        <div class="tab-bar no-print" style="margin-bottom:16px;">
            <button class="tab-btn" :class="{active:tabs.fleet.view==='utilization'}"  @click="switchView('fleet','utilization')">Utilization</button>
            <button class="tab-btn" :class="{active:tabs.fleet.view==='roi'}"           @click="switchView('fleet','roi')">ROI Ranking</button>
            <button class="tab-btn" :class="{active:tabs.fleet.view==='idle'}"          @click="switchView('fleet','idle')">Idle Units</button>
            <button class="tab-btn" :class="{active:tabs.fleet.view==='maintenance'}"   @click="switchView('fleet','maintenance')">Maintenance</button>
            <button class="tab-btn" :class="{active:tabs.fleet.view==='yard'}"          @click="switchView('fleet','yard')">By Yard</button>
        </div>

        <template x-if="tabs.fleet.viewLoading">
            <div class="card"><div class="card-body"><div class="skeleton-chart" style="height:260px;"></div></div></div>
        </template>

        <!-- ── Utilization ── -->
        <div x-show="tabs.fleet.view==='utilization' && !tabs.fleet.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Unit Utilization</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('fleet','utilization')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-fleet-util" style="height:300px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Unit #</th><th>Type</th><th>Yard</th><th>Status</th>
                        <th class="text-right">Days Leased</th><th class="text-right">Period Days</th>
                        <th class="text-right">Utilization %</th><th class="text-right">Revenue</th>
                        <th class="text-right">Maint. Cost</th><th class="text-right">ROI</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('fleet')" :key="r.id">
                            <tr :class="r.is_idle ? 'row-muted' : ''">
                                <td class="font-mono" x-text="r.unit_number"></td>
                                <td x-text="r.equipment_type"></td>
                                <td x-text="r.yard_location || '—'"></td>
                                <td><span class="badge" :class="unitStatusBadge(r.status)" x-text="r.status"></span></td>
                                <td class="text-right" x-text="r.days_on_lease"></td>
                                <td class="text-right text-muted" x-text="r.total_period_days"></td>
                                <td class="text-right font-mono" :class="utilColor(r.utilization_pct)" x-text="r.utilization_pct + '%'"></td>
                                <td class="text-right font-mono" x-text="money(r.revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td>
                                <td class="text-right font-mono" :class="r.roi < 0 ? 'text-danger' : 'text-success'" x-text="money(r.roi)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── ROI Ranking ── -->
        <div x-show="tabs.fleet.view==='roi' && !tabs.fleet.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Equipment ROI Ranking (Revenue – Maintenance Cost)</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('fleet','roi')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-fleet-roi" style="height:320px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>Unit #</th><th>Type</th><th>Yard</th>
                        <th class="text-right">Revenue</th><th class="text-right">Labor</th>
                        <th class="text-right">Parts</th><th class="text-right">Maintenance</th>
                        <th class="text-right">ROI</th><th class="text-right">Work Orders</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in viewData('fleet')" :key="r.id">
                            <tr>
                                <td class="text-muted" x-text="i+1"></td>
                                <td class="font-mono" x-text="r.unit_number"></td>
                                <td x-text="r.equipment_type"></td>
                                <td x-text="r.yard_location || '—'"></td>
                                <td class="text-right font-mono" x-text="money(r.revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.labor_cost)"></td>
                                <td class="text-right font-mono" x-text="money(r.parts_cost)"></td>
                                <td class="text-right font-mono text-danger" x-text="money(r.maintenance_cost)"></td>
                                <td class="text-right font-mono" :class="r.roi < 0 ? 'text-danger' : 'text-success'" x-text="money(r.roi)"></td>
                                <td class="text-right" x-text="r.work_order_count"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Idle Units ── -->
        <div x-show="tabs.fleet.view==='idle' && !tabs.fleet.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Idle Units — 0 Days Leased in Period</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('fleet','idle')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="display:flex;gap:20px;flex-wrap:wrap;">
                    <div id="chart-fleet-idle" style="height:240px;flex:1;min-width:240px;"></div>
                    <div style="flex:2;min-width:260px;overflow-x:auto;">
                        <table class="table">
                            <thead><tr><th>Unit #</th><th>Type</th><th>Yard</th><th>Status</th><th class="text-right">Maint. Cost</th><th class="text-right">Work Orders</th></tr></thead>
                            <tbody>
                                <template x-for="r in viewData('fleet')" :key="r.id">
                                    <tr>
                                        <td class="font-mono" x-text="r.unit_number"></td>
                                        <td x-text="r.equipment_type"></td>
                                        <td x-text="r.yard_location || '—'"></td>
                                        <td><span class="badge" :class="unitStatusBadge(r.status)" x-text="r.status"></span></td>
                                        <td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td>
                                        <td class="text-right" x-text="r.work_order_count"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <template x-if="!viewData('fleet').length && !tabs.fleet.viewLoading">
                            <div class="empty-state" style="padding:32px;">
                                <svg width="40" height="40" style="color:var(--color-success);margin:0 auto 8px;display:block;"><use href="#icon-check-circle"/></svg>
                                <p class="empty-state-title">No idle units</p>
                                <p class="empty-state-text">All units were active during this period.</p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Maintenance by Type ── -->
        <div x-show="tabs.fleet.view==='maintenance' && !tabs.fleet.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Maintenance Cost by Work Type</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('fleet','maintenance')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="display:flex;gap:20px;flex-wrap:wrap;">
                    <div id="chart-maint-type" style="height:260px;flex:1;min-width:240px;"></div>
                    <div style="flex:2;min-width:260px;overflow-x:auto;">
                        <table class="table">
                            <thead><tr><th>Work Type</th><th class="text-right">Work Orders</th><th class="text-right">Units</th><th class="text-right">Labor</th><th class="text-right">Parts</th><th class="text-right">Total Cost</th><th class="text-right">Avg Cost</th></tr></thead>
                            <tbody>
                                <template x-for="r in viewData('fleet')" :key="r.work_type">
                                    <tr>
                                        <td x-text="r.work_type.replace(/_/g,' ')"></td>
                                        <td class="text-right" x-text="r.work_order_count"></td>
                                        <td class="text-right" x-text="r.units_affected"></td>
                                        <td class="text-right font-mono" x-text="money(r.labor_cost)"></td>
                                        <td class="text-right font-mono" x-text="money(r.parts_cost)"></td>
                                        <td class="text-right font-mono" x-text="money(r.total_cost)"></td>
                                        <td class="text-right font-mono" x-text="money(r.avg_cost)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── By Yard ── -->
        <div x-show="tabs.fleet.view==='yard' && !tabs.fleet.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Fleet Performance by Yard</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('fleet','yard')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-fleet-yard" style="height:260px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Yard</th><th class="text-right">Units</th><th class="text-right">Idle</th><th class="text-right">Avg Utilization</th><th class="text-right">Revenue</th><th class="text-right">Maintenance</th><th class="text-right">ROI</th></tr></thead>
                    <tbody>
                        <template x-for="r in viewData('fleet')" :key="r.yard_location">
                            <tr>
                                <td x-text="r.yard_location"></td>
                                <td class="text-right" x-text="r.unit_count"></td>
                                <td class="text-right" x-text="r.idle_count"></td>
                                <td class="text-right font-mono" :class="utilColor(r.avg_utilization)" x-text="r.avg_utilization + '%'"></td>
                                <td class="text-right font-mono" x-text="money(r.revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.maintenance)"></td>
                                <td class="text-right font-mono" :class="r.roi < 0 ? 'text-danger' : 'text-success'" x-text="money(r.roi)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div><!-- /fleet tab -->

    <!-- ════════════════════════════════════════════════════════════
         CUSTOMER TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='customer'">

        <template x-if="tabs.customer.kpis">
            <div class="stat-grid mb-4">
                <div class="stat-card stat-card--blue">
                    <div class="stat-label">Unique Customers</div>
                    <div class="stat-value" x-text="tabs.customer.kpis.unique_customers"></div>
                    <div class="stat-delta" x-text="tabs.customer.kpis.active_customers + ' active'"></div>
                </div>
                <div class="stat-card stat-card--green">
                    <div class="stat-label">Period Revenue</div>
                    <div class="stat-value font-mono" x-text="money(tabs.customer.kpis.total_revenue)"></div>
                </div>
                <div class="stat-card stat-card--purple">
                    <div class="stat-label">Avg Invoice Value</div>
                    <div class="stat-value font-mono" x-text="money(tabs.customer.kpis.avg_invoice_value)"></div>
                </div>
                <div class="stat-card" :class="tabs.customer.kpis.avg_days_to_pay > 30 ? 'stat-card--red' : tabs.customer.kpis.avg_days_to_pay > 15 ? 'stat-card--amber' : 'stat-card--green'">
                    <div class="stat-label">Avg Days to Pay</div>
                    <div class="stat-value font-mono" x-text="tabs.customer.kpis.avg_days_to_pay + 'd'"></div>
                </div>
                <div class="stat-card stat-card--teal">
                    <div class="stat-label">Invoices</div>
                    <div class="stat-value" x-text="tabs.customer.kpis.invoice_count"></div>
                </div>
                <div class="stat-card stat-card--slate">
                    <div class="stat-label">Leases</div>
                    <div class="stat-value" x-text="tabs.customer.kpis.lease_count"></div>
                </div>
            </div>
        </template>

        <div class="tab-bar no-print" style="margin-bottom:16px;">
            <button class="tab-btn" :class="{active:tabs.customer.view==='ltv'}"              @click="switchView('customer','ltv')">Lifetime Value</button>
            <button class="tab-btn" :class="{active:tabs.customer.view==='payment_behavior'}" @click="switchView('customer','payment_behavior')">Payment Behavior</button>
            <button class="tab-btn" :class="{active:tabs.customer.view==='new_returning'}"    @click="switchView('customer','new_returning')">New vs Returning</button>
            <button class="tab-btn" :class="{active:tabs.customer.view==='frequency'}"        @click="switchView('customer','frequency')">Lease Frequency</button>
            <button class="tab-btn" :class="{active:tabs.customer.view==='credit_notes'}"     @click="switchView('customer','credit_notes')">Credit Notes</button>
        </div>

        <template x-if="tabs.customer.viewLoading">
            <div class="card"><div class="card-body"><div class="skeleton-chart" style="height:260px;"></div></div></div>
        </template>

        <!-- ── LTV Ranking ── -->
        <div x-show="tabs.customer.view==='ltv' && !tabs.customer.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Customer Lifetime Value</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('customer','ltv')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-cust-ltv" style="height:300px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>Customer</th><th>Status</th><th>Since</th>
                        <th class="text-right">Lifetime Revenue</th><th class="text-right">Collected</th>
                        <th class="text-right">Outstanding</th><th class="text-right">Period Rev.</th>
                        <th class="text-right">Invoices</th><th class="text-right">Leases</th>
                        <th class="text-right">Credit Issued</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in viewData('customer')" :key="r.customer_id">
                            <tr>
                                <td class="text-muted" x-text="i+1"></td>
                                <td x-text="r.company_name"></td>
                                <td><span class="badge" :class="'badge-' + customerBadge(r.customer_status)" x-text="r.customer_status || '—'"></span></td>
                                <td class="text-muted" style="font-size:11px;" x-text="(r.customer_since||'').slice(0,10)"></td>
                                <td class="text-right font-mono" x-text="money(r.lifetime_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.lifetime_collected)"></td>
                                <td class="text-right font-mono" :class="r.lifetime_outstanding > 0 ? 'text-danger' : ''" x-text="money(r.lifetime_outstanding)"></td>
                                <td class="text-right font-mono" x-text="money(r.period_revenue)"></td>
                                <td class="text-right" x-text="r.total_invoices"></td>
                                <td class="text-right" x-text="r.total_leases"></td>
                                <td class="text-right font-mono" x-text="money(r.credit_issued)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Payment Behavior ── -->
        <div x-show="tabs.customer.view==='payment_behavior' && !tabs.customer.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Avg Days to Pay by Customer</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('customer','payment_behavior')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-pay-behavior" style="height:280px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Customer</th><th class="text-right">Paid Invoices</th>
                        <th class="text-right">Avg Days</th><th class="text-right">Best</th>
                        <th class="text-right">Worst</th><th class="text-right">On Time</th>
                        <th class="text-right">Late</th><th class="text-right">Amount Paid</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('customer')" :key="r.customer_id">
                            <tr>
                                <td x-text="r.company_name"></td>
                                <td class="text-right" x-text="r.paid_invoice_count"></td>
                                <td class="text-right font-mono" :class="r.avg_days_to_pay > 30 ? 'text-danger' : r.avg_days_to_pay > 0 ? 'text-warning' : 'text-success'"
                                    x-text="r.avg_days_to_pay + 'd'"></td>
                                <td class="text-right text-success font-mono" x-text="r.min_days + 'd'"></td>
                                <td class="text-right text-danger font-mono" x-text="r.max_days + 'd'"></td>
                                <td class="text-right text-success" x-text="r.on_time_count"></td>
                                <td class="text-right text-danger" x-text="r.late_count"></td>
                                <td class="text-right font-mono" x-text="money(r.total_paid_amount)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── New vs Returning ── -->
        <div x-show="tabs.customer.view==='new_returning' && !tabs.customer.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">New vs Returning Customer Revenue</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('customer','new_returning')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-new-returning" style="height:280px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Period</th><th class="text-right">New Revenue</th><th class="text-right">Returning Revenue</th>
                        <th class="text-right">Total</th><th class="text-right">New Customers</th><th class="text-right">Returning</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('customer')" :key="r.period">
                            <tr>
                                <td x-text="r.period_label"></td>
                                <td class="text-right font-mono text-success" x-text="money(r.new_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.returning_revenue)"></td>
                                <td class="text-right font-mono" x-text="money(r.total_revenue)"></td>
                                <td class="text-right text-success" x-text="r.new_customers"></td>
                                <td class="text-right" x-text="r.returning_customers"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Lease Frequency ── -->
        <div x-show="tabs.customer.view==='frequency' && !tabs.customer.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Customer Lease Frequency</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('customer','frequency')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-cust-freq" style="height:260px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>Customer</th><th>Status</th>
                        <th class="text-right">Leases</th><th class="text-right">Active</th>
                        <th class="text-right">Completed</th><th class="text-right">Avg Lease Days</th>
                        <th class="text-right">Equipment Types</th><th>First Lease</th><th>Last Lease</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in viewData('customer')" :key="r.customer_id">
                            <tr>
                                <td class="text-muted" x-text="i+1"></td>
                                <td x-text="r.company_name"></td>
                                <td><span class="badge" :class="'badge-' + customerBadge(r.customer_status)" x-text="r.customer_status || '—'"></span></td>
                                <td class="text-right" x-text="r.lease_count"></td>
                                <td class="text-right text-success" x-text="r.active_leases"></td>
                                <td class="text-right" x-text="r.completed_leases"></td>
                                <td class="text-right font-mono" x-text="parseFloat(r.avg_lease_days).toFixed(1) + 'd'"></td>
                                <td class="text-right" x-text="r.equipment_categories_used"></td>
                                <td class="text-muted" style="font-size:11px;" x-text="r.first_lease_date"></td>
                                <td class="text-muted" style="font-size:11px;" x-text="r.last_lease_date"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Credit Notes ── -->
        <div x-show="tabs.customer.view==='credit_notes' && !tabs.customer.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Credit Note Impact by Customer</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCsv('customer','credit_notes')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-credit-notes" style="height:260px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr>
                        <th>Customer</th><th class="text-right">Credits</th>
                        <th class="text-right">Total Issued</th><th class="text-right">Used</th>
                        <th class="text-right">Remaining</th><th class="text-right">Avg Amount</th>
                        <th class="text-right">Active</th><th class="text-right">Used Up</th><th class="text-right">Expired</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in viewData('customer')" :key="r.customer_id">
                            <tr>
                                <td x-text="r.company_name"></td>
                                <td class="text-right" x-text="r.credit_note_count"></td>
                                <td class="text-right font-mono" x-text="money(r.total_issued)"></td>
                                <td class="text-right font-mono" x-text="money(r.total_used)"></td>
                                <td class="text-right font-mono text-success" x-text="money(r.total_remaining)"></td>
                                <td class="text-right font-mono" x-text="money(r.avg_credit_amount)"></td>
                                <td class="text-right text-info" x-text="r.active_credits"></td>
                                <td class="text-right text-muted" x-text="r.fully_used"></td>
                                <td class="text-right text-danger" x-text="r.expired_credits"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div><!-- /customer tab -->

    <!-- ════════════════════════════════════════════════════════════
         COMPLIANCE TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='compliance'">

        <!-- Compliance uses a window selector, not a date range -->
        <div class="card mb-4 no-print">
            <div class="card-body" style="padding:12px 16px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:600;color:var(--text-secondary);">LOOK-AHEAD WINDOW</span>
                    <template x-for="w in [30,60,90,180,365]" :key="w">
                        <button class="btn btn-sm" :class="compWindow===w ? 'btn-primary' : 'btn-secondary'"
                            @click="setCompWindow(w)" x-text="'Next ' + w + ' days'">
                        </button>
                    </template>
                    <span x-show="tabs.compliance.loading" style="font-size:12px;color:var(--text-muted);">Loading...</span>
                </div>
            </div>
        </div>

        <template x-if="tabs.compliance.kpis">
            <div class="stat-grid mb-4">
                <div class="stat-card stat-card--slate">
                    <div class="stat-label">Total Units Tracked</div>
                    <div class="stat-value" x-text="tabs.compliance.kpis.total_units"></div>
                </div>
                <div class="stat-card stat-card--red">
                    <div class="stat-label">Expired Documents</div>
                    <div class="stat-value" x-text="tabs.compliance.kpis.expired_count"></div>
                    <div class="stat-delta">Past expiry date</div>
                </div>
                <div class="stat-card stat-card--amber">
                    <div class="stat-label">Expiring ≤30 Days</div>
                    <div class="stat-value" x-text="tabs.compliance.kpis.expiring_30"></div>
                </div>
                <div class="stat-card stat-card--blue">
                    <div class="stat-label">Expiring ≤90 Days</div>
                    <div class="stat-value" x-text="tabs.compliance.kpis.expiring_90"></div>
                </div>
                <div class="stat-card stat-card--green">
                    <div class="stat-label">Compliant Units</div>
                    <div class="stat-value" x-text="tabs.compliance.kpis.ok_count"></div>
                    <div class="stat-delta">>90 days remaining</div>
                </div>
                <div class="stat-card stat-card--slate">
                    <div class="stat-label">As Of</div>
                    <div class="stat-value" style="font-size:14px;font-family:var(--font-mono);" x-text="tabs.compliance.kpis.as_of_date"></div>
                </div>
            </div>
        </template>

        <div class="tab-bar no-print" style="margin-bottom:16px;">
            <button class="tab-btn" :class="{active:tabs.compliance.view==='timeline'}" @click="switchView('compliance','timeline')">Expiry Timeline</button>
            <button class="tab-btn" :class="{active:tabs.compliance.view==='status'}"   @click="switchView('compliance','status')">Status Snapshot</button>
            <button class="tab-btn" :class="{active:tabs.compliance.view==='expired'}"  @click="switchView('compliance','expired')">Expired</button>
            <button class="tab-btn" :class="{active:tabs.compliance.view==='upcoming'}" @click="switchView('compliance','upcoming')">Upcoming Renewals</button>
        </div>

        <template x-if="tabs.compliance.viewLoading">
            <div class="card"><div class="card-body"><div class="skeleton-chart" style="height:260px;"></div></div></div>
        </template>

        <!-- ── Expiry Timeline ── -->
        <div x-show="tabs.compliance.view==='timeline' && !tabs.compliance.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title" x-text="'Document Expiry Timeline — Next ' + compWindow + ' Days'"></span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCompCsv('timeline')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body"><div id="chart-comp-timeline" style="height:280px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table">
                    <thead><tr><th>Month</th><th class="text-right">CVI Expirations</th><th class="text-right">Registration</th><th class="text-right">Insurance</th><th class="text-right">Total</th></tr></thead>
                    <tbody>
                        <template x-for="r in viewData('compliance')" :key="r.period">
                            <tr>
                                <td x-text="r.period_label"></td>
                                <td class="text-right" :class="r.cvi_count > 0 ? 'text-warning' : ''" x-text="r.cvi_count"></td>
                                <td class="text-right" :class="r.reg_count > 0 ? 'text-warning' : ''" x-text="r.reg_count"></td>
                                <td class="text-right" :class="r.ins_count > 0 ? 'text-warning' : ''" x-text="r.ins_count"></td>
                                <td class="text-right font-mono" :class="r.total > 0 ? 'text-danger' : ''" x-text="r.total"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div></div>
        </div>

        <!-- ── Status Snapshot ── -->
        <div x-show="tabs.compliance.view==='status' && !tabs.compliance.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Document Status Snapshot</span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCompCsv('status')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="display:flex;gap:20px;flex-wrap:wrap;">
                    <div id="chart-comp-status" style="height:260px;flex:1;min-width:240px;"></div>
                    <div style="flex:2;min-width:260px;">
                        <template x-if="tabs.compliance.viewData.status && tabs.compliance.viewData.status.chart_data">
                            <table class="table">
                                <thead><tr><th>Document</th><th class="text-right text-danger">Expired</th><th class="text-right text-warning">≤30d</th><th class="text-right text-info">31–90d</th><th class="text-right text-success">OK</th><th class="text-right text-muted">No Date</th></tr></thead>
                                <tbody>
                                    <template x-for="(label, i) in tabs.compliance.viewData.status.chart_data.labels" :key="label">
                                        <tr>
                                            <td x-text="label"></td>
                                            <td class="text-right text-danger" x-text="tabs.compliance.viewData.status.chart_data.expired[i]"></td>
                                            <td class="text-right text-warning" x-text="tabs.compliance.viewData.status.chart_data.expiring_30[i]"></td>
                                            <td class="text-right text-info" x-text="tabs.compliance.viewData.status.chart_data.expiring_90[i]"></td>
                                            <td class="text-right text-success" x-text="tabs.compliance.viewData.status.chart_data.ok[i]"></td>
                                            <td class="text-right text-muted" x-text="tabs.compliance.viewData.status.chart_data.missing[i]"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Expired ── -->
        <div x-show="tabs.compliance.view==='expired' && !tabs.compliance.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title">Expired Documents <span class="badge badge-danger" x-text="viewData('compliance').length"></span></span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCompCsv('expired')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="overflow-x:auto;">
                    <table class="table">
                        <thead><tr>
                            <th>Unit #</th><th>Yard</th><th>Status</th>
                            <th class="text-right">CVI Expiry</th><th class="text-right">CVI Overdue</th>
                            <th class="text-right">Reg. Expiry</th><th class="text-right">Reg. Overdue</th>
                            <th class="text-right">Insurance Expiry</th><th class="text-right">Ins. Overdue</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in viewData('compliance')" :key="r.id">
                                <tr>
                                    <td class="font-mono" x-text="r.unit_number"></td>
                                    <td x-text="r.yard_location || '—'"></td>
                                    <td><span class="badge" :class="unitStatusBadge(r.status)" x-text="r.status"></span></td>
                                    <td class="text-right font-mono" :class="r.cvi_expiry && r.cvi_days_overdue > 0 ? 'text-danger' : ''" x-text="r.cvi_expiry || '—'"></td>
                                    <td class="text-right text-danger" x-text="r.cvi_days_overdue > 0 ? r.cvi_days_overdue + 'd' : ''"></td>
                                    <td class="text-right font-mono" :class="r.registration_expiry && r.reg_days_overdue > 0 ? 'text-danger' : ''" x-text="r.registration_expiry || '—'"></td>
                                    <td class="text-right text-danger" x-text="r.reg_days_overdue > 0 ? r.reg_days_overdue + 'd' : ''"></td>
                                    <td class="text-right font-mono" :class="r.insurance_expiry && r.ins_days_overdue > 0 ? 'text-danger' : ''" x-text="r.insurance_expiry || '—'"></td>
                                    <td class="text-right text-danger" x-text="r.ins_days_overdue > 0 ? r.ins_days_overdue + 'd' : ''"></td>
                                </tr>
                            </template>
                            <template x-if="!viewData('compliance').length">
                                <tr><td colspan="9" class="text-center text-success" style="padding:24px;">All documents are current — no expired documents found.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Upcoming Renewals ── -->
        <div x-show="tabs.compliance.view==='upcoming' && !tabs.compliance.viewLoading">
            <div class="card mb-3">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <span class="card-title" x-text="'Upcoming Renewals — Next ' + compWindow + ' Days'"></span>
                    <button class="btn btn-secondary btn-sm no-print" @click="exportCompCsv('upcoming')">
                        <svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> Export CSV
                    </button>
                </div>
                <div class="card-body" style="overflow-x:auto;">
                    <table class="table">
                        <thead><tr>
                            <th>Unit #</th><th>Yard</th><th>Status</th>
                            <th class="text-right">CVI Expiry</th><th class="text-right">Days Left</th>
                            <th class="text-right">Reg. Expiry</th><th class="text-right">Days Left</th>
                            <th class="text-right">Insurance Expiry</th><th class="text-right">Days Left</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="r in viewData('compliance')" :key="r.id">
                                <tr>
                                    <td class="font-mono" x-text="r.unit_number"></td>
                                    <td x-text="r.yard_location || '—'"></td>
                                    <td><span class="badge" :class="unitStatusBadge(r.status)" x-text="r.status"></span></td>
                                    <td class="text-right font-mono" x-text="r.cvi_expiry || '—'"></td>
                                    <td class="text-right" :class="daysLeftClass(r.cvi_days_left)" x-text="r.cvi_days_left !== null && r.cvi_days_left !== '' ? r.cvi_days_left + 'd' : ''"></td>
                                    <td class="text-right font-mono" x-text="r.registration_expiry || '—'"></td>
                                    <td class="text-right" :class="daysLeftClass(r.reg_days_left)" x-text="r.reg_days_left !== null && r.reg_days_left !== '' ? r.reg_days_left + 'd' : ''"></td>
                                    <td class="text-right font-mono" x-text="r.insurance_expiry || '—'"></td>
                                    <td class="text-right" :class="daysLeftClass(r.ins_days_left)" x-text="r.ins_days_left !== null && r.ins_days_left !== '' ? r.ins_days_left + 'd' : ''"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div><!-- /compliance tab -->

</div><!-- /x-data reports-root -->

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>

<style>
/* ── Print styles ── */
@media print {
    .no-print, .sidebar, .topbar, #date-range-bar { display:none !important; }
    .tab-bar { display:none !important; }
    [x-show] { display:block !important; }
    .card { break-inside:avoid; box-shadow:none; border:1px solid #ddd; }
    .page-header-actions { display:none; }
    body::after {
        content: 'Printed: ' attr(data-print-date);
        display:block; text-align:right; font-size:10px; color:#666;
        padding-top:8px; border-top:1px solid #ddd; margin-top:16px;
    }
}
.text-success { color:var(--color-success) !important; }
.text-danger  { color:var(--color-danger)  !important; }
.text-warning { color:var(--color-warning) !important; }
.text-info    { color:var(--color-info)    !important; }
.text-right   { text-align:right; }
.row-muted    { opacity:.65; }
.mb-3 { margin-bottom:12px; }
.mb-4 { margin-bottom:20px; }
@keyframes spin { to { transform:rotate(360deg); } }
.skeleton-chart { background:var(--bg-muted); border-radius:6px; animation:shimmer 1.5s infinite; }
</style>

<script>
// Set print date on body for the CSS print timestamp
document.body.setAttribute('data-print-date', new Date().toLocaleString('en-CA'));

function FF_Reports() {
    return {
        mainTab: 'financial',
        preset:  'this_month',
        customFrom: '',
        customTo:   '',
        resolvedFrom: '',
        resolvedTo:   '',
        compWindow: 90,

        presetOptions: [
            {value:'this_month',   label:'This Month'},
            {value:'last_month',   label:'Last Month'},
            {value:'this_quarter', label:'This Quarter'},
            {value:'last_quarter', label:'Last Quarter'},
            {value:'this_year',    label:'This Year'},
            {value:'last_year',    label:'Last Year'},
            {value:'last_30',      label:'Last 30d'},
            {value:'last_90',      label:'Last 90d'},
            {value:'all_time',     label:'All Time'},
            {value:'custom',       label:'Custom'},
        ],

        tabs: {
            financial:  {loading:false, viewLoading:false, kpis:null, view:'period',       viewData:{}, chartsRendered:{}},
            fleet:      {loading:false, viewLoading:false, kpis:null, view:'utilization',  viewData:{}, chartsRendered:{}},
            customer:   {loading:false, viewLoading:false, kpis:null, view:'ltv',          viewData:{}, chartsRendered:{}},
            compliance: {loading:false, viewLoading:false, kpis:null, view:'timeline',     viewData:{}, chartsRendered:{}},
        },

        init() {
            this.loadTab('financial');
        },

        isLoading() {
            return this.tabs[this.mainTab]?.loading || this.tabs[this.mainTab]?.viewLoading;
        },

        setPreset(p) {
            this.preset = p;
            if (p !== 'custom') {
                // Invalidate all cached view data (date changed)
                for (const t in this.tabs) {
                    this.tabs[t].viewData  = {};
                    this.tabs[t].kpis      = null;
                    this.tabs[t].chartsRendered = {};
                }
                this.loadTab(this.mainTab);
            }
        },

        switchMainTab(tab) {
            this.mainTab = tab;
            const t = this.tabs[tab];
            if (!t.kpis && !t.loading) {
                this.loadTab(tab);
            } else if (t.kpis && !t.viewData[t.view]) {
                this.loadView(tab, t.view);
            } else if (t.kpis && t.viewData[t.view] && !t.chartsRendered[t.view]) {
                this.$nextTick(() => this.initChart(tab, t.view, t.viewData[t.view]));
            }
        },

        switchView(tab, view) {
            this.tabs[tab].view = view;
            if (!this.tabs[tab].viewData[view]) {
                this.loadView(tab, view);
            } else if (!this.tabs[tab].chartsRendered[view]) {
                this.$nextTick(() => this.initChart(tab, view, this.tabs[tab].viewData[view]));
            }
        },

        runReport() {
            for (const t in this.tabs) {
                this.tabs[t].viewData  = {};
                this.tabs[t].kpis      = null;
                this.tabs[t].chartsRendered = {};
            }
            this.loadTab(this.mainTab);
        },

        setCompWindow(days) {
            this.compWindow = days;
            this.tabs.compliance.viewData  = {};
            this.tabs.compliance.kpis      = null;
            this.tabs.compliance.chartsRendered = {};
            if (this.mainTab === 'compliance') this.loadTab('compliance');
        },

        buildParams(tab, view) {
            const p = { preset: this.preset };
            if (this.preset === 'custom') {
                if (this.customFrom) p.date_from = this.customFrom;
                if (this.customTo)   p.date_to   = this.customTo;
            }
            if (tab !== 'compliance') p.view = view;
            else { p.view = view; p.window_days = this.compWindow; }
            return new URLSearchParams(p).toString();
        },

        apiBase(tab) {
            const map = {financial:'revenue', fleet:'fleet', customer:'customer', compliance:'compliance'};
            return base_url('api/v1/reports/' + (map[tab]||tab) + '.php');
        },

        loadTab(tab) {
            const t = this.tabs[tab];
            t.loading = true;
            this.loadView(tab, t.view, true);
        },

        loadView(tab, view, isInit = false) {
            const t = this.tabs[tab];
            if (!isInit) t.viewLoading = true;
            else         t.loading = true;

            const url = this.apiBase(tab) + '?' + this.buildParams(tab, view);
            FF_Api.get(url).then(d => {
                if (!d.success) { t.loading = false; t.viewLoading = false; return; }
                const data = d.data;

                t.kpis             = data.kpis;
                t.viewData[view]   = data;
                this.resolvedFrom  = data.date_from  || data.as_of_date || '';
                this.resolvedTo    = data.date_to    || data.as_of_date || '';
                t.loading          = false;
                t.viewLoading      = false;

                if (this.mainTab === tab && t.view === view) {
                    this.$nextTick(() => this.initChart(tab, view, data));
                }
            }).catch(() => { t.loading = false; t.viewLoading = false; });
        },

        viewData(tab) {
            const t = this.tabs[tab];
            return t.viewData[t.view]?.table || [];
        },

        exportCsv(endpoint, view) {
            const p = this.buildParams(this.mainTab, view);
            window.location = base_url('api/v1/reports/' + endpoint + '.php') + '?' + p + '&format=csv';
        },

        exportCompCsv(view) {
            const p = new URLSearchParams({ view, window_days: this.compWindow, format: 'csv' });
            window.location = base_url('api/v1/reports/compliance.php') + '?' + p.toString();
        },

        // ── Chart initialization router ──────────────────────────────────────
        initChart(tab, view, data) {
            if (!data) return;
            const key = tab + '_' + view;
            if (this.tabs[tab].chartsRendered[key]) return;
            this.tabs[tab].chartsRendered[key] = true;

            const chartData = data.chart_data;
            if (!chartData || !Object.keys(chartData).length) return;

            const palette = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#f97316','#84cc16'];
            const isDark  = document.documentElement.getAttribute('data-theme') === 'dark';
            const textColor  = isDark ? '#9c9c96' : '#6b6b66';
            const gridColor  = isDark ? '#2e2e2e' : '#e5e5e2';
            const baseConfig = {
                chart:  { background:'transparent', toolbar:{show:true}, fontFamily:'inherit' },
                colors: palette,
                theme:  { mode: isDark ? 'dark' : 'light' },
                grid:   { borderColor: gridColor },
                xaxis:  { labels:{ style:{colors:textColor}, rotate:-30 } },
                yaxis:  { labels:{ style:{colors:textColor}, formatter: v => '$' + (v||0).toLocaleString('en-CA',{minimumFractionDigits:0,maximumFractionDigits:0}) } },
                tooltip:{ theme: isDark ? 'dark' : 'light' },
                dataLabels:{ enabled: false },
                legend: { labels:{ colors: textColor } },
            };

            const render = (elId, options) => {
                const el = document.getElementById(elId);
                if (!el) return;
                if (el._apexChart) { try { el._apexChart.destroy(); } catch(e){} }
                const chart = new ApexCharts(el, options);
                chart.render();
                el._apexChart = chart;
            };

            // ── Financial charts ──────────────────────────────────────────
            if (tab === 'financial') {
                if (view === 'period' && chartData.categories?.length) {
                    render('chart-rev-period', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:280},
                        series:[
                            {name:'Net Revenue', data: chartData.net_revenue || []},
                            {name:'Collected',   data: chartData.collected   || []},
                            {name:'Outstanding', data: chartData.outstanding || []},
                        ],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{borderRadius:3}},
                        yaxis:{labels:{style:{colors:textColor}, formatter:v=>'$'+(v||0).toLocaleString('en-CA',{minimumFractionDigits:0,maximumFractionDigits:0})}},
                    });
                }
                if (view === 'customer' && chartData.categories?.length) {
                    render('chart-rev-customer', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:320},
                        series:[{name:'Revenue', data: chartData.gross_revenue || []},{name:'Outstanding', data:chartData.outstanding||[]}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'equipment_type' && chartData.categories?.length) {
                    render('chart-rev-type', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:260},
                        series:[{name:'Gross Revenue', data: chartData.gross_revenue||[]}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'ar_aging' && chartData.categories?.length) {
                    render('chart-aging', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:220},
                        colors:['#ef4444','#f59e0b','#f97316','#8b5cf6','#6b7280'],
                        series:[{name:'Outstanding', data: chartData.amounts||[]}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'collection' && chartData.categories?.length) {
                    render('chart-collection', { ...baseConfig, chart:{...baseConfig.chart,type:'line',height:280},
                        series:[
                            {name:'Invoiced',  type:'bar',  data: chartData.invoiced  ||[]},
                            {name:'Collected', type:'bar',  data: chartData.collected ||[]},
                            {name:'Rate %',    type:'line', data: chartData.collection_rate||[]},
                        ],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        yaxis:[
                            {seriesName:'Invoiced',  labels:{style:{colors:textColor},formatter:v=>'$'+(v||0).toLocaleString('en-CA',{minimumFractionDigits:0,maximumFractionDigits:0})}},
                            {seriesName:'Collected', show:false},
                            {seriesName:'Rate %', opposite:true, min:0, max:100, labels:{style:{colors:textColor},formatter:v=>(v||0)+'%'}},
                        ],
                        stroke:{width:[0,0,2]},
                    });
                }
                if (view === 'status' && chartData.labels?.length) {
                    render('chart-inv-status', { ...baseConfig, chart:{...baseConfig.chart,type:'donut',height:260},
                        series: chartData.total_amounts||[],
                        labels: (chartData.labels||[]).map(l=>l.replace(/_/g,' ')),
                        yaxis: undefined,
                    });
                }
            }

            // ── Fleet charts ──────────────────────────────────────────────
            if (tab === 'fleet') {
                if (view === 'utilization' && chartData.categories?.length) {
                    render('chart-fleet-util', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:300},
                        series:[{name:'Utilization %', data: chartData.utilization_pct||[]}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        yaxis:{min:0,max:100,labels:{style:{colors:textColor},formatter:v=>(v||0)+'%'}},
                        plotOptions:{bar:{borderRadius:2,distributed:true}},
                        dataLabels:{enabled:false},
                    });
                }
                if (view === 'roi' && chartData.categories?.length) {
                    render('chart-fleet-roi', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:320},
                        series:[
                            {name:'Revenue',     data: chartData.revenue   ||[]},
                            {name:'Maintenance', data: chartData.maint_cost||[]},
                        ],
                        colors:['#10b981','#ef4444'],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'idle' && chartData.labels?.length) {
                    render('chart-fleet-idle', { ...baseConfig, chart:{...baseConfig.chart,type:'donut',height:240},
                        series: chartData.counts||[],
                        labels: chartData.labels||[],
                        yaxis: undefined,
                    });
                }
                if (view === 'maintenance' && chartData.labels?.length) {
                    render('chart-maint-type', { ...baseConfig, chart:{...baseConfig.chart,type:'donut',height:260},
                        series: chartData.total_cost||[],
                        labels: (chartData.labels||[]).map(l=>l.replace(/_/g,' ')),
                        yaxis: undefined,
                    });
                }
                if (view === 'yard' && chartData.categories?.length) {
                    render('chart-fleet-yard', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:260},
                        series:[
                            {name:'Revenue',     data: chartData.revenue    ||[]},
                            {name:'Maintenance', data: chartData.maintenance||[]},
                        ],
                        colors:['#10b981','#ef4444'],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{borderRadius:2}},
                    });
                }
            }

            // ── Customer charts ───────────────────────────────────────────
            if (tab === 'customer') {
                if (view === 'ltv' && chartData.categories?.length) {
                    render('chart-cust-ltv', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:300},
                        series:[
                            {name:'Lifetime Revenue', data: chartData.lifetime_revenue||[]},
                            {name:'Period Revenue',   data: chartData.period_revenue  ||[]},
                        ],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'payment_behavior' && chartData.categories?.length) {
                    render('chart-pay-behavior', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:280},
                        series:[{name:'Avg Days to Pay', data: chartData.avg_days_to_pay||[], color:'#f59e0b'}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>(v||0).toFixed(0)+'d'}},
                        annotations:{yaxis:[{y:0,borderColor:'#6b7280',label:{text:'Due Date',style:{color:textColor,background:'transparent'}}}]},
                        plotOptions:{bar:{borderRadius:2,distributed:false}},
                    });
                }
                if (view === 'new_returning' && chartData.categories?.length) {
                    render('chart-new-returning', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:280},
                        series:[
                            {name:'New Revenue',       data: chartData.new_revenue      ||[]},
                            {name:'Returning Revenue', data: chartData.returning_revenue||[]},
                        ],
                        colors:['#10b981','#3b82f6'],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{borderRadius:2,stacked:true}},
                    });
                }
                if (view === 'frequency' && chartData.categories?.length) {
                    render('chart-cust-freq', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:260},
                        series:[{name:'Leases', data: chartData.lease_count||[]}],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{horizontal:true,borderRadius:2}},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>v}},
                    });
                }
                if (view === 'credit_notes' && chartData.categories?.length) {
                    render('chart-credit-notes', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:260},
                        series:[
                            {name:'Issued',    data: chartData.total_issued   ||[]},
                            {name:'Used',      data: chartData.total_used     ||[]},
                            {name:'Remaining', data: chartData.total_remaining||[]},
                        ],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        plotOptions:{bar:{borderRadius:2}},
                    });
                }
            }

            // ── Compliance charts ─────────────────────────────────────────
            if (tab === 'compliance') {
                if (view === 'timeline' && chartData.categories?.length) {
                    render('chart-comp-timeline', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:280},
                        series:[
                            {name:'CVI',          data: chartData.cvi         ||[]},
                            {name:'Registration', data: chartData.registration||[]},
                            {name:'Insurance',    data: chartData.insurance   ||[]},
                        ],
                        colors:['#3b82f6','#10b981','#f59e0b'],
                        xaxis:{...baseConfig.xaxis, categories: chartData.categories},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>Math.round(v)}},
                        plotOptions:{bar:{borderRadius:2,stacked:false}},
                    });
                }
                if (view === 'status' && chartData.labels?.length) {
                    render('chart-comp-status', { ...baseConfig, chart:{...baseConfig.chart,type:'bar',height:260},
                        series:[
                            {name:'Expired',    data: chartData.expired    ||[]},
                            {name:'≤30d',       data: chartData.expiring_30||[]},
                            {name:'31–90d',     data: chartData.expiring_90||[]},
                            {name:'OK',         data: chartData.ok         ||[]},
                            {name:'No Date',    data: chartData.missing    ||[]},
                        ],
                        colors:['#ef4444','#f59e0b','#06b6d4','#10b981','#9ca3af'],
                        xaxis:{...baseConfig.xaxis, categories: chartData.labels},
                        yaxis:{labels:{style:{colors:textColor},formatter:v=>Math.round(v)}},
                        plotOptions:{bar:{borderRadius:2}},
                    });
                }
            }
        },

        // ── Display helpers ───────────────────────────────────────────────────
        money(val) {
            const n = parseFloat(val || 0);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
        },
        pct(val) { return (parseFloat(val)||0).toFixed(1) + '%'; },

        customerBadge(s) {
            return {active:'success',inactive:'neutral',pending:'info',suspended:'danger',credit_hold:'warning'}[s] || 'neutral';
        },
        unitStatusBadge(s) {
            return {available:'badge-success',on_lease:'badge-info',reserved:'badge-purple',maintenance:'badge-warning',inactive:'badge-neutral',decommissioned:'badge-danger'}[s] || 'badge-neutral';
        },
        invStatusBadge(s) {
            return {draft:'badge-neutral',sent:'badge-info',paid:'badge-success',partially_paid:'badge-warning',overdue:'badge-danger',void:'badge-neutral',written_off:'badge-danger'}[s] || 'badge-neutral';
        },
        agingBadge(b) {
            return {current:'badge-success','1_30':'badge-warning','31_60':'badge-warning','61_90':'badge-danger','90_plus':'badge-danger'}[b] || 'badge-neutral';
        },
        utilColor(pct) {
            const p = parseFloat(pct||0);
            return p >= 70 ? 'text-success' : p >= 40 ? 'text-warning' : 'text-danger';
        },
        daysLeftClass(days) {
            if (days === null || days === '') return '';
            const d = parseInt(days);
            return d <= 7 ? 'text-danger' : d <= 30 ? 'text-warning' : 'text-success';
        },
    };
}
</script>
