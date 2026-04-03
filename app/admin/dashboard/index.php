<?php
declare(strict_types=1);

/**
 * FleetForge — Admin Dashboard
 *
 * @file        app/admin/dashboard/index.php
 * @description Main dashboard page. Loads KPI tiles, 8 ApexCharts, activity feed,
 *              compliance alerts widget, and today's pickups widget via Alpine.js.
 *              S018: Today's Pickups tile now links to /reservations (was /leases?start_date=today).
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
            <div class="stat-card__header">
                <div class="stat-label">Active Revenue</div>
                <div class="stat-card__icon stat-card__icon--primary">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? '$' + formatMoney(kpis.active_revenue) : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Active lease rates</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Fleet Utilization -->
        <a href="<?= base_url('equipment') ?>"
           class="stat-card stat-card--link"
           aria-label="Fleet Utilization — click to view equipment">
            <div class="stat-card__header">
                <div class="stat-label">Fleet Utilization</div>
                <div class="stat-card__icon stat-card__icon--success">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.fleet_utilization + '%' : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded"
                 x-text="kpisLoaded ? kpis.on_lease_count + ' of ' + kpis.total_active_units + ' units' : ''"></div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Overdue Invoices -->
        <a href="<?= base_url('invoices') ?>?status=overdue"
           class="stat-card stat-card--link stat-card--danger"
           aria-label="Overdue Invoices — click to view overdue invoices">
            <div class="stat-card__header">
                <div class="stat-label">Overdue Invoices</div>
                <div class="stat-card__icon stat-card__icon--danger">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.overdue_invoices.count : '—'">—</div>
            <div class="stat-delta" x-show="kpisLoaded"
                 x-text="kpisLoaded ? '$' + formatMoney(kpis.overdue_invoices.total) + ' outstanding' : ''"></div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Compliance Alerts -->
        <a href="<?= base_url('compliance') ?>"
           class="stat-card stat-card--link stat-card--warning"
           aria-label="Compliance Alerts — click to view compliance">
            <div class="stat-card__header">
                <div class="stat-label">Compliance Alerts</div>
                <div class="stat-card__icon stat-card__icon--warning">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.compliance_alerts : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Expiring in 30 days</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Open Leases -->
        <a href="<?= base_url('leases') ?>?status=active"
           class="stat-card stat-card--link"
           aria-label="Open Leases — click to view active leases">
            <div class="stat-card__header">
                <div class="stat-label">Open Leases</div>
                <div class="stat-card__icon stat-card__icon--purple">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.open_leases : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Active &amp; pending</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

        <!-- Today's Pickups — links to reservations module (S018) -->
        <a href="<?= base_url('reservations') ?>?pickup_date=<?= date('Y-m-d') ?>"
           class="stat-card stat-card--link"
           aria-label="Today's Pickups — click to view today's reservations">
            <div class="stat-card__header">
                <div class="stat-label">Today's Pickups</div>
                <div class="stat-card__icon stat-card__icon--info">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.todays_pickups : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Reservations today</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
        </a>

    </div><!-- /stat-grid -->


    <!-- ── ACTIVE LEASES TABLE (full width) ──────────────────────── -->
    <div class="card card--accent-primary" style="margin-bottom:24px;">
        <div class="card-header">
            <span class="card-title">Active Leases</span>
            <a href="<?= base_url('leases') ?>?status=active" class="btn btn-ghost btn-sm">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div style="padding:32px 0; text-align:center;">
                <div class="skeleton skeleton-text" style="width:60%;margin:0 auto 10px;"></div>
                <div class="skeleton skeleton-text" style="width:40%;margin:0 auto;"></div>
            </div>
        </template>

        <template x-if="tablesError">
            <div class="empty-state" style="padding:24px;">
                <p class="empty-state-title">Could not load leases</p>
                <button class="btn btn-secondary btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.active_leases.length === 0">
            <div class="empty-state" style="padding:32px 24px;">
                <p class="empty-state-title">No active leases</p>
                <p class="empty-state-text">Active leases will appear here once units are out on lease.</p>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.active_leases.length > 0">
            <div class="table-wrapper" style="border:none; border-radius:0; box-shadow:none;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Contract</th>
                            <th>Customer</th>
                            <th>Unit</th>
                            <th>Rate</th>
                            <th>Started</th>
                            <th>End Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in tables.active_leases" :key="row.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                       class="font-mono font-medium link"
                                       x-text="row.contract_number"></a>
                                </td>
                                <td class="font-medium" x-text="row.customer_name"></td>
                                <td>
                                    <span class="font-mono" x-text="row.unit_number"></span>
                                    <div class="text-xs text-secondary" x-text="row.template_name_snapshot"></div>
                                </td>
                                <td class="font-mono text-sm">
                                    <template x-if="parseFloat(row.monthly_rate) > 0">
                                        <span x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA', {minimumFractionDigits:0}) + '/mo'"></span>
                                    </template>
                                    <template x-if="parseFloat(row.monthly_rate) <= 0 && parseFloat(row.daily_rate) > 0">
                                        <span x-text="'$' + parseFloat(row.daily_rate).toFixed(2) + '/day'"></span>
                                    </template>
                                </td>
                                <td class="text-sm text-secondary" x-text="fmtDate(row.start_date)"></td>
                                <td class="text-sm">
                                    <template x-if="row.end_date">
                                        <span x-text="fmtDate(row.end_date)"></span>
                                    </template>
                                    <template x-if="!row.end_date">
                                        <span class="text-tertiary">Open-ended</span>
                                    </template>
                                </td>
                                <td style="text-align:right;">
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                       class="btn btn-ghost btn-sm">View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>


    <!-- ── ROW 1: Revenue trend (2/3) + Fleet status donut (1/3) ─ -->
    <div class="grid-2-1" style="margin-bottom:24px;">

        <div class="card card--accent-primary">
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

        <div class="card card--accent-success">
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

        <div class="card card--accent-danger">
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

        <div class="card card--accent-info">
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

        <div class="card card--accent-warning">
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

        <div class="card card--accent-purple">
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

    <!-- ── ROW 4: Pending Activations (1/2) + Upcoming Returns (1/2) ── -->
    <div class="grid-2" style="margin-bottom:24px;">

        <!-- Pending Activations table -->
        <div class="card card--accent-warning">
            <div class="card-header">
                <span class="card-title">Pending Activations</span>
                <a href="<?= base_url('leases') ?>?status=pending"
                   class="btn btn-ghost btn-sm">View all →</a>
            </div>

            <template x-if="!tablesLoaded && !tablesError">
                <div style="padding:32px 0; text-align:center;">
                    <div class="skeleton skeleton-text" style="width:70%;margin:0 auto 10px;"></div>
                    <div class="skeleton skeleton-text" style="width:50%;margin:0 auto;"></div>
                </div>
            </template>

            <template x-if="tablesError">
                <div class="empty-state" style="padding:24px;">
                    <p class="empty-state-title">Could not load data</p>
                    <button class="btn btn-secondary btn-sm" @click="fetchTables()">Retry</button>
                </div>
            </template>

            <template x-if="tablesLoaded && tables.pending_leases.length === 0">
                <div class="empty-state" style="padding:24px;">
                    <p class="empty-state-title">No pending activations</p>
                    <p class="empty-state-text">All leases are active or completed.</p>
                </div>
            </template>

            <template x-if="tablesLoaded && tables.pending_leases.length > 0">
                <div class="table-wrapper" style="border:none; border-radius:0; box-shadow:none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Customer</th>
                                <th>Unit</th>
                                <th>Start Date</th>
                                <th>Overdue</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in tables.pending_leases" :key="row.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                           class="font-mono font-medium link"
                                           x-text="row.contract_number"></a>
                                    </td>
                                    <td class="font-medium text-sm" x-text="row.customer_name"></td>
                                    <td class="font-mono text-sm" x-text="row.unit_number"></td>
                                    <td class="text-sm" x-text="fmtDate(row.start_date)"></td>
                                    <td class="text-sm">
                                        <template x-if="parseInt(row.days_overdue) > 0">
                                            <span class="badge badge-no-dot badge-danger"
                                                  x-text="row.days_overdue + 'd'"></span>
                                        </template>
                                        <template x-if="parseInt(row.days_overdue) <= 0">
                                            <span class="text-secondary">—</span>
                                        </template>
                                    </td>
                                    <td style="text-align:right;">
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                           class="btn btn-ghost btn-sm">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <!-- Upcoming Returns table -->
        <div class="card card--accent-info">
            <div class="card-header">
                <span class="card-title">Upcoming Returns</span>
                <span class="text-secondary text-sm">Next 60 days</span>
            </div>

            <template x-if="!tablesLoaded && !tablesError">
                <div style="padding:32px 0; text-align:center;">
                    <div class="skeleton skeleton-text" style="width:70%;margin:0 auto 10px;"></div>
                    <div class="skeleton skeleton-text" style="width:50%;margin:0 auto;"></div>
                </div>
            </template>

            <template x-if="tablesError">
                <div class="empty-state" style="padding:24px;">
                    <p class="empty-state-title">Could not load data</p>
                    <button class="btn btn-secondary btn-sm" @click="fetchTables()">Retry</button>
                </div>
            </template>

            <template x-if="tablesLoaded && tables.upcoming_returns.length === 0">
                <div class="empty-state" style="padding:24px;">
                    <p class="empty-state-title">No returns due soon</p>
                    <p class="empty-state-text">No leases ending in the next 60 days.</p>
                </div>
            </template>

            <template x-if="tablesLoaded && tables.upcoming_returns.length > 0">
                <div class="table-wrapper" style="border:none; border-radius:0; box-shadow:none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Customer</th>
                                <th>Unit</th>
                                <th>Return Date</th>
                                <th>Days Left</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in tables.upcoming_returns" :key="row.id">
                                <tr>
                                    <td>
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                           class="font-mono font-medium link"
                                           x-text="row.contract_number"></a>
                                    </td>
                                    <td class="font-medium text-sm" x-text="row.customer_name"></td>
                                    <td>
                                        <span class="font-mono text-sm" x-text="row.unit_number"></span>
                                        <div class="text-xs text-secondary" x-text="row.template_name_snapshot"></div>
                                    </td>
                                    <td class="text-sm" x-text="fmtDate(row.end_date)"></td>
                                    <td class="text-sm">
                                        <span :class="parseInt(row.days_remaining) <= 14 ? 'badge badge-no-dot badge-danger' : parseInt(row.days_remaining) <= 30 ? 'badge badge-no-dot badge-warning' : 'text-secondary'"
                                              x-text="row.days_remaining + 'd'"></span>
                                    </td>
                                    <td style="text-align:right;">
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                                           class="btn btn-ghost btn-sm">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

    </div>

    <!-- ── ROW 5: Activity Feed (1/2) + Revenue by Type donut + Weekly Heatmap -->
    <div class="grid-2" style="margin-bottom:24px;">

        <!-- Recent Activity Feed -->
        <div class="card card--accent-primary">
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
                        <p class="empty-state-title">No activity yet</p>
                        <p class="empty-state-text">Actions will appear here as staff use the system.</p>
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
        <div class="card card--accent-success">
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
    <div class="card card--accent-info" style="margin-bottom:24px;">
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

        tables:        { active_leases: [], pending_leases: [], upcoming_returns: [] },
        tablesLoaded:  false,
        tablesError:   false,

        // Stored ApexChart instances for theme-update support
        _chartInstances: {},

        // ── Init ───────────────────────────────────────────────
        init() {
            // Fire all four fetches in parallel — no ordering dependency
            this.fetchKpis();
            this.fetchCharts();
            this.fetchActivity();
            this.fetchTables();
        },

        // ── KPI fetch ──────────────────────────────────────────
        async fetchKpis() {
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/kpis') ?>');
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
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/charts') ?>');
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

        // ── Tables fetch ───────────────────────────────────────
        async fetchTables() {
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/tables') ?>');
                if (res.success) {
                    this.tables       = res.data;
                    this.tablesLoaded = true;
                } else {
                    this.tablesError = true;
                }
            } catch (e) {
                this.tablesError = true;
                console.error('[Dashboard] Tables fetch failed', e);
            }
        },

        // ── Activity feed fetch ────────────────────────────────
        async fetchActivity() {
            try {
                const res = await FF_Api.get('<?= base_url('api/v1/dashboard/activity_feed') ?>');
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

            // WHY: isLight must be derived from FF_Theme — no global exists
            const isLight = FF_Theme.current() === 'light';

            // FIX #41: read chart colours from CSS vars so they follow the theme
            const cs      = getComputedStyle(document.documentElement);
            const cssVar  = (v) => cs.getPropertyValue(v).trim();
            const palette = [
                cssVar('--color-primary') || '#f97316',
                cssVar('--color-success') || '#22c55e',
                '#8b5cf6',
                cssVar('--color-info')    || '#06b6d4',
                cssVar('--color-danger')  || '#ef4444',
                cssVar('--color-warning') || '#eab308',
                '#3b82f6',
                '#84cc16',
            ];

            const fg      = cssVar('--text-primary')   || '#e2e8f0';
            const fgMuted = cssVar('--text-tertiary')  || '#64748b';
            const border  = cssVar('--border-color')   || '#1d2133';
            const gridBg  = cssVar('--bg-surface')     || '#111318';

            // Shared base options applied to all charts
            const base = {
                chart: { toolbar: { show: true, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } }, background: 'transparent', fontFamily: 'DM Sans, sans-serif' },
                theme: { mode: isLight ? 'light' : 'dark' },
                colors: palette,
                grid:   { borderColor: border },
                tooltip: { theme: isLight ? 'light' : 'dark' },
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
                        '<div class="empty-state" style="padding:40px"><p class="empty-state-title">No data yet</p><p class="empty-state-text">Revenue will appear here once invoices are created.</p></div>';
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
                        colors:     ['#f97316'],
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

        fmtDate(d) {
            if (!d) return '—';
            return new Date(d + 'T00:00:00').toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
