<?php
declare(strict_types=1);

/**
 * FleetForge — Reservations List Page
 *
 * @file        app/admin/reservations/index.php
 * @description Two-table reservations dashboard.
 *              "Chassis In"  — pending + confirmed reservations (units not yet checked out).
 *              "Chassis Out" — completed reservations (units have been released).
 *              Four KPI tiles: Total, Pending, Confirmed, Today's Pickups.
 *              Filter toolbar: search, status (In table), sort, direction.
 *              Each row: ID, Contact, Company, Type, Qty, Date/Time, Created By, Actions.
 *              Actions: Edit (pencil), Mark Out (confirmed→completed), Reverse (completed→confirmed),
 *                       Cancel, Delete.
 *              Fixes live 404 on /reservations sidebar link (S018 stop condition).
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/reservations/index.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @design      FLEETFORGE_DESIGN_DETAILS.md — two-table split layout
 * @session     S018
 */

// dirname(__DIR__, 3): app/admin/reservations/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('reservations', 'view');

$pageTitle = 'Reservations';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Breadcrumb + Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Reservations</span>
</nav>
<div class="page-header">
    <h1 class="page-header-title h4">Reservations</h1>
    <div class="page-header-actions">
        <?php if (can('reservations', 'create')): ?>
        <a href="<?= base_url('reservations/create') ?>" class="btn btn-primary btn-sm">
            + New Reservation
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     RESERVATIONS ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Reservations()" x-init="init()">

    <!-- ── KPI TILES ─────────────────────────────────────────────── -->
    <div class="stat-grid" style="--stat-cols:4;">

        <!-- TILES-1: every tile is now a drill-down filter. Pending/Confirmed
             use client-side quickStatus filter since the reservations list
             already merges both statuses via two server requests. -->
        <div class="stat-card" style="cursor:pointer;"
             :class="{ 'ring-active': !quickStatus && !filters.pickup_date }"
             @click="quickStatus=''; filters.pickup_date=''; applyFilters()">
            <div class="stat-label">Total Active</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.total"></div>
                    <div class="stat-delta text-secondary">pending + confirmed</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:45%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card" style="cursor:pointer;"
             :class="{ 'ring-active': quickStatus === 'pending' }"
             @click="quickStatus = quickStatus === 'pending' ? '' : 'pending'">
            <div class="stat-label">Pending</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.pending"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:35%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card" style="cursor:pointer;"
             :class="{ 'ring-active': quickStatus === 'confirmed' }"
             @click="quickStatus = quickStatus === 'confirmed' ? '' : 'confirmed'">
            <div class="stat-label">Confirmed</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.confirmed"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:35%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card"
             style="cursor:pointer;"
             :class="{ 'ring-active': filters.pickup_date === todayDate() }"
             @click="filters.pickup_date = filters.pickup_date === todayDate() ? '' : todayDate(); applyFilters()">
            <div class="stat-label">Today's Pickups</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono"
                         :class="kpis.today > 0 ? 'text-warning' : ''"
                         x-text="kpis.today"></div>
                    <div class="stat-delta text-secondary">pickup_date = today</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:35%;margin-top:8px;"></div>
            </template>
        </div>

    </div><!-- /stat-grid -->

    <!-- ── CHARTS ──────────────────────────────────────────────────
         Shown after chart data loads (status donut + 7-day pickups bar).
         WHY x-show not x-if: canvases must exist in DOM for Chart.js
         to find them; x-if would remove/recreate them on each filter.
         ─────────────────────────────────────────────────────────── -->
    <div class="ff-charts-grid" x-show="chartData.loaded"
         x-transition:enter="transition ease-out duration-500"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         style="display:none;"><!-- hidden until x-show activates -->

        <!-- Status donut -->
        <div class="card">
            <div class="card-header" style="padding:12px 16px;">
                <span class="card-title" style="margin:0;font-size:0.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">
                    Status Breakdown
                </span>
            </div>
            <div class="card-body" style="padding:16px;display:flex;flex-direction:column;align-items:center;gap:14px;">
                <canvas id="ff-donut-chart" width="150" height="150"
                        style="max-width:150px;max-height:150px;"></canvas>
                <!-- Legend -->
                <div style="width:100%;display:flex;flex-direction:column;gap:6px;font-size:0.8125rem;">
                    <div style="display:flex;align-items:center;gap:7px;">
                        <span style="width:9px;height:9px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></span>
                        <span class="text-secondary">Pending</span>
                        <span class="font-mono font-medium" style="margin-left:auto;" x-text="chartData.statuses.pending"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:7px;">
                        <span style="width:9px;height:9px;border-radius:50%;background:#22c55e;flex-shrink:0;"></span>
                        <span class="text-secondary">Confirmed</span>
                        <span class="font-mono font-medium" style="margin-left:auto;" x-text="chartData.statuses.confirmed"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:7px;">
                        <span style="width:9px;height:9px;border-radius:50%;background:#3b82f6;flex-shrink:0;"></span>
                        <span class="text-secondary">Completed</span>
                        <span class="font-mono font-medium" style="margin-left:auto;" x-text="chartData.statuses.completed"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 7-day pickups bar chart -->
        <div class="card">
            <div class="card-header" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
                <span class="card-title" style="margin:0;font-size:0.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">
                    Pickups — Past 7 Days
                </span>
                <span class="badge badge-no-dot badge-info" style="font-size:0.7rem;">Today highlighted</span>
            </div>
            <div class="card-body" style="padding:12px 16px 16px;height:190px;">
                <canvas id="ff-bar-chart" style="width:100%;height:100%;"></canvas>
            </div>
        </div>

    </div><!-- /ff-charts-grid -->

    <!-- ── FILTER TOOLBAR ────────────────────────────────────────── -->
    <div class="toolbar" style="margin-bottom:16px;">
        <div class="toolbar-left" style="flex-wrap:wrap;gap:8px;">

            <!-- Search -->
            <div style="position:relative;">
                <input type="search"
                       class="form-input"
                       style="padding-left:32px;min-width:220px;"
                       placeholder="Search contact or company…"
                       x-model="filters.q"
                       @input.debounce.400ms="applyFilters()">
                <svg style="position:absolute;left:8px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-muted);"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
            </div>

            <!-- Pickup date filter -->
            <input type="date"
                   class="form-input"
                   style="min-width:160px;"
                   x-model="filters.pickup_date"
                   @change="applyFilters()"
                   title="Filter by pickup date">

            <!-- Priority filter -->
            <select class="form-select" style="min-width:130px;"
                    x-model="filters.priority"
                    @change="applyFilters()">
                <option value="">All Priorities</option>
                <option value="urgent">Urgent</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>

        </div>
        <div class="toolbar-right">

            <!-- Sort -->
            <select class="form-select" style="min-width:140px;"
                    x-model="filters.sort"
                    @change="applyFilters()">
                <option value="pickup_date">Sort: Pickup Date</option>
                <option value="created_at">Sort: Created</option>
                <option value="company_name">Sort: Company</option>
                <option value="priority">Sort: Priority</option>
                <option value="status">Sort: Status</option>
            </select>

            <!-- Direction -->
            <select class="form-select" style="min-width:90px;"
                    x-model="filters.dir"
                    @change="applyFilters()">
                <option value="ASC">ASC</option>
                <option value="DESC">DESC</option>
            </select>

            <!-- Clear -->
            <button class="btn btn-ghost btn-sm"
                    x-show="hasActiveFilters()"
                    @click="clearFilters()">
                Clear
            </button>

        </div>
    </div>

    <!-- ================================================================
         FLEET AVAILABILITY HEAT MAP
         Forward-looking 4-week calendar. Each cell = one day; color
         intensity = how many reservations are booked on that day.
         ================================================================ -->
    <div class="card" style="margin-bottom:16px;" x-show="heatmapData.loaded">

        <!-- Header with toggle -->
        <div class="card-header" style="display:flex;align-items:center;gap:10px;cursor:pointer;"
             @click="heatmapOpen = !heatmapOpen">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.5" stroke="currentColor"
                 style="width:16px;height:16px;color:var(--color-primary);flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
            </svg>
            <span class="card-title h6" style="margin:0;font-size:0.8125rem;">
                Fleet Booking Density — Next 4 Weeks
            </span>
            <!-- Color legend -->
            <div style="display:flex;align-items:center;gap:5px;margin-left:auto;font-size:0.7rem;color:var(--text-muted);">
                <span>Low</span>
                <span style="width:14px;height:14px;border-radius:3px;background:rgba(59,130,246,0.1);border:1px solid var(--border-color);display:inline-block;"></span>
                <span style="width:14px;height:14px;border-radius:3px;background:rgba(59,130,246,0.35);display:inline-block;"></span>
                <span style="width:14px;height:14px;border-radius:3px;background:rgba(59,130,246,0.65);display:inline-block;"></span>
                <span style="width:14px;height:14px;border-radius:3px;background:rgba(59,130,246,0.9);display:inline-block;"></span>
                <span>High</span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" style="width:14px;height:14px;margin-left:8px;transition:transform .2s;"
                     :style="heatmapOpen ? 'transform:rotate(180deg)' : ''">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                </svg>
            </div>
        </div>

        <!-- Calendar grid (collapses/expands) -->
        <div x-show="heatmapOpen" x-transition style="padding:16px 20px;">

            <!-- Weekday headers -->
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:4px;">
                <template x-for="wd in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']">
                    <div style="text-align:center;font-size:0.7rem;font-weight:600;
                                color:var(--text-muted);padding:2px 0;"
                         x-text="wd"></div>
                </template>
            </div>

            <!-- Day cells — padded so day 0 falls on its correct weekday -->
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;">

                <!-- Padding cells before first day -->
                <template x-for="pad in heatmapData.days[0].weekday">
                    <div style="height:52px;border-radius:5px;background:transparent;"></div>
                </template>

                <!-- Actual day cells -->
                <template x-for="cell in heatmapData.days" :key="cell.date">
                    <div class="ff-heatmap-cell"
                         :title="cell.date + ': ' + cell.count + ' reservation' + (cell.count !== 1 ? 's' : '')"
                         :style="'background:rgba(59,130,246,' + (cell.count === 0
                             ? (cell.isWeekend ? '0.03' : '0.06')
                             : Math.min(0.92, cell.count / heatmapData.max * 0.85 + 0.18).toFixed(2)
                         ) + ');' + (cell.isToday ? 'outline:2px solid #3b82f6;outline-offset:1px;' : '')">
                        <div class="ff-heatmap-day"
                             :style="cell.count > 0 ? 'color:' + (cell.count / heatmapData.max > 0.5 ? 'white' : 'var(--text-primary)') : ''"
                             x-text="cell.dayNum"></div>
                        <div class="ff-heatmap-month"
                             x-show="cell.dayNum === 1"
                             x-text="cell.monthLabel"></div>
                        <div class="ff-heatmap-count"
                             x-show="cell.count > 0"
                             :style="cell.count / heatmapData.max > 0.5 ? 'color:rgba(255,255,255,0.85)' : ''"
                             x-text="cell.count"></div>
                    </div>
                </template>

            </div>

        </div>
    </div><!-- /heat map card -->

    <!-- ================================================================
         VIEW TOGGLE + GANTT TIMELINE
         Toggle between Table view (default) and Timeline (Gantt) view.
         Gantt data is lazy-loaded on first open.
         ================================================================ -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
        <!-- View toggle buttons -->
        <div style="display:flex;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">
            <button type="button"
                    class="btn btn-sm"
                    :class="!ganttView ? 'btn-primary' : 'btn-ghost'"
                    @click="ganttView = false"
                    style="border-radius:0;border:none;display:flex;align-items:center;gap:5px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                </svg>
                Table
            </button>
            <button type="button"
                    class="btn btn-sm"
                    :class="ganttView ? 'btn-primary' : 'btn-ghost'"
                    @click="toggleGantt()"
                    style="border-radius:0;border:none;border-left:1px solid var(--border-color);display:flex;align-items:center;gap:5px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125"/>
                </svg>
                Timeline
            </button>
        </div>
        <span class="text-secondary text-sm" x-show="ganttView">
            Active reservations — next 14 days
        </span>
    </div>

    <!-- ── GANTT TIMELINE CARD (shown when ganttView = true) ──────── -->
    <div class="card" style="margin-bottom:24px;" x-show="ganttView">

        <!-- Loading state -->
        <template x-if="ganttView && !ganttLoaded">
            <div style="padding:32px;text-align:center;">
                <div class="skeleton skeleton-text" style="width:100%;height:40px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:90%;height:36px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:80%;height:36px;"></div>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="ganttView && ganttLoaded && ganttRows.length === 0">
            <div class="empty-state" style="padding:40px;">
                <p class="empty-state-title">No active reservations in the next 14 days</p>
                <p class="empty-state-text">Pending and confirmed reservations with upcoming pickup dates will appear here.</p>
            </div>
        </template>

        <!-- Gantt grid -->
        <template x-if="ganttView && ganttLoaded && ganttRows.length > 0">
            <div style="overflow-x:auto;">
                <table class="ff-gantt-table">
                    <thead>
                        <tr>
                            <!-- Unit column header -->
                            <th class="ff-gantt-unit-header">Unit #</th>
                            <!-- Date column headers -->
                            <template x-for="day in ganttDays" :key="day">
                                <th class="ff-gantt-day-header"
                                    :class="isGanttToday(day) ? 'ff-gantt-today-col' : ''">
                                    <div x-text="ganttDayLabel(day)"></div>
                                    <div style="font-size:0.65rem;font-weight:400;opacity:0.75;"
                                         x-text="ganttDateLabel(day)"></div>
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in ganttRows" :key="row.unitNum">
                            <tr class="ff-gantt-row">
                                <td class="ff-gantt-unit-cell font-mono"
                                    x-text="row.unitNum"></td>
                                <template x-for="day in ganttDays" :key="day">
                                    <td class="ff-gantt-cell"
                                        :class="isGanttToday(day) ? 'ff-gantt-today-col' : ''">
                                        <template x-if="row.dates[day]">
                                            <a :href="'<?= base_url('reservations/show') ?>?id=' + row.dates[day].id"
                                               class="ff-gantt-block"
                                               :class="row.dates[day].status === 'confirmed' ? 'ff-gantt-confirmed' : 'ff-gantt-pending'"
                                               :title="row.dates[day].company_name + ' — #' + row.dates[day].id + ' (' + row.dates[day].status + ')'"
                                               x-text="row.dates[day].company_name">
                                            </a>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <!-- Legend -->
                <div style="padding:10px 16px;display:flex;gap:16px;font-size:0.75rem;color:var(--text-muted);border-top:1px solid var(--border-color);">
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="width:12px;height:12px;border-radius:2px;background:#22c55e;display:inline-block;"></span> Confirmed
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="width:12px;height:12px;border-radius:2px;background:#f59e0b;display:inline-block;"></span> Pending
                    </div>
                    <div style="margin-left:auto;" x-text="ganttRows.length + ' units · ' + ganttDays.length + ' days'"></div>
                </div>
            </div>
        </template>

    </div><!-- /Gantt card -->

    <!-- ── CHASSIS IN (pending + confirmed) — hidden when Gantt active -->
    <div x-show="!ganttView">
    <!-- ── CHASSIS IN (pending + confirmed) ───────────────────────── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;">
            <span style="width:12px;height:12px;border-radius:50%;background:var(--color-success);display:inline-block;flex-shrink:0;"></span>
            <h2 class="card-title h5" style="margin:0;">Chassis In</h2>
            <span class="badge badge-no-dot badge-info"
                  style="margin-left:4px;"
                  x-text="inRows.length + ' ' + (inRows.length === 1 ? 'reservation' : 'reservations')"
                  x-show="!loading"></span>
            <span class="text-muted text-sm" style="margin-left:auto;">Pending &amp; Confirmed</span>
        </div>

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div style="padding:24px;">
                <div class="skeleton skeleton-text" style="width:100%;height:40px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:100%;height:36px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:80%;height:36px;"></div>
            </div>
        </template>

        <!-- Error state -->
        <template x-if="!loading && loadError">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load reservations</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="loadAll()">Retry</button>
            </div>
        </template>

        <!-- Empty -->
        <template x-if="!loading && !loadError && inRows.length === 0">
            <div class="empty-state" style="padding:32px;">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                    </svg>
                </div>
                <p class="empty-state-title">No active reservations</p>
                <p class="empty-state-text">No pending or confirmed reservations match the current filters.</p>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && !loadError && inRows.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Chassis In — Pending and Confirmed Reservations">
                    <thead>
                        <tr>
                            <th scope="col" class="th-sortable" @click="setSort('id')">
                                ID
                                <span x-show="filters.sort === 'id'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('company_name')">
                                Contact
                                <span x-show="filters.sort === 'company_name'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col">Company</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-center" style="width:60px;">Qty</th>
                            <th scope="col" class="th-sortable" @click="setSort('pickup_date')">
                                Date
                                <span x-show="filters.sort === 'pickup_date'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col" style="width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in inRows" :key="r.id">
                            <tr x-show="!quickStatus || r.status === quickStatus"
                                :class="r.priority === 'urgent' ? 'row-highlight-danger' : (r.priority === 'high' ? 'row-highlight-warning' : '')">
                                <td class="font-mono text-sm"
                                    :style="r.priority === 'urgent'
                                        ? 'border-left:3px solid var(--color-danger);padding-left:10px;'
                                        : r.priority === 'high'
                                        ? 'border-left:3px solid var(--color-warning);padding-left:10px;'
                                        : 'border-left:3px solid transparent;padding-left:10px;'">
                                    <a :href="'<?= base_url('reservations/show') ?>?id=' + r.id"
                                       class="link font-medium"
                                       x-text="'#' + r.id"></a>
                                </td>
                                <td>
                                    <div class="font-medium" x-text="r.contact_name"></div>
                                    <div class="text-xs text-secondary" x-text="r.contact_phone || ''"></div>
                                </td>
                                <td>
                                    <div x-text="r.company_name"></div>
                                </td>
                                <td class="text-sm">
                                    <template x-if="r.units && r.units.length > 0">
                                        <div>
                                            <!-- Show unit# + type for first unit -->
                                            <div style="display:flex;align-items:baseline;gap:4px;flex-wrap:wrap;">
                                                <span class="font-mono text-xs font-medium"
                                                      x-text="r.units[0].unit_number_snapshot || r.units[0].unit_number || '—'"></span>
                                                <span class="text-xs text-secondary"
                                                      x-show="r.units[0].template_name"
                                                      x-text="'· ' + r.units[0].template_name"></span>
                                            </div>
                                            <!-- Additional units -->
                                            <div class="text-xs text-secondary"
                                                 x-show="r.units.length > 1"
                                                 x-text="'+ ' + (r.units.length - 1) + ' more unit' + (r.units.length - 1 > 1 ? 's' : '')"></div>
                                        </div>
                                    </template>
                                    <template x-if="!r.units || r.units.length === 0">
                                        <span class="text-secondary">—</span>
                                    </template>
                                </td>
                                <td class="text-center font-mono text-sm" x-text="r.quantity"></td>
                                <td class="text-sm">
                                    <div x-text="formatDate(r.pickup_date)"></div>
                                    <div class="text-xs text-secondary"
                                         x-text="r.pickup_time ? formatTime(r.pickup_time) : ''"></div>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="statusBadge(r.status)"
                                          x-text="r.status.charAt(0).toUpperCase() + r.status.slice(1)">
                                    </span>
                                    <div class="text-xs text-secondary" x-show="r.priority === 'urgent' || r.priority === 'high'">
                                        <span :class="r.priority === 'urgent' ? 'text-danger' : 'text-warning'"
                                              x-text="r.priority.charAt(0).toUpperCase() + r.priority.slice(1)"></span>
                                    </div>
                                </td>
                                <td class="text-sm text-secondary" x-text="r.created_by_name || '—'"></td>
                                <td style="white-space:nowrap;">
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <!-- Edit -->
                                        <a :href="'<?= base_url('reservations/show') ?>?id=' + r.id"
                                           class="btn btn-primary btn-xs"
                                           title="Edit reservation">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:middle;">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                                            </svg>
                                        </a>

                                        <!-- Confirm (pending only) -->
                                        <?php if (can('reservations', 'edit')): ?>
                                        <button class="btn btn-success btn-xs"
                                                x-show="r.status === 'pending'"
                                                @click="confirmReservation(r)"
                                                title="Confirm reservation">
                                            Confirm
                                        </button>

                                        <!-- Mark Out (confirmed only) -->
                                        <button class="btn btn-primary btn-xs"
                                                x-show="r.status === 'confirmed'"
                                                @click="markOut(r)"
                                                title="Mark unit as checked out">
                                            Chassis Out
                                        </button>

                                        <!-- Cancel -->
                                        <button class="btn btn-danger btn-xs"
                                                @click="cancelReservation(r)"
                                                title="Cancel reservation">
                                            Cancel
                                        </button>
                                        <?php endif; ?>

                                        <!-- Delete (pending only) -->
                                        <?php if (can('reservations', 'delete')): ?>
                                        <button class="btn btn-ghost btn-xs"
                                                x-show="r.status === 'pending'"
                                                @click="deleteReservation(r)"
                                                title="Delete reservation"
                                                style="color:var(--color-danger);">
                                            Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Pagination for In table -->
        <template x-if="!loading && inPagination.total_pages > 1">
            <div class="pagination">
                <span class="pagination-info"
                      x-text="'Page ' + inPagination.page + ' of ' + inPagination.total_pages + ' (' + inPagination.total + ' total)'">
                </span>
                <div class="pagination-controls">
                    <button class="page-btn"
                            :disabled="inPagination.page <= 1"
                            @click="goToPageIn(inPagination.page - 1)">← Prev</button>
                    <button class="page-btn"
                            :disabled="!inPagination.has_more"
                            @click="goToPageIn(inPagination.page + 1)">Next →</button>
                </div>
            </div>
        </template>

    </div><!-- /Chassis In card -->

    <!-- ── CHASSIS OUT (completed) ────────────────────────────────── -->
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;gap:10px;">
            <span style="width:12px;height:12px;border-radius:50%;background:var(--color-accent);display:inline-block;flex-shrink:0;"></span>
            <h2 class="card-title h5" style="margin:0;">Chassis Out</h2>
            <span class="badge badge-no-dot badge-neutral"
                  style="margin-left:4px;"
                  x-text="outRows.length + ' ' + (outRows.length === 1 ? 'reservation' : 'reservations')"
                  x-show="!loading"></span>
            <span class="text-muted text-sm" style="margin-left:auto;">Completed (marked out)</span>
        </div>

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div style="padding:24px;">
                <div class="skeleton skeleton-text" style="width:100%;height:40px;margin-bottom:8px;"></div>
                <div class="skeleton skeleton-text" style="width:90%;height:36px;"></div>
            </div>
        </template>

        <!-- Empty -->
        <template x-if="!loading && !loadError && outRows.length === 0">
            <div class="empty-state" style="padding:24px 32px;">
                <p class="empty-state-title" style="font-size:0.9rem;">No completed reservations</p>
                <p class="empty-state-text">Reservations moved to "Chassis Out" after units are marked out.</p>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && !loadError && outRows.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Chassis Out — Completed Reservations">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Company</th>
                            <th scope="col">Type</th>
                            <th scope="col" class="text-center" style="width:60px;">Qty</th>
                            <th scope="col">Date</th>
                            <th scope="col">Marked Out</th>
                            <th scope="col">Created</th>
                            <th scope="col" style="width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in outRows" :key="r.id">
                            <tr>
                                <td class="font-mono text-sm">
                                    <a :href="'<?= base_url('reservations/show') ?>?id=' + r.id"
                                       class="link font-medium"
                                       x-text="'#' + r.id"></a>
                                </td>
                                <td>
                                    <div class="font-medium" x-text="r.contact_name"></div>
                                    <div class="text-xs text-secondary" x-text="r.contact_phone || ''"></div>
                                </td>
                                <td x-text="r.company_name"></td>
                                <td class="text-sm">
                                    <template x-if="r.units && r.units.length > 0">
                                        <div>
                                            <template x-for="(u, i) in r.units.slice(0, 2)" :key="i">
                                                <div style="display:flex;align-items:baseline;gap:4px;">
                                                    <span class="font-mono text-xs font-medium"
                                                          x-text="u.unit_number_snapshot || u.unit_number || '—'"></span>
                                                    <span class="text-xs text-secondary"
                                                          x-show="u.template_name"
                                                          x-text="'· ' + u.template_name"></span>
                                                </div>
                                            </template>
                                            <div class="text-xs text-secondary"
                                                 x-show="r.units.length > 2"
                                                 x-text="'+ ' + (r.units.length - 2) + ' more'"></div>
                                        </div>
                                    </template>
                                    <template x-if="!r.units || r.units.length === 0">
                                        <span class="text-secondary">—</span>
                                    </template>
                                </td>
                                <td class="text-center font-mono text-sm" x-text="r.quantity"></td>
                                <td class="text-sm" x-text="formatDate(r.pickup_date)"></td>
                                <td class="text-sm">
                                    <div x-text="r.marked_out_at ? formatDatetime(r.marked_out_at) : '—'"></div>
                                </td>
                                <td class="text-sm text-secondary" x-text="r.created_by_name || '—'"></td>
                                <td style="white-space:nowrap;">
                                    <div style="display:flex;gap:4px;">
                                        <!-- View -->
                                        <a :href="'<?= base_url('reservations/show') ?>?id=' + r.id"
                                           class="btn btn-ghost btn-xs">View</a>

                                        <!-- Reverse (completed→confirmed) — manager only -->
                                        <?php if (can('reservations', 'edit') && in_array($_SESSION['ff_user']['role_slug'] ?? '', ['super_admin', 'manager'])): ?>
                                        <button class="btn btn-warning btn-xs"
                                                @click="reverseMarkOut(r)"
                                                title="Reverse — move back to Chassis In">
                                            Reverse
                                        </button>
                                        <?php endif; ?>

                                        <!-- Delete cancelled only via show page -->
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Out table pagination -->
        <template x-if="!loading && outPagination.total_pages > 1">
            <div class="pagination">
                <span class="pagination-info"
                      x-text="'Page ' + outPagination.page + ' of ' + outPagination.total_pages">
                </span>
                <div class="pagination-controls">
                    <button class="page-btn"
                            :disabled="outPagination.page <= 1"
                            @click="goToPageOut(outPagination.page - 1)">← Prev</button>
                    <button class="page-btn"
                            :disabled="!outPagination.has_more"
                            @click="goToPageOut(outPagination.page + 1)">Next →</button>
                </div>
            </div>
        </template>

    </div><!-- /Chassis Out card -->
    </div><!-- /x-show !ganttView wrapper -->

    <!-- ── CANCEL MODAL ───────────────────────────────────────────── -->
    <div x-show="cancelModal.open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);"
         @keydown.escape.window="cancelModal.open = false">

        <div class="card" style="width:480px;max-width:calc(100vw - 32px);padding:24px;">
            <h3 class="h5" style="margin-bottom:8px;">Cancel Reservation</h3>
            <p class="text-secondary text-sm" style="margin-bottom:16px;">
                You are cancelling
                <strong x-text="cancelModal.reservation ? cancelModal.reservation.company_name : ''"></strong>
                (Reservation
                <span x-text="cancelModal.reservation ? '#' + cancelModal.reservation.id : ''"></span>).
                Please provide a reason.
            </p>

            <div class="form-group">
                <label class="form-label" for="cancel-reason">Reason <span class="text-danger">*</span></label>
                <textarea id="cancel-reason"
                          class="form-input"
                          rows="3"
                          placeholder="e.g. Customer called to cancel, unit no longer needed…"
                          x-model="cancelModal.reason"></textarea>
                <p class="form-error" x-show="cancelModal.error" x-text="cancelModal.error"></p>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button class="btn btn-ghost btn-sm"
                        @click="cancelModal.open = false; cancelModal.reason = ''; cancelModal.error = ''">
                    Back
                </button>
                <button class="btn btn-danger btn-sm"
                        :disabled="cancelModal.submitting"
                        @click="submitCancel()">
                    <span x-show="!cancelModal.submitting">Cancel Reservation</span>
                    <span x-show="cancelModal.submitting">Cancelling…</span>
                </button>
            </div>
        </div>

    </div>

</div><!-- /x-data -->

<!-- ============================================================
     ALPINE COMPONENT
     ============================================================ -->
<script>
function FF_Reservations() {
    return {
        // ── State ────────────────────────────────────────────────
        inRows:       [],
        outRows:      [],
        loading:      true,
        loadError:    null,
        kpis:         { total: 0, pending: 0, confirmed: 0, today: 0 },
        kpisLoaded:   false,
        inPagination: {},
        outPagination:{},

        filters: {
            q:           '',
            pickup_date: '',
            priority:    '',
            sort:        'pickup_date',
            dir:         'ASC',
        },

        // TILES-1: client-side quick-filter set by the Pending/Confirmed tiles.
        // Applied via x-show on each row so we don't need two API roundtrips
        // (the list is already pre-merged from pending + confirmed endpoints).
        quickStatus: '',

        inPage:  1,
        outPage: 1,

        // ── Chart data ───────────────────────────────────────────
        // WHY separate state: chart data is fetched independently of the
        // table data so that charts load and animate without blocking the table.
        chartData: {
            loaded:   false,
            statuses: { pending: 0, confirmed: 0, completed: 0 },
            weekly:   { labels: [], counts: [] },
        },
        _donutChart: null,  // Chart.js instance refs for destroy/recreate on refresh
        _barChart:   null,

        // ── Heat map state ───────────────────────────────────────
        heatmapData: { loaded: false, days: [], max: 1 },
        heatmapOpen: true,  // expanded by default — operator wants density visible on entry

        // ── Gantt (timeline) view ─────────────────────────────────
        ganttView:  false,   // false = table view, true = timeline view
        ganttRows:  [],      // [{unitNum, dates:{date→reservation}}]
        ganttDays:  [],      // array of date strings (next 14 days)
        ganttLoaded: false,

        cancelModal: {
            open:         false,
            reservation:  null,
            reason:       '',
            error:        '',
            submitting:   false,
        },

        // ── Init ─────────────────────────────────────────────────
        init() {
            this.loadAll();
            this.loadKpis();
            this.loadHeatmap();
        },

        // ── Load both tables ─────────────────────────────────────
        async loadAll() {
            this.loading   = true;
            this.loadError = null;
            await Promise.all([
                this.loadIn(),
                this.loadOut(),
            ]);
            this.loading = false;
        },

        // ── Build query string from filters ──────────────────────
        buildParams(extraStatus, page) {
            const p = new URLSearchParams();
            if (this.filters.q)           p.set('q', this.filters.q);
            if (this.filters.pickup_date) p.set('pickup_date', this.filters.pickup_date);
            if (this.filters.priority)    p.set('priority', this.filters.priority);
            p.set('sort', this.filters.sort);
            p.set('dir',  this.filters.dir);
            p.set('status', extraStatus);
            p.set('page', page);
            p.set('per_page', '25');
            return p.toString();
        },

        // ── Load Chassis In (pending + confirmed) ─────────────────
        // WHY two requests: the API supports a single status filter.
        // We merge pending + confirmed into a single table client-side.
        async loadIn() {
            try {
                const [pendingRes, confirmedRes] = await Promise.all([
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?` + this.buildParams('pending', this.inPage)),
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?` + this.buildParams('confirmed', this.inPage)),
                ]);
                if (!pendingRes.ok || !confirmedRes.ok) throw new Error('Server error');

                const [pd, cd] = await Promise.all([pendingRes.json(), confirmedRes.json()]);

                // Merge and sort client-side by pickup_date ASC
                const merged = [
                    ...(pd.data?.items || []),
                    ...(cd.data?.items || []),
                ];
                merged.sort((a, b) => {
                    if (this.filters.sort === 'pickup_date') {
                        const cmp = a.pickup_date.localeCompare(b.pickup_date);
                        return this.filters.dir === 'ASC' ? cmp : -cmp;
                    }
                    return 0;
                });
                this.inRows = merged;
                this.inPagination = pd.data?.pagination || {};
            } catch (e) {
                this.loadError = e.message || 'Failed to load';
            }
        },

        // ── Load Chassis Out (completed) ──────────────────────────
        async loadOut() {
            try {
                const qs   = this.buildParams('completed', this.outPage);
                const res  = await fetch(`<?= base_url('api/v1/reservations/index.php') ?>?` + qs);
                if (!res.ok) throw new Error('Server error');
                const data = await res.json();
                this.outRows       = data.data?.items || [];
                this.outPagination = data.data?.pagination || {};
            } catch (e) {
                this.loadError = e.message || 'Failed to load';
            }
        },

        // ── Load KPI counts ───────────────────────────────────────
        async loadKpis() {
            try {
                const today = this.todayDate();
                const [totalRes, pendRes, confRes, todayRes] = await Promise.all([
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=pending&per_page=1`),
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=pending&per_page=1`),
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=confirmed&per_page=1`),
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?pickup_date=${today}&per_page=1`),
                ]);
                const [td, pd, cd, todr] = await Promise.all([
                    totalRes.json(), pendRes.json(), confRes.json(), todayRes.json()
                ]);

                // Total active = pending + confirmed totals
                const pendTotal = pd.data?.pagination?.total || 0;
                const confTotal = cd.data?.pagination?.total || 0;

                this.kpis = {
                    total:     pendTotal + confTotal,
                    pending:   pendTotal,
                    confirmed: confTotal,
                    today:     todr.data?.pagination?.total || 0,
                };
                this.kpisLoaded = true;
                this.loadChartData();   // charts draw after kpis are ready
            } catch {}
        },

        // ── Load chart data (status donut + 7-day bar) ────────────
        // WHY separate method: keeps loadKpis() focused; chart data
        // needs one extra API call (completed count) + 7 date calls.
        async loadChartData() {
            try {
                // Completed count (pending + confirmed already in this.kpis)
                const cRes  = await fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=completed&per_page=1`);
                const cData = await cRes.json();
                this.chartData.statuses = {
                    pending:   this.kpis.pending,
                    confirmed: this.kpis.confirmed,
                    completed: cData.data?.pagination?.total || 0,
                };

                // Pickup counts for each of the past 7 days
                const days = [], labels = [];
                for (let i = 6; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(d.getDate() - i);
                    days.push(d.toISOString().slice(0, 10));
                    labels.push(d.toLocaleDateString('en-US', {
                        weekday: 'short', month: 'short', day: 'numeric',
                    }));
                }

                const counts = await Promise.all(
                    days.map(day =>
                        fetch(`<?= base_url('api/v1/reservations/index.php') ?>?pickup_date=${day}&per_page=1`)
                            .then(r => r.json())
                            .then(d => d.data?.pagination?.total || 0)
                            .catch(() => 0)
                    )
                );

                this.chartData.weekly = { labels, counts };
                this.chartData.loaded = true;

                // WHY setTimeout 50ms: x-show="chartData.loaded" must update the
                // DOM (unhide canvases) before Chart.js can measure their dimensions.
                setTimeout(() => this.renderCharts(), 50);
            } catch (e) {
                console.warn('FF charts: data load failed', e);
            }
        },

        // ── Draw / redraw Chart.js charts ─────────────────────────
        renderCharts() {
            if (typeof Chart === 'undefined') return;

            // ── Status donut ──────────────────────────────────────
            const donutEl = document.getElementById('ff-donut-chart');
            if (donutEl) {
                if (this._donutChart) this._donutChart.destroy();
                this._donutChart = new Chart(donutEl, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Confirmed', 'Completed'],
                        datasets: [{
                            data: [
                                this.chartData.statuses.pending,
                                this.chartData.statuses.confirmed,
                                this.chartData.statuses.completed,
                            ],
                            backgroundColor: ['#f59e0b', '#22c55e', '#3b82f6'],
                            borderWidth: 0,
                            hoverOffset: 8,
                        }],
                    },
                    options: {
                        responsive: false,
                        cutout: '68%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => `  ${ctx.label}: ${ctx.parsed}`,
                                },
                            },
                        },
                        animation: { animateRotate: true, duration: 700 },
                    },
                });
            }

            // ── 7-day pickups bar chart ───────────────────────────
            const barEl = document.getElementById('ff-bar-chart');
            if (barEl) {
                if (this._barChart) this._barChart.destroy();
                // Detect dark mode for grid + label colors
                const dark      = document.documentElement.getAttribute('data-theme') === 'dark'
                               || window.matchMedia('(prefers-color-scheme: dark)').matches;
                const gridColor = dark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
                const textColor = dark ? '#94a3b8' : '#64748b';

                // Highlight today's bar (last element)
                const bgColors = this.chartData.weekly.counts.map((_, i) =>
                    i === 6 ? '#2563eb' : '#3b82f6'
                );

                this._barChart = new Chart(barEl, {
                    type: 'bar',
                    data: {
                        labels: this.chartData.weekly.labels,
                        datasets: [{
                            label: 'Pickups',
                            data:  this.chartData.weekly.counts,
                            backgroundColor: bgColors,
                            borderRadius: 5,
                            borderSkipped: false,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: ctx => `  ${ctx.parsed.y} pickup${ctx.parsed.y !== 1 ? 's' : ''}`,
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid:  { display: false },
                                ticks: { color: textColor, font: { size: 11 } },
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { color: textColor, font: { size: 11 }, stepSize: 1, precision: 0 },
                                grid:  { color: gridColor },
                            },
                        },
                        animation: { duration: 600 },
                    },
                });
            }
        },

        // ── Load heat map — next 28 days pickup density ───────────
        // WHY 28 parallel calls: each is a lightweight per_page=1 request;
        // together they paint a full 4-week forward-looking calendar so
        // dispatchers can spot busy vs open days at a glance.
        async loadHeatmap() {
            const days = [], meta = [];
            const today = new Date();
            for (let i = 0; i < 28; i++) {
                const d = new Date(today);
                d.setDate(d.getDate() + i);
                const iso = d.toISOString().slice(0, 10);
                days.push(iso);
                meta.push({
                    date:       iso,
                    dayNum:     d.getDate(),
                    weekday:    d.getDay(),            // 0=Sun
                    monthLabel: d.toLocaleDateString('en-US', { month: 'short' }),
                    isToday:    i === 0,
                    isWeekend:  d.getDay() === 0 || d.getDay() === 6,
                });
            }

            const counts = await Promise.all(
                days.map(date =>
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?pickup_date=${date}&per_page=1`)
                        .then(r => r.json())
                        .then(d => d.data?.pagination?.total || 0)
                        .catch(() => 0)
                )
            );

            const max = Math.max(...counts, 1);
            this.heatmapData = {
                loaded: true,
                days:   meta.map((m, i) => ({ ...m, count: counts[i] })),
                max,
            };
        },

        // ── Load Gantt data — all active reservations for next 14 days
        // WHY separate from loadAll: Gantt needs per_page=200 to get a
        // full picture; the table uses per_page=25 for performance.
        async loadGanttData() {
            if (this.ganttLoaded) return;
            try {
                const [pendRes, confRes] = await Promise.all([
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=pending&per_page=200&sort=pickup_date&dir=ASC`),
                    fetch(`<?= base_url('api/v1/reservations/index.php') ?>?status=confirmed&per_page=200&sort=pickup_date&dir=ASC`),
                ]);
                const [pd, cd] = await Promise.all([pendRes.json(), confRes.json()]);
                const allRes = [
                    ...(pd.data?.items || []),
                    ...(cd.data?.items || []),
                ];

                // Build 14-day window starting today
                const today = new Date();
                const days = [];
                for (let i = 0; i < 14; i++) {
                    const d = new Date(today);
                    d.setDate(d.getDate() + i);
                    days.push(d.toISOString().slice(0, 10));
                }
                this.ganttDays = days;

                // Map: unitNumber → { date → reservation }
                const unitMap = {};
                for (const r of allRes) {
                    const units = r.units || [];
                    if (!units.length) {
                        // Reservation with no linked units — put in "Unassigned" row
                        const key = '(Unassigned)';
                        if (!unitMap[key]) unitMap[key] = {};
                        unitMap[key][r.pickup_date] = r;
                        continue;
                    }
                    for (const u of units) {
                        const key = u.unit_number_snapshot || ('Unit #' + (u.equipment_unit_id || '?'));
                        if (!unitMap[key]) unitMap[key] = {};
                        unitMap[key][r.pickup_date] = r;
                    }
                }

                this.ganttRows = Object.entries(unitMap)
                    .map(([unitNum, dates]) => ({ unitNum, dates }))
                    .sort((a, b) => a.unitNum.localeCompare(b.unitNum));

                this.ganttLoaded = true;
            } catch (e) {
                console.warn('Gantt load failed', e);
            }
        },

        // ── Toggle Gantt view (lazy-loads data on first open) ─────
        async toggleGantt() {
            this.ganttView = !this.ganttView;
            if (this.ganttView && !this.ganttLoaded) {
                await this.loadGanttData();
            }
        },

        // ── Gantt day label helpers ───────────────────────────────
        ganttDayLabel(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('en-US', { weekday: 'short' });
        },
        ganttDateLabel(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        },
        isGanttToday(dateStr) {
            return dateStr === new Date().toISOString().slice(0, 10);
        },

        // ── Apply filters (reset to page 1) ──────────────────────
        applyFilters() {
            this.inPage  = 1;
            this.outPage = 1;
            this.loadAll();
            this.loadKpis();
        },

        // ── Pagination ────────────────────────────────────────────
        goToPageIn(page)  { this.inPage  = page; this.loadIn(); },
        goToPageOut(page) { this.outPage = page; this.loadOut(); },

        // ── Sort ─────────────────────────────────────────────────
        setSort(col) {
            if (this.filters.sort === col) {
                this.filters.dir = this.filters.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.filters.sort = col;
                this.filters.dir  = 'ASC';
            }
            this.applyFilters();
        },

        // ── Helpers ───────────────────────────────────────────────
        clearFilters() {
            this.filters = { q: '', pickup_date: '', priority: '', sort: 'pickup_date', dir: 'ASC' };
            this.applyFilters();
        },

        hasActiveFilters() {
            return this.filters.q || this.filters.pickup_date || this.filters.priority;
        },

        todayDate() {
            return new Date().toISOString().slice(0, 10);
        },

        formatDate(d) {
            if (!d) return '—';
            const [y, m, day] = d.split('-');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return `${months[parseInt(m, 10) - 1]} ${parseInt(day, 10)}, ${y}`;
        },

        formatDatetime(dt) {
            if (!dt) return '—';
            const d = new Date(dt.replace(' ', 'T'));
            return d.toLocaleString('en-CA', {
                month: 'short', day: 'numeric', year: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false,
            });
        },

        formatTime(t) {
            if (!t) return '';
            // HH:MM:SS → HH:MM
            return t.substring(0, 5);
        },

        statusBadge(status) {
            return {
                'pending':   'badge-warning',
                'confirmed': 'badge-success',
                'completed': 'badge-info',
                'cancelled': 'badge-danger',
            }[status] || 'badge-neutral';
        },

        // ── Confirm reservation (pending → confirmed) ─────────────
        async confirmReservation(r) {
            if (!(await FF_Confirm.ask(`Confirm reservation #${r.id} for ${r.company_name}?`))) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: r.id, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                FF_Toast.error('Error: ' + e.message);
            }
        },

        // ── Mark Out (confirmed → completed) ─────────────────────
        async markOut(r) {
            if (!(await FF_Confirm.ask(`Mark reservation #${r.id} (${r.company_name}) as Chassis Out?\n\nThis records the unit as physically checked out.`))) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/mark_out.php') ?>', {
                    id: r.id,
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                FF_Toast.error('Error: ' + e.message);
            }
        },

        // ── Reverse mark-out (completed → confirmed) ─────────────
        async reverseMarkOut(r) {
            if (!(await FF_Confirm.ask(`Reverse mark-out for reservation #${r.id}?\n\nThis will move it back to Chassis In as Confirmed.`))) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: r.id, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                FF_Toast.error('Error: ' + e.message);
            }
        },

        // ── Cancel modal ──────────────────────────────────────────
        cancelReservation(r) {
            this.cancelModal = { open: true, reservation: r, reason: '', error: '', submitting: false };
        },

        async submitCancel() {
            if (!this.cancelModal.reason.trim()) {
                this.cancelModal.error = 'Please provide a cancellation reason.';
                return;
            }
            this.cancelModal.submitting = true;
            this.cancelModal.error = '';
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id:            this.cancelModal.reservation.id,
                    status:        'cancelled',
                    cancel_reason: this.cancelModal.reason,
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed to cancel');
                this.cancelModal.open = false;
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                this.cancelModal.error = e.message;
            } finally {
                this.cancelModal.submitting = false;
            }
        },

        // ── Delete reservation ────────────────────────────────────
        async deleteReservation(r) {
            if (!(await FF_Confirm.ask(`Permanently delete reservation #${r.id} for ${r.company_name}?\n\nThis cannot be undone.`))) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/delete.php') ?>', { id: r.id });
                if (!res.success) throw new Error(res.error?.message || 'Failed to delete');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                FF_Toast.error('Error: ' + e.message);
            }
        },
    };
}
</script>

<!-- Chart.js v4.4.3 (pinned, self-hosted via S-PROD-3 2026-05-14; loaded deferred so it doesn't block table render) -->
<script src="<?= asset_url('assets/vendor/chartjs/chart.umd.min.js') ?>" defer></script>

<style>
/* ── Charts grid: donut (narrow) + bar (wide) side by side ─── */
.ff-charts-grid {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 16px;
    margin-bottom: 20px;
    align-items: start;
}
@media (max-width: 640px) {
    .ff-charts-grid { grid-template-columns: 1fr; }
}

/* ── Heat map calendar cells ─────────────────────────────────── */
.ff-heatmap-cell {
    border-radius: 5px;
    padding: 5px 6px;
    min-height: 52px;
    cursor: default;
    transition: filter .15s;
    position: relative;
}
.ff-heatmap-cell:hover { filter: brightness(1.08); }
.ff-heatmap-day {
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1;
    color: var(--text-primary);
}
.ff-heatmap-month {
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--color-primary);
    letter-spacing: .04em;
    line-height: 1.2;
}
.ff-heatmap-count {
    position: absolute;
    bottom: 5px;
    right: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    line-height: 1;
}

/* ── Gantt table ─────────────────────────────────────────────── */
.ff-gantt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.ff-gantt-unit-header {
    position: sticky;
    left: 0;
    background: var(--bg-surface);
    z-index: 2;
    white-space: nowrap;
    padding: 8px 14px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border-color);
    min-width: 110px;
}
.ff-gantt-day-header {
    padding: 6px 4px;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-muted);
    border-bottom: 2px solid var(--border-color);
    min-width: 80px;
    white-space: nowrap;
}
.ff-gantt-today-col {
    background: rgba(59,130,246,0.05);
}
.ff-gantt-row:hover { background: var(--bg-muted); }
.ff-gantt-unit-cell {
    position: sticky;
    left: 0;
    background: var(--bg-surface);
    z-index: 1;
    padding: 5px 14px;
    font-size: 0.8rem;
    font-weight: 600;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.ff-gantt-row:hover .ff-gantt-unit-cell { background: var(--bg-muted); }
.ff-gantt-cell {
    padding: 4px 3px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.ff-gantt-block {
    display: block;
    border-radius: 4px;
    padding: 3px 6px;
    font-size: 0.7rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 76px;
    text-decoration: none;
    color: white;
    transition: opacity .15s;
}
.ff-gantt-block:hover { opacity: 0.85; color: white; }
.ff-gantt-confirmed { background: #22c55e; }
.ff-gantt-pending   { background: #f59e0b; }
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
