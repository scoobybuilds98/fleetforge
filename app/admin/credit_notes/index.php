<?php
declare(strict_types=1);

/**
 * app/admin/credit_notes/index.php
 *
 * Credit notes list page. Server-renders 4 KPI tiles, then Alpine.js
 * fetches the paginated table from api/v1/credit_notes/index.php.
 *
 * KPI tiles:
 *   1. Active credit notes total (amount_remaining of all active notes)
 *   2. Count of active + partially_used notes
 *   3. Total issued this calendar month
 *   4. Fully used this calendar month (applied to invoices)
 *
 * Filters: customer search, status, currency, source
 * Table columns: CN#, Customer, Source, Amount, Remaining, Currency, Status, Expires, Created
 *
 * D32: All CSS classes verified in public/assets/css/app.css before use.
 * D30: asset_url() for static assets; base_url() for page/API links.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.8 (credit notes referenced under payments/invoices)
 * @decisions D5/D30/D32, §12 Permission Matrix (invoices module)
 * @session  S011
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

// -----------------------------------------------------------------------
// KPI tiles — server-rendered
// -----------------------------------------------------------------------

// Total outstanding balance across all active + partially_used notes
$activeBalance = db_row(
    "SELECT COALESCE(SUM(amount_remaining), 0) AS total, COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status IN ('active', 'partially_used')",
    []
);

// Total credit notes issued this calendar month (by amount)
$issuedThisMonth = db_row(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL
       AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

// Fully used this calendar month
$fullyUsedThisMonth = db_row(
    "SELECT COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status = 'fully_used'
       AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

// Expired/void this month (informational)
$expiredThisMonth = db_row(
    "SELECT COUNT(*) AS cnt
     FROM credit_notes
     WHERE deleted_at IS NULL AND status IN ('expired', 'void')
       AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    []
);

$pageTitle = 'Credit Notes';
require_once FF_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-header-title h4">Credit Notes</h1>
        <p style="margin:4px 0 0; color:var(--text-secondary); font-size:0.9rem;">Issue and track customer credit notes against outstanding invoices</p>
    </div>
    <?php if (can('invoices', 'create')): ?>
    <div class="page-header-actions">
        <a href="<?= base_url('credit_notes/create') ?>" class="btn btn-primary btn-md">
            <?= heroicon('plus', 'icon-sm') ?>
            New Credit Note
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- KPI tiles -->
<div x-data="creditNotesKpis()" x-init="loadKpis()">
<div class="stat-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card stat-card--blue" style="cursor:pointer;"
         @click="activeTile = activeTile === 'active' ? '' : 'active'; drill('status', activeTile === 'active' ? 'active' : '')"
         :class="{ 'ring-active': activeTile === 'active' }">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-credit-card"/></svg></span>
        <div class="stat-label">Outstanding Balance</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.active_balance)"></div>
        <div class="stat-delta" x-text="kpis.active_cnt + ' active note' + (kpis.active_cnt !== 1 ? 's' : '')"></div>
    </div>
    <!-- TILES-1: Issued This Month shows all credit notes (clear status filter) -->
    <div class="stat-card stat-card--teal" style="cursor:pointer;"
         @click="activeTile = activeTile === 'issued' ? '' : 'issued'; drill('status', '')"
         :class="{ 'ring-active': activeTile === 'issued' }">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-arrow-up-tray"/></svg></span>
        <div class="stat-label">Issued This Month</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.issued_total)"></div>
        <div class="stat-delta" x-text="kpis.issued_cnt + ' note' + (kpis.issued_cnt !== 1 ? 's' : '')"></div>
    </div>
    <div class="stat-card stat-card--green" style="cursor:pointer;"
         @click="activeTile = activeTile === 'applied' ? '' : 'applied'; drill('status', activeTile === 'applied' ? 'fully_applied' : '')"
         :class="{ 'ring-active': activeTile === 'applied' }">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Fully Applied This Month</div>
        <div class="stat-value" x-text="kpis.fully_used_cnt"></div>
        <div class="stat-delta">notes fully consumed</div>
    </div>
    <div class="stat-card stat-card--red" style="cursor:pointer;"
         @click="activeTile = activeTile === 'expired' ? '' : 'expired'; drill('status', activeTile === 'expired' ? 'expired' : '')"
         :class="{ 'ring-active': activeTile === 'expired' }">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-x-circle"/></svg></span>
        <div class="stat-label">Voided / Expired This Month</div>
        <div class="stat-value" x-text="kpis.expired_cnt"></div>
        <div class="stat-delta">notes cancelled</div>
    </div>
</div>
</div>

<style>
.stat-card[style*="cursor:pointer"]:hover { transform: translateY(-1px); transition: transform 0.15s; }
.stat-card.ring-active { box-shadow: 0 0 0 2px var(--color-primary); }
</style>

<!-- Credit notes table — Alpine.js -->
<div class="card" id="credit-notes-table" x-data="creditNotesList()" x-init="init()">

    <!-- Filters -->
    <div class="card-header" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">
        <select class="form-select" style="width:160px;" x-model="filters.status" @change="goPage(1)">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="partially_used">Partially Used</option>
            <option value="fully_used">Fully Used</option>
            <option value="expired">Expired</option>
            <option value="void">Void</option>
        </select>
        <select class="form-select" style="width:130px;" x-model="filters.currency" @change="goPage(1)">
            <option value="">All Currencies</option>
            <option value="CAD">CAD</option>
            <option value="USD">USD</option>
        </select>
        <select class="form-select" style="width:190px;" x-model="filters.source" @change="goPage(1)">
            <option value="">All Sources</option>
            <option value="mileage_overpayment">Mileage Overpayment</option>
            <option value="invoice_adjustment">Invoice Adjustment</option>
            <option value="damage_resolution">Damage Resolution</option>
            <option value="goodwill">Goodwill</option>
            <option value="payment_returned">Payment Returned</option>
            <option value="other">Other</option>
        </select>
        <div style="margin-left:auto; display:flex; gap:0.5rem; align-items:center;">
            <span x-show="loading" style="color:var(--text-secondary); font-size:0.875rem;">Loading…</span>
            <span x-show="!loading && total > 0" style="color:var(--text-secondary); font-size:0.875rem;" x-text="total + ' result' + (total !== 1 ? 's' : '')"></span>
        </div>
    </div>

    <!-- Table -->
    <div style="overflow-x:auto;">
        <table class="table">
            <thead>
                <tr>
                    <th @click="setSort('credit_note_number')" style="cursor:pointer; user-select:none;">
                        CN # <span x-text="sortIcon('credit_note_number')"></span>
                    </th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th @click="setSort('amount')" style="cursor:pointer; user-select:none; text-align:right;">
                        Amount <span x-text="sortIcon('amount')"></span>
                    </th>
                    <th @click="setSort('amount_remaining')" style="cursor:pointer; user-select:none; text-align:right;">
                        Remaining <span x-text="sortIcon('amount_remaining')"></span>
                    </th>
                    <th>Cur</th>
                    <th @click="setSort('status')" style="cursor:pointer; user-select:none;">
                        Status <span x-text="sortIcon('status')"></span>
                    </th>
                    <th>Expires</th>
                    <th @click="setSort('created_at')" style="cursor:pointer; user-select:none;">
                        Created <span x-text="sortIcon('created_at')"></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="9" style="text-align:center; padding:2rem; color:var(--text-secondary);">Loading…</td></tr>
                </template>
                <template x-if="!loading && rows.length === 0">
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <div class="empty-state-title">No credit notes found</div>
                                <div class="empty-state-text">Try adjusting your filters or issue a new credit note.</div>
                            </div>
                        </td>
                    </tr>
                </template>
                <template x-for="cn in rows" :key="cn.id">
                    <tr>
                        <td>
                            <a :href="'<?= base_url('credit_notes/show') ?>?id=' + cn.id"
                               class="link font-mono" x-text="cn.credit_note_number"></a>
                        </td>
                        <td>
                            <a :href="'<?= base_url('customers/show') ?>?id=' + cn.customer_id"
                               class="link" x-text="cn.customer_name || '—'"></a>
                        </td>
                        <td><span x-text="sourceLabel(cn.source)"></span></td>
                        <td class="font-mono" style="text-align:right;" x-text="formatMoney(cn.amount)"></td>
                        <td class="font-mono" style="text-align:right;" x-text="formatMoney(cn.amount_remaining)"></td>
                        <td x-text="cn.currency"></td>
                        <td><span :class="statusBadge(cn.status)" x-text="statusLabel(cn.status)"></span></td>
                        <td x-text="cn.expires_at ? cn.expires_at : '—'"></td>
                        <td x-text="fmtDate(cn.created_at)"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="card-footer" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
        <div style="font-size:0.875rem; color:var(--text-secondary);">
            Page <span x-text="page"></span> of <span x-text="totalPages"></span>
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button class="btn btn-sm btn-secondary" :disabled="page <= 1" @click="goPage(page - 1)">← Prev</button>
            <button class="btn btn-sm btn-secondary" :disabled="page >= totalPages" @click="goPage(page + 1)">Next →</button>
        </div>
    </div>
</div>

<script>
function creditNotesKpis() {
    return {
        kpis: {
            active_balance:  <?= json_encode($activeBalance['total'] ?? '0.00') ?>,
            active_cnt:      <?= json_encode((int)($activeBalance['cnt'] ?? 0)) ?>,
            issued_total:    <?= json_encode($issuedThisMonth['total'] ?? '0.00') ?>,
            issued_cnt:      <?= json_encode((int)($issuedThisMonth['cnt'] ?? 0)) ?>,
            fully_used_cnt:  <?= json_encode((int)($fullyUsedThisMonth['cnt'] ?? 0)) ?>,
            expired_cnt:     <?= json_encode((int)($expiredThisMonth['cnt'] ?? 0)) ?>,
        },
        activeTile: '',
        fmt(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        drill(filter, val) {
            const el = document.getElementById('credit-notes-table');
            if (!el) return;
            const d = Alpine.$data(el);
            d.filters[filter] = d.filters[filter] === val ? '' : val;
            d.page = 1;
            d.fetchRows();
        },
        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/credit_notes/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
    };
}

function creditNotesList() {
    return {
        rows: [],
        total: 0,
        page: 1,
        perPage: 25,
        totalPages: 1,
        loading: false,
        sort: 'created_at',
        dir: 'DESC',
        filters: {
            status: '',
            currency: '',
            source: '',
        },

        init() {
            this.fetchRows();
        },

        fetchRows() {
            this.loading = true;
            const params = new URLSearchParams({
                page: this.page,
                per_page: this.perPage,
                sort: this.sort,
                dir: this.dir,
            });
            if (this.filters.status)   params.set('status', this.filters.status);
            if (this.filters.currency) params.set('currency', this.filters.currency);
            if (this.filters.source)   params.set('source', this.filters.source);

            FF_Api.get('<?= base_url('api/v1/credit_notes/index') ?>?' + params.toString())
                .then(data => {
                    this.rows       = data.data || [];
                    this.total      = data.total || 0;
                    this.totalPages = data.last_page || 1;
                })
                .catch(() => { this.rows = []; })
                .finally(() => { this.loading = false; });
        },

        goPage(n) {
            this.page = n;
            this.fetchRows();
        },

        setSort(col) {
            if (this.sort === col) {
                this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sort = col;
                this.dir  = 'DESC';
            }
            this.goPage(1);
        },

        sortIcon(col) {
            if (this.sort !== col) return '↕';
            return this.dir === 'ASC' ? '↑' : '↓';
        },

        // Status badge classes (D32 — confirmed in app.css)
        statusBadge(status) {
            const map = {
                active:          'badge badge-success',
                partially_used:  'badge badge-warning',
                fully_used:      'badge badge-neutral',
                expired:         'badge badge-neutral',
                void:            'badge badge-neutral line-through',
            };
            return map[status] || 'badge badge-neutral';
        },

        statusLabel(status) {
            const map = {
                active:         'Active',
                partially_used: 'Partially Used',
                fully_used:     'Fully Used',
                expired:        'Expired',
                void:           'Void',
            };
            return map[status] || status;
        },

        sourceLabel(source) {
            const map = {
                mileage_overpayment: 'Mileage Overpayment',
                invoice_adjustment:  'Invoice Adjustment',
                damage_resolution:   'Damage Resolution',
                goodwill:            'Goodwill',
                payment_returned:    'Payment Returned',
                other:               'Other',
            };
            return map[source] || source;
        },

        formatMoney(val) {
            if (val == null) return '—';
            const n = parseFloat(val);
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        fmtDate(val) {
            if (!val) return '—';
            return new Date(val).toLocaleDateString('en-CA', { year: 'numeric', month: 'short', day: 'numeric' });
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
