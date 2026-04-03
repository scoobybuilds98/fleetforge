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

        <div class="stat-card">
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

        <div class="stat-card">
            <div class="stat-label">Pending</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.pending"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:35%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card">
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
             @click="filters.pickup_date = todayDate(); applyFilters()">
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
                            <tr :class="r.priority === 'urgent' ? 'row-highlight-danger' : (r.priority === 'high' ? 'row-highlight-warning' : '')">
                                <td class="font-mono text-sm">
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
                                            <div class="font-mono text-xs"
                                                 x-text="r.units[0].template_name || '—'"></div>
                                            <div class="text-xs text-secondary"
                                                 x-show="r.units.length > 1"
                                                 x-text="'+ ' + (r.units.length - 1) + ' more'"></div>
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
                                        <span class="font-mono text-xs"
                                              x-text="r.units.map(u => u.template_name || u.unit_number).filter(Boolean).join(', ')">
                                        </span>
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

        inPage:  1,
        outPage: 1,

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
            } catch {}
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
            if (!confirm(`Confirm reservation #${r.id} for ${r.company_name}?`)) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: r.id, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                alert('Error: ' + e.message);
            }
        },

        // ── Mark Out (confirmed → completed) ─────────────────────
        async markOut(r) {
            if (!confirm(`Mark reservation #${r.id} (${r.company_name}) as Chassis Out?\n\nThis records the unit as physically checked out.`)) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/mark_out.php') ?>', {
                    id: r.id,
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                alert('Error: ' + e.message);
            }
        },

        // ── Reverse mark-out (completed → confirmed) ─────────────
        async reverseMarkOut(r) {
            if (!confirm(`Reverse mark-out for reservation #${r.id}?\n\nThis will move it back to Chassis In as Confirmed.`)) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/update_status.php') ?>', {
                    id: r.id, status: 'confirmed',
                });
                if (!res.success) throw new Error(res.error?.message || 'Failed');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                alert('Error: ' + e.message);
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
            if (!confirm(`Permanently delete reservation #${r.id} for ${r.company_name}?\n\nThis cannot be undone.`)) return;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/reservations/delete.php') ?>', { id: r.id });
                if (!res.success) throw new Error(res.error?.message || 'Failed to delete');
                this.loadAll();
                this.loadKpis();
            } catch (e) {
                alert('Error: ' + e.message);
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
