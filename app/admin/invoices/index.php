<?php
declare(strict_types=1);

/**
 * app/admin/invoices/index.php
 *
 * Invoice list page with AR aging summary tiles, tab filters (Outstanding / Paid / All),
 * search, status filter, and paginated data table.
 * Fetches data from api/v1/invoices/index.php via Alpine.js.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions D30 (asset_url), D32 (CSS classes verified in app.css)
 * @session  S008
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

// AR aging summary — computed server-side for KPI tiles
$arCurrent = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
     FROM invoices WHERE status = 'sent' AND due_date >= CURDATE() AND deleted_at IS NULL", []
);
$ar30 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
     FROM invoices WHERE status IN ('sent','overdue') AND due_date < CURDATE()
     AND due_date >= CURDATE() - INTERVAL 30 DAY AND deleted_at IS NULL", []
);
$ar60 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
     FROM invoices WHERE status IN ('sent','overdue') AND due_date < CURDATE() - INTERVAL 30 DAY
     AND due_date >= CURDATE() - INTERVAL 60 DAY AND deleted_at IS NULL", []
);
$ar90 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
     FROM invoices WHERE status IN ('sent','overdue') AND due_date < CURDATE() - INTERVAL 60 DAY
     AND deleted_at IS NULL", []
);

$pageTitle = 'Invoices';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<nav class="breadcrumb">
    <a href="<?= base_url('dashboard') ?>">Dashboard</a>
    <span class="breadcrumb-sep">/</span>
    <span class="breadcrumb-current">Invoices</span>
</nav>
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Invoices</h1>
    </div>
    <div class="page-header-actions">
        <?php if (can('invoices', 'create')): ?>
        <a href="<?= base_url('invoices/create') ?>" class="btn btn-primary btn-sm">
            + New Invoice
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     AR Aging KPI Tiles
     ============================================================ -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Current</div>
        <div class="stat-value font-mono"><?= format_currency($arCurrent['total'] ?? 0) ?></div>
        <div class="stat-delta"><?= (int)($arCurrent['cnt'] ?? 0) ?> invoice<?= (int)($arCurrent['cnt'] ?? 0) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">1–30 Days</div>
        <div class="stat-card__value" style="color:var(--color-warning);"><?= format_currency($ar30['total'] ?? 0) ?></div>
        <div class="stat-delta"><?= (int)($ar30['cnt'] ?? 0) ?> overdue</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">31–60 Days</div>
        <div class="stat-card__value" style="color:var(--color-danger);"><?= format_currency($ar60['total'] ?? 0) ?></div>
        <div class="stat-delta"><?= (int)($ar60['cnt'] ?? 0) ?> overdue</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">60+ Days</div>
        <div class="stat-card__value" style="color:var(--color-danger);"><?= format_currency($ar90['total'] ?? 0) ?></div>
        <div class="stat-delta"><?= (int)($ar90['cnt'] ?? 0) ?> overdue</div>
    </div>
</div>

<!-- ============================================================
     INVOICES ALPINE COMPONENT
     ============================================================ -->
<div x-data="FF_Invoices()" x-init="init()">

    <!-- ── TAB BAR ──────────────────────────────────────────────── -->
    <div class="tab-bar" role="tablist">
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'outstanding' }"
                @click="setTab('outstanding')" :aria-selected="activeTab === 'outstanding'" role="tab">
            Outstanding
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'paid' }"
                @click="setTab('paid')" :aria-selected="activeTab === 'paid'" role="tab">
            Paid
        </button>
        <button class="tab-btn" :class="{ 'is-active': activeTab === 'all' }"
                @click="setTab('all')" :aria-selected="activeTab === 'all'" role="tab">
            All
        </button>
    </div>

    <!-- ── FILTER TOOLBAR ────────────────────────────────────── -->
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <input type="search"
                   class="form-control form-control-sm"
                   placeholder="Search invoice #, company…"
                   x-model="filters.search"
                   @input.debounce.400ms="resetPage()"
                   maxlength="255"
                   style="min-width:220px;"
                   aria-label="Search invoices">
            <select class="form-select form-control-sm"
                    x-model="filters.status"
                    @change="resetPage()"
                    x-show="activeTab === 'all'">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="sent">Sent</option>
                <option value="partially_paid">Partially Paid</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
                <option value="void">Void</option>
                <option value="written_off">Written Off</option>
            </select>
        </div>
        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="pagination.total !== undefined ? pagination.total + ' invoice' + (pagination.total !== 1 ? 's' : '') : ''">
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
                <p class="empty-state-title">Failed to load invoices</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <template x-if="!loading && !loadError && invoices.length === 0">
            <div class="empty-state">
                <p class="empty-state-title">No invoices found</p>
                <p class="empty-state-text">
                    <template x-if="filters.search || filters.status">
                        <span>Try adjusting your filters.</span>
                    </template>
                    <template x-if="!filters.search && !filters.status">
                        <span>Invoices are generated from active leases.</span>
                    </template>
                </p>
            </div>
        </template>

        <template x-if="!loading && !loadError && invoices.length > 0">
            <div class="table-wrapper">
                <table class="table" aria-label="Invoices">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Lease / Unit</th>
                            <th>Period</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th>Total</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="inv in invoices" :key="inv.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id"
                                       class="font-mono font-medium link"
                                       x-text="inv.invoice_number">
                                    </a>
                                </td>
                                <td>
                                    <div class="font-medium" x-text="inv.company_name_snapshot || '—'"></div>
                                </td>
                                <td class="text-sm">
                                    <div class="font-mono" x-text="inv.contract_number_snapshot || '—'"></div>
                                    <div class="text-secondary" x-text="inv.unit_number_invoice_snapshot || ''"></div>
                                </td>
                                <td class="text-sm">
                                    <div x-text="formatDate(inv.billing_period_start)"></div>
                                    <div class="text-secondary" x-text="'→ ' + formatDate(inv.billing_period_end)"></div>
                                </td>
                                <td class="text-sm" x-text="formatDate(inv.invoice_date)"></td>
                                <td class="text-sm">
                                    <span :class="isOverdue(inv) ? 'text-danger font-medium' : ''"
                                          x-text="formatDate(inv.due_date)"></span>
                                </td>
                                <td class="font-mono text-sm" x-text="'$' + parseFloat(inv.total_amount).toFixed(2)"></td>
                                <td class="font-mono text-sm">
                                    <span :class="parseFloat(inv.balance_due) > 0 ? 'text-danger' : ''"
                                          x-text="parseFloat(inv.balance_due) > 0 ? '$' + parseFloat(inv.balance_due).toFixed(2) : '—'">
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="statusBadgeClass(inv.status)"
                                          x-text="statusLabel(inv.status)">
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id"
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
function FF_Invoices() {
    return {
        invoices:    [],
        loading:     true,
        loadError:   null,
        pagination:  {},
        activeTab:   'outstanding',
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
            if (this.activeTab === 'outstanding') {
                // draft + sent + partially_paid + overdue — no single status, filter client-side
            } else if (this.activeTab === 'paid') {
                params.set('status', 'paid');
            } else {
                if (this.filters.status) params.set('status', this.filters.status);
            }

            if (this.filters.search) params.set('q', this.filters.search);
            params.set('page',     this.currentPage);
            params.set('per_page', 50);
            params.set('sort',     'created_at');
            params.set('dir',      'DESC');

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/invoices') ?>?' + params);
                if (r.success) {
                    let items = r.data.items;

                    if (this.activeTab === 'outstanding') {
                        items = items.filter(i => ['draft','sent','partially_paid','overdue'].includes(i.status));
                    }

                    this.invoices   = items;
                    this.pagination = r.data.pagination;
                } else {
                    this.loadError = r.message || 'Failed to load invoices.';
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
                draft:          'badge-neutral',
                sent:           'badge-info',
                paid:           'badge-success',
                partially_paid: 'badge-warning',
                overdue:        'badge-danger',
                void:           'badge-neutral line-through',   /* FIX #34 */
                written_off:    'badge-danger',
            };
            return map[status] || 'badge-neutral';
        },

        statusLabel(status) {
            const map = {
                draft:          'Draft',
                sent:           'Sent',
                paid:           'Paid',
                partially_paid: 'Partial',
                overdue:        'Overdue',
                void:           'Void',
                written_off:    'Written Off',
            };
            return map[status] || status;
        },

        isOverdue(inv) {
            if (inv.status === 'paid' || inv.status === 'void') return false;
            return inv.due_date && new Date(inv.due_date + 'T00:00:00') < new Date();
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
