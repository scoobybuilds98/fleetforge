<?php
declare(strict_types=1);

/**
 * app/admin/maintenance_work_orders/index.php
 *
 * Maintenance Work Orders list page.
 * Server-renders 4 KPI tiles, then Alpine.js loads the filterable table.
 *
 * KPI tiles: Total WOs, Open, In Progress / Waiting Parts, Completed This Month.
 * Filters: status, work_type, priority, date_from, date_to, q (search).
 * Default sort: requested_date DESC.
 *
 * URL target for navigation entry 'url => /maintenance'.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/maintenance_work_orders/index.php
 * @decisions D5/D7/D30/D32
 * @session  S015
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$kpiTotal      = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE deleted_at IS NULL");
$kpiOpen       = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE status = 'open' AND deleted_at IS NULL");
$kpiActive     = db_count("SELECT COUNT(*) FROM maintenance_work_orders WHERE status IN ('in_progress','waiting_parts') AND deleted_at IS NULL");
$kpiCompleted  = db_count(
    "SELECT COUNT(*) FROM maintenance_work_orders
     WHERE status = 'completed' AND deleted_at IS NULL
       AND completed_date >= DATE_FORMAT(NOW(), '%Y-%m-01')"
);

$pageTitle = 'Work Orders';
$helpModuleSlug = 'maintenance';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Maintenance Work Orders</h1>
    <?php if (can('maintenance', 'create')): ?>
    <a href="<?= base_url('maintenance_work_orders/create') ?>" class="btn btn-primary btn-sm">
        + New Work Order
    </a>
    <?php endif; ?>
    <div class="page-header-actions">
        <?= help_button('maintenance') ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div x-data="woKpis()" x-init="loadKpis()">
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card stat-card--blue" style="cursor:pointer;"
         @click="activeTile = activeTile === 'total' ? '' : 'total'; setFilter('status','')"
         :class="{ 'ring-active': activeTile === 'total' }">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-clipboard"/></svg></span>
        <div class="stat-label">Total Work Orders</div>
        <div class="stat-value font-mono" x-text="kpis.total"></div>
        <div class="stat-delta">all time</div>
    </div>

    <div class="stat-card stat-card--amber" style="cursor:pointer;"
         @click="activeTile = activeTile === 'open' ? '' : 'open'; setFilter('status', activeTile === 'open' ? 'open' : '')"
         :class="{ 'ring-active': activeTile === 'open' }">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-lock-open"/></svg></span>
        <div class="stat-label">Open</div>
        <div class="stat-value font-mono" x-text="kpis.open"></div>
        <div class="stat-delta">awaiting start</div>
    </div>

    <div class="stat-card stat-card--teal" style="cursor:pointer;"
         @click="activeTile = activeTile === 'active' ? '' : 'active'; setFilter('status', activeTile === 'active' ? 'in_progress' : '')"
         :class="{ 'ring-active': activeTile === 'active' }">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-bolt"/></svg></span>
        <div class="stat-label">Active</div>
        <div class="stat-value font-mono" x-text="kpis.active"></div>
        <div class="stat-delta">in progress / waiting parts</div>
    </div>

    <!-- TILES-1: clickable drill → status=completed -->
    <div class="stat-card stat-card--green" style="cursor:pointer;"
         @click="activeTile = activeTile === 'completed' ? '' : 'completed'; setFilter('status', activeTile === 'completed' ? 'completed' : '')"
         :class="{ 'ring-active': activeTile === 'completed' }">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Completed This Month</div>
        <div class="stat-value font-mono" x-text="kpis.completed_this_month"></div>
        <div class="stat-delta">closed out</div>
    </div>

</div>
</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<!-- S-LIST-TOOLBAR: bare Alpine root so the filter toolbar sits ABOVE the table
     card, matching customers/invoices. id stays on the x-data element —
     setFilter() below reads el._x_dataStack on exactly this node. -->
<div x-data="woList()"
     id="wo-table-card">

    <!-- ── FILTER TOOLBAR ────────────────────────────────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left table-toolbar-left--wrap">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search title or WO#…"
                   x-model="filters.q"
                   @input.debounce.400ms="goPage(1)"
                   maxlength="255"
                   style="min-width:200px;"
                   aria-label="Search work orders">

            <select class="form-select form-control-sm"
                    x-model="filters.status" @change="goPage(1)"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="open">Open</option>
                <option value="in_progress">In Progress</option>
                <option value="waiting_parts">Waiting Parts</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.work_type" @change="goPage(1)"
                    aria-label="Filter by work type">
                <option value="">All Types</option>
                <option value="scheduled_service">Scheduled Service</option>
                <option value="repair">Repair</option>
                <option value="inspection">Inspection</option>
                <option value="tire">Tire</option>
                <option value="electrical">Electrical</option>
                <option value="body_damage">Body Damage</option>
                <option value="breakdown">Breakdown</option>
                <option value="other">Other</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.priority" @change="goPage(1)"
                    aria-label="Filter by priority">
                <option value="">All Priorities</option>
                <option value="emergency">Emergency</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>

            <button class="btn btn-secondary btn-sm" @click="resetFilters()">Reset</button>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="total > 0 ? total + ' work order' + (total === 1 ? '' : 's') : ''"></span>

            <!-- Sort mirrors the sortable column headers below; both write the
                 same sort/dir pair the API allowlists. -->
            <select class="form-select form-control-sm"
                    x-model="sort" @change="goPage(1)"
                    aria-label="Sort by">
                <optgroup label="Dates">
                    <option value="requested_date">Requested</option>
                    <option value="scheduled_date">Scheduled</option>
                    <option value="completed_date">Completed</option>
                    <option value="updated_at">Last updated</option>
                </optgroup>
                <optgroup label="Identifier">
                    <option value="work_order_number">WO #</option>
                    <option value="status">Status</option>
                    <option value="priority">Priority</option>
                    <option value="vendor_name">Vendor</option>
                </optgroup>
                <optgroup label="Financial">
                    <option value="total_cost">Cost</option>
                </optgroup>
            </select>

            <select class="form-select form-control-sm"
                    x-model="dir" @change="goPage(1)"
                    aria-label="Sort direction"
                    style="width:auto;">
                <option value="DESC">↓ Desc</option>
                <option value="ASC">↑ Asc</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ────────────────────────────────────────────── -->
    <div class="card">

    <!-- Loading -->
    <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading…</span>
    </div>

    <!-- Empty state -->
    <template x-if="!loading && rows.length === 0">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-state-title">No work orders found</p>
                <p class="empty-state-text">
                    <?php if (can('maintenance', 'create')): ?>
                        <a href="<?= base_url('maintenance_work_orders/create') ?>">Create a work order</a> to track repairs and maintenance.
                    <?php else: ?>
                        No work orders match your filters.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </template>

    <!-- Bulk action bar -->
    <div x-show="selectedIds.length > 0"
         x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
         x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
         class="ff-bulk-bar">
        <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
        <div class="ff-bulk-bar-sep"></div>
        <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
            <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
            Delete
        </button>
        <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear" aria-label="Clear selection">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
        </button>
    </div>

    <!-- Table -->
    <template x-if="!loading && rows.length > 0">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th class="th-checkbox">
                            <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all">
                        </th>
                        <th class="th-sortable" @click="setSort('work_order_number')">
                            WO # <span x-show="sort === 'work_order_number'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Unit</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th class="th-sortable" @click="setSort('priority')">
                            Priority <span x-show="sort === 'priority'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('status')">
                            Status <span x-show="sort === 'status'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('requested_date')">
                            Requested <span x-show="sort === 'requested_date'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('total_cost')" style="text-align:right;">
                            Cost <span x-show="sort === 'total_cost'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Vendor</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr class="table-row-link"
                            :class="{ 'ff-row-selected': selectedIds.includes(row.id) }"
                            @click="window.location = '<?= base_url('maintenance_work_orders/show') ?>?id=' + row.id"
                            style="cursor:pointer;">
                            <td class="td-checkbox" @click.stop>
                                <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(row.id)" @change="toggleSelect(row.id)">
                            </td>
                            <td class="font-mono" x-text="row.work_order_number"></td>
                            <td>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + row.equipment_unit_id"
                                   @click.stop
                                   class="link font-mono"
                                   x-text="row.unit_number"></a>
                                <div class="text-secondary" style="font-size:0.75rem;"
                                     x-text="[row.unit_year, row.brand, row.model].filter(Boolean).join(' ')"></div>
                            </td>
                            <td x-text="row.title" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></td>
                            <td>
                                <span class="badge" :class="typeBadge(row.work_type)"
                                      x-text="typeLabel(row.work_type)"></span>
                            </td>
                            <td>
                                <span class="badge" :class="priorityBadge(row.priority)"
                                      x-text="priorityLabel(row.priority)"></span>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadge(row.status)"
                                      x-text="statusLabel(row.status)"></span>
                            </td>
                            <td x-text="row.requested_date ?? '—'"></td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="row.total_cost > 0 ? '$' + parseFloat(row.total_cost).toLocaleString('en-CA', {minimumFractionDigits:2}) : '—'"></td>
                            <td x-text="row.vendor_name || '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </template>

    <!-- Pagination -->
    <template x-if="!loading && totalPages > 1">
        <div class="card-footer" style="display:flex;justify-content:center;gap:8px;padding:12px;">
            <button class="btn btn-secondary btn-sm"
                    :disabled="page <= 1"
                    @click="goPage(page - 1)">← Prev</button>
            <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                  x-text="'Page ' + page + ' of ' + totalPages"></span>
            <button class="btn btn-secondary btn-sm"
                    :disabled="page >= totalPages"
                    @click="goPage(page + 1)">Next →</button>
        </div>
    </template>

    </div><!-- /card -->

</div><!-- /wo-table-card -->

<script>
function woKpis() {
    return {
        activeTile: '',
        kpis: {
            total:                <?= json_encode($kpiTotal) ?>,
            open:                 <?= json_encode($kpiOpen) ?>,
            active:               <?= json_encode($kpiActive) ?>,
            completed_this_month: <?= json_encode($kpiCompleted) ?>,
        },
        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/maintenance_work_orders/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
    };
}

// Allow KPI tiles to set filters from outside Alpine component
function setFilter(key, val) {
    const el = document.getElementById('wo-table-card');
    if (!el || !el._x_dataStack) return;
    const comp = Alpine.$data(el);
    if (!comp) return;
    comp.filters[key] = val;
    comp.page = 1;
    comp.fetch();
}

function woList() {
    return {
        rows:        [],
        total:       0,
        page:        1,
        perPage:     25,
        totalPages:  1,
        loading:     false,
        sort:        'requested_date',
        dir:         'DESC',
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,
        filters: {
            status:    '',
            work_type: '',
            priority:  '',
            q:         '',
        },

        init() {
            this.$watch('page', () => this.clearSelection());
            this.fetch();
        },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({
                page:     this.page,
                per_page: this.perPage,
                sort:     this.sort,
                dir:      this.dir,
            });
            if (this.filters.status)    p.set('status', this.filters.status);
            if (this.filters.work_type) p.set('work_type', this.filters.work_type);
            if (this.filters.priority)  p.set('priority', this.filters.priority);
            if (this.filters.q)         p.set('q', this.filters.q);

            FF_Api.get('<?= base_url('api/v1/maintenance_work_orders/index.php') ?>?' + p.toString())
                .then(d => {
                    if (d && d.error) { this.rows = []; return; }
                    this.rows       = d.data?.items ?? [];
                    this.total      = d.data?.pagination?.total ?? 0;
                    this.totalPages = d.data?.pagination?.total_pages ?? 1;
                })
                .catch(() => { this.rows = []; })
                .finally(() => { this.loading = false; });
        },

        goPage(n) { this.page = n; this.fetch(); },

        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.rows.length > 0 && this.selectedIds.length === this.rows.length;
        },

        toggleSelectAll() {
            if (this.selectAll) { this.selectedIds = []; this.selectAll = false; }
            else { this.selectedIds = this.rows.map(i => i.id); this.selectAll = true; }
        },

        clearSelection() { this.selectedIds = []; this.selectAll = false; },

        async bulkDelete() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask('Delete ' + count + ' work order' + (count === 1 ? '' : 's') + '? This cannot be undone.');
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/maintenance_work_orders/bulk_delete') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' deleted' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0, 3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection(); await this.fetch();
                } else { FF_Toast.error(res.error?.message || 'Bulk delete failed.'); }
            } catch(e) { FF_Toast.error('Network error.'); }
            finally { this.bulkWorking = false; }
        },

        setSort(col) {
            if (this.sort === col) { this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC'; }
            else { this.sort = col; this.dir = 'DESC'; }
            this.page = 1;
            this.fetch();
        },

        sortIcon(col) { return this.sort === col ? (this.dir === 'ASC' ? ' ↑' : ' ↓') : ''; },

        resetFilters() {
            this.filters = { status: '', work_type: '', priority: '', q: '' };
            this.page = 1;
            this.fetch();
        },

        statusBadge(s) {
            return {
                open:          'badge badge-info',
                in_progress:   'badge badge-warning',
                waiting_parts: 'badge badge-warning',
                completed:     'badge badge-success',
                cancelled:     'badge badge-neutral',
            }[s] ?? 'badge badge-neutral';
        },

        statusLabel(s) {
            return {
                open:          'Open',
                in_progress:   'In Progress',
                waiting_parts: 'Waiting Parts',
                completed:     'Completed',
                cancelled:     'Cancelled',
            }[s] ?? s;
        },

        priorityBadge(p) {
            return {
                emergency: 'badge badge-danger',
                high:      'badge badge-warning',
                medium:    'badge badge-info',
                low:       'badge badge-neutral',
            }[p] ?? 'badge badge-neutral';
        },

        priorityLabel(p) {
            return { emergency: 'Emergency', high: 'High', medium: 'Medium', low: 'Low' }[p] ?? p;
        },

        typeBadge(t) {
            return {
                scheduled_service: 'badge badge-info',
                repair:            'badge badge-warning',
                inspection:        'badge badge-neutral',
                tire:              'badge badge-neutral',
                electrical:        'badge badge-warning',
                body_damage:       'badge badge-danger',
                breakdown:         'badge badge-danger',
                other:             'badge badge-neutral',
            }[t] ?? 'badge badge-neutral';
        },

        typeLabel(t) {
            return {
                scheduled_service: 'Scheduled',
                repair:            'Repair',
                inspection:        'Inspection',
                tire:              'Tire',
                electrical:        'Electrical',
                body_damage:       'Body Damage',
                breakdown:         'Breakdown',
                other:             'Other',
            }[t] ?? t;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
