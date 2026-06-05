<?php
declare(strict_types=1);

/**
 * app/admin/credit_applications/index.php
 *
 * Credit Applications — module index page.
 *
 * Alpine.js component (FF_CreditApps) backed by api/v1/credit_applications/all.php.
 * Displays 4 KPI tiles + a filterable, sortable, paginated table of all applications
 * across all customers.
 *
 * Trap 7: token_hash / signature_path / form_data / rendered_html / submitted_ip
 * never selected, never rendered — enforced in the API endpoint.
 *
 * Permission: customers:view (credit applications are a customer sub-resource
 * per D-CCA-PERM).
 *
 * @session S-CCA-MODULE
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('customers', 'view');

$pageTitle      = 'Credit Applications';
$helpModuleSlug = 'credit-applications';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <h1 class="page-header-title h4">Credit Applications</h1>
    <div class="page-header-actions">
        <a href="<?= base_url('settings?tab=credit_application') ?>"
           class="btn btn-ghost btn-sm">⚙ Settings</a>
        <?= help_button('credit-applications') ?>
    </div>
</div>

<!-- ============================================================
     CREDIT APPLICATIONS ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_CreditApps()" x-init="init()">

    <!-- ── KPI TILES ─────────────────────────────────────────── -->
    <div class="stat-grid">

        <div class="stat-card stat-card--amber" style="cursor:pointer;"
             :class="{ 'ring-active': filters.status === 'submitted' }"
             @click="setStatusFilter('submitted')">
            <span class="stat-icon stat-icon--amber">
                <?= heroicon('clipboard-document-check') ?>
            </span>
            <div class="stat-label">Awaiting Review</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.awaiting_review"></div>
                    <div class="stat-delta">submitted, not yet reviewed</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:50%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--blue" style="cursor:pointer;"
             :class="{ 'ring-active': filters.status === '' && !filters.outcome }"
             @click="setStatusFilter('')">
            <span class="stat-icon stat-icon--blue">
                <?= heroicon('document-text') ?>
            </span>
            <div class="stat-label">Total Submitted</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.submitted_total"></div>
                    <div class="stat-delta">submitted + reviewed</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:50%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--teal" style="cursor:pointer;"
             :class="{ 'ring-active': filters.status === 'reviewed' }"
             @click="setStatusFilter('reviewed')">
            <span class="stat-icon stat-icon--teal">
                <?= heroicon('shield-check') ?>
            </span>
            <div class="stat-label">Reviewed</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.reviewed"></div>
                    <div class="stat-delta">approved / declined / needs info</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:50%;margin-top:8px;"></div>
            </template>
        </div>

        <div class="stat-card stat-card--purple" style="cursor:pointer;"
             :class="{ 'ring-active': !filters.status && !filters.q && !filters.outcome }"
             @click="clearFilters()">
            <span class="stat-icon stat-icon--purple">
                <?= heroicon('clipboard-document-list') ?>
            </span>
            <div class="stat-label">All Applications</div>
            <template x-if="kpisLoaded">
                <div>
                    <div class="stat-value font-mono" x-text="kpis.total"></div>
                    <div class="stat-delta">across all customers</div>
                </div>
            </template>
            <template x-if="!kpisLoaded">
                <div class="skeleton skeleton-text-lg" style="width:50%;margin-top:8px;"></div>
            </template>
        </div>

    </div>

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search customer or applicant…"
                   x-model="filters.q"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:220px;"
                   aria-label="Search credit applications">

            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="resetPage()"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="sent">Sent</option>
                <option value="opened">Opened</option>
                <option value="submitted">Submitted</option>
                <option value="reviewed">Reviewed</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.outcome"
                    @change="resetPage()"
                    aria-label="Filter by outcome">
                <option value="">All Outcomes</option>
                <option value="approved">Approved</option>
                <option value="declined">Declined</option>
                <option value="needs_info">Needs Info</option>
            </select>

            <input type="date"
                   class="form-control form-control-sm"
                   x-model="filters.date_from"
                   @change="resetPage()"
                   title="From date"
                   aria-label="From date"
                   style="width:auto;">
            <input type="date"
                   class="form-control form-control-sm"
                   x-model="filters.date_to"
                   @change="resetPage()"
                   title="To date"
                   aria-label="To date"
                   style="width:auto;">

            <button class="btn btn-ghost btn-sm"
                    x-show="hasActiveFilters()"
                    @click="clearFilters()">
                Clear
            </button>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' application' + (pagination.total !== 1 ? 's' : '')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="resetPage()"
                    aria-label="Sort by">
                <optgroup label="Date">
                    <option value="created_at">Date created</option>
                    <option value="sent_at">Date sent</option>
                    <option value="submitted_at">Date submitted</option>
                    <option value="reviewed_at">Date reviewed</option>
                </optgroup>
                <optgroup label="Other">
                    <option value="company_name">Customer name</option>
                    <option value="status">Status</option>
                </optgroup>
            </select>
            <select class="form-select form-control-sm"
                    x-model="filters.dir"
                    @change="resetPage()"
                    aria-label="Sort direction"
                    style="width:auto;">
                <option value="DESC">↓ Desc</option>
                <option value="ASC">↑ Asc</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading credit applications…">
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
                <p class="empty-state-title">Failed to load credit applications</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && apps.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <?= heroicon('clipboard-document-list', 'width="48" height="48" stroke-width="1.5"') ?>
                </div>
                <p class="empty-state-title">No credit applications found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your filters.' : 'Send a credit application from a customer profile to get started.'">
                </p>
                <button class="btn btn-ghost btn-sm"
                        x-show="hasActiveFilters()"
                        @click="clearFilters()">
                    Clear filters
                </button>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && !loadError && apps.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Credit Applications">
                    <thead>
                        <tr>
                            <th scope="col">Customer</th>
                            <th scope="col">Status</th>
                            <th scope="col">Sent By</th>
                            <th scope="col">Sent</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Approved Limit</th>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="app in apps" :key="app.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + app.customer_id"
                                       class="link font-medium"
                                       x-text="app.company_name"></a>
                                    <div class="text-xs text-secondary"
                                         x-show="app.print_name_first || app.print_name_last"
                                         x-text="[app.print_name_first, app.print_name_last].filter(Boolean).join(' ')">
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="statusBadgeClass(app.status)"
                                          x-text="statusLabel(app.status)"></span>
                                    <div class="text-xs text-secondary"
                                         x-show="app.is_expired">expired</div>
                                </td>
                                <td class="text-secondary" x-text="app.sent_by_name || '—'"></td>
                                <td class="text-secondary" style="font-size:0.75rem;white-space:nowrap;"
                                    x-text="formatDate(app.sent_at)"></td>
                                <td class="text-secondary" style="font-size:0.75rem;white-space:nowrap;"
                                    x-text="formatDate(app.submitted_at)"></td>
                                <td>
                                    <span x-show="app.review_outcome"
                                          style="font-size:0.78rem;font-weight:600;"
                                          :style="'color:' + outcomeColor(app.review_outcome)"
                                          x-text="outcomeLabel(app.review_outcome)"></span>
                                    <span x-show="!app.review_outcome"
                                          class="text-secondary">—</span>
                                </td>
                                <td style="font-size:0.78rem;"
                                    x-text="app.approved_credit_limit ? formatMoney(app.approved_credit_limit) : '—'">
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <!-- Show "View" for submitted/reviewed (filed) apps.
                                         show.php renders rendered_html snapshot + review panel
                                         for any app in these statuses, with or without a PDF. -->
                                    <a x-show="app.status === 'submitted' || app.status === 'reviewed'"
                                       :href="'<?= base_url('credit_applications/show') ?>?id=' + app.id + '&from=module'"
                                       class="btn btn-ghost btn-xs">View</a>
                                    <a :href="'<?= base_url('customers/show') ?>?id=' + app.customer_id"
                                       class="btn btn-ghost btn-xs">Customer →</a>
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

    </div><!-- /card -->

</div><!-- /x-data -->

<script>
function FF_CreditApps() {
    return {
        apps:        [],
        pagination:  {},
        kpis:        {},
        kpisLoaded:  false,
        filters: {
            q:         '',
            status:    '',
            outcome:   '',
            date_from: '',
            date_to:   '',
            sort:      'created_at',
            dir:       'DESC',
        },
        page:      1,
        loading:   true,
        loadError: null,

        init() {
            this.load();
        },

        async load() {
            this.loading   = true;
            this.loadError = null;

            const params = new URLSearchParams();
            if (this.filters.q)         params.set('q',         this.filters.q);
            if (this.filters.status)    params.set('status',    this.filters.status);
            if (this.filters.outcome)   params.set('outcome',   this.filters.outcome);
            if (this.filters.date_from) params.set('date_from', this.filters.date_from);
            if (this.filters.date_to)   params.set('date_to',   this.filters.date_to);
            if (this.filters.sort)      params.set('sort',       this.filters.sort);
            if (this.filters.dir)       params.set('dir',        this.filters.dir);
            params.set('page',     this.page);
            params.set('per_page', 25);

            try {
                const res  = await fetch(
                    `<?= base_url('api/v1/credit_applications/all') ?>?` + params.toString(),
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
                );
                const json = await res.json();

                if (!res.ok || !json.success) {
                    this.loadError = json.error?.message ?? 'Failed to load credit applications.';
                    return;
                }

                this.apps       = json.data.items;
                this.pagination = json.data.pagination;

                // KPIs are always global counts — load once and cache.
                if (!this.kpisLoaded && json.data.kpis) {
                    this.kpis      = json.data.kpis;
                    this.kpisLoaded = true;
                }
            } catch (e) {
                this.loadError = 'Network error. Please try again.';
            } finally {
                this.loading = false;
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

        // WHY: clicking a KPI tile toggles the status filter so the tile acts
        // as a drill-down shortcut — click twice to clear back to "all".
        setStatusFilter(status) {
            this.filters.status = (this.filters.status === status) ? '' : status;
            this.resetPage();
        },

        clearFilters() {
            this.filters.q         = '';
            this.filters.status    = '';
            this.filters.outcome   = '';
            this.filters.date_from = '';
            this.filters.date_to   = '';
            this.resetPage();
        },

        hasActiveFilters() {
            return !!(this.filters.q || this.filters.status || this.filters.outcome
                   || this.filters.date_from || this.filters.date_to);
        },

        formatDate(dt) {
            if (!dt) return '—';
            // Parse as local time by replacing space with T for ISO 8601 compatibility.
            const d = new Date(dt.replace(' ', 'T'));
            return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
        },

        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // WHY: badge variants match the status lifecycle colour convention.
        statusBadgeClass(status) {
            return {
                'badge-info':    status === 'sent',
                'badge-warning': status === 'opened',
                'badge-primary': status === 'submitted',
                'badge-success': status === 'reviewed',
            };
        },

        statusLabel(status) {
            const map = { sent: 'Sent', opened: 'Opened', submitted: 'Submitted', reviewed: 'Reviewed' };
            return map[status] || status;
        },

        outcomeColor(outcome) {
            const map = { approved: '#16a34a', declined: '#dc2626', needs_info: '#d97706' };
            return map[outcome] || 'var(--text-secondary)';
        },

        outcomeLabel(outcome) {
            if (!outcome) return '—';
            const map = { approved: 'Approved', declined: 'Declined', needs_info: 'Needs Info' };
            return map[outcome] || outcome;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
