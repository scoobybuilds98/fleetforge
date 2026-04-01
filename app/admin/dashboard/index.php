<?php
declare(strict_types=1);

/**
 * FleetForge — Admin Dashboard
 *
 * @file        app/admin/dashboard/index.php
 * @description Main dashboard page. Loads KPI tiles, 8 ApexCharts, activity feed,
 *              compliance alerts widget, and today's pickups widget via Alpine.js
 *              fetch calls to the api/v1/dashboard/* endpoints.
 *              No module permission required — dashboard is accessible to all
 *              authenticated staff users.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/dashboard/kpis.php,
 *              api/v1/dashboard/charts.php, api/v1/dashboard/activity_feed.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.1 Dashboard
 * @design      FLEETFORGE_DESIGN_DETAILS.md §4 Dashboard Grid Layout
 * @session     S004 — KPIs + charts wired; stubs replaced
 */

// dirname(__DIR__, 3): app/admin/dashboard/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();

$pageTitle = 'Dashboard';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Dashboard</h1>
    <div class="page-header-actions">
        <span class="text-secondary text-sm">
            <?= e(format_date(date('Y-m-d'))) ?>
        </span>
    </div>
</div>

<!-- ============================================================
     DASHBOARD ALPINE COMPONENT
     Fetches all dashboard data on mount. Charts rendered after
     KPI + chart data resolves. Error states handled inline.
     ============================================================ -->
<div x-data="FF_Dashboard()" x-init="init()">

    <!-- ── KPI TILES ──────────────────────────────────────────── -->
    <!-- 6 tiles, single row desktop / 3×2 tablet / 2×3 mobile   -->
    <!-- Spec §7.1; drilldowns per FLEETFORGE_SPEC_FINAL.md §7.1  -->
    <div class="stat-grid" id="kpi-grid" aria-label="Key performance indicators">

        <!-- Active Revenue -->
        <a href="<?= base_url('reports') ?>"
           class="stat-card stat-card--link"
           :class="kpiError ? 'stat-card--error' : ''"
           aria-label="Active Revenue — click to view revenue reports">
            <div class="stat-label">Active Revenue</div>
            <div class="stat-value" x-text="kpisLoaded ? '$' + formatMoney(kpis.active_revenue) : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">
                Active lease rates
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Fleet Utilization -->
        <a href="<?= base_url('equipment') ?>"
           class="stat-card stat-card--link"
           aria-label="Fleet Utilization — click to view equipment">
            <div class="stat-label">Fleet Utilization</div>
            <div class="stat-value"
                 x-text="kpisLoaded ? kpis.fleet_utilization + '%' : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded"
                 x-text="kpisLoaded ? kpis.on_lease_count + ' of ' + kpis.total_active_units + ' units' : ''">
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Overdue Invoices -->
        <a href="<?= base_url('invoices') ?>?status=overdue"
           class="stat-card stat-card--link stat-card--danger"
           aria-label="Overdue Invoices — click to view overdue invoices">
            <div class="stat-label">Overdue Invoices</div>
            <div class="stat-value"
                 x-text="kpisLoaded ? kpis.overdue_invoices.count : '—'">—</div>
            <div class="stat-delta" x-show="kpisLoaded"
                 x-text="kpisLoaded ? '$' + formatMoney(kpis.overdue_invoices.total) + ' outstanding' : ''">
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Compliance Alerts -->
        <a href="<?= base_url('compliance') ?>"
           class="stat-card stat-card--link stat-card--warning"
           aria-label="Compliance Alerts — click to view compliance">
            <div class="stat-label">Compliance Alerts</div>
            <div class="stat-value"
                 x-text="kpisLoaded ? kpis.compliance_alerts : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">
                Expiring in 30 days
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Open Leases -->
        <a href="<?= base_url('leases') ?>?status=active"
           class="stat-card stat-card--link"
           aria-label="Open Leases — click to view active leases">
            <div class="stat-label">Open Leases</div>
            <div class="stat-value"
                 x-text="kpisLoaded ? kpis.open_leases : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">
                Active &amp; pending
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Today's Pickups -->
        <a href="<?= base_url('leases') ?>?start_date=today"
           class="stat-card stat-card--link"
           aria-label="Today's Pickups — click to view today's lease starts">
            <div class="stat-label">Today's Pickups</div>
            <div class="stat-value"
                 x-text="kpisLoaded ? kpis.todays_pickups : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">
                Starting today
            </div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

    </div><!-- /stat-grid -->


    <!-- ── ROW 1: Revenue trend (2/3) + Fleet status donut (1/3) ─ -->
    <div class="grid-2-1" style="margin-bottom:24px;">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Revenue — Last 12 Months</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:220px;"></div>
                <div x-show="chartsLoaded" id="chart-revenue-trend"
                     style="height:220px;" aria-label="Revenue over time chart"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Fleet Status</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:220px;"></div>
                <div x-show="chartsLoaded" id="chart-fleet-status"
                     style="height:220px;" aria-label="Fleet status donut chart"></div>
            </div>
        </div>

    </div>

    <!-- ── ROW 2: AR Aging (1/2) + Utilization Trend (1/2) ───── -->
    <div class="grid-2" style="margin-bottom:24px;">

        <div class="card">
            <div class="card-header">
                <span class="card-title">AR Aging</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:180px;"></div>
                <div x-show="chartsLoaded" id="chart-ar-aging"
                     style="height:180px;" aria-label="AR aging bar chart"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Utilization Trend</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:180px;"></div>
                <div x-show="chartsLoaded" id="chart-utilization-trend"
                     style="height:180px;" aria-label="Fleet utilization trend chart"></div>
            </div>
        </div>

    </div>

    <!-- ── ROW 3: Top Customers (1/2) + Leases Trend (1/2) ───── -->
    <div class="grid-2" style="margin-bottom:24px;">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Top Customers — YTD</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:180px;"></div>
                <div x-show="chartsLoaded" id="chart-top-customers"
                     style="height:180px;" aria-label="Top customers by revenue chart"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Leases Opened vs Closed</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:180px;"></div>
                <div x-show="chartsLoaded" id="chart-leases-trend"
                     style="height:180px;" aria-label="Leases opened vs closed chart"></div>
            </div>
        </div>

    </div>

    <!-- ── ROW 4: Today's Pickups widget (1/2) + Compliance Alerts (1/2) -->
    <div class="grid-2" style="margin-bottom:24px;">

        <!-- Today's Pickups list widget -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Today's Pickups</span>
                <a href="<?= base_url('leases') ?>?start_date=today"
                   class="btn btn-ghost btn-sm">View all</a>
            </div>
            <div class="card-body" style="padding:0;">

                <!-- Loading skeleton -->
                <template x-if="!kpisLoaded && !activityLoaded">
                    <div class="p-4 text-center text-muted text-sm">Loading…</div>
                </template>

                <!-- Zero state -->
                <template x-if="kpisLoaded && kpis.todays_pickups === 0">
                    <div class="empty-state" style="padding:24px;">
                        <p class="empty-state-primary">No pickups today</p>
                        <p class="empty-state-secondary">No leases starting today.</p>
                    </div>
                </template>

                <!-- Count summary when > 0 (full list widget is a future session) -->
                <template x-if="kpisLoaded && kpis.todays_pickups > 0">
                    <div class="p-4">
                        <p class="text-secondary text-sm">
                            <strong x-text="kpis.todays_pickups"></strong>
                            lease<span x-text="kpis.todays_pickups === 1 ? '' : 's'"></span>
                            starting today.
                            <a href="<?= base_url('leases') ?>?start_date=today"
                               class="link">View details →</a>
                        </p>
                    </div>
                </template>

            </div>
        </div>

        <!-- Compliance Alerts list widget -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Compliance Alerts</span>
                <a href="<?= base_url('compliance') ?>"
                   class="btn btn-ghost btn-sm">View all</a>
            </div>
            <div class="card-body" style="padding:0;">

                <template x-if="!kpisLoaded">
                    <div class="p-4 text-center text-muted text-sm">Loading…</div>
                </template>

                <template x-if="kpisLoaded && kpis.compliance_alerts === 0">
                    <div class="empty-state" style="padding:24px;">
                        <p class="empty-state-primary">All clear</p>
                        <p class="empty-state-secondary">No compliance expiries in the next 30 days.</p>
                    </div>
                </template>

                <template x-if="kpisLoaded && kpis.compliance_alerts > 0">
                    <div class="p-4">
                        <p class="text-secondary text-sm">
                            <strong x-text="kpis.compliance_alerts"></strong>
                            unit<span x-text="kpis.compliance_alerts === 1 ? '' : 's'"></span>
                            with documents expiring within 30 days.
                            <a href="<?= base_url('compliance') ?>"
                               class="link">Review →</a>
                        </p>
                    </div>
                </template>

            </div>
        </div>

    </div>

    <!-- ── ROW 5: Activity Feed (1/2) + Revenue by Type donut + Weekly Heatmap -->
    <div class="grid-2" style="margin-bottom:24px;">

        <!-- Recent Activity Feed -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Activity</span>
            </div>
            <div class="card-body" style="padding:0; max-height:320px; overflow-y:auto;">

                <template x-if="!activityLoaded && !activityError">
                    <div class="p-4 text-center text-muted text-sm">Loading…</div>
                </template>

                <template x-if="activityError">
                    <div class="p-4 text-center text-sm" style="color:var(--color-danger)">
                        Failed to load activity feed.
                        <button class="btn btn-ghost btn-sm" @click="fetchActivity()">Retry</button>
                    </div>
                </template>

                <template x-if="activityLoaded && activity.length === 0">
                    <div class="empty-state" style="padding:24px;">
                        <p class="empty-state-primary">No activity yet</p>
                        <p class="empty-state-secondary">Actions will appear here as staff use the system.</p>
                    </div>
                </template>

                <template x-if="activityLoaded && activity.length > 0">
                    <ul class="activity-feed" role="list">
                        <template x-for="item in activity" :key="item.id">
                            <li class="activity-item">
                                <span class="activity-dot"
                                      :class="'activity-dot--' + item.module"
                                      aria-hidden="true"></span>
                                <div class="activity-body">
                                    <span class="activity-desc"
                                          x-text="item.description"></span>
                                    <span class="activity-meta text-muted text-xs"
                                          x-text="item.user_name + ' · ' + item.time_ago"></span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>

            </div>
        </div>

        <!-- Revenue by Equipment Type (donut) -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Revenue by Equipment Type — YTD</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:280px;"></div>
                <div x-show="chartsLoaded" id="chart-revenue-by-type"
                     style="height:280px;" aria-label="Revenue by equipment type donut chart"></div>
            </div>
        </div>

    </div>

    <!-- ── ROW 6: Weekly Revenue Heatmap (full width) ─────────── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
            <span class="card-title">Daily Revenue — Last 12 Weeks</span>
        </div>
        <div class="card-body">
            <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                 style="height:160px;"></div>
            <div x-show="chartsLoaded" id="chart-weekly-heatmap"
                 style="height:160px;" aria-label="Weekly revenue heatmap"></div>
        </div>
    </div>

</div><!-- /x-data=FF_Dashboard -->


<!-- ============================================================
     DASHBOARD JAVASCRIPT
     Alpine component + ApexCharts initialisation.
     Defined inline so chart element IDs are guaranteed in DOM
     before the script runs. Alpine picks up x-data="FF_Dashboard()"
     on the outer div above.
     ============================================================ -->
<script>
/**
 * FF_Dashboard — Alpine.js component for the admin dashboard.
 *
 * Responsibilities:
 *   1. Fetch KPI data from api/v1/dashboard/kpis.php
 *   2. Fetch all 8 chart datasets from api/v1/dashboard/charts.php
 *   3. Fetch activity feed from api/v1/dashboard/activity_feed.php
 *   4. Render ApexCharts after data arrives
 *   5. Handle loading skeletons and error states
 *
 * WHY inline rather than in app.js: chart element IDs must exist in the
 * DOM before ApexCharts renders. Putting this in a module-level init in
 * app.js would require a DOMContentLoaded listener and careful sequencing.
 * Inline keeps the dependency obvious.
 */
function FF_Dashboard() {
    return {
        // ── State ──────────────────────────────────────────────
        kpis:          {},
        kpisLoaded:    false,
        kpiError:      false,

        charts:        {},
        chartsLoaded:  false,
        chartError:    false,

        activity:      [],
        activityLoaded: false,
        activityError:  false,

        // Stored ApexChart instances for theme-update support
        _chartInstances: {},

        // ── Init ───────────────────────────────────────────────
        init() {
            // Fire all three fetches in parallel — no ordering dependency
            this.fetchKpis();
            this.fetchCharts();
            this.fetchActivity();
        },

        // ── KPI fetch ──────────────────────────────────────────
        async fetchKpis() {
            try {
                const res = await FF_API.get('<?= base_url('api/v1/dashboard/kpis') ?>');
                if (res.success) {
                    this.kpis       = res.data;
                    this.kpisLoaded = true;
                } else {
                    this.kpiError = true;
                }
            } catch (e) {
                this.kpiError = true;
                console.error('[Dashboard] KPI fetch failed', e);
            }
        },

        // ── Charts fetch ───────────────────────────────────────
        async fetchCharts() {
            try {
                const res = await FF_API.get('<?= base_url('api/v1/dashboard/charts') ?>');
                if (res.success) {
                    this.charts      = res.data;
                    this.chartsLoaded = true;
                    // Wait one tick for x-show to reveal chart divs, then render
                    this.$nextTick(() => this.renderAllCharts());
                } else {
                    this.chartError = true;
                }
            } catch (e) {
                this.chartError = true;
                console.error('[Dashboard] Charts fetch failed', e);
            }
        },

        // ── Activity feed fetch ────────────────────────────────
        async fetchActivity() {
            try {
                const res = await FF_API.get('<?= base_url('api/v1/dashboard/activity_feed') ?>');
                if (res.success) {
                    this.activity       = res.data.items;
                    this.activityLoaded = true;
                } else {
                    this.activityError = true;
                }
            } catch (e) {
                this.activityError = true;
                console.error('[Dashboard] Activity fetch failed', e);
            }
        },

        // ── Render all 8 charts ────────────────────────────────
        renderAllCharts() {
            const d = this.charts;
            const c = this._chartInstances;

            // Spec chart palette
            const palette = ['#3b82f6','#10b981','#f59e0b','#8b5cf6',
                             '#ef4444','#06b6d4','#f97316','#84cc16'];

            const isDark  = document.documentElement.dataset.theme === 'dark';
            const fg      = isDark ? '#e8e8e4' : '#1c1c1a';
            const fgMuted = isDark ? '#6b6b66' : '#a3a39e';
            const border  = isDark ? '#2e2e2e' : '#e5e5e2';
            const gridBg  = isDark ? '#1a1a1a' : '#ffffff';

            // Shared base options applied to all charts
            const base = {
                chart: { toolbar: { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } }, background: 'transparent', fontFamily: 'DM Sans, sans-serif' },
                theme: { mode: isDark ? 'dark' : 'light' },
                colors: palette,
                grid:   { borderColor: border },
                tooltip: { theme: isDark ? 'dark' : 'light' },
                legend: { labels: { colors: fg } },
                xaxis:  { labels: { style: { colors: fgMuted } }, axisBorder: { color: border }, axisTicks: { color: border } },
                yaxis:  { labels: { style: { colors: fgMuted } } },
            };

            // 1. Revenue trend — area
            if (d.revenue_trend && document.getElementById('chart-revenue-trend')) {
                c.revenue_trend = new ApexCharts(
                    document.getElementById('chart-revenue-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'area', height: 220 }),
                        stroke: { curve: 'smooth', width: 2 },
                        fill:   { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.revenue_trend.labels }),
                        yaxis: Object.assign({}, base.yaxis, { labels: { style: { colors: fgMuted }, formatter: v => '$' + this.formatMoney(v) } }),
                        series: d.revenue_trend.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => '$' + this.formatMoney(v) } }),
                    })
                );
                c.revenue_trend.render();
            }

            // 2. Fleet status — donut
            if (d.fleet_status && document.getElementById('chart-fleet-status')) {
                c.fleet_status = new ApexCharts(
                    document.getElementById('chart-fleet-status'),
                    Object.assign({}, base, {
                        chart:  Object.assign({}, base.chart, { type: 'donut', height: 220 }),
                        labels: d.fleet_status.labels,
                        series: d.fleet_status.series,
                        legend: Object.assign({}, base.legend, { position: 'bottom' }),
                        plotOptions: { pie: { donut: { size: '60%' } } },
                        dataLabels: { enabled: false },
                    })
                );
                c.fleet_status.render();
            }

            // 3. AR aging — horizontal bar
            if (d.ar_aging && document.getElementById('chart-ar-aging')) {
                c.ar_aging = new ApexCharts(
                    document.getElementById('chart-ar-aging'),
                    Object.assign({}, base, {
                        chart:  Object.assign({}, base.chart, { type: 'bar', height: 180 }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.ar_aging.labels, labels: { style: { colors: fgMuted }, formatter: v => '$' + this.formatMoney(v) } }),
                        series: d.ar_aging.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => '$' + this.formatMoney(v) } }),
                    })
                );
                c.ar_aging.render();
            }

            // 4. Utilization trend — line
            if (d.utilization_trend && document.getElementById('chart-utilization-trend')) {
                c.utilization_trend = new ApexCharts(
                    document.getElementById('chart-utilization-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'line', height: 180 }),
                        stroke: { curve: 'smooth', width: 2 },
                        dataLabels: { enabled: false },
                        markers: { size: 3 },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.utilization_trend.labels }),
                        yaxis: Object.assign({}, base.yaxis, { min: 0, max: 100, labels: { style: { colors: fgMuted }, formatter: v => v + '%' } }),
                        series: d.utilization_trend.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => v + '%' } }),
                    })
                );
                c.utilization_trend.render();
            }

            // 5. Top customers — horizontal bar
            if (d.top_customers && document.getElementById('chart-top-customers')) {
                c.top_customers = new ApexCharts(
                    document.getElementById('chart-top-customers'),
                    Object.assign({}, base, {
                        chart:  Object.assign({}, base.chart, { type: 'bar', height: 180 }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.top_customers.labels, labels: { style: { colors: fgMuted }, formatter: v => '$' + this.formatMoney(v) } }),
                        series: d.top_customers.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => '$' + this.formatMoney(v) } }),
                    })
                );
                c.top_customers.render();
            }

            // 6. Leases trend — grouped bar
            if (d.leases_trend && document.getElementById('chart-leases-trend')) {
                c.leases_trend = new ApexCharts(
                    document.getElementById('chart-leases-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'bar', height: 180 }),
                        plotOptions: { bar: { columnWidth: '60%', borderRadius: 2 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.leases_trend.labels }),
                        series: d.leases_trend.series,
                    })
                );
                c.leases_trend.render();
            }

            // 7. Revenue by equipment type — donut
            if (d.revenue_by_type && document.getElementById('chart-revenue-by-type')) {
                if (d.revenue_by_type.series && d.revenue_by_type.series.length > 0) {
                    c.revenue_by_type = new ApexCharts(
                        document.getElementById('chart-revenue-by-type'),
                        Object.assign({}, base, {
                            chart:  Object.assign({}, base.chart, { type: 'donut', height: 280 }),
                            labels: d.revenue_by_type.labels,
                            series: d.revenue_by_type.series,
                            legend: Object.assign({}, base.legend, { position: 'bottom' }),
                            plotOptions: { pie: { donut: { size: '60%' } } },
                            dataLabels: { enabled: false },
                            tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => '$' + this.formatMoney(v) } }),
                        })
                    );
                    c.revenue_by_type.render();
                } else {
                    document.getElementById('chart-revenue-by-type').innerHTML =
                        '<div class="empty-state" style="padding:40px"><p class="empty-state-primary">No data yet</p><p class="empty-state-secondary">Revenue will appear here once invoices are created.</p></div>';
                }
            }

            // 8. Weekly heatmap
            if (d.weekly_heatmap && document.getElementById('chart-weekly-heatmap')) {
                c.weekly_heatmap = new ApexCharts(
                    document.getElementById('chart-weekly-heatmap'),
                    Object.assign({}, base, {
                        chart:      Object.assign({}, base.chart, { type: 'heatmap', height: 160 }),
                        dataLabels: { enabled: false },
                        stroke:     { width: 2, colors: [gridBg] },
                        colors:     ['#3b82f6'],
                        plotOptions: { heatmap: { shadeIntensity: 0.8, radius: 2, useFillColorAsStroke: false } },
                        series: d.weekly_heatmap.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: v => '$' + this.formatMoney(v) } }),
                    })
                );
                c.weekly_heatmap.render();
            }
        },

        // ── Helpers ────────────────────────────────────────────

        /**
         * Format a numeric or string value as a comma-separated money string.
         * WHY JS: format_currency() is PHP-side; for dynamic Alpine values we
         * need a client-side formatter that matches the server's output style.
         */
        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
