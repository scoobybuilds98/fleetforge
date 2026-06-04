<?php
declare(strict_types=1);

/**
 * FleetForge — Invoices List Page
 *
 * @file        app/admin/invoices/index.php
 * @description Invoice list with AR aging KPI tiles (Current, 1–30, 31–60, 60+ days),
 *              three tabs (Outstanding / Paid / All), search, status filter (All tab),
 *              sort column selector, direction selector, sortable column headers,
 *              and paginated data table.
 *
 * @depends     config/app.php, includes/auth.php, includes/header.php,
 *              includes/footer.php, api/v1/invoices/index.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.7 Invoices
 * @decisions   D30 (asset_url), D32 (CSS classes)
 * @session     S008, S017
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('invoices', 'view');

// ── AR Aging KPI tiles — server-side so they always reflect reality ──────────
// WHY server-side: these are accounting figures that must be accurate on load,
// not approximations from the paginated list response.
$arCurrent = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status = 'sent' AND due_date >= CURDATE() AND deleted_at IS NULL",
    []
);
$ar30 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE()
        AND due_date >= CURDATE() - INTERVAL 30 DAY
        AND deleted_at IS NULL",
    []
);
$ar60 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE() - INTERVAL 30 DAY
        AND due_date >= CURDATE() - INTERVAL 60 DAY
        AND deleted_at IS NULL",
    []
);
$ar90 = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(balance_due),0) AS total
       FROM invoices
      WHERE status IN ('sent','overdue')
        AND due_date < CURDATE() - INTERVAL 60 DAY
        AND deleted_at IS NULL",
    []
);

// S-QBO-INVOICE-LIST-BADGE: per-row QBO push-status column on the list.
// Visible only when QBO is connected — column hidden entirely (header
// + body cell) when disconnected so the layout stays compact for
// non-QBO operators.
$qboConnected = (string) settings_get('quickbooks.connection_status', 'disconnected') === 'connected';

$pageTitle      = 'Invoices';
$helpModuleSlug = 'invoices';
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
    <h1 class="page-header-title h4">Invoices</h1>
    <div class="page-header-actions">
        <?= help_button('invoices') ?>
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
<div x-data="invoicesKpis()" x-init="loadKpis()">
<div class="stat-grid">

    <!-- TILES-1: each aging tile now drills to the matching bucket via the
         new `aging` query param (current | ar30 | ar60 | ar90) so clicking
         "31-60 Days Overdue" actually shows 31-60 day invoices instead of
         the generic overdue list. Clicking again clears. -->
    <div class="stat-card stat-card--blue"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'current' }"
         @click="activeTile = activeTile === 'current' ? '' : 'current'; setAging(activeTile)">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-document-text"/></svg></span>
        <div class="stat-label">Current</div>
        <div class="stat-value currency" x-text="fmt(kpis.current_total)"></div>
        <div class="stat-delta text-secondary" x-text="kpis.current_cnt + ' invoice' + (kpis.current_cnt !== 1 ? 's' : '')"></div>
    </div>

    <div class="stat-card stat-card--amber"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'ar30' }"
         @click="activeTile = activeTile === 'ar30' ? '' : 'ar30'; setAging(activeTile)">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">1–30 Days Overdue</div>
        <div class="stat-value currency" style="color:var(--color-warning);" x-text="fmt(kpis.ar30_total)"></div>
        <div class="stat-delta text-secondary" x-text="kpis.ar30_cnt + ' overdue'"></div>
    </div>

    <div class="stat-card stat-card--red"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'ar60' }"
         @click="activeTile = activeTile === 'ar60' ? '' : 'ar60'; setAging(activeTile)">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">31–60 Days Overdue</div>
        <div class="stat-value currency" style="color:var(--color-danger);" x-text="fmt(kpis.ar60_total)"></div>
        <div class="stat-delta text-secondary" x-text="kpis.ar60_cnt + ' overdue'"></div>
    </div>

    <div class="stat-card stat-card--red"
         style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'ar90' }"
         @click="activeTile = activeTile === 'ar90' ? '' : 'ar90'; setAging(activeTile)">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-fire"/></svg></span>
        <div class="stat-label">60+ Days Overdue</div>
        <div class="stat-value currency" style="color:var(--color-danger);" x-text="fmt(kpis.ar90_total)"></div>
        <div class="stat-delta text-secondary" x-text="kpis.ar90_cnt + ' overdue'"></div>
    </div>

</div>
</div>

<!-- ============================================================
     INVOICES ALPINE COMPONENT
     ============================================================ -->
<div id="invoices-table-card" x-data="FF_Invoices()" x-init="init()">

    <!-- ── TAB BAR ───────────────────────────────────────────────── -->
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

    <!-- ── FILTER TOOLBAR ────────────────────────────────────────── -->
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

            <!-- Status filter — only on All tab -->
            <select class="form-select form-control-sm"
                    x-show="activeTab === 'all'"
                    x-model="filters.status"
                    @change="resetPage()"
                    aria-label="Filter by status">
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
                  x-text="pagination.total !== undefined
                      ? pagination.total + ' invoice' + (pagination.total !== 1 ? 's' : '')
                      : ''">
            </span>

            <select class="form-select form-control-sm"
                    x-model="filters.sort"
                    @change="resetPage()"
                    aria-label="Sort by">
                <option value="created_at">Newest first</option>
                <option value="invoice_number">Invoice #</option>
                <option value="invoice_date">Invoice date</option>
                <option value="due_date">Due date</option>
                <option value="total_amount">Amount</option>
                <option value="balance_due">Balance due</option>
                <option value="status">Status</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.dir"
                    @change="resetPage()"
                    aria-label="Direction">
                <option value="DESC">↓ Desc</option>
                <option value="ASC">↑ Asc</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ────────────────────────────────────────────── -->
    <div class="card">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div aria-busy="true" aria-label="Loading invoices…">
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
                <p class="empty-state-title">Failed to load invoices</p>
                <p class="empty-state-text" x-text="loadError"></p>
                <button class="btn btn-secondary btn-sm" @click="load()">Retry</button>
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !loadError && invoices.length === 0">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5z"/></svg>
                </div>
                <p class="empty-state-title">No invoices found</p>
                <p class="empty-state-text"
                   x-text="hasActiveFilters() ? 'Try adjusting your filters.' : 'Invoices are generated from active leases.'">
                </p>
            </div>
        </template>

        <!-- Bulk action bar — visible when one or more rows are checked -->
        <div x-show="selectedIds.length > 0"
             x-transition
             class="bulk-action-bar"
             style="display:flex;align-items:center;gap:12px;padding:10px 16px;
                    background:var(--color-surface-raised,#1e2430);
                    border:1px solid var(--color-border);border-radius:8px;
                    margin-bottom:12px;">
            <span class="text-sm font-medium"
                  x-text="selectedIds.length + ' item' + (selectedIds.length === 1 ? '' : 's') + ' selected'"></span>
            <button class="btn btn-danger btn-sm" @click="bulkDelete()">
                Delete selected
            </button>
            <button class="btn btn-ghost btn-sm" @click="clearSelection()">
                Clear
            </button>
        </div>

        <!-- Table -->
        <template x-if="!loading && !loadError && invoices.length > 0">
            <div style="overflow-x:auto;">
                <table class="table" aria-label="Invoices">
                    <thead>
                        <tr>
                            <th style="width:40px;text-align:center;padding:8px 12px;">
                                <input type="checkbox" class="form-checkbox"
                                       :checked="selectAll"
                                       @change="toggleSelectAll()"
                                       title="Select all on this page">
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('invoice_number')">
                                Invoice #
                                <span x-show="filters.sort === 'invoice_number'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col">Customer</th>
                            <th scope="col">Lease / Unit</th>
                            <th scope="col">Period</th>
                            <th scope="col" class="th-sortable" @click="setSort('invoice_date')">
                                Date
                                <span x-show="filters.sort === 'invoice_date'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('due_date')">
                                Due
                                <span x-show="filters.sort === 'due_date'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable text-right" @click="setSort('total_amount')">
                                Total
                                <span x-show="filters.sort === 'total_amount'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable text-right" @click="setSort('balance_due')">
                                Balance
                                <span x-show="filters.sort === 'balance_due'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <th scope="col" class="th-sortable" @click="setSort('status')">
                                Status
                                <span x-show="filters.sort === 'status'"
                                      x-text="filters.dir === 'ASC' ? '↑' : '↓'"></span>
                            </th>
                            <?php if ($qboConnected): /* S-QBO-INVOICE-LIST-BADGE column header — gated on quickbooks.connection_status */ ?>
                            <th scope="col" style="width:1%;white-space:nowrap;text-align:center;" title="QuickBooks sync status">QBO</th>
                            <?php endif; ?>
                            <th scope="col" style="width:1%;white-space:nowrap;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="inv in invoices" :key="inv.id">
                            <tr>
                                <td style="text-align:center;padding:8px 12px;" @click.stop>
                                    <input type="checkbox" class="form-checkbox"
                                           :checked="selectedIds.includes(inv.id)"
                                           @change="toggleSelect(inv.id)">
                                </td>
                                <td>
                                    <a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id"
                                       class="font-mono font-medium link"
                                       x-text="inv.invoice_number">
                                    </a>
                                </td>
                                <td>
                                    <div class="font-medium"
                                         x-text="inv.company_name_snapshot || '—'"></div>
                                </td>
                                <td class="text-sm">
                                    <div class="font-mono"
                                         x-text="inv.contract_number_snapshot || '—'"></div>
                                    <div class="text-secondary"
                                         x-text="inv.unit_number_invoice_snapshot || ''"></div>
                                </td>
                                <td class="text-sm">
                                    <div x-text="formatDate(inv.billing_period_start)"></div>
                                    <div class="text-secondary"
                                         x-text="'→ ' + formatDate(inv.billing_period_end)"></div>
                                </td>
                                <td class="text-sm" x-text="formatDate(inv.invoice_date)"></td>
                                <td class="text-sm">
                                    <span :class="isOverdue(inv) ? 'text-danger font-medium' : ''"
                                          x-text="formatDate(inv.due_date)"></span>
                                </td>
                                <td class="font-mono text-sm text-right"
                                    x-text="'$' + formatMoney(inv.total_amount)"></td>
                                <td class="font-mono text-sm text-right">
                                    <span :class="parseFloat(inv.balance_due) > 0 ? 'text-danger' : 'text-secondary'"
                                          x-text="parseFloat(inv.balance_due) > 0
                                              ? '$' + formatMoney(inv.balance_due)
                                              : '—'">
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-no-dot"
                                          :class="statusBadgeClass(inv.status)"
                                          x-text="statusLabel(inv.status)">
                                    </span>
                                </td>
                                <?php if ($qboConnected): /* S-QBO-INVOICE-LIST-BADGE per-row badge — clicks open the QBO invoices admin */ ?>
                                <td style="text-align:center;white-space:nowrap;">
                                    <template x-if="inv.qbo_push_status">
                                        <a :href="'<?= base_url('quickbooks/invoices') ?>'"
                                           :title="qboLabel(inv.qbo_push_status)"
                                           :class="qboBadgeClass(inv.qbo_push_status)"
                                           class="badge badge-no-dot"
                                           style="font-size:11px; text-decoration:none; min-width:1.5em; display:inline-block;"
                                           x-text="qboIcon(inv.qbo_push_status)"></a>
                                    </template>
                                    <template x-if="!inv.qbo_push_status">
                                        <span class="text-muted" style="font-size:11px;" title="QuickBooks: not synced">○</span>
                                    </template>
                                </td>
                                <?php endif; ?>
                                <td style="text-align:right;white-space:nowrap;">
                                    <a :href="'<?= base_url('invoices/show') ?>?id=' + inv.id"
                                       class="btn btn-ghost btn-xs">View</a>
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

</div><!-- /x-data -->

<style>
.stat-card[style*="cursor:pointer"]:hover { transform: translateY(-1px); transition: transform 0.15s; }
.stat-card.ring-active { box-shadow: 0 0 0 2px var(--color-primary); }
</style>

<script>
function invoicesKpis() {
    return {
        activeTile: '',
        kpis: {
            current_total: <?= json_encode($arCurrent['total'] ?? '0.00') ?>,
            current_cnt:   <?= json_encode((int)($arCurrent['cnt'] ?? 0)) ?>,
            ar30_total:    <?= json_encode($ar30['total'] ?? '0.00') ?>,
            ar30_cnt:      <?= json_encode((int)($ar30['cnt'] ?? 0)) ?>,
            ar60_total:    <?= json_encode($ar60['total'] ?? '0.00') ?>,
            ar60_cnt:      <?= json_encode((int)($ar60['cnt'] ?? 0)) ?>,
            ar90_total:    <?= json_encode($ar90['total'] ?? '0.00') ?>,
            ar90_cnt:      <?= json_encode((int)($ar90['cnt'] ?? 0)) ?>,
        },
        fmt(n) { return '$' + Number(n || 0).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        /**
         * Cross-component filter: targets the FF_Invoices table via DOM id.
         * Toggle behavior: clicking the same tile again clears the filter.
         */
        setFilter(key, val) {
            const el = document.getElementById('invoices-table-card');
            if (!el) return;
            const d = Alpine.$data(el);
            d.filters[key] = d.filters[key] === val ? '' : val;
            d.activeTab = 'all';
            d.currentPage = 1;
            d.load();
        },

        /**
         * TILES-1: AR-aging bucket drilldown. Used by the 4 invoice KPI tiles
         * (Current / 1-30 / 31-60 / 60+). Passes the tile key through to the
         * invoices list filter so the list narrows to the exact bucket the
         * user clicked instead of the generic overdue status filter.
         * Empty bucket clears the filter.
         */
        setAging(bucket) {
            const el = document.getElementById('invoices-table-card');
            if (!el) return;
            const d = Alpine.$data(el);
            d.filters.aging  = bucket || '';
            d.filters.status = '';
            d.activeTab      = 'all';
            d.currentPage    = 1;
            d.load();
        },

        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/invoices/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
    };
}

function FF_Invoices() {
    return {
        invoices:    [],
        loading:     true,
        loadError:   null,
        pagination:  {},
        activeTab:   'outstanding',

        // Bulk-select state
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,

        filters: {
            search:     '',
            status:     '',
            aging:      '',   // TILES-1: AR aging bucket filter (current|ar30|ar60|ar90)
            sort:       'created_at',
            dir:        'DESC',
            leaseId:    '',   // BUGFIX-1: from ?lease_id= URL param (tile drill-through)
            customerId: '',   // BUGFIX-1: from ?customer_id= URL param (tile drill-through)
        },
        currentPage: 1,

        async init() {
            // BUGFIX-1: Read context params set by tiles on lease/customer show pages.
            // e.g. invoices?lease_id=3&status=overdue from the "Outstanding" tile on a lease.
            const sp = new URLSearchParams(location.search);
            const urlLeaseId    = sp.get('lease_id');
            const urlCustomerId = sp.get('customer_id');
            const urlStatus     = sp.get('status');

            if (urlLeaseId)    this.filters.leaseId    = urlLeaseId;
            if (urlCustomerId) this.filters.customerId = urlCustomerId;

            // When a status param is passed alongside lease/customer context,
            // switch to the 'all' tab with that status so the filter applies.
            if (urlStatus) {
                this.activeTab      = 'all';
                this.filters.status = urlStatus;
            }

            await this.load();
        },

        setTab(tab) {
            this.activeTab      = tab;
            this.filters.status = '';
            this.filters.aging  = '';   // TILES-1: clear aging drill when switching tabs
            // Also reset the tile active-ring state held in invoicesKpis()
            const kpisEl = document.querySelector('[x-data="invoicesKpis()"]');
            if (kpisEl) { try { Alpine.$data(kpisEl).activeTile = ''; } catch (_e) {} }
            this.currentPage    = 1;
            this.load();
        },

        async load() {
            this.clearSelection();
            this.loading   = true;
            this.loadError = null;

            const params = new URLSearchParams();

            // Tab drives status scoping.
            // Outstanding = draft|sent|partially_paid|overdue — API only accepts one status
            // at a time so we fetch unfiltered and filter client-side for this tab.
            if (this.activeTab === 'paid') {
                params.set('status', 'paid');
            } else if (this.activeTab === 'all' && this.filters.status) {
                params.set('status', this.filters.status);
            }
            // outstanding: no API status param — filter client-side after fetch

            // TILES-1: AR aging bucket from tile click. API translates this
            // into due_date range + status constraint so we DON'T also send
            // the status filter when aging is set (the API already scopes it).
            if (this.filters.aging) {
                params.set('aging', this.filters.aging);
                params.delete('status');
            }

            if (this.filters.search)     params.set('q',           this.filters.search);
            if (this.filters.leaseId)    params.set('lease_id',    this.filters.leaseId);
            if (this.filters.customerId) params.set('customer_id', this.filters.customerId);
            params.set('sort',     this.filters.sort);
            params.set('dir',      this.filters.dir);
            params.set('page',     this.currentPage);
            params.set('per_page', 25);

            try {
                const r = await FF_Api.get('<?= base_url('api/v1/invoices') ?>?' + params);
                if (r.success) {
                    let items = r.data.items;

                    if (this.activeTab === 'outstanding') {
                        const open = ['draft', 'sent', 'partially_paid', 'overdue'];
                        items = items.filter(i => open.includes(i.status));
                    }

                    this.invoices   = items;
                    this.pagination = r.data.pagination;
                } else {
                    this.loadError = r.message || 'Failed to load invoices.';
                }
            } catch(e) {
                this.loadError = 'Network error. Please try again.';
            }
            this.loading = false;
        },

        resetPage() { this.currentPage = 1; this.load(); },
        goToPage(p)  { this.currentPage = p; this.load(); },

        // Toggle direction when clicking the active sort column;
        // default DESC for numeric/date columns, ASC for invoice_number/status.
        setSort(col) {
            if (this.filters.sort === col) {
                this.filters.dir = this.filters.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.filters.sort = col;
                this.filters.dir  = ['invoice_number', 'status'].includes(col) ? 'ASC' : 'DESC';
            }
            this.resetPage();
        },

        hasActiveFilters() {
            return this.filters.search || this.filters.status;
        },

        statusBadgeClass(status) {
            const map = {
                draft:          'badge-neutral',
                sent:           'badge-info',
                paid:           'badge-success',
                partially_paid: 'badge-warning',
                overdue:        'badge-danger',
                void:           'badge-neutral',
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
            if (['paid', 'void', 'written_off'].includes(inv.status)) return false;
            return inv.due_date && new Date(inv.due_date + 'T00:00:00') < new Date();
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        formatMoney(val) {
            const n = parseFloat(val);
            if (isNaN(n)) return '0.00';
            return n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // S-QBO-INVOICE-LIST-BADGE: per-row QBO push-status helpers.
        // Vocabulary mirrors app/admin/invoices/show.php lines 1082-1107
        // so the list-view badge classifications stay consistent with
        // the canonical S-QBO-11 show-page badge.
        qboBadgeClass(s) {
            if (s === 'pushed') return 'badge-success';
            if (s === 'failed') return 'badge-danger';
            if (typeof s === 'string' && s.startsWith('failed_preflight')) return 'badge-warning';
            return 'badge-neutral'; // pending, skipped_*, anything else
        },
        qboIcon(s) {
            if (s === 'pushed') return '✓';
            if (s === 'pending') return '⋯';
            if (typeof s === 'string' && s.startsWith('failed')) return '✗';
            if (typeof s === 'string' && s.startsWith('skipped')) return '–';
            return '○';
        },
        qboLabel(s) {
            const m = {
                pushed:                              'QuickBooks: Pushed',
                pending:                             'QuickBooks: Queued',
                failed:                              'QuickBooks: Push Failed',
                failed_preflight:                    'QuickBooks: Pre-flight Blocked',
                failed_preflight_field_too_long:     'QuickBooks: Pre-flight Blocked (field too long)',
                failed_preflight_currency_mismatch:  'QuickBooks: Pre-flight Blocked (currency mismatch)',
                skipped_voided:                      'QuickBooks: Skipped (voided)',
                skipped_by_mode:                     'QuickBooks: Skipped (mode)',
            };
            return m[s] || (s ? ('QuickBooks: ' + s) : 'QuickBooks: not synced');
        },

        // ── Bulk-select helpers ──────────────────────────────────────────────
        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.invoices.length > 0 && this.selectedIds.length === this.invoices.length;
        },
        toggleSelectAll() {
            if (this.selectAll) {
                this.selectedIds = [];
                this.selectAll   = false;
            } else {
                this.selectedIds = this.invoices.map(item => item.id);
                this.selectAll   = true;
            }
        },
        clearSelection() {
            this.selectedIds = [];
            this.selectAll   = false;
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
                const res = await FF_Api.post('<?= base_url('api/v1/invoices/bulk_delete') ?>', { ids: this.selectedIds });
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
