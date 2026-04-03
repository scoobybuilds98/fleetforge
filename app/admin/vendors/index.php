<?php
declare(strict_types=1);

/**
 * app/admin/vendors/index.php
 *
 * Vendors list page.
 * Server-renders 4 KPI tiles (total/preferred/active work orders/top spend),
 * then Alpine.js loads the filterable/sortable table from the API.
 *
 * Filters: vendor_type, is_preferred, q (search).
 * Sort: name, total_spent, rating, created_at.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/vendors/index.php
 * @decisions D5/D7/D30/D32
 * @session  S014
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$kpis = [
    'total'     => db_count("SELECT COUNT(*) FROM vendors WHERE deleted_at IS NULL"),
    'preferred' => db_count("SELECT COUNT(*) FROM vendors WHERE is_preferred = 1 AND deleted_at IS NULL"),
    'active_wo' => db_count(
        "SELECT COUNT(*) FROM maintenance_work_orders
         WHERE status IN ('open','in_progress','waiting_parts') AND deleted_at IS NULL
           AND vendor_id IS NOT NULL"
    ),
];

// Top total_spent vendor (for KPI tile headline)
$topSpendRow = db_row(
    "SELECT name, total_spent FROM vendors
     WHERE deleted_at IS NULL AND total_spent > 0
     ORDER BY total_spent DESC LIMIT 1"
);
$kpis['top_vendor_name']  = $topSpendRow['name'] ?? null;
$kpis['top_vendor_spent'] = $topSpendRow['total_spent'] ?? '0.00';

$pageTitle = 'Vendors';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Vendors</h1>
    <?php if (can('maintenance', 'create')): ?>
    <a href="<?= base_url('vendors/create') ?>" class="btn btn-primary btn-sm">
        + New Vendor
    </a>
    <?php endif; ?>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card">
        <div class="stat-label">Total Vendors</div>
        <div class="stat-value font-mono"><?= e($kpis['total']) ?></div>
        <div class="stat-delta">active in system</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Preferred Vendors</div>
        <div class="stat-value font-mono"><?= e($kpis['preferred']) ?></div>
        <div class="stat-delta">marked preferred</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Work Orders</div>
        <div class="stat-value font-mono"><?= e($kpis['active_wo']) ?></div>
        <div class="stat-delta">open / in progress / waiting parts</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Top Vendor by Spend</div>
        <div class="stat-value font-mono"><?= format_currency($kpis['top_vendor_spent']) ?></div>
        <div class="stat-delta">
            <?= $kpis['top_vendor_name'] ? e($kpis['top_vendor_name']) : 'No spend recorded' ?>
        </div>
    </div>

</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<div class="card"
     x-data="vendorsList()"
     x-init="init()">

    <!-- Filter bar -->
    <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

        <select class="form-select form-select-sm"
                x-model="filters.vendor_type"
                @change="goPage(1)">
            <option value="">All Types</option>
            <option value="maintenance">Maintenance</option>
            <option value="repair">Repair</option>
            <option value="parts">Parts</option>
            <option value="inspection">Inspection</option>
            <option value="towing">Towing</option>
            <option value="other">Other</option>
        </select>

        <select class="form-select form-select-sm"
                x-model="filters.is_preferred"
                @change="goPage(1)">
            <option value="">All Vendors</option>
            <option value="1">Preferred Only</option>
        </select>

        <input type="text"
               class="form-control"
               style="max-width:220px;height:32px;font-size:0.875rem;"
               placeholder="Search name or contact…"
               x-model="filters.q"
               @input.debounce.400ms="goPage(1)">

        <button class="btn btn-secondary btn-sm"
                @click="resetFilters()">Reset</button>

        <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
              x-text="total > 0 ? total + ' vendor' + (total === 1 ? '' : 's') : ''"></span>
    </div>

    <!-- Loading -->
    <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading…</span>
    </div>

    <!-- Empty state -->
    <template x-if="!loading && rows.length === 0">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-state-title">No vendors found</p>
                <p class="empty-state-text">Try adjusting your filters, or add a new vendor.</p>
            </div>
        </div>
    </template>

    <!-- Table -->
    <template x-if="!loading && rows.length > 0">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th @click="setSort('name')" style="cursor:pointer;">
                            Name <span x-text="sortIcon('name')"></span>
                        </th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Rating</th>
                        <th @click="setSort('total_spent')" style="cursor:pointer;text-align:right;">
                            Total Spent <span x-text="sortIcon('total_spent')"></span>
                        </th>
                        <th style="text-align:right;">Work Orders</th>
                        <th>Preferred</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr class="table-row-link"
                            @click="window.location = '<?= base_url('vendors/show') ?>?id=' + row.id"
                            style="cursor:pointer;">
                            <td x-text="row.name"></td>
                            <td>
                                <span class="badge"
                                      :class="typeBadge(row.vendor_type)"
                                      x-text="typeLabel(row.vendor_type)"></span>
                            </td>
                            <td x-text="row.contact_name || '—'"></td>
                            <td x-text="row.phone || '—'"></td>
                            <td x-text="row.rating ? '★'.repeat(row.rating) : '—'"></td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="row.total_spent ? '$' + parseFloat(row.total_spent).toLocaleString('en-CA', {minimumFractionDigits:2}) : '$0.00'"></td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="row.work_order_count ?? 0"></td>
                            <td x-text="row.is_preferred ? 'Yes' : '—'"></td>
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

<script>
function vendorsList() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        perPage:    25,
        totalPages: 1,
        loading:    false,
        sort:       'name',
        dir:        'ASC',
        filters: {
            vendor_type:  '',
            is_preferred: '',
            q:            '',
        },

        init() { this.fetch(); },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({
                page:     this.page,
                per_page: this.perPage,
                sort:     this.sort,
                dir:      this.dir,
            });
            if (this.filters.vendor_type)  p.set('vendor_type', this.filters.vendor_type);
            if (this.filters.is_preferred) p.set('is_preferred', this.filters.is_preferred);
            if (this.filters.q)            p.set('q', this.filters.q);

            FF_Api.get('<?= base_url('api/v1/vendors/index.php') ?>?' + p.toString())
                .then(d => {
                    this.rows       = d.data?.items ?? [];
                    this.total      = d.data?.pagination?.total ?? 0;
                    this.totalPages = d.data?.pagination?.total_pages ?? 1;
                })
                .catch(() => { this.rows = []; })
                .finally(() => { this.loading = false; });
        },

        goPage(n) { this.page = n; this.fetch(); },

        setSort(col) {
            if (this.sort === col) { this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC'; }
            else { this.sort = col; this.dir = 'ASC'; }
            this.page = 1;
            this.fetch();
        },

        sortIcon(col) { return this.sort === col ? (this.dir === 'ASC' ? ' ↑' : ' ↓') : ''; },

        resetFilters() {
            this.filters = { vendor_type: '', is_preferred: '', q: '' };
            this.page = 1;
            this.fetch();
        },

        // vendor_type → badge class
        typeBadge(t) {
            return {
                maintenance: 'badge badge-warning',
                repair:      'badge badge-danger',
                parts:       'badge badge-info',
                inspection:  'badge badge-purple',
                towing:      'badge badge-neutral',
                other:       'badge badge-neutral',
            }[t] ?? 'badge badge-neutral';
        },

        typeLabel(t) {
            return {
                maintenance: 'Maintenance',
                repair:      'Repair',
                parts:       'Parts',
                inspection:  'Inspection',
                towing:      'Towing',
                other:       'Other',
            }[t] ?? t;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
