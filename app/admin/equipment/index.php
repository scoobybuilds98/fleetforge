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

$pageTitle = 'Equipment';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Equipment</h1>
    <div class="page-header-actions">
        <a href="<?= base_url('equipment/templates') ?>" class="btn btn-secondary btn-sm">
            Templates
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

        <div class="stat-card stat-card--link" style="cursor:pointer;"
             @click="drilldown('available')"
             title="Show available units">
            <div class="stat-label">Available</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono text-success" x-text="kpis.available"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:45%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--link" style="cursor:pointer;"
             @click="drilldown('on_lease')"
             title="Show units on lease">
            <div class="stat-label">On Lease</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.on_lease"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:40%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--link" style="cursor:pointer;"
             @click="drilldown('maintenance')"
             title="Show units in maintenance">
            <div class="stat-label">In Maintenance</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono text-warning" x-text="kpis.maintenance"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:40%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card">
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
                    aria-label="Filter by template">
                <option value="">All Templates</option>
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
                <option value="unit_number">Unit # (A→Z)</option>
                <option value="status">Status</option>
                <option value="template">Template</option>
                <option value="created_at">Newest first</option>
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

        <!-- Data table -->
        <template x-if="!loading && !loadError && units.length > 0">
            <div class="table-wrapper">
                <table class="table" aria-label="Equipment units">
                    <thead>
                        <tr>
                            <th class="th-sortable" @click="setSort('unit_number')" style="cursor:pointer;">
                                Unit # <span x-text="sortIndicator('unit_number')"></span>
                            </th>
                            <th>Template</th>
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
                            <tr>
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
                                <td x-text="unit.mileage ? unit.mileage.toLocaleString() + ' mi' : '0 mi'"
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
                                    <!-- Compliance: show warning if any expiry within 30 days or expired -->
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

    </div><!-- /.card -->

    <!-- ── PAGINATION ─────────────────────────────────────────── -->
    <template x-if="!loading && pagination.total_pages > 1">
        <div class="pagination">
            <div class="pagination-info"
                 x-text="'Page ' + pagination.current_page + ' of ' + pagination.total_pages">
            </div>
            <div class="pagination-controls">
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.current_page <= 1"
                        @click="goToPage(pagination.current_page - 1)">
                    ← Prev
                </button>
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.current_page >= pagination.total_pages"
                        @click="goToPage(pagination.current_page + 1)">
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

        async init() {
            // Load templates for the filter dropdown
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/templates') ?>?active=1&per_page=100');
                if (r.success) this.templates = r.data.items;
            } catch(e) { /* non-critical */ }

            await this.loadKpis();
            await this.load();
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

        // Health score badge — spec §12
        healthBadgeClass(score) {
            if (score >= 80) return 'badge-success';
            if (score >= 50) return 'badge-warning';
            if (score >= 20) return 'badge-danger';
            return 'badge-danger';
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
