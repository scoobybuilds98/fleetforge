<?php
declare(strict_types=1);

/**
 * app/admin/equipment/index.php
 *
 * Equipment Units list page. Displays 4 KPI stat tiles (available, on_lease,
 * maintenance, total) followed by a filterable, paginated table of equipment
 * units. Filters: search, status, template_id. Default sort: unit_number ASC.
 * Each row shows unit number, template, year, status badge, yard, mileage.
 * Dispatchers and above can view; create permission gates the New Unit button.
 *
 * Alpine.js component FF_Equipment() — fetches api/v1/equipment/units/index.php
 * and api/v1/equipment/units/index.php?per_page=1 for KPI counts (status-bucketed).
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/units/index.php,
 *          api/v1/equipment/templates/index.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4, §4.1 Equipment list KPI drilldowns
 * @decisions D30, D32, D33
 * @session S006
 */

// dirname(__DIR__, 3): app/admin/equipment/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Equipment';
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Equipment</h1>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-secondary btn-sm">
            Equipment Type
        </a>
        <?php if (can('equipment', 'create')): ?>
        <a href="<?= base_url('equipment/create') ?>" class="btn btn-primary btn-sm">
            + New Unit
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     EQUIPMENT ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Equipment()" x-init="init()">

    <!-- ── KPI TILES ────────────────────────────────────────── -->
    <!-- Spec §4.1: each tile drills down to filtered view -->
    <div class="stat-grid">

        <div class="stat-card stat-card--link stat-card--green" style="cursor:pointer;"
             @click="drilldown('available')"
             :class="{ 'ring-active': filters.status === 'available' }"
             title="Show available units">
            <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
            <div class="stat-label">Available</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono text-success" x-text="kpis.available"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:45%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--link stat-card--blue" style="cursor:pointer;"
             @click="drilldown('on_lease')"
             :class="{ 'ring-active': filters.status === 'on_lease' }"
             title="Show units on lease">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-key"/></svg></span>
            <div class="stat-label">On Lease</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.on_lease"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:40%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--link stat-card--amber" style="cursor:pointer;"
             @click="drilldown('maintenance')"
             :class="{ 'ring-active': filters.status === 'maintenance' }"
             title="Show units in maintenance">
            <span class="stat-icon stat-icon--amber"><svg><use href="#icon-wrench"/></svg></span>
            <div class="stat-label">In Maintenance</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono text-warning" x-text="kpis.maintenance"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:40%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--slate">
            <span class="stat-icon stat-icon--slate"><svg><use href="#icon-truck"/></svg></span>
            <div class="stat-label">Total Fleet</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.total"></div>
                    <div class="stat-delta text-secondary"
                         x-text="kpis.total > 0 ? Math.round(kpis.on_lease / kpis.total * 100) + '% utilization' : ''">
                    </div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:55%;margin-top:8px;"></div>
            </template>
        </div>

    </div>

    <!-- ── FILTER BANNER (drilldown context) ────────────────── -->
    <template x-if="filters.status !== ''">
        <div class="card card-body" style="margin-bottom:1rem;background:var(--color-info-light);color:var(--color-info-text);display:flex;align-items:center;justify-content:space-between;padding:0.625rem 1rem;">
            <span>Showing: <strong x-text="filters.status.replace('_', ' ')"></strong> units only</span>
            <button class="btn btn-ghost btn-sm" @click="clearDrilldown()" aria-label="Clear filter">
                &times; Clear filter
            </button>
        </div>
    </template>

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search unit #, VIN, plate…"
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:220px;"
                   aria-label="Search equipment">

            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="resetPage()"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="on_lease">On Lease</option>
                <option value="reserved">Reserved</option>
                <option value="maintenance">Maintenance</option>
                <option value="inactive">Inactive</option>
                <option value="decommissioned">Decommissioned</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.template_id"
                    @change="resetPage()"
                    aria-label="Filter by equipment type">
                <option value="">All Equipment Types</option>
                <template x-for="tpl in templates" :key="tpl.id">
                    <option :value="tpl.id" x-text="tpl.name"></option>
                </template>
            </select>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' unit' + (pagination.total !== 1 ? 's' : '')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="resetPage()"
                    aria-label="Sort by">
                <optgroup label="Identifier">
                    <option value="unit_number">Unit #</option>
                    <option value="template">Equipment Type</option>
                    <option value="status">Status</option>
                </optgroup>
                <optgroup label="Asset">
                    <option value="year">Year</option>
                    <option value="acquired_date">Date acquired</option>
                    <option value="created_at">Date added</option>
                    <option value="mileage">Mileage</option>
                </optgroup>
                <optgroup label="Condition &amp; Compliance">
                    <option value="health_score">Health score</option>
                    <option value="cvi_expiry">CVI expiry</option>
                </optgroup>
            </select>
            <select class="form-select form-control-sm"
                    x-model="filters.dir"
                    @change="resetPage()"
                    aria-label="Sort direction"
                    style="width:auto;">
                <option value="ASC">↑ Asc</option>
                <option value="DESC">↓ Desc</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading equipment…">
                <template x-for="n in 8" :key="n">
                    <div class="skeleton skeleton-row"></div>
                </template>
            </div>
        </template>

        <!-- Error state -->
        <template x-if="!loading && loadError">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                </div>
                <p class="empty-state-title">Failed to load equipment</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && units.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                </div>
                <p class="empty-state-title">No equipment found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your search or filters.' : 'Register your fleet to start tracking.'">
                </p>
                <?php if (can('equipment', 'create')): ?>
                <a href="<?= base_url('equipment/create') ?>"
                   class="btn btn-primary btn-sm"
                   x-show="!hasActiveFilters()">
                    + Add Unit
                </a>
                <?php endif; ?>
                <template x-if="hasActiveFilters()">
                    <button class="btn btn-secondary btn-sm" @click="clearFilters()">
                        Clear Filters
                    </button>
                </template>
            </div>
        </template>

        <!-- ── Bulk action bar ────────────────────────────────────── -->
        <div x-show="selectedIds.length > 0"
             x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
             x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
             class="ff-bulk-bar">
            <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" style="background:rgba(34,197,94,0.12);color:#4ade80;" @click="bulkSetStatus('available')" :disabled="bulkWorking">
                <svg width="11" height="12" viewBox="0 0 11 12" fill="currentColor"><path d="M1 1l9 5-9 5V1z"/></svg>
                Available
            </button>
            <button class="ff-bulk-btn" style="background:rgba(234,179,8,0.12);color:#fbbf24;" @click="bulkSetStatus('maintenance')" :disabled="bulkWorking">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M8.5 1a3 3 0 0 1 0 5L3 11.5A1 1 0 0 1 1.5 10L7 4.5A3 3 0 0 1 8.5 1z"/></svg>
                Maintenance
            </button>
            <button class="ff-bulk-btn" style="background:rgba(107,114,128,0.15);color:#9ca3af;" @click="bulkSetStatus('inactive')" :disabled="bulkWorking">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="2" y="1" width="3" height="10" rx="1"/><rect x="7" y="1" width="3" height="10" rx="1"/></svg>
                Inactive
            </button>
            <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
                <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor" aria-hidden="true"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
                Delete
            </button>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear selection" aria-label="Clear selection">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
            </button>
        </div>

        <!-- ── Desktop table (hidden on mobile) ───────────────────── -->
        <!-- WHY data-no-auto-label: the auto-stack JS (app.js labelCellsIn)
             has a race with Alpine x-for — it can run before rows are in the
             DOM, leaving td cells without data-label, so values never show.
             Mobile view is handled by the explicit card template below. -->
        <template x-if="!loading && !loadError && units.length > 0">
            <div class="table-wrapper eq-table-desktop">
                <table class="table" aria-label="Equipment units" data-no-auto-label>
                    <thead>
                        <tr>
                            <th class="th-checkbox">
                                <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all on this page">
                            </th>
                            <th class="th-sortable" @click="setSort('unit_number')" style="cursor:pointer;">
                                Unit # <span x-text="sortIndicator('unit_number')"></span>
                            </th>
                            <th>Equipment Type</th>
                            <th>Year</th>
                            <th class="th-sortable" @click="setSort('status')" style="cursor:pointer;">
                                Status <span x-text="sortIndicator('status')"></span>
                            </th>
                            <th>Yard</th>
                            <th>Mileage</th>
                            <th>Health</th>
                            <th>Compliance</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="unit in units" :key="unit.id">
                            <tr :class="{ 'ff-row-selected': selectedIds.includes(unit.id) }">
                                <td class="td-checkbox" @click.stop>
                                    <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(unit.id)" @change="toggleSelect(unit.id)">
                                </td>
                                <td>
                                    <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id"
                                       class="font-mono font-medium link"
                                       x-text="unit.unit_number">
                                    </a>
                                </td>
                                <td>
                                    <div x-text="unit.template_name" class="font-medium"></div>
                                    <div x-text="unit.template_category ? unit.template_category.replace('_',' ') : ''"
                                         class="text-sm text-secondary" style="text-transform:capitalize;"></div>
                                </td>
                                <td x-text="unit.year || '—'" class="font-mono"></td>
                                <td>
                                    <span class="badge"
                                          :class="statusBadgeClass(unit.status)"
                                          x-text="unit.status.replace('_',' ')">
                                    </span>
                                </td>
                                <td x-text="unit.yard_location || '—'" class="text-secondary"></td>
                                <td x-text="unit.mileage ? unit.mileage.toLocaleString() + ' mi' : (unit.samsara_odometer_km && Number(unit.samsara_odometer_km) > 0 ? Math.round(Number(unit.samsara_odometer_km)).toLocaleString() + ' km' : '0 mi')"
                                    class="font-mono text-sm"></td>
                                <td>
                                    <template x-if="unit.health_score !== null">
                                        <span class="badge badge-no-dot"
                                              :class="healthBadgeClass(unit.health_score)"
                                              x-text="unit.health_score + '/100'">
                                        </span>
                                    </template>
                                    <template x-if="unit.health_score === null">
                                        <span class="text-secondary">—</span>
                                    </template>
                                </td>
                                <td>
                                    <template x-if="hasComplianceIssue(unit)">
                                        <span class="badge badge-warning badge-no-dot">Expiring</span>
                                    </template>
                                    <template x-if="!hasComplianceIssue(unit)">
                                        <span class="text-secondary text-sm">OK</span>
                                    </template>
                                </td>
                                <td style="text-align:right;">
                                    <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id"
                                       class="btn btn-ghost btn-sm">View</a>
                                    <?php if (can('equipment', 'edit')): ?>
                                    <a :href="'<?= base_url('equipment/edit') ?>?id=' + unit.id"
                                       class="btn btn-ghost btn-sm">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── Mobile cards (≤767px, hidden on desktop) ─────────── -->
        <!-- WHY explicit template: avoid the auto-stack JS race; each
             field is a plain x-text binding — no data-label magic needed. -->
        <template x-if="!loading && !loadError && units.length > 0">
            <div class="eq-cards-mobile">
                <template x-for="unit in units" :key="'m' + unit.id">
                    <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id"
                       class="eq-mobile-card">

                        <!-- Header row: unit # + status badge -->
                        <div class="eq-mc-header">
                            <span class="eq-mc-unit font-mono" x-text="unit.unit_number"></span>
                            <span class="badge"
                                  :class="statusBadgeClass(unit.status)"
                                  x-text="unit.status.replace('_',' ')">
                            </span>
                        </div>

                        <!-- Template + category -->
                        <div class="eq-mc-row">
                            <span class="eq-mc-label">Equipment Type</span>
                            <span class="eq-mc-value">
                                <span x-text="unit.template_name" class="font-medium"></span>
                                <template x-if="unit.template_category">
                                    <span class="text-secondary"
                                          x-text="' · ' + unit.template_category.replace('_',' ')"
                                          style="text-transform:capitalize;"></span>
                                </template>
                            </span>
                        </div>

                        <!-- Year + Yard on same row -->
                        <div class="eq-mc-row">
                            <span class="eq-mc-label">Year</span>
                            <span class="eq-mc-value font-mono" x-text="unit.year || '—'"></span>
                        </div>
                        <div class="eq-mc-row">
                            <span class="eq-mc-label">Yard</span>
                            <span class="eq-mc-value text-secondary" x-text="unit.yard_location || '—'"></span>
                        </div>

                        <!-- Mileage + Health -->
                        <div class="eq-mc-row">
                            <span class="eq-mc-label">Mileage</span>
                            <span class="eq-mc-value font-mono"
                                  x-text="unit.mileage ? unit.mileage.toLocaleString() + ' mi' : (unit.samsara_odometer_km && Number(unit.samsara_odometer_km) > 0 ? Math.round(Number(unit.samsara_odometer_km)).toLocaleString() + ' km' : '0 mi')">
                            </span>
                        </div>
                        <div class="eq-mc-row">
                            <span class="eq-mc-label">Health</span>
                            <span class="eq-mc-value">
                                <template x-if="unit.health_score !== null">
                                    <span class="badge badge-no-dot"
                                          :class="healthBadgeClass(unit.health_score)"
                                          x-text="unit.health_score + '/100'">
                                    </span>
                                </template>
                                <template x-if="unit.health_score === null">
                                    <span class="text-secondary">—</span>
                                </template>
                            </span>
                        </div>

                        <!-- Compliance -->
                        <template x-if="hasComplianceIssue(unit)">
                            <div class="eq-mc-row">
                                <span class="eq-mc-label">Compliance</span>
                                <span class="eq-mc-value">
                                    <span class="badge badge-warning badge-no-dot">Expiring</span>
                                </span>
                            </div>
                        </template>

                    </a>
                </template>
            </div>
        </template>

    </div><!-- /.card -->

    <!-- ── PAGINATION ─────────────────────────────────────────── -->
    <template x-if="!loading && pagination.total_pages > 1">
        <div class="pagination">
            <div class="pagination-info"
                 x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages">
            </div>
            <div class="pagination-controls">
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.page <= 1"
                        @click="goToPage(pagination.page - 1)">
                    ← Prev
                </button>
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.page >= pagination.total_pages"
                        @click="goToPage(pagination.page + 1)">
                    Next →
                </button>
            </div>
        </div>
    </template>

</div><!-- /x-data -->

<script>
function FF_Equipment() {
    return {
        units:      [],
        templates:  [],
        kpis:       { available: 0, on_lease: 0, maintenance: 0, total: 0 },
        kpisLoaded: false,
        loading:    true,
        loadError:  null,
        pagination: {},
        filters: {
            search:      '<?= htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES) ?>',
            status:      '<?= htmlspecialchars($_GET['status'] ?? '', ENT_QUOTES) ?>',
            template_id: '',
            sort:        'unit_number',
            dir:         'ASC',
        },
        currentPage: 1,
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,

        async init() {
            // Load templates for the filter dropdown
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/templates') ?>?active=1&per_page=100');
                if (r.success) this.templates = r.data.items;
            } catch(e) { /* non-critical */ }

            await this.loadKpis();
            await this.load();
            this.$watch('currentPage', () => this.clearSelection());
        },

        async loadKpis() {
            // Fetch counts for each status bucket in parallel
            const statuses = ['available', 'on_lease', 'maintenance'];
            const [avail, onLease, maint, total] = await Promise.all([
                FF_Api.get('<?= base_url('api/v1/equipment/units') ?>?status=available&per_page=1'),
                FF_Api.get('<?= base_url('api/v1/equipment/units') ?>?status=on_lease&per_page=1'),
                FF_Api.get('<?= base_url('api/v1/equipment/units') ?>?status=maintenance&per_page=1'),
                FF_Api.get('<?= base_url('api/v1/equipment/units') ?>?per_page=1'),
            ]);
            this.kpis = {
                available:   avail.success  ? avail.data.pagination.total  : 0,
                on_lease:    onLease.success ? onLease.data.pagination.total : 0,
                maintenance: maint.success  ? maint.data.pagination.total  : 0,
                total:       total.success  ? total.data.pagination.total  : 0,
            };
            this.kpisLoaded = true;
        },

        async load() {
            this.clearSelection();
            this.loading   = true;
            this.loadError = null;
            const params = new URLSearchParams();
            if (this.filters.search)      params.set('search',      this.filters.search);
            if (this.filters.status)      params.set('status',      this.filters.status);
            if (this.filters.template_id) params.set('template_id', this.filters.template_id);
            params.set('sort',     this.filters.sort);
            params.set('dir',      this.filters.dir);
            params.set('page',     this.currentPage);
            params.set('per_page', 25);
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/units') ?>?' + params);
                if (r.success) {
                    this.units      = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    this.loadError = r.message || 'Failed to load equipment.';
                }
            } catch(e) {
                this.loadError = 'Network error. Check your connection.';
            }
            this.loading = false;
        },

        resetPage() { this.currentPage = 1; this.load(); },
        goToPage(p) { this.currentPage = p; this.load(); },

        setSort(col) {
            if (this.filters.sort === col) {
                this.filters.dir = this.filters.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.filters.sort = col;
                this.filters.dir  = 'ASC';
            }
            this.resetPage();
        },

        sortIndicator(col) {
            if (this.filters.sort !== col) return '';
            return this.filters.dir === 'ASC' ? '↑' : '↓';
        },

        // KPI tile click — pre-filters the table
        drilldown(status) {
            this.filters.status = (this.filters.status === status) ? '' : status;
            this.resetPage();
        },

        clearDrilldown() { this.filters.status = ''; this.resetPage(); },

        clearFilters() {
            this.filters.search      = '';
            this.filters.status      = '';
            this.filters.template_id = '';
            this.resetPage();
        },

        hasActiveFilters() {
            return this.filters.search !== '' ||
                   this.filters.status !== '' ||
                   this.filters.template_id !== '';
        },

        // Status badge CSS class — design §9
        statusBadgeClass(status) {
            const map = {
                available:       'badge-success',
                on_lease:        'badge-info',
                reserved:        'badge-purple',
                maintenance:     'badge-warning',
                inactive:        'badge-neutral',
                decommissioned:  'badge-danger',
            };
            return map[status] || 'badge-neutral';
        },

        // Health score badge — spec §12 four bands (S-CRON-3 canonical mapping)
        // Mirrors PHP equipment_health_color() in includes/functions.php.
        healthBadgeClass(score) {
            if (score === null || score === undefined) return 'badge-neutral';
            if (score >= 80) return 'badge-success';   // green
            if (score >= 50) return 'badge-warning';   // yellow
            if (score >= 20) return 'badge-warning';   // orange (no separate badge)
            return 'badge-danger';                     // red
        },

        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.units.length > 0 && this.selectedIds.length === this.units.length;
        },

        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll = false;
            } else {
                this.selectedIds = this.units.map(item => item.id);
                this.selectAll = true;
            }
        },

        clearSelection() {
            this.selectedIds = [];
            this.selectAll = false;
        },

        async bulkDelete() {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                'Delete ' + count + ' item' + (count === 1 ? '' : 's') + '? This cannot be undone.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/equipment/units/bulk_delete') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.deleted > 0) FF_Toast.success(d.deleted + ' deleted' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' could not be deleted: ' + d.errors.map(e => e.reason).join('; '));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Bulk delete failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during bulk delete.');
            } finally {
                this.bulkWorking = false;
            }
        },

        async bulkSetStatus(newStatus) {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const label = { available: 'Available', maintenance: 'Maintenance', inactive: 'Inactive' }[newStatus] || newStatus;
            const confirmed = await FF_Confirm.ask(
                'Set ' + count + ' unit' + (count === 1 ? '' : 's') + ' to ' + label + '? Units that cannot transition to this status will be skipped.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/equipment/units/bulk_update_status') ?>', { ids: this.selectedIds, new_status: newStatus });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' unit' + (d.actioned === 1 ? '' : 's') + ' → ' + label + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0,3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Status update failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during status update.');
            } finally {
                this.bulkWorking = false;
            }
        },

        // Compliance: flag if any expiry date is within 30 days or in the past
        hasComplianceIssue(unit) {
            const warn = 30 * 24 * 60 * 60 * 1000; // 30 days in ms
            const soon = Date.now() + warn;
            const fields = ['cvi_expiry','registration_expiry','mvi_expiry','insurance_expiry'];
            return fields.some(f => {
                if (!unit[f]) return false;
                return new Date(unit[f]).getTime() <= soon;
            });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
