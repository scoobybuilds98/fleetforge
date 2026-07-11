<?php
declare(strict_types=1);

/**
 * FleetForge — Admin Dashboard
 *
 * @file        app/admin/dashboard/index.php
 * @description Main dashboard page. Loads KPI tiles, 12 ApexCharts, activity feed,
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

$pageTitle      = 'Dashboard';
$helpModuleSlug = 'dashboard';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Dashboard</h1>
    <div class="page-header-actions">
        <?= help_button('dashboard') ?>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
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
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- Available Units -->
        <a href="<?= base_url('equipment') ?>?status=available"
           class="stat-card stat-card--link"
           aria-label="Available Units — click to view available equipment">
            <div class="stat-card__header">
                <div class="stat-label">Available Units</div>
                <div class="stat-card__icon stat-card__icon--success">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.available_units : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Ready to rent</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- Open Work Orders -->
        <a href="<?= base_url('maintenance_work_orders') ?>?status=open"
           class="stat-card stat-card--link"
           aria-label="Open Work Orders — click to view maintenance">
            <div class="stat-card__header">
                <div class="stat-label">Open Work Orders</div>
                <div class="stat-card__icon stat-card__icon--warning">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.open_work_orders : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Open &amp; in progress</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- Open Damage Claims -->
        <a href="<?= base_url('damage_claims') ?>"
           class="stat-card stat-card--link"
           :class="kpisLoaded && kpis.open_damage_claims > 0 ? 'stat-card--danger' : ''"
           aria-label="Open Damage Claims — click to view damage claims">
            <div class="stat-card__header">
                <div class="stat-label">Damage Claims</div>
                <div class="stat-card__icon stat-card__icon--danger">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.open_damage_claims : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Open claims</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- Sent Invoices (awaiting payment) -->
        <a href="<?= base_url('invoices') ?>?status=sent"
           class="stat-card stat-card--link"
           aria-label="Sent Invoices — click to view sent invoices">
            <div class="stat-card__header">
                <div class="stat-label">Sent Invoices</div>
                <div class="stat-card__icon stat-card__icon--purple">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.981l7.5-4.039a2.25 2.25 0 0 1 2.134 0l7.5 4.039a2.25 2.25 0 0 1 1.183 1.98V19.5Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.sent_invoices : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Awaiting payment</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- This Month's Collections -->
        <a href="<?= base_url('payments') ?>"
           class="stat-card stat-card--link"
           aria-label="Monthly Collections — click to view payments">
            <div class="stat-card__header">
                <div class="stat-label">Monthly Collections</div>
                <div class="stat-card__icon stat-card__icon--success">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? '$' + formatMoney(kpis.monthly_collections) : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Collected this month</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

        <!-- Active Reservations -->
        <a href="<?= base_url('reservations') ?>"
           class="stat-card stat-card--link"
           aria-label="Active Reservations — click to view reservations">
            <div class="stat-card__header">
                <div class="stat-label">Active Reservations</div>
                <div class="stat-card__icon stat-card__icon--info">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                </div>
            </div>
            <div class="stat-value" x-text="kpisLoaded ? kpis.active_reservations : '—'">—</div>
            <div class="stat-delta text-secondary" x-show="kpisLoaded">Pending &amp; confirmed</div>
            <div class="stat-skeleton" x-show="!kpisLoaded" aria-hidden="true"></div>
            <!-- S-LUX-2: sparkline hook (D-LUX2-4) — populated in a later session; hidden while empty. -->
            <div class="kpi-spark" aria-hidden="true"></div>
        </a>

    </div><!-- /stat-grid -->


    <!-- ── ROW 1: Revenue trend (2/3) + Fleet status donut (1/3) ─ -->
    <div class="dashboard-grid dashboard-grid--charts" style="margin-bottom:24px;">

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Revenue — Last 12 Months</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:320px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-revenue-trend" style="min-height:320px;"
                         aria-label="Revenue over time chart"></div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Fleet Status</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-fleet-status" style="min-height:300px;"
                         aria-label="Fleet status donut chart"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── ACTIVE LEASES ─────────────────────────────────────────── -->
    <?php /* S-DASHBOARD-MOBILE-CAROUSEL */ ?>
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Active Leases</h3>
            <a href="<?= base_url('leases') ?>?status=active"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load leases.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.active_leases.length === 0">
            <div class="carousel-empty">No active leases</div>
        </template>

        <template x-if="tablesLoaded && tables.active_leases.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.active_leases" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill cc-pill--active">Active</span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.template_name_snapshot || 'Unknown type'"></span>
                            <span class="cc-dot">·</span>
                            <span x-text="row.unit_number"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <template x-if="parseFloat(row.monthly_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA',{minimumFractionDigits:0})"></span>
                                    <span class="cc-rate-period">/mo</span>
                                </span>
                            </template>
                            <template x-if="parseFloat(row.monthly_rate) <= 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.daily_rate).toFixed(2)"></span>
                                    <span class="cc-rate-period">/day</span>
                                </span>
                            </template>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Started</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.start_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Active</span>
                                <span class="cc-footer-value"
                                      x-text="Math.max(0, parseInt(row.days_active)) + ' days'"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── RESERVATIONS ───────────────────────────────────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Reservations</h3>
            <a href="<?= base_url('reservations') ?>"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load reservations.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.reservations.length === 0">
            <div class="carousel-empty">No upcoming reservations</div>
        </template>

        <template x-if="tablesLoaded && tables.reservations.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.reservations" :key="row.id">
                    <a :href="'<?= base_url('reservations/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.reservation_number"></span>
                            <span class="cc-pill"
                                  :class="row.status === 'confirmed' ? 'cc-pill--active' : 'cc-pill--info'"
                                  x-text="row.status.charAt(0).toUpperCase() + row.status.slice(1)"></span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="parseInt(row.quantity) + ' unit' + (parseInt(row.quantity) === 1 ? '' : 's') + ' reserved'"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <span class="cc-rate-amount" x-text="fmtDate(row.pickup_date)"></span>
                            <span class="cc-rate-period"> pickup</span>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Time</span>
                                <span class="cc-footer-value"
                                      x-text="row.pickup_time ? row.pickup_time.substring(0,5) : '—'"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">In</span>
                                <span class="cc-footer-value"
                                      x-text="parseInt(row.days_until_pickup) === 0 ? 'Today' : (parseInt(row.days_until_pickup) + ' day' + (parseInt(row.days_until_pickup) === 1 ? '' : 's'))"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── Revenue Forecast (2/3) + Occupancy by Type (1/3) ─ -->
    <div class="dashboard-grid dashboard-grid--charts" style="margin-bottom:24px;">

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Revenue Forecast — Next 6 Months</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:320px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-revenue-forecast" style="min-height:320px;"
                         aria-label="Projected revenue next 6 months"></div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Occupancy by Equipment Type</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-occupancy-by-type" style="min-height:300px;"
                         aria-label="Fleet occupancy by equipment type"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── DRAFT INVOICES (moved below Reservations) ───────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Draft Invoices</h3>
            <a href="<?= base_url('invoices') ?>?status=draft"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.draft_invoices.length === 0">
            <div class="carousel-empty">No draft invoices</div>
        </template>

        <template x-if="tablesLoaded && tables.draft_invoices.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.draft_invoices" :key="row.id">
                    <a :href="'<?= base_url('invoices/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.invoice_number"></span>
                            <span class="cc-pill cc-pill--warning">Draft</span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <span class="cc-rate-amount"
                                  x-text="'$' + parseFloat(row.total_amount).toLocaleString('en-CA',{minimumFractionDigits:0, maximumFractionDigits:0})"></span>
                            <span class="cc-rate-period"> total</span>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Created</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.invoice_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">In draft</span>
                                <span class="cc-footer-value"
                                      x-text="row.days_in_draft + ' days'"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── HIGH-VALUE LEASES ─────────────────────────────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">High-Value Leases</h3>
            <a href="<?= base_url('leases') ?>?status=active&sort=rate_desc"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.high_value_leases.length === 0">
            <div class="carousel-empty">No active leases</div>
        </template>

        <template x-if="tablesLoaded && tables.high_value_leases.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.high_value_leases" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill cc-pill--active">Active</span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.template_name_snapshot || 'Unknown type'"></span>
                            <span class="cc-dot">·</span>
                            <span x-text="row.unit_number"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <template x-if="parseFloat(row.monthly_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA',{minimumFractionDigits:0})"></span>
                                    <span class="cc-rate-period">/mo</span>
                                </span>
                            </template>
                            <template x-if="parseFloat(row.monthly_rate) <= 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.daily_rate).toFixed(2)"></span>
                                    <span class="cc-rate-period">/day</span>
                                </span>
                            </template>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Started</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.start_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Active</span>
                                <span class="cc-footer-value"
                                      x-text="row.days_active + ' days'"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── ROW 2: AR Aging (1/2) + Utilization Trend (1/2) ───── -->
    <div class="dashboard-grid dashboard-grid--equal" style="margin-bottom:24px;">

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">AR Aging</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-ar-aging" style="min-height:300px;"
                         aria-label="AR aging bar chart"></div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Utilization Trend</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-utilization-trend" style="min-height:300px;"
                         aria-label="Fleet utilization trend chart"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── OUTSTANDING INVOICES ──────────────────────────────────── -->
    <?php /* S-DASHBOARD-CAROUSEL-REORGANIZE */ ?>
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Outstanding Invoices</h3>
            <a href="<?= base_url('invoices') ?>?status=outstanding"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.invoices.length === 0">
            <div class="carousel-empty">No outstanding invoices</div>
        </template>

        <template x-if="tablesLoaded && tables.invoices.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.invoices" :key="row.id">
                    <a :href="'<?= base_url('invoices/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <!-- WHY 'partially_paid' not 'partial': DB enum uses partially_paid -->
                        <div class="cc-top">
                            <span class="cc-id" x-text="row.invoice_number"></span>
                            <span class="cc-pill"
                                  :class="{
                                      'cc-pill--danger':  row.status === 'overdue',
                                      'cc-pill--warning': row.status === 'partially_paid',
                                      'cc-pill--info':    row.status === 'sent'
                                  }"
                                  x-text="row.status === 'partially_paid' ? 'Partial' : (row.status.charAt(0).toUpperCase() + row.status.slice(1))">
                            </span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate"
                             :class="parseFloat(row.balance_due) > 0 ? 'text-danger' : ''">
                            <span class="cc-rate-amount"
                                  x-text="'$' + parseFloat(row.balance_due).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2})"></span>
                            <span class="cc-rate-period"> owing</span>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Due</span>
                                <!-- days_overdue > 0 means past due date -->
                                <span class="cc-footer-value"
                                      :class="parseInt(row.days_overdue) > 0 ? 'text-danger' : ''"
                                      x-text="fmtDate(row.due_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Total</span>
                                <span class="cc-footer-value"
                                      x-text="'$' + parseFloat(row.total_amount).toLocaleString('en-CA',{minimumFractionDigits:2})"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── OVERDUE PAYMENTS ──────────────────────────────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Overdue Payments</h3>
            <a href="<?= base_url('invoices') ?>?status=overdue"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.overdue_payments.length === 0">
            <div class="carousel-empty">No overdue payments</div>
        </template>

        <template x-if="tablesLoaded && tables.overdue_payments.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.overdue_payments" :key="row.id">
                    <a :href="'<?= base_url('invoices/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.invoice_number"></span>
                            <span class="cc-pill cc-pill--danger"
                                  x-text="row.days_overdue + ' days overdue'"></span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate" :class="'text-danger'">
                            <span class="cc-rate-amount"
                                  x-text="'$' + parseFloat(row.balance_due).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2})"></span>
                            <span class="cc-rate-period"> owing</span>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Due</span>
                                <span class="cc-footer-value text-danger"
                                      x-text="fmtDate(row.due_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Invoice total</span>
                                <span class="cc-footer-value"
                                      x-text="'$' + parseFloat(row.total_amount).toLocaleString('en-CA',{minimumFractionDigits:2, maximumFractionDigits:2})"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── Payment Speed (1/2) + Lease Expiry Calendar (1/2) ── -->
    <div class="dashboard-grid dashboard-grid--equal" style="margin-bottom:24px;">

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Avg Days to Pay — Last 12 Months</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-payment-speed" style="min-height:300px;"
                         aria-label="Average invoice payment speed trend"></div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Lease Expiry Calendar — Next 12 Months</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-lease-expiry-calendar" style="min-height:300px;"
                         aria-label="Leases expiring by month"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── ROW 3: Top Customers (1/2) + Leases Trend (1/2) ───── -->
    <div class="dashboard-grid dashboard-grid--equal" style="margin-bottom:24px;">

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Top Customers — YTD</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-top-customers" style="min-height:300px;"
                         aria-label="Top customers by revenue chart"></div>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Leases Opened vs Closed</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:320px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-leases-trend" style="min-height:320px;"
                         aria-label="Leases opened vs closed chart"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── EXPIRING THIS MONTH ──────────────────────────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Expiring This Month</h3>
            <a href="<?= base_url('leases') ?>?filter=expiring_this_month"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.expiring_this_month.length === 0">
            <div class="carousel-empty">No leases expiring this month</div>
        </template>

        <template x-if="tablesLoaded && tables.expiring_this_month.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.expiring_this_month" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill"
                                  :class="parseInt(row.days_remaining) <= 7 ? 'cc-pill--danger' : 'cc-pill--warning'"
                                  x-text="row.days_remaining + ' days left'"></span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.template_name_snapshot || 'Unknown type'"></span>
                            <span class="cc-dot">·</span>
                            <span x-text="row.unit_number"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <template x-if="parseFloat(row.monthly_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA',{minimumFractionDigits:0})"></span>
                                    <span class="cc-rate-period">/mo</span>
                                </span>
                            </template>
                            <template x-if="parseFloat(row.monthly_rate) <= 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.daily_rate).toFixed(2)"></span>
                                    <span class="cc-rate-period">/day</span>
                                </span>
                            </template>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Expires</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.end_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Days left</span>
                                <span class="cc-footer-value"
                                      x-text="row.days_remaining + ' days'"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── PENDING ACTIVATIONS ───────────────────────────────────── -->
    <?php /* S-DASHBOARD-CAROUSEL-REORGANIZE */ ?>
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Pending Activations</h3>
            <a href="<?= base_url('leases') ?>?status=pending"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.pending_leases.length === 0">
            <div class="carousel-empty">No pending activations</div>
        </template>

        <template x-if="tablesLoaded && tables.pending_leases.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.pending_leases" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill"
                                  :class="parseInt(row.days_overdue) > 0 ? 'cc-pill--danger' : 'cc-pill--warning'">
                                <span x-text="parseInt(row.days_overdue) > 0 ? row.days_overdue + ' days overdue' : 'Pending'"></span>
                            </span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.unit_number || '—'"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <template x-if="parseFloat(row.monthly_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA',{minimumFractionDigits:0})"></span>
                                    <span class="cc-rate-period">/mo</span>
                                </span>
                            </template>
                            <template x-if="!(parseFloat(row.monthly_rate) > 0) && parseFloat(row.daily_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.daily_rate).toFixed(2)"></span>
                                    <span class="cc-rate-period">/day</span>
                                </span>
                            </template>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Scheduled</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.start_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Created</span>
                                <!-- created_at is datetime; slice to YYYY-MM-DD so fmtDate parses cleanly -->
                                <span class="cc-footer-value" x-text="fmtDate(row.created_at && row.created_at.slice(0,10))"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── UPCOMING RETURNS ──────────────────────────────────────── -->
    <?php /* S-DASHBOARD-CAROUSEL-REORGANIZE */ ?>
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Upcoming Returns</h3>
            <a href="<?= base_url('leases') ?>?filter=returning_soon"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.upcoming_returns.length === 0">
            <div class="carousel-empty">No upcoming returns</div>
        </template>

        <template x-if="tablesLoaded && tables.upcoming_returns.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.upcoming_returns" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill"
                                  :class="{
                                      'cc-pill--danger':  parseInt(row.days_remaining) <= 3,
                                      'cc-pill--warning': parseInt(row.days_remaining) > 3 && parseInt(row.days_remaining) <= 7,
                                      'cc-pill--info':    parseInt(row.days_remaining) > 7
                                  }">
                                <span x-text="row.days_remaining + ' days left'"></span>
                            </span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.template_name_snapshot || '—'"></span>
                            <span class="cc-dot">·</span>
                            <span x-text="row.unit_number"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate" style="font-size:1.125rem;">
                            <span x-text="fmtDate(row.end_date)"></span>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Return date</span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Days remaining</span>
                                <span class="cc-footer-value"
                                      :class="{
                                          'text-danger':  parseInt(row.days_remaining) <= 3,
                                          'text-warning': parseInt(row.days_remaining) > 3 && parseInt(row.days_remaining) <= 7,
                                          'text-success': parseInt(row.days_remaining) > 7
                                      }"
                                      x-text="row.days_remaining + ' days'"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── ROW 4: Activity Feed (1/2) + Revenue by Equipment Type (1/2) ── -->
    <div class="dashboard-grid dashboard-grid--equal" style="margin-bottom:24px;">

        <!-- Recent Activity Feed -->
        <div class="card dashboard-widget">
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
        <div class="card chart-card">
            <div class="card-header">
                <span class="card-title">Revenue by Equipment Type — YTD</span>
            </div>
            <div class="card-body">
                <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                     style="height:300px;"></div>
                <div x-show="chartsLoaded" class="chart-wrap">
                    <div id="chart-revenue-by-type" style="min-height:300px;"
                         aria-label="Revenue by equipment type donut chart"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── RECENTLY ACTIVATED ───────────────────────────────────── -->
    <div class="dashboard-section">
        <div class="dashboard-section-header">
            <h3 class="dashboard-section-title">Recently Activated</h3>
            <a href="<?= base_url('leases') ?>?status=active&sort=start_date_desc"
               class="dashboard-section-viewall">View all →</a>
        </div>

        <template x-if="!tablesLoaded && !tablesError">
            <div class="carousel-empty">Loading…</div>
        </template>

        <template x-if="tablesError">
            <div class="carousel-empty">
                Could not load data.
                <button class="btn btn-ghost btn-sm" @click="fetchTables()">Retry</button>
            </div>
        </template>

        <template x-if="tablesLoaded && tables.recently_activated.length === 0">
            <div class="carousel-empty">No leases activated in the last 7 days</div>
        </template>

        <template x-if="tablesLoaded && tables.recently_activated.length > 0">
            <div class="dashboard-carousel">
                <template x-for="row in tables.recently_activated" :key="row.id">
                    <a :href="'<?= base_url('leases/show') ?>?id=' + row.id"
                       class="carousel-card carousel-card--link">

                        <div class="cc-top">
                            <span class="cc-id" x-text="row.contract_number"></span>
                            <span class="cc-pill cc-pill--active"
                                  x-text="parseInt(row.days_active) === 0 ? 'Today' : row.days_active + ' days ago'"></span>
                        </div>

                        <div class="cc-customer" x-text="row.customer_name"></div>

                        <div class="cc-equipment">
                            <span x-text="row.template_name_snapshot || 'Unknown type'"></span>
                            <span class="cc-dot">·</span>
                            <span x-text="row.unit_number"></span>
                        </div>

                        <div class="cc-divider"></div>

                        <div class="cc-rate">
                            <template x-if="parseFloat(row.monthly_rate) > 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.monthly_rate).toLocaleString('en-CA',{minimumFractionDigits:0})"></span>
                                    <span class="cc-rate-period">/mo</span>
                                </span>
                            </template>
                            <template x-if="parseFloat(row.monthly_rate) <= 0">
                                <span>
                                    <span class="cc-rate-amount"
                                          x-text="'$' + parseFloat(row.daily_rate).toFixed(2)"></span>
                                    <span class="cc-rate-period">/day</span>
                                </span>
                            </template>
                        </div>

                        <div class="cc-footer">
                            <span class="cc-footer-item">
                                <span class="cc-footer-label">Activated</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.start_date)"></span>
                            </span>
                            <span class="cc-footer-item cc-footer-item--right">
                                <span class="cc-footer-label">Ends</span>
                                <span class="cc-footer-value" x-text="fmtDate(row.end_date)"></span>
                            </span>
                        </div>

                    </a>
                </template>
            </div>
        </template>
    </div>

    <!-- ── ROW 5: Weekly Revenue Heatmap (full width) ─────────── -->
    <div class="card chart-card" style="margin-bottom:24px;">
        <div class="card-header">
            <span class="card-title">Daily Revenue — Last 12 Weeks</span>
        </div>
        <div class="card-body">
            <div x-show="!chartsLoaded" class="chart-skeleton" aria-hidden="true"
                 style="height:300px;"></div>
            <div x-show="chartsLoaded" class="chart-wrap">
                <div id="chart-weekly-heatmap" style="min-height:300px;"
                     aria-label="Weekly revenue heatmap"></div>
            </div>
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

        // S-DASHBOARD-CAROUSEL-REORGANIZE — added invoices + reservations
        // to the initial empty-array state so the x-if/x-for templates can
        // bind before fetchTables() resolves (avoids "cannot read length of
        // undefined" during first paint).
        tables:        { active_leases: [], pending_leases: [], upcoming_returns: [],
                         invoices: [], reservations: [],
                         expiring_this_month: [], draft_invoices: [], high_value_leases: [],
                         recently_activated: [], overdue_payments: [] },
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

        // ── Render all 12 charts ───────────────────────────────
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

            // Shared base options applied to all charts.
            // S-DASHBOARD-CHART-POLISH:
            //   - parentHeightOffset:0 stops ApexCharts from adding ~30px of
            //     phantom vertical space that pushed chart bodies past the
            //     card-body's allotted height and caused the visible clipping.
            //   - redrawOnParentResize / redrawOnWindowResize default true in
            //     ApexCharts v3 but we set them explicitly so the patched
            //     constructor in app.js can't accidentally clear them.
            //   - animateGradually:false keeps the chart's "draw-in" feel but
            //     fires it once on render rather than slowly per-datapoint.
            //   - grid.padding gives bars/lines breathing room from card edges.
            const base = {
                chart: {
                    background: 'transparent',
                    fontFamily: 'DM Sans, sans-serif',
                    toolbar: {
                        show: true,
                        tools: {
                            download: true, selection: false, zoom: false,
                            zoomin: false, zoomout: false, pan: false, reset: false,
                        },
                    },
                    animations: { enabled: true, speed: 400, animateGradually: { enabled: false } },
                    parentHeightOffset: 0,
                    redrawOnParentResize: true,
                    redrawOnWindowResize: true,
                },
                theme: { mode: isLight ? 'light' : 'dark' },
                colors: palette,
                grid: {
                    borderColor: border,
                    strokeDashArray: 3,
                    padding: { top: 0, right: 8, bottom: 0, left: 8 },
                },
                tooltip: { theme: isLight ? 'light' : 'dark' },
                legend: { labels: { colors: fg } },
                xaxis: { labels: { style: { colors: fgMuted } }, axisBorder: { color: border }, axisTicks: { color: border } },
                yaxis: { labels: { style: { colors: fgMuted } } },
            };

            // S-DASHBOARD-CHART-POLISH — shared donut + hbar plot options.
            // Defined once so both donut charts share donut.size + total label
            // and both horizontal bars share barHeight + datalabel placement.
            const donutPlot = {
                pie: {
                    donut: {
                        size: '68%',
                        labels: {
                            show: true,
                            total: { show: true, fontSize: '14px', fontWeight: 600, color: fg },
                        },
                    },
                },
            };
            const hbarPlot = {
                bar: {
                    horizontal: true,
                    borderRadius: 4,
                    barHeight: '60%',
                    dataLabels: { position: 'right' },
                },
            };
            const moneyFmt = (v) => '$' + this.formatMoney(v);

            // 1. Revenue trend — area
            if (d.revenue_trend && document.getElementById('chart-revenue-trend')) {
                c.revenue_trend = new ApexCharts(
                    document.getElementById('chart-revenue-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'area', height: 320 }),
                        stroke: { curve: 'smooth', width: 2 },
                        fill:   { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.revenue_trend.labels }),
                        yaxis: Object.assign({}, base.yaxis, { labels: { style: { colors: fgMuted }, formatter: moneyFmt } }),
                        series: d.revenue_trend.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                    })
                );
                c.revenue_trend.render();
            }

            // 2. Fleet status — donut
            if (d.fleet_status && document.getElementById('chart-fleet-status')) {
                c.fleet_status = new ApexCharts(
                    document.getElementById('chart-fleet-status'),
                    Object.assign({}, base, {
                        chart:  Object.assign({}, base.chart, { type: 'donut', height: 300 }),
                        labels: d.fleet_status.labels,
                        series: d.fleet_status.series,
                        legend: Object.assign({}, base.legend, {
                            position: 'bottom', offsetY: 4, fontSize: '12px',
                            markers: { size: 8, shape: 'circle' },
                        }),
                        plotOptions: donutPlot,
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
                        chart:  Object.assign({}, base.chart, { type: 'bar', height: 300 }),
                        plotOptions: hbarPlot,
                        dataLabels: {
                            enabled: true,
                            formatter: (v) => '$' + Math.round(v).toLocaleString(),
                            offsetX: 4,
                            style: { fontSize: '11px', fontWeight: 500, colors: [fg] },
                        },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.ar_aging.labels, labels: { style: { colors: fgMuted }, formatter: moneyFmt } }),
                        series: d.ar_aging.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                    })
                );
                c.ar_aging.render();
            }

            // 4. Utilization trend — line
            if (d.utilization_trend && document.getElementById('chart-utilization-trend')) {
                c.utilization_trend = new ApexCharts(
                    document.getElementById('chart-utilization-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'line', height: 300 }),
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
                        chart:  Object.assign({}, base.chart, { type: 'bar', height: 300 }),
                        plotOptions: hbarPlot,
                        dataLabels: {
                            enabled: true,
                            formatter: (v) => '$' + Math.round(v).toLocaleString(),
                            offsetX: 4,
                            style: { fontSize: '11px', fontWeight: 500, colors: [fg] },
                        },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.top_customers.labels, labels: { style: { colors: fgMuted }, formatter: moneyFmt } }),
                        series: d.top_customers.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                    })
                );
                c.top_customers.render();
            }

            // 6. Leases trend — grouped bar
            if (d.leases_trend && document.getElementById('chart-leases-trend')) {
                c.leases_trend = new ApexCharts(
                    document.getElementById('chart-leases-trend'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'bar', height: 320 }),
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
                            chart:  Object.assign({}, base.chart, { type: 'donut', height: 300 }),
                            labels: d.revenue_by_type.labels,
                            series: d.revenue_by_type.series,
                            legend: Object.assign({}, base.legend, {
                                position: 'bottom', offsetY: 4, fontSize: '12px',
                                markers: { size: 8, shape: 'circle' },
                            }),
                            plotOptions: donutPlot,
                            dataLabels: { enabled: false },
                            tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                        })
                    );
                    c.revenue_by_type.render();
                } else {
                    document.getElementById('chart-revenue-by-type').innerHTML =
                        '<div class="empty-state" style="padding:40px"><p class="empty-state-title">No data yet</p><p class="empty-state-text">Revenue will appear here once invoices are created.</p></div>';
                }
            }

            // 9. Revenue forecast — bar
            if (d.revenue_forecast && document.getElementById('chart-revenue-forecast')) {
                c.revenue_forecast = new ApexCharts(
                    document.getElementById('chart-revenue-forecast'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'bar', height: 320 }),
                        plotOptions: { bar: { columnWidth: '55%', borderRadius: 4 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.revenue_forecast.labels }),
                        yaxis: Object.assign({}, base.yaxis, { labels: { style: { colors: fgMuted }, formatter: moneyFmt } }),
                        series: d.revenue_forecast.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                    })
                );
                c.revenue_forecast.render();
            }

            // 10. Occupancy by type — 100% stacked horizontal bar
            if (d.occupancy_by_type && document.getElementById('chart-occupancy-by-type')) {
                c.occupancy_by_type = new ApexCharts(
                    document.getElementById('chart-occupancy-by-type'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'bar', height: 300, stacked: true, stackType: '100%' }),
                        plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 2 } },
                        dataLabels: {
                            enabled: true,
                            formatter: (v) => Math.round(v) + '%',
                            style: { fontSize: '11px', fontWeight: 500, colors: ['#fff'] },
                        },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.occupancy_by_type.labels, labels: { style: { colors: fgMuted }, formatter: (v) => v + '%' } }),
                        series: d.occupancy_by_type.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: (v) => Math.round(v) + '%' } }),
                    })
                );
                c.occupancy_by_type.render();
            }

            // 11. Payment speed — line
            if (d.payment_speed && document.getElementById('chart-payment-speed')) {
                c.payment_speed = new ApexCharts(
                    document.getElementById('chart-payment-speed'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'line', height: 300 }),
                        stroke: { curve: 'smooth', width: 2 },
                        dataLabels: { enabled: false },
                        markers: { size: 3 },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.payment_speed.labels }),
                        yaxis: Object.assign({}, base.yaxis, { labels: { style: { colors: fgMuted }, formatter: (v) => v + ' days' } }),
                        series: d.payment_speed.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: (v) => v !== null ? v + ' days' : 'No data' } }),
                    })
                );
                c.payment_speed.render();
            }

            // 12. Lease expiry calendar — bar
            if (d.lease_expiry_calendar && document.getElementById('chart-lease-expiry-calendar')) {
                c.lease_expiry_calendar = new ApexCharts(
                    document.getElementById('chart-lease-expiry-calendar'),
                    Object.assign({}, base, {
                        chart: Object.assign({}, base.chart, { type: 'bar', height: 300 }),
                        plotOptions: { bar: { columnWidth: '60%', borderRadius: 3 } },
                        dataLabels: { enabled: false },
                        xaxis: Object.assign({}, base.xaxis, { categories: d.lease_expiry_calendar.labels }),
                        yaxis: Object.assign({}, base.yaxis, { labels: { style: { colors: fgMuted }, formatter: (v) => Math.round(v) + '' } }),
                        series: d.lease_expiry_calendar.series,
                        colors: [palette[4]],
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: (v) => v + ' lease' + (v !== 1 ? 's' : '') } }),
                    })
                );
                c.lease_expiry_calendar.render();
            }

            // 8. Weekly heatmap
            if (d.weekly_heatmap && document.getElementById('chart-weekly-heatmap')) {
                c.weekly_heatmap = new ApexCharts(
                    document.getElementById('chart-weekly-heatmap'),
                    Object.assign({}, base, {
                        chart:      Object.assign({}, base.chart, { type: 'heatmap', height: 300 }),
                        dataLabels: { enabled: false },
                        stroke:     { width: 2, colors: [gridBg] },
                        colors:     ['#f97316'],
                        plotOptions: { heatmap: { shadeIntensity: 0.8, radius: 2, useFillColorAsStroke: false } },
                        series: d.weekly_heatmap.series,
                        tooltip: Object.assign({}, base.tooltip, { y: { formatter: moneyFmt } }),
                    })
                );
                c.weekly_heatmap.render();
            }

            // S-DASHBOARD-CHART-POLISH: register instances at window scope so
            // the sidebar-toggle reflow handler in app.js can iterate them
            // without poking at Alpine internals. Idempotent — last render wins.
            window.FF_DashboardCharts = c;
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

<!-- ── S-CAROUSEL-CARD-3D: Subtle micro-tilt on hover (Apple/Chanel) ────────
     FF_CardTilt adds a gentle perspective rotateX/rotateY on mousemove —
     max ±5° each axis — so the card has physical presence without moving.
     No translateY, no scale: the card stays exactly in place. The CSS
     border-color transition (200ms ease) handles the primary hover signal.
     Touch devices don't fire mousemove so no tilt occurs there.
     Re-runs after 900ms to wait for Alpine x-if rendering to complete. ─────── -->
<script>
function FF_CardTilt() {
    const cards = document.querySelectorAll('.carousel-card--link');
    if (!cards.length) return;

    cards.forEach(function (card) {
        card.addEventListener('mousemove', function (e) {
            const rect    = card.getBoundingClientRect();
            const x       = (e.clientX - rect.left)  / rect.width  - 0.5;
            const y       = (e.clientY - rect.top)   / rect.height - 0.5;

            // WHY ±5°: subtle enough to feel physical, not enough to distort
            // the card or cause any perceived movement / layout shift.
            const rotateX = (-y * 5).toFixed(2);
            const rotateY = ( x * 5).toFixed(2);

            // No translateY, no scale — card stays in place, only tilts.
            card.style.transform =
                'perspective(1200px) ' +
                'rotateX(' + rotateX + 'deg) ' +
                'rotateY(' + rotateY + 'deg)';
        });

        card.addEventListener('mouseleave', function () {
            card.style.transform = '';
        });
    });
}

// WHY 900ms: Alpine x-if renders cards asynchronously after tablesLoaded flips
// to true. 900ms gives the fetch + Alpine render cycle time to complete before
// we query for .carousel-card--link. Re-attaches if Alpine re-renders.
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(FF_CardTilt, 900);
});
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
