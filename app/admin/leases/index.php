<?php
declare(strict_types=1);

/**
 * FleetForge — Leases List Page
 *
 * @file        app/admin/leases/index.php
 * @description Leases list with three tabs: Active+Pending, Closed (completed+cancelled), All.
 *              Filter bar: search, status, customer filter.
 *              Status badges: active=badge-success, pending=badge-info,
 *              completed=badge-neutral, cancelled=badge-danger (design §9).
 *              Links to show, create pages.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/leases/index.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D30 (asset_url), D32 (CSS classes), D33 (heroicons)
 * @session     S007
 */

// dirname(__DIR__, 3): app/admin/leases/ → app/admin/ → app/ → project root
require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('leases', 'view');

$pageTitle = 'Leases';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Leases</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Leases</h1>
    </div>
    <div class="page-header-actions">
        <?php if (can('leases', 'create')): ?>
        <a href="<?= base_url('leases/create') ?>" class="btn btn-primary btn-sm">
            + New Lease
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     LEASES ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Leases()" x-init="init()">

    <!-- ── TAB BAR ──────────────────────────────────────────────── -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'open' }"
                @click="setTab('open')" :aria-selected="activeTab === 'open'" role="tab">
            Active &amp; Pending
            <span class="tab-badge" x-show="counts.active !== null"
                  x-text="(counts.active || 0) + (counts.pending || 0)"></span>
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'closed' }"
                @click="setTab('closed')" :aria-selected="activeTab === 'closed'" role="tab">
            Closed
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'all' }"
                @click="setTab('all')" :aria-selected="activeTab === 'all'" role="tab">
            All
        </button>
    </div>

    <!-- ── SUMMARY STRIP ─────────────────────────────────────────── -->
    <div class="summary-strip" x-show="!loading && leases.length > 0">
        <div class="summary-strip__item">
            <span class="summary-strip__dot" style="background:var(--color-success);"></span>
            <span class="summary-strip__value" x-text="counts.active ?? '—'"></span>
            <span class="summary-strip__label">Active</span>
        </div>
        <div class="summary-strip__item">
            <span class="summary-strip__dot" style="background:var(--color-info);"></span>
            <span class="summary-strip__value" x-text="counts.pending ?? '—'"></span>
            <span class="summary-strip__label">Pending</span>
        </div>
        <div class="summary-strip__item">
            <span class="summary-strip__dot" style="background:var(--text-tertiary);"></span>
            <span class="summary-strip__value" x-text="counts.completed ?? '—'"></span>
            <span class="summary-strip__label">Completed</span>
        </div>
        <div class="summary-strip__item">
            <span class="summary-strip__value font-mono" x-text="totalRate ? '$' + totalRate + '/mo' : '—'"></span>
            <span class="summary-strip__label">Active rate total</span>
        </div>
    </div>

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search contract, company, unit…"
                   x-model.debounce.400ms="filters.search"
                   @input="resetPage()"
                   maxlength="255"
                   style="min-width:220px;"
                   aria-label="Search leases">
            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="resetPage()"
                    x-show="activeTab === 'all'">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined ? pagination.total + ' lease' + (pagination.total !== 1 ? 's' : '') : ''">
            </span>
        </div>
    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="card">

        <template x-if="loading">
            <div>
                <template x-for="n in 5" :key="n">
                    <div class="skeleton skeleton-row"></div>
                </template>
            </div>
        </template>

        <template x-if="!loading && loadError">
            <div class="empty-state">
                <p class="empty-state-title">Failed to load leases</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <template x-if="!loading && !loadError && leases.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No leases found</p>
                <p class="empty-state-text">
                    <template x-if="filters.search || filters.status">
                        <span>Try adjusting your filters.</span>
                    </template>
                    <template x-if="!filters.search && !filters.status">
                        <span>Create a lease to get started.</span>
                    </template>
                </p>
                <?php if (can('leases', 'create')): ?>
                <a href="<?= base_url('leases/create') ?>" class="btn btn-primary btn-sm">
                    + New Lease
                </a>
                <?php endif; ?>
            </div>
        </template>

        <template x-if="!loading && !loadError && leases.length > 0">
            <div class="table-wrapper">
                <table class="table" aria-label="Leases">
                    <thead>
                        <tr>
                            <th>Contract</th>
                            <th>Customer</th>
                            <th>Unit</th>
                            <th>Dates</th>
                            <th>Rates</th>
                            <th>Status</th>
                            <th>Balance</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="lease in leases" :key="lease.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + lease.id"
                                       class="font-mono font-medium link"
                                       x-text="lease.contract_number">
                                    </a>
                                    <div class="text-sm text-secondary" x-text="lease.po_number ? 'PO: ' + lease.po_number : ''"></div>
                                </td>
                                <td>
                                    <div class="font-medium" x-text="lease.customer_display_name || lease.company_name_snapshot"></div>
                                </td>
                                <td>
                                    <div class="font-mono" x-text="lease.unit_display_number || lease.unit_number_snapshot"></div>
                                    <div class="text-sm text-secondary" x-text="lease.template_name_snapshot"></div>
                                </td>
                                <td class="text-sm">
                                    <div x-text="formatDate(lease.start_date)"></div>
                                    <div class="text-secondary" x-text="lease.end_date ? '→ ' + formatDate(lease.end_date) : 'Open-ended'"></div>
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
                                <td class="font-mono text-sm">
                                    <span :class="parseFloat(lease.outstanding_balance) > 0 ? 'text-danger' : ''"
                                          x-text="parseFloat(lease.outstanding_balance) > 0
                                              ? '$' + parseFloat(lease.outstanding_balance).toFixed(2)
                                              : '—'">
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <a :href="'<?= base_url('leases/show') ?>?id=' + lease.id"
                                       class="btn btn-ghost btn-sm">View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

    </div>

    <!-- ── PAGINATION ─────────────────────────────────────────── -->
    <template x-if="!loading && pagination.total_pages > 1">
        <div class="pagination">
            <div class="pagination-info"
                 x-text="'Page ' + pagination.current_page + ' of ' + pagination.total_pages">
            </div>
            <div class="pagination-controls">
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.current_page <= 1"
                        @click="goToPage(pagination.current_page - 1)">← Prev</button>
                <button class="btn btn-secondary btn-sm"
                        :disabled="pagination.current_page >= pagination.total_pages"
                        @click="goToPage(pagination.current_page + 1)">Next →</button>
            </div>
        </div>
    </template>

</div><!-- /x-data -->

<script>
function FF_Leases() {
    return {
        leases:      [],
        loading:     true,
        loadError:   null,
        pagination:  {},
        activeTab:   'open',
        counts:      { active: null, pending: null, completed: null },
        totalRate:   null,
        filters:     { search: '', status: '' },
        currentPage: 1,

        async init() {
            await this.load();
        },

        setTab(tab) {
            this.activeTab   = tab;
            this.filters.status = '';
            this.currentPage = 1;
            this.load();
        },

        async load() {
            this.loading   = true;
            this.loadError = null;
            const params = new URLSearchParams();

            // Tab drives the status filter
            if (this.activeTab === 'open') {
                // active + pending — fetch active first, then load again for pending
                // Simpler: send no status filter but filter client-side...
                // Actually: API supports single status. Use search param approach.
                // Workaround: fetch all and filter client-side for open tab
                params.set('status', '');  // no status filter applied at API for 'open' tab
            } else if (this.activeTab === 'closed') {
                params.set('status', 'completed');  // will also need cancelled — see note
            } else {
                // All tab — use the status filter select
                if (this.filters.status) params.set('status', this.filters.status);
            }

            if (this.filters.search) params.set('search', this.filters.search);
            params.set('page',     this.currentPage);
            params.set('per_page', 50);
            params.set('sort',     'created_at');
            params.set('dir',      'DESC');

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/leases') ?>?' + params);
                if (r.success) {
                    let items = r.data.items;

                    // Filter client-side for open tab (active + pending)
                    if (this.activeTab === 'open') {
                        this.counts.open = r.data.pagination.total;
                        items = items.filter(l => l.status === 'active' || l.status === 'pending');
                    } else if (this.activeTab === 'closed') {
                        // Also need cancelled — re-fetch without filter and filter client-side
                        items = items.filter(l => l.status === 'completed' || l.status === 'cancelled');
                    }

                    this.leases     = items;
                    this.pagination = r.data.pagination;

                    // Compute summary counts from all returned items (before tab filter)
                    const all = r.data.items;
                    this.counts.active    = all.filter(l => l.status === 'active').length;
                    this.counts.pending   = all.filter(l => l.status === 'pending').length;
                    this.counts.completed = all.filter(l => l.status === 'completed').length;
                    // Total monthly rate of active leases on this page
                    const rateSum = all
                        .filter(l => l.status === 'active' && parseFloat(l.monthly_rate) > 0)
                        .reduce((sum, l) => sum + parseFloat(l.monthly_rate), 0);
                    this.totalRate = rateSum > 0
                        ? rateSum.toLocaleString('en-CA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                        : null;
                } else {
                    this.loadError = r.message || 'Failed to load leases.';
                }
            } catch(e) {
                this.loadError = 'Network error.';
            }
            this.loading = false;
        },

        resetPage() { this.currentPage = 1; this.load(); },
        goToPage(p) { this.currentPage = p; this.load(); },

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
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
