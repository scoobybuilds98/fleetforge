<?php
declare(strict_types=1);

/**
 * app/admin/damage_claims/index.php
 *
 * Damage Claims list page.
 * Server-renders 4 KPI tiles (open/assessed/total this year/avg repair cost),
 * then Alpine.js loads the filterable/sortable table from the API.
 *
 * Filters: status, severity, customer_id (hidden — from query string).
 * Sort: claim_number, status, severity, estimated_repair_cost, created_at.
 *
 * D30: asset_url() / base_url().
 * D32: Only CSS classes confirmed in app.css.
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/damage_claims/index.php
 * @decisions D5/D30/D32
 * @session  S012
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('maintenance', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$kpis = [
    'open' => db_count(
        "SELECT COUNT(*) FROM damage_claims
         WHERE status IN ('reported','assessed','repair_ordered')
           AND deleted_at IS NULL"
    ),
    'invoiced' => db_count(
        "SELECT COUNT(*) FROM damage_claims
         WHERE status = 'invoiced' AND deleted_at IS NULL"
    ),
    'year_total' => db_count(
        "SELECT COUNT(*) FROM damage_claims
         WHERE YEAR(created_at) = YEAR(NOW()) AND deleted_at IS NULL"
    ),
];

$avgRow = db_row(
    "SELECT AVG(estimated_repair_cost) AS avg_cost
     FROM damage_claims
     WHERE estimated_repair_cost IS NOT NULL
       AND YEAR(created_at) = YEAR(NOW())
       AND deleted_at IS NULL"
);
$kpis['avg_repair'] = $avgRow ? (float)($avgRow['avg_cost'] ?? 0) : 0.0;

// Pre-filter from query string (e.g., from customer/equipment show pages)
$preCustomerId = clean_int($_GET['customer_id'] ?? null);
$preUnitId     = clean_int($_GET['equipment_unit_id'] ?? null);

$pageTitle = 'Damage Claims';
$helpModuleSlug = 'damage-claims';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Damage Claims</h1>
    <?php if (can('maintenance', 'create')): ?>
    <a href="<?= base_url('damage_claims/create') ?>" class="btn btn-primary btn-sm">
        + New Claim
    </a>
    <?php endif; ?>
    <div class="page-header-actions">
        <?= help_button('damage-claims') ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div x-data="damageClaimsKpis()" x-init="loadKpis()">
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card stat-card--amber"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'open' }"
         @click="activeTile = activeTile === 'open' ? '' : 'open'; setFilter('status', activeTile === 'open' ? 'open' : '')">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">Open Claims</div>
        <div class="stat-value font-mono" x-text="kpis.open"></div>
        <div class="stat-delta">reported / assessed / repair ordered</div>
    </div>

    <div class="stat-card stat-card--blue"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'invoiced' }"
         @click="activeTile = activeTile === 'invoiced' ? '' : 'invoiced'; setFilter('status', activeTile === 'invoiced' ? 'invoiced' : '')">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-clipboard"/></svg></span>
        <div class="stat-label">Awaiting Invoice</div>
        <div class="stat-value font-mono" x-text="kpis.invoiced"></div>
        <div class="stat-delta">invoiced status</div>
    </div>

    <!-- TILES-1: "Claims This Year" clears status/date filters so all
         this-year claims are shown; toggles an active ring for feedback. -->
    <div class="stat-card stat-card--teal" style="cursor:pointer;"
         :class="{ 'ring-active': activeTile === 'year' }"
         @click="activeTile = activeTile === 'year' ? '' : 'year'; setFilter('status', '')">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-arrow-trending-up"/></svg></span>
        <div class="stat-label">Claims This Year</div>
        <div class="stat-value font-mono" x-text="kpis.year_total"></div>
        <div class="stat-delta" x-text="new Date().getFullYear()"></div>
    </div>

    <!-- TILES-1: Avg Repair Cost drills to the repair_ordered subset -->
    <div class="stat-card stat-card--purple" style="cursor:pointer;"
         :class="{ 'ring-active': activeTile === 'avg' }"
         @click="activeTile = activeTile === 'avg' ? '' : 'avg'; setFilter('status', activeTile === 'avg' ? 'repair_ordered' : '')">
        <span class="stat-icon stat-icon--purple"><svg><use href="#icon-currency-dollar"/></svg></span>
        <div class="stat-label">Avg Repair Cost</div>
        <div class="stat-value font-mono" x-text="fmt(kpis.avg_repair)"></div>
        <div class="stat-delta">estimated, this year</div>
    </div>

</div>
</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<div class="card"
     id="damage-claims-table"
     x-data="damageClaimsList()">

    <!-- Filter bar -->
    <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

        <select class="form-select form-select-sm"
                x-model="filters.status"
                @change="goPage(1)">
            <option value="">All Statuses</option>
            <option value="reported">Reported</option>
            <option value="assessed">Assessed</option>
            <option value="repair_ordered">Repair Ordered</option>
            <option value="invoiced">Invoiced</option>
            <option value="resolved">Resolved</option>
            <option value="written_off">Written Off</option>
        </select>

        <select class="form-select form-select-sm"
                x-model="filters.severity"
                @change="goPage(1)">
            <option value="">All Severities</option>
            <option value="minor">Minor</option>
            <option value="moderate">Moderate</option>
            <option value="major">Major</option>
            <option value="total_loss">Total Loss</option>
        </select>

        <input type="text"
               class="form-input form-input-sm"
               style="max-width:200px;"
               placeholder="Search claim # or unit…"
               x-model="filters.q"
               @input.debounce.400ms="goPage(1)">

        <button class="btn btn-secondary btn-sm"
                @click="resetFilters()">Reset</button>

        <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;white-space:nowrap;"
              x-text="total > 0 ? total + ' claim' + (total === 1 ? '' : 's') : ''"></span>
    </div>

    <!-- Bulk action bar -->
    <div x-show="selectedIds.length > 0"
         x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
         x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
         class="ff-bulk-bar">
        <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
        <div class="ff-bulk-bar-sep"></div>
        <!-- Delete: only reported/assessed rows are eligible -->
        <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
            <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
            Delete
        </button>
        <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear" aria-label="Clear selection">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
        </button>
    </div>

    <!-- Loading -->
    <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
        <span class="text-secondary">Loading…</span>
    </div>

    <!-- Empty state -->
    <template x-if="!loading && rows.length === 0">
        <div class="card-body">
            <div class="empty-state">
                <p class="empty-state-title">No damage claims found</p>
                <p class="empty-state-text">Try adjusting your filters, or create a new claim.</p>
            </div>
        </div>
    </template>

    <!-- Table -->
    <template x-if="!loading && rows.length > 0">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th class="th-checkbox">
                            <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all">
                        </th>
                        <th class="th-sortable" @click="setSort('claim_number')">
                            Claim # <span x-show="sort === 'claim_number'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th>Unit</th>
                        <th>Customer</th>
                        <th class="th-sortable" @click="setSort('severity')">
                            Severity <span x-show="sort === 'severity'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('status')">
                            Status <span x-show="sort === 'status'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('estimated_repair_cost')" style="text-align:right;">
                            Est. Cost <span x-show="sort === 'estimated_repair_cost'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                        <th class="th-sortable" @click="setSort('created_at')">
                            Reported <span x-show="sort === 'created_at'" x-text="dir === 'ASC' ? '↑' : '↓'"></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in rows" :key="row.id">
                        <tr class="table-row-link"
                            @click="window.location = '<?= base_url('damage_claims/show') ?>?id=' + row.id"
                            style="cursor:pointer;"
                            :class="{ 'ff-row-selected': selectedIds.includes(row.id) }">
                            <td class="td-checkbox" @click.stop>
                                <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(row.id)" @change="toggleSelect(row.id)">
                            </td>
                            <td class="font-mono" x-text="row.claim_number"></td>
                            <td x-text="row.unit_number ?? '—'"></td>
                            <td x-text="row.customer_name ?? '—'"></td>
                            <td>
                                <span class="badge"
                                      :class="severityBadge(row.severity)"
                                      x-text="severityLabel(row.severity)"></span>
                            </td>
                            <td>
                                <span class="badge"
                                      :class="statusBadge(row.status)"
                                      x-text="statusLabel(row.status)"></span>
                            </td>
                            <td class="font-mono" style="text-align:right;"
                                x-text="row.estimated_repair_cost ? '$' + parseFloat(row.estimated_repair_cost).toLocaleString('en-CA', {minimumFractionDigits:2}) : '—'"></td>
                            <td x-text="row.created_at ? row.created_at.substring(0,10) : '—'"></td>
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

<style>
.stat-card[style*="cursor:pointer"]:hover { transform: translateY(-1px); transition: transform 0.15s; }
.stat-card.ring-active { box-shadow: 0 0 0 2px var(--color-primary); }
</style>

<script>
function damageClaimsKpis() {
    return {
        activeTile: '',
        kpis: {
            open:       <?= json_encode($kpis['open']) ?>,
            invoiced:   <?= json_encode($kpis['invoiced']) ?>,
            year_total: <?= json_encode($kpis['year_total']) ?>,
            avg_repair: <?= json_encode(number_format((float)($kpis['avg_repair'] ?? 0), 2, '.', '')) ?>,
        },
        fmt(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        /* Cross-component filter: push filter to the table component via DOM id */
        setFilter(key, val) {
            const el = document.getElementById('damage-claims-table');
            if (!el) return;
            const d = Alpine.$data(el);
            d.filters[key] = d.filters[key] === val ? '' : val;
            d.page = 1;
            d.fetch();
        },
        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/damage_claims/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
    };
}

function damageClaimsList() {
    return {
        rows:        [],
        total:       0,
        page:        1,
        perPage:     25,
        totalPages:  1,
        loading:     false,
        sort:        'created_at',
        dir:         'DESC',
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,
        filters: {
            status:   <?= json_encode($_GET['status'] ?? '') ?>,
            severity: <?= json_encode($_GET['severity'] ?? '') ?>,
            q:        '',
            customer_id:        <?= json_encode($preCustomerId ? (string)$preCustomerId : '') ?>,
            equipment_unit_id:  <?= json_encode($preUnitId    ? (string)$preUnitId    : '') ?>,
        },

        init() { this.fetch(); },

        fetch() {
            this.loading = true;
            const p = new URLSearchParams({ page: this.page, per_page: this.perPage, sort: this.sort, dir: this.dir });
            if (this.filters.status)              p.set('status', this.filters.status);
            if (this.filters.severity)            p.set('severity', this.filters.severity);
            if (this.filters.q)                   p.set('q', this.filters.q);
            if (this.filters.customer_id)         p.set('customer_id', this.filters.customer_id);
            if (this.filters.equipment_unit_id)   p.set('equipment_unit_id', this.filters.equipment_unit_id);

            FF_Api.get('api/v1/damage_claims/index.php?' + p.toString())
                .then(d => {
                    this.rows       = d.data?.items ?? [];
                    this.total      = d.data?.pagination?.total ?? 0;
                    this.totalPages = d.data?.pagination?.total_pages ?? 1;
                })
                .catch(() => { this.rows = []; })
                .finally(() => { this.loading = false; });
        },

        goPage(n) { this.page = n; this.clearSelection(); this.fetch(); },

        setSort(col) {
            if (this.sort === col) { this.dir = this.dir === 'DESC' ? 'ASC' : 'DESC'; }
            else { this.sort = col; this.dir = 'DESC'; }
            this.page = 1;
            this.fetch();
        },

        sortIcon(col) { return this.sort === col ? (this.dir === 'DESC' ? ' ↓' : ' ↑') : ''; },

        /* ── Bulk selection ─────────────────────────────────────────────── */
        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.rows.length > 0 && this.selectedIds.length === this.rows.length;
        },

        toggleSelectAll() {
            if (this.selectAll) { this.selectedIds = []; this.selectAll = false; }
            else { this.selectedIds = this.rows.map(r => r.id); this.selectAll = true; }
        },

        clearSelection() { this.selectedIds = []; this.selectAll = false; },

        async bulkDelete() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask('Delete ' + count + ' claim' + (count === 1 ? '' : 's') + '? This cannot be undone.');
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/damage_claims/bulk_delete') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' deleted' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0, 3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection(); await this.fetch();
                } else { FF_Toast.error(res.error?.message || 'Bulk delete failed.'); }
            } catch (e) { FF_Toast.error('Network error.'); }
            finally { this.bulkWorking = false; }
        },

        resetFilters() {
            this.filters = { status:'', severity:'', q:'', customer_id:'', equipment_unit_id:'' };
            this.page = 1;
            this.clearSelection();
            this.fetch();
        },

        statusBadge(s) {
            return {
                reported:       'badge badge-info',
                assessed:       'badge badge-warning',
                repair_ordered: 'badge badge-warning',
                invoiced:       'badge badge-purple',
                resolved:       'badge badge-success',
                written_off:    'badge badge-neutral',
            }[s] ?? 'badge badge-neutral';
        },

        statusLabel(s) {
            return {
                reported:       'Reported',
                assessed:       'Assessed',
                repair_ordered: 'Repair Ordered',
                invoiced:       'Invoiced',
                resolved:       'Resolved',
                written_off:    'Written Off',
            }[s] ?? s;
        },

        severityBadge(s) {
            return {
                minor:       'badge badge-info',
                moderate:    'badge badge-warning',
                major:       'badge badge-danger',
                total_loss:  'badge badge-danger',
            }[s] ?? 'badge badge-neutral';
        },

        severityLabel(s) {
            return {
                minor:      'Minor',
                moderate:   'Moderate',
                major:      'Major',
                total_loss: 'Total Loss',
            }[s] ?? s;
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
