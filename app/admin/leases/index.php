<?php
declare(strict_types=1);

/**
 * FleetForge — Leases List Page
 *
 * @file        app/admin/leases/index.php
 * @description Paginated, filterable list of leases. A summary strip of the
 *              lists a dispatcher works from (on rent + monthly revenue,
 *              ending within 30 days, starting this week, billing behind —
 *              api/v1/leases/kpis.php; each tile filters the table via
 *              ?focus=, S-LIST-COMPACT). Three tabs in the table toolbar: Active+Pending,
 *              Closed (completed+cancelled), All — every tab paginates
 *              SERVER-SIDE at 20 rows/page (statuses= multi-status API scope;
 *              S-LEASES-PAGINATE-20). Filter toolbar: search, status
 *              (All tab), sort column, direction. Sortable column headers.
 *              Status badges: active=badge-success, pending=badge-info,
 *              completed=badge-neutral, cancelled=badge-danger.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/index.php,
 *              api/v1/leases/kpis.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D30 (asset_url), D32 (CSS classes), D33 (heroicons)
 * @session     S007, S017
 */

// dirname(__DIR__, 3): app/admin/leases/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'view');

$pageTitle      = 'Leases';
$helpModuleSlug = 'leases';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header — module hero (S-MODULE-CHROME, lib/Ui/ModuleHero.php)
     ============================================================ -->
<?php ob_start(); ?>
        <?= help_button('leases') ?>
        <?php if (can('leases', 'create')): ?>
        <a href="<?= base_url('leases/create') ?>" class="btn btn-primary btn-sm">
            + New Lease
        </a>
        <?php endif; ?>
<?= \FleetForge\Ui\ModuleHero::render([
    'crumbs'   => [['Dashboard', base_url('dashboard')], ['Leases', null]],
    'eyebrow'  => 'Operations',
    'icon'     => 'calendar-days',
    'accent'   => 'warning',
    'title'    => 'Leases',
    'subtitle' => 'Every rental from reservation to return — rates, mileage, billing and close-out.',
    'art'      => 'leases',
    'actions'  => ob_get_clean(),
]) ?>

<!-- ============================================================
     LEASES ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Leases()">

    <!-- ── SUMMARY STRIP (S-LIST-COMPACT) ─────────────────────────
         The lists a dispatcher works from — not the status counts, which
         the status tabs below already carry. Each tile filters the table
         to exactly its leases (?focus=, same windows as the counts:
         api/v1/leases/_focus.php). -->
    <div class="stat-grid stat-grid--4 ff-stats">

        <button type="button" class="stat-card stat-card--green" @click="setFocus('')">
            <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
            <div class="stat-label">On rent now</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.active"></div>
                    <div class="stat-delta"
                         x-text="kpis.active_revenue !== null ? '$' + formatMoney(kpis.active_revenue) + ' / month' : (kpis.pending + ' pending')"></div>
                </div>
            </template>
            <template x-if="!kpisLoaded"><div class="skeleton skeleton-text-lg" style="width:45%;"></div></template>
        </button>

        <button type="button" class="stat-card stat-card--amber"
                :class="{ 'ring-active': filters.focus === 'ending' }"
                @click="setFocus('ending')">
            <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
            <div class="stat-label">Ending within 30 days</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.ending"></div>
                    <div class="stat-delta" :class="kpis.overdue > 0 ? 'text-danger' : ''"
                         x-text="kpis.overdue > 0 ? kpis.overdue + ' already past end date' : 'none past their end date'"></div>
                </div>
            </template>
            <template x-if="!kpisLoaded"><div class="skeleton skeleton-text-lg" style="width:40%;"></div></template>
        </button>

        <button type="button" class="stat-card stat-card--blue"
                :class="{ 'ring-active': filters.focus === 'starting' }"
                @click="setFocus('starting')">
            <span class="stat-icon stat-icon--blue"><svg><use href="#icon-truck"/></svg></span>
            <div class="stat-label">Starting this week</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.starting"></div>
                    <div class="stat-delta" x-text="kpis.pending + ' pending activation in total'"></div>
                </div>
            </template>
            <template x-if="!kpisLoaded"><div class="skeleton skeleton-text-lg" style="width:40%;"></div></template>
        </button>

        <button type="button" class="stat-card stat-card--red"
                :class="{ 'ring-active': filters.focus === 'unbilled' }"
                @click="setFocus('unbilled')">
            <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
            <div class="stat-label">Billing behind</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.unbilled"></div>
                    <div class="stat-delta">active leases with unbilled days</div>
                </div>
            </template>
            <template x-if="!kpisLoaded"><div class="skeleton skeleton-text-lg" style="width:40%;"></div></template>
        </button>

    </div>

    <!-- ── TABLE TOOLBAR — status tabs, search, sort, pager ─────────
         The status tabs live in the table's own heading (S-LIST-COMPACT);
         the page-wide tab bar and the full-width pager row above the
         rows are gone. -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <div class="tab-bar" role="tablist" aria-label="Lease status">
                <button class="tab-btn" :class="{ 'is-active': activeTab === 'open' }"
                        @click="setTab('open')" :aria-selected="activeTab === 'open'" role="tab">
                    Active &amp; Pending
                    <span class="tab-badge" x-show="kpisLoaded" x-text="kpis.active + kpis.pending"></span>
                </button>
                <button class="tab-btn" :class="{ 'is-active': activeTab === 'closed' }"
                        @click="setTab('closed')" :aria-selected="activeTab === 'closed'" role="tab">
                    Closed
                    <span class="tab-badge" x-show="kpisLoaded" x-text="kpis.closed"></span>
                </button>
                <button class="tab-btn" :class="{ 'is-active': activeTab === 'all' }"
                        @click="setTab('all')" :aria-selected="activeTab === 'all'" role="tab">
                    All
                </button>
            </div>

            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search contract, company, unit…"
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:200px;"
                   aria-label="Search leases">

            <!-- Status filter — only shown on All tab -->
            <select class="form-select form-control-sm"
                    x-show="activeTab === 'all'"
                    x-model="filters.status"
                    @change="resetPage()"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>

            <!-- The summary tile this list is narrowed to (click ✕ to clear) -->
            <button type="button" class="ff-focus-chip" x-show="filters.focus" x-cloak
                    @click="setFocus('')" :title="'Showing ' + focusLabel() + ' — click to show every lease'">
                <span x-text="focusLabel()"></span>
                <span aria-hidden="true">✕</span>
            </button>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' lease' + (pagination.total !== 1 ? 's' : '')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="if (filters.sort !== 'company_name_snapshot') filters.customer_filter = ''; resetPage()"
                    aria-label="Sort by">
                <optgroup label="Date">
                    <option value="created_at">Date created</option>
                    <option value="updated_at">Last updated</option>
                    <option value="start_date">Start date</option>
                    <option value="end_date">End date</option>
                    <option value="next_billing_date">Next billing date</option>
                    <option value="billed_through">Billed through</option>
                </optgroup>
                <optgroup label="Identifier">
                    <option value="contract_number">Contract #</option>
                    <option value="company_name_snapshot">Customer name</option>
                    <option value="status">Status</option>
                </optgroup>
                <optgroup label="Financial">
                    <option value="outstanding_balance">Outstanding balance</option>
                    <option value="total_invoiced">Total invoiced</option>
                    <option value="monthly_rate">Monthly rate</option>
                </optgroup>
            </select>

            <!-- Contextual customer filter — appears when sorting by customer name -->
            <input x-show="filters.sort === 'company_name_snapshot'"
                   x-transition
                   type="search"
                   class="form-control form-control-sm"
                   placeholder="Filter by customer…"
                   x-model="filters.customer_filter"
                   @input.debounce.350ms="resetPage()"
                   style="min-width:160px;"
                   aria-label="Filter by customer name">

            <select class="form-select form-control-sm"
                    x-model="filters.dir"
                    @change="resetPage()"
                    aria-label="Direction"
                    style="width:auto;">
                <option value="DESC">↓ Desc</option>
                <option value="ASC">↑ Asc</option>
            </select>

            <?php $position = 'toolbar'; require FF_ROOT . '/includes/partials/pagination-bar.php'; ?>
        </div>

    </div>

    <!-- ── TABLE CARD ────────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading leases…">
                <template x-for="n in 6" :key="n">
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
                <p class="empty-state-title">Failed to load leases</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && leases.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/></svg>
                </div>
                <p class="empty-state-title">No leases found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your filters.' : 'Create a lease to get started.'">
                </p>
                <?php if (can('leases', 'create')): ?>
                <a href="<?= base_url('leases/create') ?>" class="btn btn-primary btn-sm"
                   x-show="!hasActiveFilters()">
                    + New Lease
                </a>
                <?php endif; ?>
            </div>
        </template>

        <!-- Bulk action bar — visible when one or more rows are checked -->
        <div x-show="selectedIds.length > 0"
             x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
             x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
             class="ff-bulk-bar">
            <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn"
                    style="background:rgba(249,115,22,0.12);color:var(--color-primary-text,#fb923c);"
                    @click="bulkClose()" :disabled="bulkWorking">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><polyline points="1,6 4.5,9.5 11,2"/></svg>
                Close leases
            </button>
            <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
                <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor" aria-hidden="true"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
                Delete
            </button>
            <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear selection" aria-label="Clear selection">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
            </button>
        </div>

        <!-- Table -->
        <template x-if="!loading && !loadError && leases.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Leases">
                    <thead>
                        <tr>
                            <th class="th-checkbox">
                                <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all on this page">
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('contract_number')">
                                Contract
                                <span x-show="filters.sort === 'contract_number'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col">Customer</th>
                            <th scope="col">Unit</th>
                            <th scope="col" class="th-sortable" @click="setSort('start_date')">
                                Dates
                                <span x-show="filters.sort === 'start_date'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('billed_through')"
                                title="Latest invoice coverage (non-void) — how far this unit has been billed">
                                Billed Thru
                                <span x-show="filters.sort === 'billed_through'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col">Rates</th>
                            <th scope="col" class="th-sortable" @click="setSort('status')">
                                Status
                                <span x-show="filters.sort === 'status'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="text-right">Balance</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="lease in leases" :key="lease.id">
                            <tr :class="{ 'ff-row-selected': selectedIds.includes(lease.id) }">
                                <td class="td-checkbox" @click.stop>
                                    <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(lease.id)" @change="toggleSelect(lease.id)">
                                </td>
                                <td>
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + lease.id"
                                       class="font-mono font-medium link"
                                       x-text="lease.contract_number">
                                    </a>
                                    <div class="text-xs text-secondary"
                                         x-text="lease.po_number ? 'PO: ' + lease.po_number : ''"></div>
                                </td>
                                <td>
                                    <div class="font-medium"
                                         x-text="lease.customer_display_name || lease.company_name_snapshot"></div>
                                </td>
                                <td>
                                    <div class="font-mono"
                                         x-text="lease.unit_display_number || lease.unit_number_snapshot"></div>
                                    <div class="text-xs text-secondary"
                                         x-text="lease.template_name_snapshot"></div>
                                </td>
                                <td class="text-sm">
                                    <div x-text="formatDate(lease.start_date)"></div>
                                    <div class="text-secondary"
                                         x-text="lease.end_date ? '→ ' + formatDate(lease.end_date) : 'Open-ended'">
                                    </div>
                                </td>
                                <td class="text-sm">
                                    <!-- Billed-through: live invoice coverage. Amber on an
                                         ACTIVE lease whose coverage is behind today = unbilled
                                         usage the operator should invoice. -->
                                    <span :class="billedThroughBehind(lease) ? 'text-warning' : (lease.billed_through ? '' : 'text-secondary')"
                                          :title="billedThroughBehind(lease) ? 'Coverage is behind today — there is unbilled usage on this lease' : ''"
                                          x-text="lease.billed_through ? formatDate(lease.billed_through) : (lease.status === 'active' ? 'Not billed' : '—')">
                                    </span>
                                </td>
                                <td class="font-mono text-sm">
                                    <template x-if="parseFloat(lease.monthly_rate) > 0">
                                        <span x-text="'$' + parseFloat(lease.monthly_rate).toFixed(2) + '/mo'"></span>
                                    </template>
                                    <template x-if="parseFloat(lease.monthly_rate) <= 0 && parseFloat(lease.daily_rate) > 0">
                                        <span x-text="'$' + parseFloat(lease.daily_rate).toFixed(2) + '/day'"></span>
                                    </template>
                                    <template x-if="parseFloat(lease.monthly_rate) <= 0 && parseFloat(lease.daily_rate) <= 0">
                                        <span class="text-secondary">—</span>
                                    </template>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="statusBadgeClass(lease.status)"
                                          x-text="lease.status.charAt(0).toUpperCase() + lease.status.slice(1)">
                                    </span>
                                </td>
                                <td class="text-right font-mono text-sm">
                                    <span :class="parseFloat(lease.outstanding_balance) > 0 ? 'text-danger' : 'text-secondary'"
                                          x-text="parseFloat(lease.outstanding_balance) > 0
                                              ? '$' + formatMoney(lease.outstanding_balance)
                                              : '—'">
                                    </span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + lease.id"
                                       class="btn btn-ghost btn-xs">View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Bottom pagination — every tab paginates server-side (20 per page) -->
        <?php $position = 'bottom'; require FF_ROOT . '/includes/partials/pagination-bar.php'; ?>

    </div>

</div><!-- /x-data -->


<script>
function FF_Leases() {
    return {
        leases:      [],
        loading:     true,
        loadError:   null,
        pagination:  {},
        activeTab:   'open',

        // KPI tile data — loaded once on init, independent of table filters
        kpis:        { active: 0, pending: 0, closed: 0, ending: 0, overdue: 0, starting: 0, unbilled: 0, active_revenue: null },
        kpisLoaded:  false,

        filters: {
            search:          '',
            status:          '',
            sort:            'created_at',
            dir:             'DESC',
            customer_filter: '',
            focus:           '',   // ending | starting | unbilled (summary strip)
        },
        currentPage: 1,

        // Bulk selection state
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,

        async init() {
            // Restore last-visited tab from URL hash.
            const _h = FF_TabHash.init(['open','closed','all'], 'open');
            if (_h !== this.activeTab) this.activeTab = _h;
            FF_TabHash.write(this.activeTab);
            FF_TabHash.watchUnload(() => this.activeTab);
            this.$nextTick(() => FF_TabHash.restoreScroll(this.activeTab));

            this.load();
            this.loadKpis();
            // Clear selection whenever pagination changes
            this.$watch('currentPage', () => this.clearSelection());
        },

        // Summary strip + tab counts — one call (api/v1/leases/kpis.php).
        async loadKpis() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases/kpis') ?>');
                if (r.success) this.kpis = Object.assign(this.kpis, r.data);
            } catch (e) { /* show zeros rather than a skeleton forever */ }
            this.kpisLoaded = true;
        },

        // A summary tile narrows the table to its leases (server-side
        // ?focus=, the same window as the tile's count) and sorts by the
        // date that matters for it. '' = back to the plain Active & Pending list.
        setFocus(key) {
            const sortFor = { ending: ['end_date', 'ASC'], starting: ['start_date', 'ASC'], unbilled: ['billed_through', 'ASC'] };
            this.filters.focus = (key && this.filters.focus !== key) ? key : '';
            this.activeTab     = 'open';
            this.filters.status = '';
            FF_TabHash.write('open');
            if (this.filters.focus) {
                [this.filters.sort, this.filters.dir] = sortFor[this.filters.focus];
            } else {
                this.filters.sort = 'created_at'; this.filters.dir = 'DESC';
            }
            this.resetPage();
        },

        focusLabel() {
            return { ending: 'Ending within 30 days', starting: 'Starting this week', unbilled: 'Billing behind' }[this.filters.focus] || '';
        },

        setTab(tab) {
            FF_TabHash.save(this.activeTab); // persist scroll before leaving
            this.activeTab      = tab;
            FF_TabHash.write(tab);           // keep hash in sync
            this.filters.status = '';
            this.filters.focus  = '';
            this.currentPage    = 1;
            this.load();
        },

        async load() {
            this.clearSelection();
            this.loading   = true;
            this.loadError = null;

            const params = new URLSearchParams();

            // Tab drives the status scope, server-side (statuses=a,b): every
            // tab paginates properly — 20 rows per page with true totals —
            // instead of the old over-fetch-200-and-filter-client-side, whose
            // page counts were wrong for open/closed and which stopped
            // scaling past 200 leases.
            if (this.activeTab === 'open') {
                params.set('statuses', 'active,pending');
            } else if (this.activeTab === 'closed') {
                params.set('statuses', 'completed,cancelled');
            } else if (this.filters.status) {
                params.set('status', this.filters.status);
            }

            if (this.filters.focus)   params.set('focus',    this.filters.focus);
            if (this.filters.search)  params.set('search',   this.filters.search);
            params.set('sort',     this.filters.sort);
            params.set('dir',      this.filters.dir);
            if (this.filters.customer_filter) params.set('customer_filter', this.filters.customer_filter);
            params.set('page',     this.currentPage);
            params.set('per_page', 20);

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases') ?>?' + params);
                if (r.success) {
                    this.leases     = r.data.items;
                    this.pagination = r.data.pagination;
                } else {
                    this.loadError = r.error?.message || 'Failed to load leases.';
                }
            } catch(e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        resetPage() { this.currentPage = 1; this.load(); },

        // Clamp p to [1, total_pages] so First/Last/Prev/Next and the jump input
        // can never request an out-of-range page. No-op (no refetch) if already there.
        goToPage(p) {
            const tp = parseInt(this.pagination.total_pages, 10) || 1;
            p = Math.max(1, Math.min(tp, parseInt(p, 10) || 1));
            if (p === this.currentPage) return;
            this.currentPage = p;
            this.load();
        },

        // "Jump to page" number input: clamp the typed value, snap the field back
        // to the clamped value (so a bogus 9999 visibly corrects), then navigate.
        jumpToPage(ev) {
            const tp = parseInt(this.pagination.total_pages, 10) || 1;
            let p = parseInt(ev.target.value, 10);
            if (isNaN(p)) p = parseInt(this.pagination.page, 10) || 1;
            p = Math.max(1, Math.min(tp, p));
            ev.target.value = p;
            this.goToPage(p);
        },

        // Toggle sort direction when clicking the same column; default DESC for new column.
        setSort(col) {
            if (this.filters.sort === col) {
                this.filters.dir = this.filters.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.filters.sort = col;
                this.filters.dir  = col === 'contract_number' ? 'ASC' : 'DESC';
            }
            this.resetPage();
        },

        hasActiveFilters() {
            return this.filters.search || this.filters.status || this.filters.focus;
        },

        statusBadgeClass(status) {
            const map = {
                active:    'badge-success',
                pending:   'badge-info',
                completed: 'badge-neutral',
                cancelled: 'badge-danger',
            };
            return map[status] || 'badge-neutral';
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        /**
         * True when an ACTIVE lease's invoice coverage ends before today —
         * i.e. there is usage nobody has billed yet (or nothing billed at
         * all). Completed/cancelled leases never flag: their coverage is
         * settled at close. Date-string compare is safe (YYYY-MM-DD).
         */
        billedThroughBehind(lease) {
            if (lease.status !== 'active') return false;
            const today = new Date().toLocaleDateString('en-CA', { year:'numeric', month:'2-digit', day:'2-digit' }).slice(0, 10);
            return !lease.billed_through || lease.billed_through < today;
        },

        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // --- Bulk selection methods ---

        /** Toggle a single row in/out of the selection set. */
        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.leases.length > 0 && this.selectedIds.length === this.leases.length;
        },

        /** Select all visible rows, or deselect all if already all selected. */
        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll   = false;
            } else {
                this.selectedIds = this.leases.map(item => item.id);
                this.selectAll   = true;
            }
        },

        /** Clear all selections. */
        clearSelection() {
            this.selectedIds = [];
            this.selectAll   = false;
        },

        async bulkClose() {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                'Close ' + count + ' lease' + (count === 1 ? '' : 's') + '? Return date will be set to today. Leases with precharge balances must be closed individually.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/leases/bulk_close') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' lease' + (d.actioned === 1 ? '' : 's') + ' closed' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' could not be closed: ' + d.errors.slice(0,3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Bulk close failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error during bulk close.');
            } finally {
                this.bulkWorking = false;
            }
        },

        /** Confirm and POST selected IDs to the bulk-delete endpoint. */
        async bulkDelete() {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count     = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                'Delete ' + count + ' item' + (count === 1 ? '' : 's') + '? This cannot be undone.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/leases/bulk_delete') ?>', { ids: this.selectedIds });
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
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
