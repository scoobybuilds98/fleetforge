<?php
declare(strict_types=1);

/**
 * FleetForge — Customers List Page
 *
 * @file        app/admin/customers/index.php
 * @description Paginated, filterable list of customers. Displays 4 KPI tiles
 *              (total, active, overdue balance, credit holds) above the table.
 *              Filters: status, risk_score, search. Sort: company name, created, status, risk.
 *              Alpine.js fetches api/v1/customers/index.php on load and on filter change.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/customers/index.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 * @session     S005
 */

// dirname(__DIR__, 3): app/admin/customers/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

$pageTitle = 'Customers';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Customers</h1>
    <div class="page-header-actions">
        <?= help_button('customers') ?>
        <?php if (can('customers', 'create')): ?>
        <a href="<?= base_url('customers/create') ?>" class="btn btn-primary btn-sm">
            + New Customer
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     CUSTOMERS ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Customers()" x-init="init()">

    <!-- ── KPI TILES — all clickable drill-down filters (TILES-1) ── -->
    <div class="stat-grid">

        <div class="stat-card" style="cursor:pointer"
             :class="{ 'ring-active': !filters.status }"
             @click="filters.status = ''; resetPage()">
            <div class="stat-label">Total Customers</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.total"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:55%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card" style="cursor:pointer"
             :class="{ 'ring-active': filters.status === 'active' }"
             @click="filters.status = filters.status === 'active' ? '' : 'active'; resetPage()">
            <div class="stat-label">Active</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.active"></div>
                    <div class="stat-delta text-secondary"
                         x-text="kpis.total > 0 ? Math.round(kpis.active / kpis.total * 100) + '% of total' : ''">
                    </div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:45%;margin-top:8px;"></div>
            </template>
        </div>

        <!-- Overdue Balance drills into /invoices?status=overdue since the
             overdue AR lives on invoices, not customers. -->
        <a class="stat-card" :href="'<?= base_url('invoices') ?>?status=overdue'"
           style="cursor:pointer;text-decoration:none">
            <div class="stat-label">Overdue Balance</div>
            <template x-if="kpisLoaded">
                <div class="stat-value currency"
                     x-text="Number(kpis.overdue_balance) > 0 ? '$' + formatMoney(kpis.overdue_balance) : '$0.00'">
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:65%;margin-top:8px;"></div>
            </template>
        </a>

        <div class="stat-card" style="cursor:pointer"
             :class="{ 'ring-active': filters.status === 'credit_hold' }"
             @click="filters.status = filters.status === 'credit_hold' ? '' : 'credit_hold'; resetPage()">
            <div class="stat-label">Credit Hold</div>
            <template x-if="kpisLoaded">
                <div class="stat-value font-mono" x-text="kpis.credit_hold"></div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:40%;margin-top:8px;"></div>
            </template>
        </div>

    </div>

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search company, contact, email, DOT#, MC#…"
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:260px;"
                   aria-label="Search customers">

            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="resetPage()"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="pending">Pending</option>
                <option value="suspended">Suspended</option>
                <option value="credit_hold">Credit Hold</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.risk_score"
                    @change="resetPage()"
                    aria-label="Filter by risk">
                <option value="">All Risk Levels</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' customer' + (pagination.total !== 1 ? 's' : '')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="resetPage()"
                    aria-label="Sort by">
                <option value="created_at">Newest first</option>
                <option value="company_name">Company name</option>
                <option value="status">Status</option>
                <option value="risk_score">Risk (High → Low)</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading customers…">
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
                <p class="empty-state-title">Failed to load customers</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && customers.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                </div>
                <p class="empty-state-title">No customers found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your filters.' : 'Add your first customer to get started.'">
                </p>
                <?php if (can('customers', 'create')): ?>
                <a href="<?= base_url('customers/create') ?>"
                   class="btn btn-primary btn-sm"
                   x-show="!hasActiveFilters()">
                    + New Customer
                </a>
                <?php endif; ?>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && !loadError && customers.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Customers">
                    <thead>
                        <tr>
                            <th scope="col" class="th-sortable" @click="setSort('company_name')">
                                Company <span x-show="filters.sort === 'company_name'">↑</span>
                            </th>
                            <th scope="col">Contact</th>
                            <th scope="col">Location</th>
                            <th scope="col" class="th-sortable" @click="setSort('status')">
                                Status <span x-show="filters.sort === 'status'">↑</span>
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('risk_score')">
                                Risk <span x-show="filters.sort === 'risk_score'">↑</span>
                            </th>
                            <th scope="col">Leases</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col">Tags</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in customers" :key="c.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + c.id"
                                       class="link font-medium"
                                       x-text="c.company_name"></a>
                                    <div class="text-xs text-secondary" x-text="c.email || ''"></div>
                                </td>
                                <td x-text="c.contact_name || '—'"></td>
                                <td x-text="[c.city, c.province].filter(Boolean).join(', ') || '—'"></td>
                                <td>
                                    <span class="badge"
                                          :class="statusBadgeClass(c.status)"
                                          x-text="c.status.replace(/_/g, ' ')"></span>
                                </td>
                                <td>
                                    <span class="badge"
                                          :class="riskBadgeClass(c.risk_score)"
                                          x-text="c.risk_score"></span>
                                </td>
                                <td>
                                    <span class="font-mono" x-text="c.active_lease_count"></span>
                                    <span class="text-secondary font-mono"
                                          x-text="' / ' + c.lease_count"></span>
                                </td>
                                <td class="text-right">
                                    <span class="currency"
                                          x-text="parseFloat(c.outstanding_balance) > 0
                                              ? '$' + formatMoney(c.outstanding_balance)
                                              : '—'">
                                    </span>
                                </td>
                                <td>
                                    <template x-for="tag in c.tags.slice(0,3)" :key="tag">
                                        <span class="badge badge-neutral"
                                              style="margin-right:3px;"
                                              x-text="tag"></span>
                                    </template>
                                    <span class="text-secondary text-xs"
                                          x-show="c.tags.length > 3"
                                          x-text="'+' + (c.tags.length - 3)">
                                    </span>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + c.id"
                                       class="btn btn-ghost btn-xs">View</a>
                                    <?php if (can('customers', 'edit')): ?>
                                    <a :href="'<?= base_url('customers/edit') ?>?id=' + c.id"
                                       class="btn btn-ghost btn-xs">Edit</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Pagination -->
        <template x-if="!loading && pagination.total_pages > 1">
            <div class="pagination">
                <span class="pagination-info"
                      x-text="'Page ' + pagination.page + ' of ' + pagination.total_pages">
                </span>
                <div class="pagination-controls">
                    <button class="page-btn"
                            :disabled="pagination.page <= 1"
                            @click="goToPage(pagination.page - 1)">← Prev</button>
                    <button class="page-btn"
                            :disabled="!pagination.has_more"
                            @click="goToPage(pagination.page + 1)">Next →</button>
                </div>
            </div>
        </template>

    </div>

</div>

<script>
function FF_Customers() {
    return {
        customers:  [],
        pagination: {},
        kpis:       {},
        kpisLoaded: false,
        filters: {
            search:     '',
            status:     '',
            risk_score: '',
            sort:       'created_at',
        },
        page:      1,
        loading:   true,
        loadError: null,

        init() {
            this.load();
            this.loadKpis();
        },

        async load() {
            this.loading   = true;
            this.loadError = null;

            const params = new URLSearchParams();
            if (this.filters.search)     params.set('search',     this.filters.search);
            if (this.filters.status)     params.set('status',     this.filters.status);
            if (this.filters.risk_score) params.set('risk_score', this.filters.risk_score);
            if (this.filters.sort)       params.set('sort',       this.filters.sort);
            params.set('page',     this.page);
            params.set('per_page', 25);

            try {
                const res  = await fetch(`<?= base_url('api/v1/customers') ?>?` + params.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const json = await res.json();

                if (!res.ok || !json.success) {
                    this.loadError = json.error?.message ?? 'Failed to load customers.';
                    return;
                }

                this.customers  = json.data.items;
                this.pagination = json.data.pagination;
            } catch (e) {
                this.loadError = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async loadKpis() {
            // TILES-1: use the dedicated /api/v1/customers/kpis endpoint so
            // overdue_balance is computed server-side from invoices.balance_due
            // instead of being stubbed to 0 (which made the tile render "—"
            // forever regardless of real overdue AR).
            try {
                const r = await fetch(`<?= base_url('api/v1/customers/kpis') ?>`, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const j = await r.json();
                if (j.success) {
                    this.kpis = {
                        total:           Number(j.data.total)           || 0,
                        active:          Number(j.data.active)          || 0,
                        credit_hold:     Number(j.data.credit_hold)     || 0,
                        // keep as number so x-text comparisons work
                        overdue_balance: Number(j.data.overdue_balance) || 0,
                    };
                }
            } catch (e) {
                /* swallow — kpisLoaded still flips so skeleton doesn't hang */
            } finally {
                this.kpisLoaded = true;
            }
        },

        resetPage() {
            this.page = 1;
            this.load();
        },

        goToPage(n) {
            this.page = n;
            this.load();
        },

        setSort(col) {
            this.filters.sort = col;
            this.resetPage();
        },

        hasActiveFilters() {
            return this.filters.search || this.filters.status || this.filters.risk_score;
        },

        // WHY: badge variants per FLEETFORGE_DESIGN_DETAILS.md §9
        statusBadgeClass(status) {
            return {
                'badge-success': status === 'active',
                'badge-neutral': status === 'inactive',
                'badge-info':    status === 'pending',
                'badge-danger':  status === 'suspended',
                'badge-warning': status === 'credit_hold',
            };
        },

        riskBadgeClass(risk) {
            return {
                'badge-danger':  risk === 'high',
                'badge-warning': risk === 'medium',
                'badge-success': risk === 'low',
            };
        },

        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
