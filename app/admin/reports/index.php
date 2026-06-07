<?php
declare(strict_types=1);
/**
 * app/admin/reports/index.php
 *
 * Reports Module — 4-tab report centre: Financial, Fleet, Customer, Compliance.
 * Each tab has sub-views, KPI tiles, ApexCharts visualisations, data tables,
 * CSV export, and print support.  All data fetched from api/v1/reports/ endpoints.
 *
 * Designed for scale: 1000+ units.  Fleet charts use distribution histograms
 * (client-side binning) instead of per-unit bars.  All tables capped at 25 rows
 * with expand toggle.  Charts summarise patterns; tables provide detail.
 *
 * Required by: sidebar navigation
 * Requires: includes/auth.php, includes/header.php, app.css, app.js, ApexCharts
 *
 * Spec ref:  §7.10 Reports, PROGRESS.md S021
 * Decisions: D7 (base_url), D17 (PSR-4), D32 (only confirmed CSS classes)
 */

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('reports', 'view');

$pageTitle      = 'Reports';
$helpModuleSlug = 'reports';
$canExport      = can('reports', 'view');
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reports</h1>
        <p class="page-subtitle">Financial, fleet, customer, and compliance analytics</p>
    </div>
    <div class="page-header-actions no-print">
        <?= help_button('reports') ?>
        <button class="btn btn-secondary btn-sm" onclick="window.print()">
            <svg width="14" height="14"><use href="#icon-printer"/></svg> Print
        </button>
    </div>
</div>

<div x-data="FF_Reports()" x-init="init()" x-cloak id="reports-root">

    <!-- ── Date range bar ── -->
    <div class="card rpt-toolbar no-print" id="date-range-bar">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 16px;">
            <span class="rpt-toolbar-label">DATE RANGE</span>
            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <template x-for="p in presetOptions" :key="p.value">
                    <button class="btn btn-sm" :class="preset===p.value?'btn-primary':'btn-secondary'" @click="setPreset(p.value)" x-text="p.label"></button>
                </template>
            </div>
            <template x-if="preset==='custom'">
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="date" class="form-control rpt-date-input" x-model="customFrom" :max="customTo||''">
                    <span style="color:var(--text-muted);font-size:12px;">→</span>
                    <input type="date" class="form-control rpt-date-input" x-model="customTo" :min="customFrom||''">
                    <button class="btn btn-primary btn-sm" @click="runReport()">Go</button>
                </div>
            </template>
            <span x-show="isLoading()" class="rpt-spinner-text"><span class="rpt-spinner"></span> Loading…</span>
            <span x-show="resolvedFrom&&resolvedTo&&!isLoading()" class="rpt-date-range" x-text="resolvedFrom+' → '+resolvedTo"></span>
        </div>
    </div>

    <!-- ── Main tab bar ── -->
    <div class="tab-bar no-print" style="margin-bottom:20px;">
        <button class="tab-btn" :class="{'is-active':mainTab==='financial'}"  @click="switchMainTab('financial')"><svg width="14" height="14"><use href="#icon-banknotes"/></svg> Financial</button>
        <button class="tab-btn" :class="{'is-active':mainTab==='fleet'}"      @click="switchMainTab('fleet')"><svg width="14" height="14"><use href="#icon-truck"/></svg> Fleet</button>
        <button class="tab-btn" :class="{'is-active':mainTab==='customer'}"   @click="switchMainTab('customer')"><svg width="14" height="14"><use href="#icon-user-group"/></svg> Customers</button>
        <button class="tab-btn" :class="{'is-active':mainTab==='compliance'}" @click="switchMainTab('compliance')"><svg width="14" height="14"><use href="#icon-shield-check"/></svg> Compliance</button>
        <?php if (can('ai', 'view')): ?>
        <button class="tab-btn" :class="{'is-active':mainTab==='ai'}" @click="switchMainTab('ai')" style="margin-left:auto;">
            <span style="font-size:0.875rem;">&#10024;</span> AI Generate
        </button>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         FINANCIAL TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='financial'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <div x-show="tabs.financial.kpis" class="stat-grid mb-4">
                <div class="stat-card stat-card--green"><div class="stat-label">Gross Revenue</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.gross_revenue)"></div><div class="stat-delta" x-text="(tabs.financial.kpis?.invoice_count??'')+' invoices'"></div></div>
                <div class="stat-card stat-card--blue"><div class="stat-label">Collected</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.total_collected)"></div><div class="stat-delta" x-text="(tabs.financial.kpis?.collection_rate??'')+'% rate'"></div></div>
                <div class="stat-card stat-card--amber"><div class="stat-label">Outstanding</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.total_outstanding)"></div><div class="stat-delta" x-text="(tabs.financial.kpis?.overdue_count??'')+' overdue'"></div></div>
                <div class="stat-card stat-card--red"><div class="stat-label">Overdue Amount</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.overdue_amount)"></div></div>
                <div class="stat-card stat-card--purple"><div class="stat-label">Avg Invoice</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.avg_invoice_value)"></div><div class="stat-delta" x-text="(tabs.financial.kpis?.unique_customers??'')+' customers'"></div></div>
                <div class="stat-card stat-card--teal"><div class="stat-label">Tax Collected</div><div class="stat-value font-mono" x-text="money(tabs.financial.kpis?.total_tax)"></div><div class="stat-delta" x-text="(tabs.financial.kpis?.paid_count??'')+' paid'"></div></div>
        </div>
        <div x-show="tabs.financial.loading&&!tabs.financial.kpis" class="stat-grid mb-4"><template x-for="i in 6"><div class="stat-card"><div class="skeleton-bar" style="width:60%;height:12px;margin-bottom:8px;"></div><div class="skeleton-bar" style="width:80%;height:24px;"></div></div></template></div>

        <!-- Sub-view tabs -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;" class="no-print">
            <div class="tab-bar" style="margin-bottom:0;">
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='period'}"         @click="switchView('financial','period')">By Period</button>
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='customer'}"        @click="switchView('financial','customer')">By Customer</button>
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='equipment_type'}"  @click="switchView('financial','equipment_type')">By Type</button>
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='ar_aging'}"        @click="switchView('financial','ar_aging')">AR Aging</button>
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='collection'}"      @click="switchView('financial','collection')">Collection Rate</button>
                <button class="tab-btn" :class="{'is-active':tabs.financial.view==='status'}"          @click="switchView('financial','status')">Invoice Status</button>
            </div>
            <button class="btn btn-secondary btn-sm" @click="exportCsv('revenue',tabs.financial.view)"><svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> CSV</button>
        </div>

        <div x-show="tabs.financial.viewLoading" class="card"><div class="card-body"><div class="skeleton-chart" style="height:280px;"></div></div></div>

        <!-- By Period -->
        <div x-show="tabs.financial.view==='period'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Revenue Trend</div><div class="rpt-chart-sub">Monthly net revenue, collected, and outstanding amounts</div></div></div><div id="chart-rev-period" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Period</th><th class="text-right">Invoices</th><th class="text-right">Net Revenue</th><th class="text-right">Tax</th><th class="text-right">Gross Revenue</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th><th class="text-right">Customers</th></tr></thead>
                <tbody><template x-for="r in capped('financial')" :key="r.period"><tr><td x-text="r.period_label"></td><td class="text-right" x-text="r.invoice_count"></td><td class="text-right font-mono" x-text="money(r.net_revenue)"></td><td class="text-right font-mono" x-text="money(r.tax_total)"></td><td class="text-right font-mono" x-text="money(r.gross_revenue)"></td><td class="text-right font-mono" x-text="money(r.collected)"></td><td class="text-right font-mono" x-text="money(r.outstanding)"></td><td class="text-right" x-text="r.unique_customers"></td></tr></template></tbody>
                <tfoot x-show="currentTotals"><tr style="font-weight:600;"><td>Total</td><td></td><td class="text-right font-mono" x-text="money(currentTotals?.net_revenue)"></td><td class="text-right font-mono" x-text="money(currentTotals?.tax_total)"></td><td class="text-right font-mono" x-text="money(currentTotals?.gross_revenue)"></td><td class="text-right font-mono" x-text="money(currentTotals?.collected)"></td><td class="text-right font-mono" x-text="money(currentTotals?.outstanding)"></td><td></td></tr></tfoot>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('financial')"><span x-text="capLabel('financial')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('financial')" x-text="isExpanded('financial')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- By Customer -->
        <div x-show="tabs.financial.view==='customer'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Top Customers by Revenue</div><div class="rpt-chart-sub">Top 15 customers ranked by gross revenue</div></div></div><div id="chart-rev-customer" style="height:360px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>#</th><th>Customer</th><th>Status</th><th class="text-right">Invoices</th><th class="text-right">Net Revenue</th><th class="text-right">Gross Revenue</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th><th class="text-right">Avg Invoice</th></tr></thead>
                <tbody><template x-for="(r,i) in capped('financial')" :key="r.customer_id"><tr><td class="text-muted" x-text="i+1"></td><td x-text="r.company_name"></td><td><span class="badge" :class="'badge-'+customerBadge(r.customer_status)" x-text="r.customer_status"></span></td><td class="text-right" x-text="r.invoice_count"></td><td class="text-right font-mono" x-text="money(r.net_revenue)"></td><td class="text-right font-mono" x-text="money(r.gross_revenue)"></td><td class="text-right font-mono" x-text="money(r.collected)"></td><td class="text-right font-mono" x-text="money(r.outstanding)"></td><td class="text-right font-mono" x-text="money(r.avg_invoice)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('financial')"><span x-text="capLabel('financial')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('financial')" x-text="isExpanded('financial')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- By Equipment Type -->
        <div x-show="tabs.financial.view==='equipment_type'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Revenue by Equipment Category</div><div class="rpt-chart-sub">Gross revenue breakdown by trailer/equipment type</div></div></div><div id="chart-rev-type" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Equipment Type</th><th class="text-right">Units</th><th class="text-right">Leases</th><th class="text-right">Invoices</th><th class="text-right">Net Revenue</th><th class="text-right">Gross Revenue</th><th class="text-right">Collected</th></tr></thead>
                <tbody><template x-for="r in capped('financial')" :key="r.equipment_type"><tr><td x-text="r.equipment_type"></td><td class="text-right" x-text="r.unit_count"></td><td class="text-right" x-text="r.lease_count"></td><td class="text-right" x-text="r.invoice_count"></td><td class="text-right font-mono" x-text="money(r.net_revenue)"></td><td class="text-right font-mono" x-text="money(r.gross_revenue)"></td><td class="text-right font-mono" x-text="money(r.collected)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('financial')"><span x-text="capLabel('financial')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('financial')" x-text="isExpanded('financial')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- AR Aging -->
        <div x-show="tabs.financial.view==='ar_aging'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Accounts Receivable Aging</div><div class="rpt-chart-sub">Outstanding balances grouped by overdue period</div></div></div><div id="chart-aging" style="height:240px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Invoice #</th><th>Customer</th><th>Invoice Date</th><th>Due Date</th><th class="text-right">Total</th><th class="text-right">Balance Due</th><th class="text-right">Days Overdue</th><th>Bucket</th></tr></thead>
                <tbody><template x-for="r in capped('financial')" :key="r.id"><tr><td class="font-mono" x-text="r.invoice_number"></td><td x-text="r.company_name"></td><td x-text="r.invoice_date"></td><td x-text="r.due_date"></td><td class="text-right font-mono" x-text="money(r.total_amount)"></td><td class="text-right font-mono" x-text="money(r.balance_due)"></td><td class="text-right" x-text="Math.max(0,parseInt(r.days_overdue||0))"></td><td><span class="badge" :class="agingBadge(r.aging_bucket)" x-text="(r.aging_bucket||'').replace('_','–')"></span></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('financial')"><span x-text="capLabel('financial')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('financial')" x-text="isExpanded('financial')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Collection Rate -->
        <div x-show="tabs.financial.view==='collection'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Collection Rate Trend</div><div class="rpt-chart-sub">Monthly invoiced vs. collected with collection rate overlay</div></div></div><div id="chart-collection" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Period</th><th class="text-right">Invoices</th><th class="text-right">Fully Paid</th><th class="text-right">Invoiced</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th><th class="text-right">Collection %</th></tr></thead>
                <tbody><template x-for="r in capped('financial')" :key="r.period"><tr><td x-text="r.period_label"></td><td class="text-right" x-text="r.invoice_count"></td><td class="text-right" x-text="r.fully_paid_count"></td><td class="text-right font-mono" x-text="money(r.invoiced)"></td><td class="text-right font-mono" x-text="money(r.collected)"></td><td class="text-right font-mono" x-text="money(r.outstanding)"></td><td class="text-right font-mono" x-text="pct(r.collection_rate)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('financial')"><span x-text="capLabel('financial')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('financial')" x-text="isExpanded('financial')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Invoice Status -->
        <div x-show="tabs.financial.view==='status'&&!tabs.financial.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Invoice Status Distribution</div><div class="rpt-chart-sub">Count and total value by invoice status</div></div></div><div id="chart-inv-status" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Status</th><th class="text-right">Count</th><th class="text-right">Customers</th><th class="text-right">Total Amount</th><th class="text-right">Balance Due</th><th class="text-right">Avg Amount</th></tr></thead>
                <tbody><template x-for="r in capped('financial')" :key="r.status"><tr><td><span class="badge" :class="invStatusBadge(r.status)" x-text="(r.status||'').replace(/_/g,' ')"></span></td><td class="text-right" x-text="r.invoice_count"></td><td class="text-right" x-text="r.unique_customers"></td><td class="text-right font-mono" x-text="money(r.total_amount)"></td><td class="text-right font-mono" x-text="money(r.balance_due)"></td><td class="text-right font-mono" x-text="money(r.avg_amount)"></td></tr></template></tbody>
                </table>
            </div></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         FLEET TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='fleet'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <div x-show="tabs.fleet.kpis" class="stat-grid mb-4">
                <div class="stat-card stat-card--blue"><div class="stat-label">Avg Utilization</div><div class="stat-value font-mono" x-text="pct(tabs.fleet.kpis?.avg_utilization)"></div><div class="stat-delta" x-text="(tabs.fleet.kpis?.period_days??'')+'-day period'"></div></div>
                <div class="stat-card stat-card--green"><div class="stat-label">Total Units</div><div class="stat-value" x-text="tabs.fleet.kpis?.total_units"></div><div class="stat-delta" x-text="(tabs.fleet.kpis?.active_units??'')+' active'"></div></div>
                <div class="stat-card stat-card--amber"><div class="stat-label">Idle Units</div><div class="stat-value" x-text="tabs.fleet.kpis?.idle_units"></div><div class="stat-delta">0 days leased in period</div></div>
                <div class="stat-card stat-card--green"><div class="stat-label">Fleet Revenue</div><div class="stat-value font-mono" x-text="money(tabs.fleet.kpis?.total_revenue)"></div></div>
                <div class="stat-card stat-card--red"><div class="stat-label">Maintenance Cost</div><div class="stat-value font-mono" x-text="money(tabs.fleet.kpis?.total_maint_cost)"></div></div>
                <div class="stat-card" :class="parseFloat(tabs.fleet.kpis?.total_roi||0)>=0?'stat-card--green':'stat-card--red'"><div class="stat-label">Fleet ROI</div><div class="stat-value font-mono" x-text="money(tabs.fleet.kpis?.total_roi)"></div><div class="stat-delta">Revenue – Maintenance</div></div>
        </div>
        <div x-show="tabs.fleet.loading&&!tabs.fleet.kpis" class="stat-grid mb-4"><template x-for="i in 6"><div class="stat-card"><div class="skeleton-bar" style="width:60%;height:12px;margin-bottom:8px;"></div><div class="skeleton-bar" style="width:80%;height:24px;"></div></div></template></div>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;" class="no-print">
            <div class="tab-bar" style="margin-bottom:0;">
                <button class="tab-btn" :class="{'is-active':tabs.fleet.view==='utilization'}"  @click="switchView('fleet','utilization')">Utilization</button>
                <button class="tab-btn" :class="{'is-active':tabs.fleet.view==='roi'}"           @click="switchView('fleet','roi')">ROI Ranking</button>
                <button class="tab-btn" :class="{'is-active':tabs.fleet.view==='idle'}"          @click="switchView('fleet','idle')">Idle Units</button>
                <button class="tab-btn" :class="{'is-active':tabs.fleet.view==='maintenance'}"   @click="switchView('fleet','maintenance')">Maintenance</button>
                <button class="tab-btn" :class="{'is-active':tabs.fleet.view==='yard'}"          @click="switchView('fleet','yard')">By Yard</button>
            </div>
            <button class="btn btn-secondary btn-sm" @click="exportCsv('fleet',tabs.fleet.view)"><svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> CSV</button>
        </div>

        <div x-show="tabs.fleet.viewLoading" class="card"><div class="card-body"><div class="skeleton-chart" style="height:280px;"></div></div></div>

        <!-- Utilization — DISTRIBUTION HISTOGRAM (not per-unit bars) -->
        <div x-show="tabs.fleet.view==='utilization'&&!tabs.fleet.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Fleet Utilization Distribution</div><div class="rpt-chart-sub">Number of units in each utilization bracket — shows fleet health at a glance</div></div></div><div id="chart-fleet-util" style="height:320px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Unit #</th><th>Type</th><th>Yard</th><th>Status</th><th class="text-right">Days Leased</th><th class="text-right">Period Days</th><th class="text-right">Utilization</th><th class="text-right">Revenue</th><th class="text-right">Maint. Cost</th><th class="text-right">ROI</th></tr></thead>
                <tbody><template x-for="r in capped('fleet')" :key="r.unit_id"><tr><td class="font-mono" x-text="r.unit_number"></td><td x-text="r.equipment_type"></td><td x-text="r.yard_location||'—'"></td><td><span class="badge" :class="unitStatusBadge(r.status)" x-text="(r.status||'').replace(/_/g,' ')"></span></td><td class="text-right" x-text="r.days_on_lease"></td><td class="text-right" x-text="r.period_days"></td><td class="text-right font-mono" :class="utilColor(r.utilization_pct)" x-text="pct(r.utilization_pct)"></td><td class="text-right font-mono" x-text="money(r.revenue)"></td><td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td><td class="text-right font-mono" x-text="money(r.roi)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('fleet')"><span x-text="capLabel('fleet')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('fleet')" x-text="isExpanded('fleet')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- ROI — TOP 10 / BOTTOM 10 -->
        <div x-show="tabs.fleet.view==='roi'&&!tabs.fleet.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-grid-2">
                <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Top 10 — Highest ROI</div><div class="rpt-chart-sub">Best-performing units by revenue minus maintenance</div></div></div><div id="chart-fleet-roi-top" style="height:280px;"></div></div>
                <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Bottom 10 — Lowest ROI</div><div class="rpt-chart-sub">Units needing attention — maintenance exceeds revenue</div></div></div><div id="chart-fleet-roi-bottom" style="height:280px;"></div></div>
            </div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>#</th><th>Unit #</th><th>Type</th><th>Yard</th><th class="text-right">Revenue</th><th class="text-right">Labor</th><th class="text-right">Parts</th><th class="text-right">Maintenance</th><th class="text-right">ROI</th><th class="text-right">Work Orders</th></tr></thead>
                <tbody><template x-for="(r,i) in capped('fleet')" :key="r.unit_id"><tr><td class="text-muted" x-text="i+1"></td><td class="font-mono" x-text="r.unit_number"></td><td x-text="r.equipment_type"></td><td x-text="r.yard_location||'—'"></td><td class="text-right font-mono" x-text="money(r.revenue)"></td><td class="text-right font-mono" x-text="money(r.labor_cost)"></td><td class="text-right font-mono" x-text="money(r.parts_cost)"></td><td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td><td class="text-right font-mono" :style="parseFloat(r.roi||0)>=0?'color:var(--color-success)':'color:var(--color-danger)'" x-text="money(r.roi)"></td><td class="text-right" x-text="r.work_order_count"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('fleet')"><span x-text="capLabel('fleet')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('fleet')" x-text="isExpanded('fleet')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Idle Units -->
        <div x-show="tabs.fleet.view==='idle'&&!tabs.fleet.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Idle Fleet by Equipment Type</div><div class="rpt-chart-sub">Units with zero lease days in the selected period</div></div></div><div id="chart-fleet-idle" style="height:260px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Unit #</th><th>Type</th><th>Yard</th><th>Status</th><th class="text-right">Maint. Cost</th><th class="text-right">Work Orders</th></tr></thead>
                <tbody><template x-for="r in capped('fleet')" :key="r.unit_id"><tr><td class="font-mono" x-text="r.unit_number"></td><td x-text="r.equipment_type"></td><td x-text="r.yard_location||'—'"></td><td><span class="badge" :class="unitStatusBadge(r.status)" x-text="(r.status||'').replace(/_/g,' ')"></span></td><td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td><td class="text-right" x-text="r.work_order_count"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('fleet')"><span x-text="capLabel('fleet')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('fleet')" x-text="isExpanded('fleet')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Maintenance by Type -->
        <div x-show="tabs.fleet.view==='maintenance'&&!tabs.fleet.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Maintenance Cost by Work Type</div><div class="rpt-chart-sub">Breakdown of labour, parts, and total cost by work order type</div></div></div><div id="chart-maint-type" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Work Type</th><th class="text-right">Work Orders</th><th class="text-right">Units</th><th class="text-right">Labour</th><th class="text-right">Parts</th><th class="text-right">Total Cost</th><th class="text-right">Avg Cost</th></tr></thead>
                <tbody><template x-for="r in capped('fleet')" :key="r.work_type"><tr><td x-text="(r.work_type||'').replace(/_/g,' ')"></td><td class="text-right" x-text="r.work_order_count"></td><td class="text-right" x-text="r.unit_count"></td><td class="text-right font-mono" x-text="money(r.labor_cost)"></td><td class="text-right font-mono" x-text="money(r.parts_cost)"></td><td class="text-right font-mono" x-text="money(r.total_cost)"></td><td class="text-right font-mono" x-text="money(r.avg_cost)"></td></tr></template></tbody>
                </table>
            </div></div>
        </div>

        <!-- By Yard -->
        <div x-show="tabs.fleet.view==='yard'&&!tabs.fleet.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Fleet Performance by Yard</div><div class="rpt-chart-sub">Revenue vs. maintenance cost per yard location</div></div></div><div id="chart-fleet-yard" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Yard</th><th class="text-right">Units</th><th class="text-right">Idle</th><th class="text-right">Avg Utilization</th><th class="text-right">Revenue</th><th class="text-right">Maintenance</th><th class="text-right">ROI</th></tr></thead>
                <tbody><template x-for="r in capped('fleet')" :key="r.yard"><tr><td x-text="r.yard||'Unassigned'"></td><td class="text-right" x-text="r.unit_count"></td><td class="text-right" x-text="r.idle_count"></td><td class="text-right font-mono" :class="utilColor(r.avg_utilization)" x-text="pct(r.avg_utilization)"></td><td class="text-right font-mono" x-text="money(r.revenue)"></td><td class="text-right font-mono" x-text="money(r.maintenance_cost)"></td><td class="text-right font-mono" x-text="money(r.roi)"></td></tr></template></tbody>
                </table>
            </div></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         CUSTOMER TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='customer'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <div x-show="tabs.customer.kpis" class="stat-grid mb-4">
                <div class="stat-card stat-card--blue"><div class="stat-label">Unique Customers</div><div class="stat-value" x-text="tabs.customer.kpis?.unique_customers"></div><div class="stat-delta" x-text="(tabs.customer.kpis?.active_customers??'')+' active'"></div></div>
                <div class="stat-card stat-card--green"><div class="stat-label">Period Revenue</div><div class="stat-value font-mono" x-text="money(tabs.customer.kpis?.total_revenue)"></div></div>
                <div class="stat-card stat-card--purple"><div class="stat-label">Avg Invoice Value</div><div class="stat-value font-mono" x-text="money(tabs.customer.kpis?.avg_invoice_value)"></div></div>
                <div class="stat-card" :class="parseFloat(tabs.customer.kpis?.avg_days_to_pay||0)>30?'stat-card--red':parseFloat(tabs.customer.kpis?.avg_days_to_pay||0)>15?'stat-card--amber':'stat-card--green'"><div class="stat-label">Avg Days to Pay</div><div class="stat-value font-mono" x-text="(tabs.customer.kpis?.avg_days_to_pay??'')+' days'"></div></div>
                <div class="stat-card stat-card--teal"><div class="stat-label">Invoices</div><div class="stat-value" x-text="tabs.customer.kpis?.invoice_count"></div></div>
                <div class="stat-card stat-card--slate"><div class="stat-label">Leases</div><div class="stat-value" x-text="tabs.customer.kpis?.lease_count"></div></div>
        </div>
        <div x-show="tabs.customer.loading&&!tabs.customer.kpis" class="stat-grid mb-4"><template x-for="i in 6"><div class="stat-card"><div class="skeleton-bar" style="width:60%;height:12px;margin-bottom:8px;"></div><div class="skeleton-bar" style="width:80%;height:24px;"></div></div></template></div>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;" class="no-print">
            <div class="tab-bar" style="margin-bottom:0;">
                <button class="tab-btn" :class="{'is-active':tabs.customer.view==='ltv'}"              @click="switchView('customer','ltv')">Lifetime Value</button>
                <button class="tab-btn" :class="{'is-active':tabs.customer.view==='payment_behavior'}"  @click="switchView('customer','payment_behavior')">Payment Behavior</button>
                <button class="tab-btn" :class="{'is-active':tabs.customer.view==='new_returning'}"     @click="switchView('customer','new_returning')">New vs Returning</button>
                <button class="tab-btn" :class="{'is-active':tabs.customer.view==='frequency'}"         @click="switchView('customer','frequency')">Lease Frequency</button>
                <button class="tab-btn" :class="{'is-active':tabs.customer.view==='credit_notes'}"      @click="switchView('customer','credit_notes')">Credit Notes</button>
            </div>
            <button class="btn btn-secondary btn-sm" @click="exportCsv('customer',tabs.customer.view)"><svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> CSV</button>
        </div>

        <div x-show="tabs.customer.viewLoading" class="card"><div class="card-body"><div class="skeleton-chart" style="height:280px;"></div></div></div>

        <!-- Lifetime Value -->
        <div x-show="tabs.customer.view==='ltv'&&!tabs.customer.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Customer Lifetime Value Ranking</div><div class="rpt-chart-sub">All-time revenue vs. current-period revenue — top 15 customers</div></div></div><div id="chart-cust-ltv" style="height:340px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>#</th><th>Customer</th><th>Status</th><th>Since</th><th class="text-right">Lifetime Revenue</th><th class="text-right">Collected</th><th class="text-right">Outstanding</th><th class="text-right">Period Rev.</th><th class="text-right">Invoices</th><th class="text-right">Leases</th><th class="text-right">Credit Issued</th></tr></thead>
                <tbody><template x-for="(r,i) in capped('customer')" :key="r.customer_id"><tr><td class="text-muted" x-text="i+1"></td><td x-text="r.company_name"></td><td><span class="badge" :class="'badge-'+customerBadge(r.customer_status)" x-text="r.customer_status"></span></td><td x-text="(r.customer_since||'').substring(0,10)"></td><td class="text-right font-mono" x-text="money(r.lifetime_revenue)"></td><td class="text-right font-mono" x-text="money(r.lifetime_collected)"></td><td class="text-right font-mono" x-text="money(r.lifetime_outstanding)"></td><td class="text-right font-mono" x-text="money(r.period_revenue)"></td><td class="text-right" x-text="r.total_invoices"></td><td class="text-right" x-text="r.total_leases"></td><td class="text-right font-mono" x-text="money(r.credit_issued)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('customer')"><span x-text="capLabel('customer')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('customer')" x-text="isExpanded('customer')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Payment Behavior — DISTRIBUTION HISTOGRAM -->
        <div x-show="tabs.customer.view==='payment_behavior'&&!tabs.customer.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Payment Timing Distribution</div><div class="rpt-chart-sub">How many customers pay early, on time, or late — based on average days relative to due date</div></div></div><div id="chart-pay-behavior" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Customer</th><th class="text-right">Paid Invoices</th><th class="text-right">Avg Days</th><th class="text-right">Best</th><th class="text-right">Worst</th><th class="text-right">On Time</th><th class="text-right">Late</th><th class="text-right">Amount Paid</th></tr></thead>
                <tbody><template x-for="r in capped('customer')" :key="r.customer_id"><tr><td x-text="r.company_name"></td><td class="text-right" x-text="r.paid_invoice_count"></td><td class="text-right font-mono" :class="parseFloat(r.avg_days_to_pay)>0?'text-danger':'text-success'" x-text="(parseFloat(r.avg_days_to_pay)||0).toFixed(1)+'d'"></td><td class="text-right font-mono" x-text="(r.min_days||0)+'d'"></td><td class="text-right font-mono" x-text="(r.max_days||0)+'d'"></td><td class="text-right" x-text="r.on_time_count"></td><td class="text-right" x-text="r.late_count"></td><td class="text-right font-mono" x-text="money(r.total_paid_amount)"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('customer')"><span x-text="capLabel('customer')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('customer')" x-text="isExpanded('customer')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- New vs Returning -->
        <div x-show="tabs.customer.view==='new_returning'&&!tabs.customer.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">New vs. Returning Revenue</div><div class="rpt-chart-sub">Monthly revenue split by first-time and returning customers</div></div></div><div id="chart-new-returning" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Period</th><th class="text-right">New Revenue</th><th class="text-right">Returning Revenue</th><th class="text-right">Total</th><th class="text-right">New Customers</th><th class="text-right">Returning</th></tr></thead>
                <tbody><template x-for="r in capped('customer')" :key="r.period"><tr><td x-text="r.period_label"></td><td class="text-right font-mono" x-text="money(r.new_revenue)"></td><td class="text-right font-mono" x-text="money(r.returning_revenue)"></td><td class="text-right font-mono" x-text="money(r.total_revenue)"></td><td class="text-right" x-text="r.new_customers"></td><td class="text-right" x-text="r.returning_customers"></td></tr></template></tbody>
                </table>
            </div></div>
        </div>

        <!-- Lease Frequency -->
        <div x-show="tabs.customer.view==='frequency'&&!tabs.customer.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Customer Lease Frequency</div><div class="rpt-chart-sub">Customers ranked by number of leases in the selected period</div></div></div><div id="chart-cust-freq" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>#</th><th>Customer</th><th>Status</th><th class="text-right">Leases</th><th class="text-right">Active</th><th class="text-right">Completed</th><th class="text-right">Avg Days</th><th class="text-right">Types</th><th>First Lease</th><th>Last Lease</th></tr></thead>
                <tbody><template x-for="(r,i) in capped('customer')" :key="r.customer_id"><tr><td class="text-muted" x-text="i+1"></td><td x-text="r.company_name"></td><td><span class="badge" :class="'badge-'+customerBadge(r.customer_status)" x-text="r.customer_status"></span></td><td class="text-right" x-text="r.lease_count"></td><td class="text-right" x-text="r.active_leases"></td><td class="text-right" x-text="r.completed_leases"></td><td class="text-right font-mono" x-text="parseFloat(r.avg_lease_days||0).toFixed(1)"></td><td class="text-right" x-text="r.equipment_categories_used"></td><td x-text="r.first_lease_date||'—'"></td><td x-text="r.last_lease_date||'—'"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('customer')"><span x-text="capLabel('customer')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('customer')" x-text="isExpanded('customer')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Credit Notes -->
        <div x-show="tabs.customer.view==='credit_notes'&&!tabs.customer.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Credit Note Impact by Customer</div><div class="rpt-chart-sub">Issued, applied, and remaining credit balances</div></div></div><div id="chart-credit-notes" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Customer</th><th class="text-right">Credits</th><th class="text-right">Total Issued</th><th class="text-right">Used</th><th class="text-right">Remaining</th><th class="text-right">Avg Amount</th><th class="text-right">Active</th><th class="text-right">Used Up</th><th class="text-right">Expired</th></tr></thead>
                <tbody><template x-for="r in capped('customer')" :key="r.customer_id"><tr><td x-text="r.company_name"></td><td class="text-right" x-text="r.credit_note_count"></td><td class="text-right font-mono" x-text="money(r.total_issued)"></td><td class="text-right font-mono" x-text="money(r.total_used)"></td><td class="text-right font-mono" x-text="money(r.total_remaining)"></td><td class="text-right font-mono" x-text="money(r.avg_credit_amount)"></td><td class="text-right" x-text="r.active_credits"></td><td class="text-right" x-text="r.fully_used"></td><td class="text-right" x-text="r.expired_credits"></td></tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('customer')"><span x-text="capLabel('customer')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('customer')" x-text="isExpanded('customer')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         COMPLIANCE TAB
    ════════════════════════════════════════════════════════════ -->
    <div x-show="mainTab==='compliance'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <!-- Compliance uses a look-ahead window instead of a date range -->
        <div class="card rpt-toolbar mb-4 no-print" id="comp-window-bar">
            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;flex-wrap:wrap;">
                <span class="rpt-toolbar-label">LOOK-AHEAD WINDOW</span>
                <template x-for="d in [30,60,90,180,365]" :key="d">
                    <button class="btn btn-sm" :class="compWindow===d?'btn-primary':'btn-secondary'" @click="setCompWindow(d)" x-text="d+'d'"></button>
                </template>
            </div>
        </div>

        <div x-show="tabs.compliance.kpis" class="stat-grid mb-4">
                <div class="stat-card stat-card--slate"><div class="stat-label">Total Units Tracked</div><div class="stat-value" x-text="tabs.compliance.kpis?.total_units"></div></div>
                <div class="stat-card stat-card--red"><div class="stat-label">Expired Documents</div><div class="stat-value" x-text="tabs.compliance.kpis?.expired_count"></div><div class="stat-delta">Past expiry date</div></div>
                <div class="stat-card stat-card--amber"><div class="stat-label">Expiring ≤30 Days</div><div class="stat-value" x-text="tabs.compliance.kpis?.expiring_30"></div></div>
                <div class="stat-card stat-card--blue"><div class="stat-label">Expiring ≤90 Days</div><div class="stat-value" x-text="tabs.compliance.kpis?.expiring_90"></div></div>
                <div class="stat-card stat-card--green"><div class="stat-label">Compliant Units</div><div class="stat-value" x-text="tabs.compliance.kpis?.ok_count"></div><div class="stat-delta">>90 days remaining</div></div>
                <div class="stat-card stat-card--slate"><div class="stat-label">As Of</div><div class="stat-value" style="font-size:16px;" x-text="tabs.compliance.kpis?.as_of_date"></div></div>
        </div>
        <div x-show="tabs.compliance.loading&&!tabs.compliance.kpis" class="stat-grid mb-4"><template x-for="i in 6"><div class="stat-card"><div class="skeleton-bar" style="width:60%;height:12px;margin-bottom:8px;"></div><div class="skeleton-bar" style="width:80%;height:24px;"></div></div></template></div>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;" class="no-print">
            <div class="tab-bar" style="margin-bottom:0;">
                <button class="tab-btn" :class="{'is-active':tabs.compliance.view==='timeline'}" @click="switchView('compliance','timeline')">Timeline</button>
                <button class="tab-btn" :class="{'is-active':tabs.compliance.view==='status'}"   @click="switchView('compliance','status')">Status</button>
                <button class="tab-btn" :class="{'is-active':tabs.compliance.view==='expired'}"  @click="switchView('compliance','expired')">Expired</button>
                <button class="tab-btn" :class="{'is-active':tabs.compliance.view==='upcoming'}" @click="switchView('compliance','upcoming')">Upcoming</button>
            </div>
            <button class="btn btn-secondary btn-sm" @click="exportCompCsv(tabs.compliance.view)"><svg width="14" height="14"><use href="#icon-arrow-down-tray"/></svg> CSV</button>
        </div>

        <div x-show="tabs.compliance.viewLoading" class="card"><div class="card-body"><div class="skeleton-chart" style="height:260px;"></div></div></div>

        <!-- Timeline -->
        <div x-show="tabs.compliance.view==='timeline'&&!tabs.compliance.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Document Expiry Timeline</div><div class="rpt-chart-sub">Number of CVI, registration, and insurance documents expiring each month</div></div></div><div id="chart-comp-timeline" style="height:300px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Month</th><th class="text-right">CVI</th><th class="text-right">Registration</th><th class="text-right">Insurance</th><th class="text-right">Total</th></tr></thead>
                <tbody><template x-for="r in capped('compliance')" :key="r.period"><tr><td x-text="r.period_label"></td><td class="text-right" x-text="r.cvi_count"></td><td class="text-right" x-text="r.reg_count"></td><td class="text-right" x-text="r.ins_count"></td><td class="text-right" style="font-weight:600;" x-text="r.total"></td></tr></template></tbody>
                </table>
            </div></div>
        </div>

        <!-- Status Snapshot -->
        <div x-show="tabs.compliance.view==='status'&&!tabs.compliance.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="rpt-chart-card"><div class="rpt-chart-hdr"><div><div class="rpt-chart-title">Compliance Status Snapshot</div><div class="rpt-chart-sub">Current state of each document type across all units</div></div></div><div id="chart-comp-status" style="height:280px;"></div></div>
            <div class="card"><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Document</th><th class="text-right">Expired</th><th class="text-right">≤30d</th><th class="text-right">31–90d</th><th class="text-right">OK</th><th class="text-right">No Date</th></tr></thead>
                <tbody>
                    <template x-if="tabs.compliance.viewData.status"><template x-for="doc in ['CVI','Registration','Insurance']" :key="doc">
                        <tr><td x-text="doc"></td>
                        <td class="text-right"><span class="badge badge-danger" x-text="tabs.compliance.viewData.status.chart_data[(doc==='CVI'?'cvi':doc==='Registration'?'reg':'ins')+'_expired']||0"></span></td>
                        <td class="text-right"><span class="badge badge-warning" x-text="tabs.compliance.viewData.status.chart_data[(doc==='CVI'?'cvi':doc==='Registration'?'reg':'ins')+'_expiring_30']||0"></span></td>
                        <td class="text-right" x-text="tabs.compliance.viewData.status.chart_data[(doc==='CVI'?'cvi':doc==='Registration'?'reg':'ins')+'_expiring_90']||0"></td>
                        <td class="text-right" x-text="tabs.compliance.viewData.status.chart_data[(doc==='CVI'?'cvi':doc==='Registration'?'reg':'ins')+'_ok']||0"></td>
                        <td class="text-right text-muted" x-text="tabs.compliance.viewData.status.chart_data[(doc==='CVI'?'cvi':doc==='Registration'?'reg':'ins')+'_missing']||0"></td>
                        </tr>
                    </template></template>
                </tbody>
                </table>
            </div></div>
        </div>

        <!-- Expired -->
        <div x-show="tabs.compliance.view==='expired'&&!tabs.compliance.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="card"><div class="card-header"><span class="card-title">Expired Documents</span><span class="text-muted" style="font-size:12px;" x-text="totalRows('compliance')+' units with expired documents'"></span></div><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Unit #</th><th>Yard</th><th>Status</th><th>CVI Expiry</th><th class="text-right">Overdue</th><th>Reg. Expiry</th><th class="text-right">Overdue</th><th>Insurance Expiry</th><th class="text-right">Overdue</th></tr></thead>
                <tbody><template x-for="r in capped('compliance')" :key="r.unit_id"><tr><td class="font-mono" x-text="r.unit_number"></td><td x-text="r.yard_location||'—'"></td><td><span class="badge" :class="unitStatusBadge(r.status)" x-text="(r.status||'').replace(/_/g,' ')"></span></td>
                    <td :class="r.cvi_expired?'text-danger':''" x-text="r.cvi_expiry||'—'"></td><td class="text-right" x-text="r.cvi_overdue_days?r.cvi_overdue_days+'d':''"></td>
                    <td :class="r.reg_expired?'text-danger':''" x-text="r.registration_expiry||'—'"></td><td class="text-right" x-text="r.reg_overdue_days?r.reg_overdue_days+'d':''"></td>
                    <td :class="r.ins_expired?'text-danger':''" x-text="r.insurance_expiry||'—'"></td><td class="text-right" x-text="r.ins_overdue_days?r.ins_overdue_days+'d':''"></td>
                </tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('compliance')"><span x-text="capLabel('compliance')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('compliance')" x-text="isExpanded('compliance')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>

        <!-- Upcoming -->
        <div x-show="tabs.compliance.view==='upcoming'&&!tabs.compliance.viewLoading" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
            <div class="card"><div class="card-header"><span class="card-title">Upcoming Renewals</span><span class="text-muted" style="font-size:12px;" x-text="totalRows('compliance')+' units with upcoming renewals'"></span></div><div class="card-body" style="overflow-x:auto;">
                <table class="table rpt-table"><thead><tr><th>Unit #</th><th>Yard</th><th>Status</th><th>CVI Expiry</th><th class="text-right">Days Left</th><th>Reg. Expiry</th><th class="text-right">Days Left</th><th>Insurance Expiry</th><th class="text-right">Days Left</th></tr></thead>
                <tbody><template x-for="r in capped('compliance')" :key="r.unit_id"><tr><td class="font-mono" x-text="r.unit_number"></td><td x-text="r.yard_location||'—'"></td><td><span class="badge" :class="unitStatusBadge(r.status)" x-text="(r.status||'').replace(/_/g,' ')"></span></td>
                    <td x-text="r.cvi_expiry||'—'"></td><td class="text-right" :class="daysLeftClass(r.cvi_days_left)"><span x-text="r.cvi_days_left!=null?r.cvi_days_left+'d':''"></span></td>
                    <td x-text="r.registration_expiry||'—'"></td><td class="text-right" :class="daysLeftClass(r.reg_days_left)"><span x-text="r.reg_days_left!=null?r.reg_days_left+'d':''"></span></td>
                    <td x-text="r.insurance_expiry||'—'"></td><td class="text-right" :class="daysLeftClass(r.ins_days_left)"><span x-text="r.ins_days_left!=null?r.ins_days_left+'d':''"></span></td>
                </tr></template></tbody>
                </table>
            </div><div class="rpt-table-ft" x-show="isOverCap('compliance')"><span x-text="capLabel('compliance')"></span><button class="btn btn-sm btn-secondary" @click="toggleExpand('compliance')" x-text="isExpanded('compliance')?'Show less ▲':'Show all ▼'"></button></div></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         AI GENERATE TAB
    ════════════════════════════════════════════════════════════ -->
    <?php if (can('ai', 'view')): ?>
    <div x-show="mainTab==='ai'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <?php
        // WHY: Include the reusable AI visualization generator with reports context
        $aiVizId      = 'FF_AiVizReports';
        $aiVizContext  = 'reports';
        $aiVizCompact  = false;
        include FF_ROOT . '/includes/partials/ai-report-generator.php';
        ?>
    </div>
    <?php endif; ?>

</div><!-- /reports-root -->

<!-- ═══════════════════════════════════════════════════════════════
     CSS
═══════════════════════════════════════════════════════════════ -->
<style>
@media print {
    .no-print, .sidebar, .topbar, #date-range-bar, #comp-window-bar { display:none !important; }
    .tab-bar { display:none !important; }
    [x-show] { display:block !important; }
    .card { break-inside:avoid; box-shadow:none; border:1px solid #ddd; }
    .page-header-actions { display:none; }
    body::after { content:'Printed: ' attr(data-print-date); display:block; text-align:right; font-size:10px; color:#666; padding-top:8px; border-top:1px solid #ddd; margin-top:16px; }
}
.text-success { color:var(--color-success) !important; }
.text-danger  { color:var(--color-danger)  !important; }
.text-warning { color:var(--color-warning) !important; }
.text-muted   { color:var(--text-muted); }
.text-right   { text-align:right; }
.font-mono    { font-family:var(--font-mono); }
.mb-3 { margin-bottom:12px; }
.mb-4 { margin-bottom:20px; }
@keyframes spin { to { transform:rotate(360deg); } }
@keyframes shimmer { 0%{opacity:.5} 50%{opacity:.8} 100%{opacity:.5} }
.skeleton-chart { background:var(--bg-muted); border-radius:8px; animation:shimmer 1.5s infinite; }
.skeleton-bar { background:var(--bg-muted); border-radius:4px; animation:shimmer 1.5s infinite; }

/* ── Report-specific components ── */
.rpt-toolbar { border:none; box-shadow:var(--shadow-sm); }
.rpt-toolbar-label { font-size:11px; font-weight:700; letter-spacing:.5px; color:var(--text-muted); white-space:nowrap; }
.rpt-date-input { width:140px; height:30px; padding:4px 8px; font-size:12px; }
.rpt-spinner { width:12px; height:12px; display:inline-block; border:2px solid var(--border-color); border-top-color:var(--color-primary); border-radius:50%; animation:spin .6s linear infinite; vertical-align:middle; margin-right:4px; }
.rpt-spinner-text { font-size:12px; color:var(--text-muted); }
.rpt-date-range { margin-left:auto; font-size:11px; color:var(--text-muted); font-family:var(--font-mono); }

/* Chart cards */
.rpt-chart-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg, 8px); margin-bottom:16px; overflow:hidden; box-shadow:var(--shadow-sm); }
.rpt-chart-hdr { padding:16px 20px 0; }
.rpt-chart-title { font-size:15px; font-weight:700; color:var(--text-primary); line-height:1.3; }
.rpt-chart-sub { font-size:12px; color:var(--text-muted); margin-top:2px; line-height:1.4; }

/* 2-column chart grid */
.rpt-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
@media (max-width:900px) { .rpt-grid-2 { grid-template-columns:1fr; } }

/* Table improvements */
.rpt-table { font-size:13px; }
.rpt-table th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:var(--text-muted); white-space:nowrap; padding:8px 12px; border-bottom:2px solid var(--border-color); position:sticky; top:0; background:var(--bg-card); z-index:1; }
.rpt-table td { padding:6px 12px; white-space:nowrap; border-bottom:1px solid var(--border-color); vertical-align:middle; }
.rpt-table tbody tr:nth-child(even) { background:var(--bg-muted, rgba(0,0,0,.02)); }
.rpt-table tbody tr:hover { background:color-mix(in srgb, var(--color-primary) 5%, transparent); }

/* Table footer with row cap */
.rpt-table-ft { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; font-size:12px; color:var(--text-muted); border-top:1px solid var(--border-color); background:var(--bg-card); border-radius:0 0 var(--radius-lg, 8px) var(--radius-lg, 8px); }
</style>

<!-- ═══════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════ -->
<script>
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
        rowCap: 25,
        expandedTables: {},

        presetOptions: [
            {value:'this_month',label:'This Month'},{value:'last_month',label:'Last Month'},
            {value:'this_quarter',label:'This Quarter'},{value:'last_quarter',label:'Last Quarter'},
            {value:'this_year',label:'This Year'},{value:'last_year',label:'Last Year'},
            {value:'last_30',label:'Last 30d'},{value:'last_90',label:'Last 90d'},
            {value:'all_time',label:'All Time'},{value:'custom',label:'Custom'},
        ],

        tabs: {
            financial:  {loading:false, viewLoading:false, kpis:null, view:'period',      viewData:{}, chartsRendered:{}},
            fleet:      {loading:false, viewLoading:false, kpis:null, view:'utilization', viewData:{}, chartsRendered:{}},
            customer:   {loading:false, viewLoading:false, kpis:null, view:'ltv',         viewData:{}, chartsRendered:{}},
            compliance: {loading:false, viewLoading:false, kpis:null, view:'timeline',    viewData:{}, chartsRendered:{}},
        },

        init() {
            // Restore two-level tab from hash (format: #mainTab:view, e.g. #financial:period).
            const _mainTabs = ['financial','fleet','customer','compliance','ai'];
            const h = location.hash.slice(1);
            const _parts = h ? h.split(':') : [];
            const _initMain = (_mainTabs.indexOf(_parts[0]) !== -1) ? _parts[0] : 'financial';
            const _initView  = _parts[1] || null;
            this.mainTab = _initMain;
            // Restore sub-view if valid (tabs object only covers non-AI tabs)
            if (_initView && this.tabs[_initMain] && this.tabs[_initMain].view !== _initView) {
                this.tabs[_initMain].view = _initView;
            }
            const _hashKey = () => {
                const m = this.mainTab;
                return m === 'ai' ? 'ai' : m + ':' + (this.tabs[m] ? this.tabs[m].view : '');
            };
            FF_TabHash.write(_hashKey());
            FF_TabHash.watchUnload(_hashKey);
            if (_initMain !== 'ai') this.loadTab(_initMain);
        },

        isLoading() { return this.tabs[this.mainTab]?.loading || this.tabs[this.mainTab]?.viewLoading; },

        setPreset(p) {
            this.preset = p;
            if (p !== 'custom') {
                for (const t in this.tabs) {
                    if (t === this.mainTab) {
                        // Current tab: keep old KPIs + view data visible during refresh
                        this.tabs[t].chartsRendered = {};
                    } else {
                        // Other tabs: clear so they re-fetch when visited
                        this.tabs[t].viewData = {};
                        this.tabs[t].kpis = null;
                        this.tabs[t].chartsRendered = {};
                    }
                }
                this.expandedTables = {};
                this.loadTab(this.mainTab);
            }
        },

        switchMainTab(tab) {
            this.mainTab = tab;
            // Write two-level hash; AI tab has no sub-view
            FF_TabHash.write(tab === 'ai' ? 'ai' : tab + ':' + this.tabs[tab].view);
            if (tab === 'ai') return; // AI tab renders statically — no data load needed
            if (!this.tabs[tab].kpis) this.loadTab(tab);
            else if (!this.tabs[tab].viewData[this.tabs[tab].view]) this.loadView(tab, this.tabs[tab].view);
            else this.$nextTick(() => this.initChart(tab, this.tabs[tab].view, this.tabs[tab].viewData[this.tabs[tab].view]));
        },

        switchView(tab, view) {
            this.tabs[tab].view = view;
            this.tabs[tab].chartsRendered = {};
            // Update hash with current mainTab + new sub-view
            FF_TabHash.write(this.mainTab + ':' + view);
            if (this.tabs[tab].viewData[view]) this.$nextTick(() => this.initChart(tab, view, this.tabs[tab].viewData[view]));
            else this.loadView(tab, view);
        },

        runReport() {
            for (const t in this.tabs) {
                if (t === this.mainTab) {
                    this.tabs[t].chartsRendered = {};
                } else {
                    this.tabs[t].viewData = {};
                    this.tabs[t].kpis = null;
                    this.tabs[t].chartsRendered = {};
                }
            }
            this.expandedTables = {};
            this.loadTab(this.mainTab);
        },

        setCompWindow(days) {
            this.compWindow = days;
            this.tabs.compliance.chartsRendered = {};
            this.expandedTables = {};
            if (this.mainTab === 'compliance') this.loadTab('compliance');
        },

        buildParams(tab, view) {
            const p = { preset: this.preset };
            if (this.preset === 'custom') {
                if (this.customFrom) p.date_from = this.customFrom;
                if (this.customTo)   p.date_to   = this.customTo;
            }
            p.view = view;
            if (tab === 'compliance') p.window_days = this.compWindow;
            return new URLSearchParams(p).toString();
        },

        apiBase(tab) {
            const map = {financial:'revenue', fleet:'fleet', customer:'customer', compliance:'compliance'};
            return (window.FF_BASE_PATH||'') + '/api/v1/reports/' + (map[tab]||tab) + '.php';
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
                if (!d.success) { t.loading=false; t.viewLoading=false; return; }
                const data = d.data;
                t.kpis           = data.kpis;
                t.viewData[view] = data;
                this.resolvedFrom = data.date_from || data.as_of_date || '';
                this.resolvedTo   = data.date_to   || data.as_of_date || '';
                t.loading     = false;
                t.viewLoading = false;
                if (this.mainTab === tab && t.view === view) {
                    this.$nextTick(() => this.initChart(tab, view, data));
                }
            }).catch(() => { t.loading=false; t.viewLoading=false; });
        },

        // ── Table data + row capping ──
        viewData(tab) {
            const t = this.tabs[tab];
            return t.viewData[t.view]?.table || [];
        },
        get currentTotals() {
            const t = this.tabs[this.mainTab];
            return t.viewData[t.view]?.totals || null;
        },
        capped(tab) {
            const all = this.viewData(tab);
            const key = tab + '_' + this.tabs[tab].view;
            if (this.expandedTables[key]) return all;
            return all.slice(0, this.rowCap);
        },
        totalRows(tab) { return this.viewData(tab).length; },
        isOverCap(tab) { return this.totalRows(tab) > this.rowCap; },
        isExpanded(tab) { return !!this.expandedTables[tab + '_' + this.tabs[tab].view]; },
        capLabel(tab) {
            const total = this.totalRows(tab);
            return this.isExpanded(tab)
                ? 'Showing all ' + total + ' rows'
                : 'Showing ' + Math.min(this.rowCap, total) + ' of ' + total + ' rows';
        },
        toggleExpand(tab) {
            const key = tab + '_' + this.tabs[tab].view;
            this.expandedTables[key] = !this.expandedTables[key];
        },

        // ── Export ──
        exportCsv(endpoint, view) {
            const p = this.buildParams(this.mainTab, view);
            window.location = (window.FF_BASE_PATH||'') + '/api/v1/reports/' + endpoint + '.php?' + p + '&format=csv';
        },
        exportCompCsv(view) {
            const p = new URLSearchParams({ view, window_days: this.compWindow, format: 'csv' });
            window.location = (window.FF_BASE_PATH||'') + '/api/v1/reports/compliance.php?' + p.toString();
        },

        // ═════════════════════════════════════════════════════════
        // CHART INITIALIZATION
        // ═════════════════════════════════════════════════════════
        initChart(tab, view, data) {
            if (!data) return;
            const key = tab + '_' + view;
            if (this.tabs[tab].chartsRendered[key]) return;
            this.tabs[tab].chartsRendered[key] = true;

            const cd = data.chart_data;
            if (!cd || !Object.keys(cd).length) return;

            const palette  = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#f97316','#84cc16'];
            const isDark   = document.documentElement.getAttribute('data-theme') === 'dark';
            const txtCol   = isDark ? '#9c9c96' : '#6b6b66';
            const gridCol  = isDark ? '#2e2e2e' : '#e5e5e2';
            const moneyFmt = (v) => '$' + (v||0).toLocaleString('en-CA', {minimumFractionDigits:0, maximumFractionDigits:0});
            const pctFmt   = (v) => (v||0).toFixed(1) + '%';

            const base = {
                chart:    { background:'transparent', fontFamily:'inherit', toolbar:{show:true, tools:{download:true,selection:false,zoom:false,zoomin:false,zoomout:false,pan:false,reset:false}} },
                colors:   palette,
                theme:    { mode: isDark ? 'dark' : 'light' },
                grid:     { borderColor: gridCol, strokeDashArray: 3 },
                xaxis:    { labels:{style:{colors:txtCol,fontSize:'11px'}} },
                yaxis:    { labels:{style:{colors:txtCol,fontSize:'11px'}, formatter:moneyFmt} },
                tooltip:  { theme: isDark ? 'dark' : 'light' },
                dataLabels: { enabled:false },
                legend:   { labels:{colors:txtCol}, fontSize:'12px' },
                stroke:   { width:2 },
            };

            const R = (id, opts) => this.render(id, opts);

            switch (key) {

                // ── FINANCIAL ──────────────────────────────────────
                case 'financial_period':
                    R('chart-rev-period', {...base, chart:{...base.chart,type:'bar',height:300}, series:[
                        {name:'Net Revenue', data:cd.net_revenue||[]},
                        {name:'Collected',   data:cd.collected||[]},
                        {name:'Outstanding', data:cd.outstanding||[]}
                    ], xaxis:{...base.xaxis, categories:cd.categories||[]}, plotOptions:{bar:{borderRadius:3,columnWidth:'60%'}}, colors:['#3b82f6','#10b981','#f59e0b']});
                    break;

                case 'financial_customer':
                    R('chart-rev-customer', {...base, chart:{...base.chart,type:'bar',height:360}, series:[
                        {name:'Revenue',     data:cd.gross_revenue||[]},
                        {name:'Outstanding', data:cd.outstanding||[]}
                    ], xaxis:{...base.xaxis, categories:cd.categories||[]}, plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'65%'}}, colors:['#3b82f6','#f59e0b']});
                    break;

                case 'financial_equipment_type':
                    R('chart-rev-type', {...base, chart:{...base.chart,type:'bar',height:280}, series:[
                        {name:'Gross Revenue', data:cd.gross_revenue||[]}
                    ], xaxis:{...base.xaxis, categories:cd.categories||[]}, plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'55%'}}, colors:['#8b5cf6']});
                    break;

                case 'financial_ar_aging':
                    R('chart-aging', {...base, chart:{...base.chart,type:'bar',height:240}, series:[
                        {name:'Outstanding', data:cd.amounts||[]}
                    ], xaxis:{...base.xaxis, categories:cd.categories||[]}, plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'55%',distributed:true}},
                    colors:['#10b981','#f59e0b','#f97316','#ef4444','#dc2626']});
                    break;

                case 'financial_collection': {
                    const yaxes = [
                        {...base.yaxis, title:{text:'Amount', style:{color:txtCol,fontSize:'12px'}}, labels:{...base.yaxis.labels, formatter:moneyFmt}},
                        {opposite:true, title:{text:'Rate %', style:{color:txtCol,fontSize:'12px'}}, labels:{style:{colors:txtCol,fontSize:'11px'}, formatter:pctFmt}, min:0, max:100}
                    ];
                    R('chart-collection', {...base, chart:{...base.chart,type:'line',height:300}, series:[
                        {name:'Invoiced',  type:'column', data:cd.invoiced||[]},
                        {name:'Collected', type:'column', data:cd.collected||[]},
                        {name:'Rate %',    type:'line',   data:cd.collection_rate||[]}
                    ], xaxis:{...base.xaxis, categories:cd.categories||[]}, yaxis:yaxes, stroke:{width:[0,0,3]}, colors:['#3b82f6','#10b981','#f59e0b']});
                    break;
                }

                case 'financial_status':
                    R('chart-inv-status', {...base, chart:{...base.chart,type:'donut',height:280},
                        series:cd.total_amounts||[], labels:(cd.labels||[]).map(s=>(s||'').replace(/_/g,' ')),
                        plotOptions:{pie:{donut:{size:'55%'}}}, legend:{position:'right'} });
                    break;

                // ── FLEET — SCALE-AWARE CHARTS ─────────────────────
                case 'fleet_utilization': {
                    // Distribution histogram: bin units into 10% brackets
                    const rows = data.table || [];
                    const bins = Array(10).fill(0);
                    rows.forEach(r => {
                        const p = Math.min(99.9, Math.max(0, parseFloat(r.utilization_pct || 0)));
                        bins[Math.floor(p / 10)]++;
                    });
                    const binLabels = ['0–10%','10–20%','20–30%','30–40%','40–50%','50–60%','60–70%','70–80%','80–90%','90–100%'];
                    // Red → yellow → green gradient
                    const utilGradient = ['#ef4444','#f97316','#f59e0b','#eab308','#a3e635','#22c55e','#10b981','#14b8a6','#06b6d4','#3b82f6'];
                    const avgUtil = rows.length ? rows.reduce((s,r) => s + parseFloat(r.utilization_pct||0), 0) / rows.length : 0;

                    R('chart-fleet-util', {...base, chart:{...base.chart,type:'bar',height:320},
                        series:[{name:'Units', data:bins}],
                        xaxis:{...base.xaxis, categories:binLabels},
                        yaxis:{...base.yaxis, title:{text:'Number of Units', style:{color:txtCol,fontSize:'12px'}}, labels:{...base.yaxis.labels, formatter:v=>Math.round(v)}},
                        colors:utilGradient,
                        plotOptions:{bar:{borderRadius:4, columnWidth:'70%', distributed:true}},
                        annotations:{yaxis:[{y:0,borderColor:'transparent'}], xaxis:[{
                            x: binLabels[Math.min(9, Math.floor(avgUtil/10))],
                            borderColor:'#6366f1', strokeDashArray:0,
                            label:{text:'Avg: '+avgUtil.toFixed(1)+'%', borderColor:'#6366f1', style:{color:'#fff',background:'#6366f1',fontSize:'11px',padding:{left:6,right:6,top:3,bottom:3}}}
                        }]},
                        tooltip:{y:{formatter:v=>v+' unit'+(v!==1?'s':'')}}
                    });
                    break;
                }

                case 'fleet_roi': {
                    // Top 10 / Bottom 10 diverging bars
                    const rows = data.table || [];
                    const top10 = rows.slice(0, Math.min(10, rows.length));
                    const bottom10 = rows.length > 10 ? rows.slice(-Math.min(10, rows.length - 10)).reverse() : [];

                    R('chart-fleet-roi-top', {...base, chart:{...base.chart,type:'bar',height:280},
                        series:[{name:'ROI', data:top10.map(r=>parseFloat(r.roi||0))}],
                        xaxis:{...base.xaxis, categories:top10.map(r=>r.unit_number)},
                        colors:['#10b981'], plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'60%'}},
                        tooltip:{y:{formatter:moneyFmt}}
                    });

                    if (bottom10.length) {
                        R('chart-fleet-roi-bottom', {...base, chart:{...base.chart,type:'bar',height:280},
                            series:[{name:'ROI', data:bottom10.map(r=>parseFloat(r.roi||0))}],
                            xaxis:{...base.xaxis, categories:bottom10.map(r=>r.unit_number)},
                            colors:['#ef4444'], plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'60%'}},
                            tooltip:{y:{formatter:moneyFmt}}
                        });
                    }
                    break;
                }

                case 'fleet_idle':
                    R('chart-fleet-idle', {...base, chart:{...base.chart,type:'donut',height:260},
                        series:cd.counts||[], labels:cd.labels||[],
                        plotOptions:{pie:{donut:{size:'50%',labels:{show:true,total:{show:true,label:'Idle Units'}}}}}, legend:{position:'right'}});
                    break;

                case 'fleet_maintenance':
                    R('chart-maint-type', {...base, chart:{...base.chart,type:'bar',height:280},
                        series:[
                            {name:'Labour', data:(cd.labor_cost||[]).map(Number)},
                            {name:'Parts',  data:(cd.parts_cost||[]).map(Number)},
                        ],
                        xaxis:{...base.xaxis, categories:(cd.labels||[]).map(s=>(s||'').replace(/_/g,' '))},
                        plotOptions:{bar:{borderRadius:3,columnWidth:'55%'}}, colors:['#3b82f6','#f59e0b'], chart:{...base.chart,type:'bar',height:280,stacked:true}});
                    break;

                case 'fleet_yard':
                    R('chart-fleet-yard', {...base, chart:{...base.chart,type:'bar',height:280},
                        series:[{name:'Revenue',data:(cd.revenue||[]).map(Number)},{name:'Maintenance',data:(cd.maintenance||[]).map(Number)}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        plotOptions:{bar:{borderRadius:3,columnWidth:'55%'}}, colors:['#10b981','#ef4444']});
                    break;

                // ── CUSTOMER ──────────────────────────────────────
                case 'customer_ltv':
                    R('chart-cust-ltv', {...base, chart:{...base.chart,type:'bar',height:340},
                        series:[{name:'Lifetime Revenue',data:cd.lifetime_revenue||[]},{name:'Period Revenue',data:cd.period_revenue||[]}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'60%'}}, colors:['#3b82f6','#10b981']});
                    break;

                case 'customer_payment_behavior': {
                    // Distribution histogram: bin customers by avg days to pay
                    const rows = data.table || [];
                    const binDef = [
                        {label:'< -14d (early)',  min:-Infinity, max:-14, color:'#059669'},
                        {label:'-14 to -8d',      min:-14,       max:-7,  color:'#10b981'},
                        {label:'-7 to -1d',       min:-7,        max:0,   color:'#84cc16'},
                        {label:'On time (0d)',     min:0,         max:1,   color:'#3b82f6'},
                        {label:'1–7d late',        min:1,         max:8,   color:'#f59e0b'},
                        {label:'8–14d late',       min:8,         max:15,  color:'#f97316'},
                        {label:'15–30d late',      min:15,        max:31,  color:'#ef4444'},
                        {label:'30+ days late',    min:31,        max:Infinity, color:'#dc2626'},
                    ];
                    const binCounts = binDef.map(() => 0);
                    rows.forEach(r => {
                        const d = parseFloat(r.avg_days_to_pay || 0);
                        for (let i = 0; i < binDef.length; i++) {
                            if (d >= binDef[i].min && d < binDef[i].max) { binCounts[i]++; break; }
                        }
                    });

                    R('chart-pay-behavior', {...base, chart:{...base.chart,type:'bar',height:300},
                        series:[{name:'Customers', data:binCounts}],
                        xaxis:{...base.xaxis, categories:binDef.map(b=>b.label)},
                        yaxis:{...base.yaxis, title:{text:'Number of Customers', style:{color:txtCol,fontSize:'12px'}}, labels:{...base.yaxis.labels, formatter:v=>Math.round(v)}},
                        colors:binDef.map(b=>b.color),
                        plotOptions:{bar:{borderRadius:4, columnWidth:'65%', distributed:true}},
                        annotations:{xaxis:[{x:'On time (0d)', borderColor:'#6366f1', strokeDashArray:0,
                            label:{text:'Due Date', borderColor:'#6366f1', style:{color:'#fff',background:'#6366f1',fontSize:'11px',padding:{left:6,right:6,top:3,bottom:3}}}}]},
                        tooltip:{y:{formatter:v=>v+' customer'+(v!==1?'s':'')}}
                    });
                    break;
                }

                case 'customer_new_returning':
                    R('chart-new-returning', {...base, chart:{...base.chart,type:'bar',height:300,stacked:true},
                        series:[{name:'New Revenue',data:cd.new_revenue||[]},{name:'Returning Revenue',data:cd.returning_revenue||[]}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        plotOptions:{bar:{borderRadius:3,columnWidth:'55%'}}, colors:['#10b981','#3b82f6']});
                    break;

                case 'customer_frequency':
                    R('chart-cust-freq', {...base, chart:{...base.chart,type:'bar',height:300},
                        series:[{name:'Leases', data:cd.lease_count||[]}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        yaxis:{...base.yaxis, labels:{...base.yaxis.labels, formatter:v=>Math.round(v)}},
                        plotOptions:{bar:{horizontal:true,borderRadius:3,barHeight:'55%'}}, colors:['#8b5cf6']});
                    break;

                case 'customer_credit_notes':
                    R('chart-credit-notes', {...base, chart:{...base.chart,type:'bar',height:280},
                        series:[{name:'Issued',data:cd.total_issued||[]},{name:'Used',data:cd.total_used||[]},{name:'Remaining',data:cd.total_remaining||[]}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        plotOptions:{bar:{borderRadius:3,columnWidth:'55%'}}, colors:['#3b82f6','#10b981','#f59e0b']});
                    break;

                // ── COMPLIANCE ─────────────────────────────────────
                case 'compliance_timeline':
                    R('chart-comp-timeline', {...base, chart:{...base.chart,type:'bar',height:300},
                        series:[{name:'CVI',data:cd.cvi||[]},{name:'Registration',data:cd.registration||[]},{name:'Insurance',data:cd.insurance||[]}],
                        xaxis:{...base.xaxis, categories:cd.categories||[]},
                        yaxis:{...base.yaxis, labels:{...base.yaxis.labels, formatter:v=>Math.round(v)}},
                        plotOptions:{bar:{borderRadius:3,columnWidth:'55%'}}, colors:['#3b82f6','#10b981','#f59e0b']});
                    break;

                case 'compliance_status': {
                    const cats = ['CVI','Registration','Insurance'];
                    const pre = ['cvi','reg','ins'];
                    R('chart-comp-status', {...base, chart:{...base.chart,type:'bar',height:260,stacked:true},
                        series:[
                            {name:'Expired',  data:pre.map(p=>parseInt(cd[p+'_expired']||0))},
                            {name:'≤30d',     data:pre.map(p=>parseInt(cd[p+'_expiring_30']||0))},
                            {name:'31–90d',   data:pre.map(p=>parseInt(cd[p+'_expiring_90']||0))},
                            {name:'OK',       data:pre.map(p=>parseInt(cd[p+'_ok']||0))},
                            {name:'No Date',  data:pre.map(p=>parseInt(cd[p+'_missing']||0))},
                        ],
                        xaxis:{...base.xaxis, categories:cats},
                        yaxis:{...base.yaxis, labels:{...base.yaxis.labels, formatter:v=>Math.round(v)}},
                        plotOptions:{bar:{borderRadius:2,columnWidth:'45%'}}, colors:['#ef4444','#f59e0b','#06b6d4','#10b981','#9ca3af']});
                    break;
                }
            }
        },

        render(elId, options) {
            const el = document.getElementById(elId);
            if (!el) return;
            if (el._apexChart) { el._apexChart.destroy(); el._apexChart = null; }
            const chart = new ApexCharts(el, options);
            chart.render();
            el._apexChart = chart;
        },

        // ── Helpers ──
        money(val) {
            const n = parseFloat(val || 0);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', {minimumFractionDigits:2, maximumFractionDigits:2});
        },
        pct(val) { return (parseFloat(val)||0).toFixed(1) + '%'; },
        customerBadge(s) { return {active:'success',inactive:'neutral',pending:'info',suspended:'danger',credit_hold:'warning'}[s]||'neutral'; },
        unitStatusBadge(s) { return {available:'badge-success',on_lease:'badge-info',reserved:'badge-purple',maintenance:'badge-warning',inactive:'badge-neutral',decommissioned:'badge-danger'}[s]||'badge-neutral'; },
        invStatusBadge(s) { return {draft:'badge-neutral',sent:'badge-info',paid:'badge-success',partially_paid:'badge-warning',overdue:'badge-danger',void:'badge-neutral',written_off:'badge-danger'}[s]||'badge-neutral'; },
        agingBadge(b) { return {current:'badge-success','1_30':'badge-warning','31_60':'badge-warning','61_90':'badge-danger','90_plus':'badge-danger'}[b]||'badge-neutral'; },
        utilColor(pct) { const p=parseFloat(pct||0); return p>=70?'text-success':p>=40?'text-warning':'text-danger'; },
        daysLeftClass(days) { if(days==null||days==='')return ''; const d=parseInt(days); return d<=7?'text-danger':d<=30?'text-warning':'text-success'; },
    };
}
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
