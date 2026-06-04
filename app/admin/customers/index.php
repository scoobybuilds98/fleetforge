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

$pageTitle       = 'Customers';
$helpModuleSlug  = 'customers';
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

        <!-- Bulk action bar -->
        <div x-show="selectedIds.length > 0"
             x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
             x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
             class="ff-bulk-bar">
            <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
            <div class="ff-bulk-bar-sep"></div>
            <button class="ff-bulk-btn" style="background:rgba(34,197,94,0.12);color:#4ade80;" @click="bulkSetStatus('active')" :disabled="bulkWorking">
                <svg width="11" height="12" viewBox="0 0 11 12" fill="currentColor"><path d="M1 1l9 5-9 5V1z"/></svg>
                Activate
            </button>
            <button class="ff-bulk-btn" style="background:rgba(107,114,128,0.15);color:#9ca3af;" @click="bulkSetStatus('inactive')" :disabled="bulkWorking">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="2" y="1" width="3" height="10" rx="1"/><rect x="7" y="1" width="3" height="10" rx="1"/></svg>
                Deactivate
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
        <template x-if="!loading && !loadError && customers.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Customers">
                    <thead>
                        <tr>
                            <th class="th-checkbox">
                                <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all on this page">
                            </th>
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
                            <tr :class="{ 'ff-row-selected': selectedIds.includes(c.id) }">
                                <td class="td-checkbox" @click.stop>
                                    <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(c.id)" @change="toggleSelect(c.id)">
                                </td>
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
        customers:   [],
        pagination:  {},
        kpis:        {},
        kpisLoaded:  false,
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,
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
            this.$watch('page', () => this.clearSelection());
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

        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.customers.length > 0 && this.selectedIds.length === this.customers.length;
        },

        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll = false;
            } else {
                this.selectedIds = this.customers.map(item => item.id);
                this.selectAll = true;
            }
        },

        clearSelection() {
            this.selectedIds = [];
            this.selectAll = false;
        },

        async bulkSetStatus(newStatus) {
            if (this.selectedIds.length === 0 || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const label = newStatus === 'active' ? 'activate' : 'deactivate';
            const confirmed = await FF_Confirm.ask(
                label.charAt(0).toUpperCase() + label.slice(1) + ' ' + count + ' customer' + (count === 1 ? '' : 's') + '? Customers with active leases cannot be deactivated.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/customers/bulk_update_status') ?>', { ids: this.selectedIds, status: newStatus });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' customer' + (d.actioned === 1 ? '' : 's') + ' ' + label + 'd' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0,3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection();
                    await this.load();
                } else {
                    FF_Toast.error(res.error?.message || 'Status update failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error.');
            } finally {
                this.bulkWorking = false;
            }
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
                const res = await FF_Api.post('<?= base_url('api/v1/customers/bulk_delete') ?>', { ids: this.selectedIds });
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
