<?php
declare(strict_types=1);

/**
 * app/admin/payments/index.php
 *
 * Payments list page with KPI summary tiles (total collected this month,
 * total AR outstanding, overdue count) and a paginated, searchable table
 * with status filter. Data fetched via Alpine.js from api/v1/payments/index.php.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.8 Payments
 * @decisions D30 (asset_url), D32 (CSS classes verified in app.css),
 *            D5/D13 (soft-delete filter in API), Permission matrix: dispatcher has no access
 * @session  S009
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('payments', 'view');

// --- KPI tiles — server-rendered (no caching needed for payment summaries) ---

// Total collected this calendar month
$collectedThisMonth = db_row(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM payments
     WHERE deleted_at IS NULL AND status = 'cleared'
       AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

// Total outstanding AR (unpaid + partially_paid invoices, non-void)
$arOutstanding = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE deleted_at IS NULL AND status IN ('sent', 'partially_paid', 'overdue')",
    []
);

// Overdue invoices (past due_date, still unpaid)
$arOverdue = db_row(
    "SELECT COALESCE(SUM(balance_due), 0) AS total, COUNT(*) AS cnt
     FROM invoices
     WHERE deleted_at IS NULL AND status IN ('sent', 'partially_paid', 'overdue')
       AND due_date < CURDATE()",
    []
);

// Payments recorded today
$today = db_row(
    "SELECT COUNT(*) AS cnt FROM payments
     WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()",
    []
);

$pageTitle = 'Payments';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- ============================================================
     Page header
     ============================================================ -->
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Payments</h1>
        <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">Record and track customer payments against invoices</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= base_url('/payments/create') ?>" class="btn btn-primary btn-md">
            <?= heroicon('plus', 'icon-sm') ?>
            Record Payment
        </a>
    </div>
</div>

<!-- ============================================================
     KPI tiles
     ============================================================ -->
<div x-data="paymentsKpis()" x-init="loadKpis()">
<div class="stat-grid" style="grid-template-columns: repeat(4,1fr); gap:16px; margin-bottom:24px;">

    <!-- Collected This Month — filters table by status=cleared -->
    <div class="stat-card stat-card--green"
         :class="{ 'ring-active': activeTile === 'cleared' }"
         style="cursor:pointer;"
         title="Click to filter payments by Cleared status"
         @click="activeTile = activeTile === 'cleared' ? '' : 'cleared'; drill('status', 'cleared')">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Collected This Month</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.collected_total)"></div>
        <div class="stat-delta" x-text="kpis.collected_cnt + ' payments'"></div>
    </div>

    <!-- TILES-1: Total AR Outstanding drills to outstanding invoices -->
    <a class="stat-card stat-card--blue"
       href="<?= base_url('invoices') ?>"
       style="cursor:pointer;text-decoration:none"
       title="Click to view outstanding invoices">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-chart-bar"/></svg></span>
        <div class="stat-label">Total AR Outstanding</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.ar_outstanding_total)"></div>
        <div class="stat-delta" x-text="kpis.ar_outstanding_cnt + ' invoices'"></div>
    </a>

    <!-- TILES-1: Overdue AR drills to overdue-status invoice list -->
    <a class="stat-card stat-card--red"
       href="<?= base_url('invoices') ?>?status=overdue"
       style="cursor:pointer;text-decoration:none"
       title="Click to view overdue invoices">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">Overdue AR</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.ar_overdue_total)"></div>
        <div class="stat-delta" x-text="kpis.ar_overdue_cnt + ' invoices past due'"></div>
    </a>

    <!-- TILES-1: Recorded Today drills payments list to today's date range -->
    <div class="stat-card stat-card--slate"
         :class="{ 'ring-active': activeTile === 'today' }"
         style="cursor:pointer;"
         title="Click to filter payments recorded today"
         @click="activeTile = activeTile === 'today' ? '' : 'today'; drill('date_from', activeTile ? todayIso() : ''); drill('date_to', activeTile ? todayIso() : '')">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-pencil-square"/></svg></span>
        <div class="stat-label">Recorded Today</div>
        <div class="stat-value font-mono" x-text="kpis.recorded_today_cnt"></div>
        <div class="stat-delta">payments</div>
    </div>

</div>
</div>

<!-- ============================================================
     Filter bar + table — Alpine.js component
     ============================================================ -->
<div class="card" x-data="FF_Payments()" id="payments-table">

    <!-- Filter bar -->
    <div class="card-header" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <div style="position:relative; flex:1; min-width:200px;">
            <input
                type="text"
                class="form-input"
                placeholder="Search by reference or payment #…"
                x-model="filters.q"
                @input.debounce.400ms="resetAndLoad()"
                style="padding-left:36px;"
            >
            <span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted);">
                <?= heroicon('magnifying-glass', 'icon-sm') ?>
            </span>
        </div>

        <select class="form-input" style="width:160px;" x-model="filters.status" @change="resetAndLoad()">
            <option value="">All Statuses</option>
            <option value="cleared">Cleared</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
            <option value="refunded">Refunded</option>
            <option value="void">Void</option>
            <option value="returned">Returned</option>
        </select>

        <button class="btn btn-secondary btn-sm" @click="resetFilters()">Reset</button>

        <span class="text-muted" style="font-size:0.85rem; margin-left:auto;" x-show="!loading">
            <span x-text="pagination.total"></span> payments
        </span>
    </div>

    <!-- Table -->
    <div class="card-body" style="padding:0; overflow-x:auto;">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div style="padding:40px; text-align:center; color:var(--text-muted);">
                <?= heroicon('arrow-path', 'icon-md') ?>
                <p style="margin-top:8px;">Loading payments…</p>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && rows.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon"><?= heroicon('credit-card', 'icon-xl') ?></div>
                <p class="empty-state-title">No payments found</p>
                <p class="empty-state-text">Adjust your filters or record a new payment.</p>
                <a href="<?= base_url('/payments/create') ?>" class="btn btn-primary btn-sm">Record Payment</a>
            </div>
        </template>

        <!-- Data table -->
        <template x-if="!loading && rows.length > 0">
            <table class="table" style="width:100%;">
                <thead>
                    <tr>
                        <th @click="setSort('payment_number')" style="cursor:pointer; white-space:nowrap;">
                            Payment #
                            <span x-show="sort === 'payment_number'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Customer</th>
                        <th @click="setSort('payment_date')" style="cursor:pointer;">
                            Date
                            <span x-show="sort === 'payment_date'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th @click="setSort('amount')" style="cursor:pointer; text-align:right;">
                            Amount
                            <span x-show="sort === 'amount'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr>
                            <td>
                                <a :href="'<?= base_url('/payments/show') ?>?id=' + row.id"
                                   class="link font-mono" x-text="row.payment_number"></a>
                            </td>
                            <td x-text="row.company_name || '—'"></td>
                            <td class="font-mono" x-text="row.payment_date"></td>
                            <td x-text="formatMethod(row.payment_method)"></td>
                            <td class="font-mono" x-text="row.reference_number || '—'"></td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="formatCurrency(row.amount) + ' ' + row.currency"></td>
                            <td>
                                <span class="badge" :class="statusBadge(row.status)" x-text="row.status"></span>
                            </td>
                            <td>
                                <a :href="'<?= base_url('/payments/show') ?>?id=' + row.id"
                                   class="btn btn-secondary btn-xs">View</a>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </template>

    </div><!-- /card-body -->

    <!-- Pagination -->
    <div class="card-footer" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;" x-show="pagination.total_pages > 1">
        <button class="btn btn-secondary btn-sm"
                :disabled="pagination.page <= 1"
                @click="goPage(pagination.page - 1)">← Prev</button>
        <span style="font-size:0.875rem; color:var(--text-secondary);">
            Page <span x-text="pagination.page"></span> of <span x-text="pagination.total_pages"></span>
        </span>
        <button class="btn btn-secondary btn-sm"
                :disabled="!pagination.has_more"
                @click="goPage(pagination.page + 1)">Next →</button>
    </div>

</div><!-- /card -->

<script>
function paymentsKpis() {
    return {
        activeTile: '',
        kpis: {
            collected_total:      <?= json_encode($collectedThisMonth['total'] ?? '0.00') ?>,
            collected_cnt:        <?= json_encode((int)($collectedThisMonth['cnt'] ?? 0)) ?>,
            ar_outstanding_total: <?= json_encode($arOutstanding['total'] ?? '0.00') ?>,
            ar_outstanding_cnt:   <?= json_encode((int)($arOutstanding['cnt'] ?? 0)) ?>,
            ar_overdue_total:     <?= json_encode($arOverdue['total'] ?? '0.00') ?>,
            ar_overdue_cnt:       <?= json_encode((int)($arOverdue['cnt'] ?? 0)) ?>,
            recorded_today_cnt:   <?= json_encode((int)($today['cnt'] ?? 0)) ?>,
        },
        fmt(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        /** TILES-1: ISO-format today's date so the date range drill can set filters.date_from/date_to */
        todayIso() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        },

        /** Cross-component drill: toggle a filter on the payments table.
         *  TILES-1: auto-registers unknown filter keys (e.g. date_from/date_to)
         *  so the "Recorded Today" tile can write date filters that the
         *  payments API already supports even if the FF_Payments component
         *  didn't pre-declare them in its filters object. */
        drill(filter, val) {
            const el = document.getElementById('payments-table');
            if (!el) return;
            const d = Alpine.$data(el);
            if (!(filter in d.filters)) d.filters[filter] = '';
            d.filters[filter] = d.filters[filter] === val ? '' : val;
            d.pagination.page = 1;
            d.load();
        },

        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/payments/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
    };
}

function FF_Payments() {
    return {
        rows:       [],
        loading:    true,
        pagination: { total: 0, page: 1, per_page: 25, total_pages: 1, has_more: false },
        sort:       'payment_date',
        dir:        'DESC',
        filters: {
            q:      '',
            status: '',
        },

        init() { this.load(); },

        async load() {
            this.loading = true;
            const p = new URLSearchParams({
                sort:    this.sort,
                dir:     this.dir,
                page:    this.pagination.page,
                per_page: this.pagination.per_page,
                ...Object.fromEntries(
                    Object.entries(this.filters).filter(([, v]) => v !== '')
                ),
            });
            const res  = await FF_Api.get('<?= base_url('api/v1/payments/index.php') ?>?' + p.toString());
            this.rows       = res.data?.items       ?? [];
            this.pagination = res.data?.pagination  ?? this.pagination;
            this.loading    = false;
        },

        resetAndLoad() {
            this.pagination.page = 1;
            this.load();
        },

        setSort(col) {
            if (this.sort === col) {
                this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sort = col;
                this.dir  = 'DESC';
            }
            this.resetAndLoad();
        },

        goPage(p) {
            this.pagination.page = p;
            this.load();
        },

        resetFilters() {
            this.filters = { q: '', status: '' };
            this.resetAndLoad();
        },

        // Badge class per design §9 Payment status colors
        statusBadge(status) {
            const map = {
                pending:  'badge-info',
                cleared:  'badge-success',
                failed:   'badge-danger',
                refunded: 'badge-warning',
                void:     'badge-neutral',
                returned: 'badge-warning',
            };
            return map[status] ?? 'badge-neutral';
        },

        formatMethod(method) {
            const map = {
                check:          'Cheque',
                ach:            'ACH',
                wire:           'Wire',
                credit_card:    'Credit Card',
                cash:           'Cash',
                e_transfer:     'e-Transfer',
                account_credit: 'Acct Credit',
                other:          'Other',
            };
            return map[method] ?? method;
        },

        formatCurrency(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
