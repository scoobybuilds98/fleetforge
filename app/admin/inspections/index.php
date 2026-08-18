<?php
declare(strict_types=1);

/**
 * app/admin/inspections/index.php
 *
 * Inspections list page.
 * Server-renders 4 KPI tiles, then Alpine.js loads the filterable table.
 *
 * KPI tiles: Total, Draft, Complete, Signed This Month.
 * Filters: status, inspection_type, q (search).
 * Default sort: inspection_date DESC.
 * Bulk actions: bulk delete (hard delete — permanent).
 *
 * @depends  config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *           api/v1/inspections/index.php
 * @decisions D5/D7/D30/D32
 * @session  S016
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('inspections', 'view');

// ── KPI tiles (server-rendered) ──────────────────────────────────────────────
$kpiTotal  = db_count("SELECT COUNT(*) FROM inspections");
$kpiDraft  = db_count("SELECT COUNT(*) FROM inspections WHERE status = 'draft'");
$kpiComplete = db_count("SELECT COUNT(*) FROM inspections WHERE status = 'complete'");
$kpiSigned = db_count(
    "SELECT COUNT(*) FROM inspections
     WHERE status = 'signed'
       AND signed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
);

$pageTitle = 'Inspections';
$helpModuleSlug = 'inspections';
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Inspections</h1>
    <?php if (can('inspections', 'create')): ?>
    <a href="<?= base_url('inspections/create') ?>" class="btn btn-primary btn-sm">
        + New Inspection
    </a>
    <?php endif; ?>
    <div class="page-header-actions">
        <?= help_button('inspections') ?>
    </div>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div x-data="inspectionsKpis()" x-init="loadKpis()">
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card stat-card--blue" style="cursor:pointer"
         @click="activeTile = activeTile === 'total' ? '' : 'total'; drill('status', '')"
         :class="{ 'ring-active': activeTile === 'total' }">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-magnifying-glass"/></svg></span>
        <div class="stat-label">Total Inspections</div>
        <div class="stat-value font-mono" x-text="kpis.total"></div>
        <div class="stat-delta">all time</div>
    </div>

    <div class="stat-card stat-card--amber" style="cursor:pointer"
         @click="activeTile = activeTile === 'draft' ? '' : 'draft'; drill('status', activeTile === 'draft' ? 'draft' : '')"
         :class="{ 'ring-active': activeTile === 'draft' }">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-pencil"/></svg></span>
        <div class="stat-label">Draft</div>
        <div class="stat-value font-mono" x-text="kpis.draft"></div>
        <div class="stat-delta">in progress</div>
    </div>

    <div class="stat-card stat-card--green" style="cursor:pointer"
         @click="activeTile = activeTile === 'complete' ? '' : 'complete'; drill('status', activeTile === 'complete' ? 'complete' : '')"
         :class="{ 'ring-active': activeTile === 'complete' }">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-check-circle"/></svg></span>
        <div class="stat-label">Complete</div>
        <div class="stat-value font-mono" x-text="kpis.complete"></div>
        <div class="stat-delta">awaiting signature</div>
    </div>

    <!-- TILES-1: Signed This Month drills to the complete status -->
    <div class="stat-card stat-card--teal" style="cursor:pointer"
         :class="{ 'ring-active': activeTile === 'signed' }"
         @click="activeTile = activeTile === 'signed' ? '' : 'signed'; drill('status', activeTile === 'signed' ? 'complete' : '')">
        <span class="stat-icon stat-icon--teal"><svg><use href="#icon-pencil-square"/></svg></span>
        <div class="stat-label">Signed This Month</div>
        <div class="stat-value font-mono" x-text="kpis.signed_this_month"></div>
        <div class="stat-delta">finalized</div>
    </div>

</div>
</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<!-- S-LIST-TOOLBAR: bare Alpine root so the filter toolbar sits ABOVE the table
     card, matching customers/invoices. id stays on the x-data element —
     the KPI tiles' drill() resolves it via Alpine.$data(). -->
<div id="inspections-table"
     x-data="inspectionList()"
     x-init="loadInspections()">

    <!-- ── FILTER TOOLBAR ────────────────────────────────────────── -->
    <div class="table-toolbar">

        <div class="table-toolbar-left">
            <input type="search" class="form-control form-control-sm"
                   placeholder="Search inspection #, unit, inspector…"
                   style="min-width:240px;"
                   maxlength="255"
                   x-model="filters.q"
                   @input.debounce.400ms="loadInspections()"
                   aria-label="Search inspections">

            <select class="form-select form-control-sm"
                    x-model="filters.status" @change="loadInspections()"
                    aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="complete">Complete</option>
                <option value="signed">Signed</option>
            </select>

            <select class="form-select form-control-sm"
                    x-model="filters.inspection_type" @change="loadInspections()"
                    aria-label="Filter by type">
                <option value="">All Types</option>
                <option value="pre_lease">Pre-Lease</option>
                <option value="post_lease">Post-Lease</option>
                <option value="periodic">Periodic</option>
                <option value="damage">Damage</option>
                <option value="compliance">Compliance</option>
            </select>

            <button class="btn btn-secondary btn-sm" @click="clearFilters()">Reset</button>
        </div>

        <div class="table-toolbar-right">
            <span class="text-secondary text-sm"
                  x-show="!loading"
                  x-text="total + ' result' + (total !== 1 ? 's' : '')"></span>

            <select class="form-select form-control-sm"
                    x-model="sort" @change="page = 1; loadInspections()"
                    aria-label="Sort by">
                <optgroup label="Dates">
                    <option value="inspection_date">Inspection date</option>
                    <option value="created_at">Date created</option>
                    <option value="updated_at">Last updated</option>
                </optgroup>
                <optgroup label="Identifier">
                    <option value="inspection_number">Inspection #</option>
                    <option value="unit_number">Unit #</option>
                    <option value="status">Status</option>
                    <option value="inspection_type">Type</option>
                </optgroup>
                <optgroup label="Condition">
                    <option value="overall_condition">Overall condition</option>
                </optgroup>
            </select>

            <select class="form-select form-control-sm"
                    x-model="dir" @change="page = 1; loadInspections()"
                    aria-label="Sort direction"
                    style="width:auto;">
                <option value="DESC">↓ Desc</option>
                <option value="ASC">↑ Asc</option>
            </select>
        </div>

    </div>

    <!-- ── TABLE CARD ────────────────────────────────────────────── -->
    <div class="card">

    <!-- Bulk action bar -->
    <div x-show="selectedIds.length > 0"
         x-transition:enter="ff-bulk-enter" x-transition:enter-start="ff-bulk-enter-from" x-transition:enter-end="ff-bulk-enter-to"
         x-transition:leave="ff-bulk-leave" x-transition:leave-start="ff-bulk-leave-from" x-transition:leave-end="ff-bulk-leave-to"
         class="ff-bulk-bar">
        <span class="ff-bulk-bar-count" x-text="selectedIds.length + ' selected'"></span>
        <div class="ff-bulk-bar-sep"></div>
        <button class="ff-bulk-btn ff-bulk-btn-delete" @click="bulkDelete()" :disabled="bulkWorking">
            <svg width="12" height="13" viewBox="0 0 12 13" fill="currentColor"><path d="M4.5 1h3a.5.5 0 0 1 .5.5v.5H4v-.5A.5.5 0 0 1 4.5 1ZM3 2h6l-.4 7.2A1.5 1.5 0 0 1 7.1 10.5H4.9a1.5 1.5 0 0 1-1.5-1.3L3 2Z"/><path d="M1 2h10" stroke="currentColor" stroke-width="1" stroke-linecap="round" fill="none"/></svg>
            Delete
        </button>
        <button class="ff-bulk-btn ff-bulk-btn-clear" @click="clearSelection()" title="Clear" aria-label="Clear selection">
            <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><line x1="1" y1="1" x2="9" y2="9"/><line x1="9" y1="1" x2="1" y2="9"/></svg>
        </button>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th class="th-checkbox">
                        <input type="checkbox" class="ff-checkbox" :checked="selectAll" @change="toggleSelectAll()" title="Select all">
                    </th>
                    <th class="th-sortable" @click="setSort('inspection_number')">Inspection # <span x-show="sort === 'inspection_number'" x-text="dir === 'ASC' ? '↑' : '↓'"></span></th>
                    <th class="th-sortable" @click="setSort('inspection_type')">Type <span x-show="sort === 'inspection_type'" x-text="dir === 'ASC' ? '↑' : '↓'"></span></th>
                    <th>Unit</th>
                    <th class="th-sortable" @click="setSort('inspection_date')">Date <span x-show="sort === 'inspection_date'" x-text="dir === 'ASC' ? '↑' : '↓'"></span></th>
                    <th>Inspector</th>
                    <th>Lease</th>
                    <th class="th-sortable" @click="setSort('overall_condition')">Condition <span x-show="sort === 'overall_condition'" x-text="dir === 'ASC' ? '↑' : '↓'"></span></th>
                    <th class="th-sortable" @click="setSort('status')">Status <span x-show="sort === 'status'" x-text="dir === 'ASC' ? '↑' : '↓'"></span></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--text-muted);">Loading...</td></tr>
                </template>
                <template x-if="!loading && inspections.length === 0">
                    <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--text-muted);">No inspections found.</td></tr>
                </template>
                <template x-for="insp in inspections" :key="insp.id">
                    <tr :class="{ 'ff-row-selected': selectedIds.includes(insp.id) }">
                        <td class="td-checkbox" @click.stop>
                            <input type="checkbox" class="ff-checkbox" :checked="selectedIds.includes(insp.id)" @change="toggleSelect(insp.id)">
                        </td>
                        <td class="font-mono" x-text="insp.inspection_number || ('#' + insp.id)"></td>
                        <td><span class="badge" :class="typeBadge(insp.inspection_type)" x-text="typeLabel(insp.inspection_type)"></span></td>
                        <td>
                            <span x-text="insp.unit_number"></span>
                            <span class="text-secondary" style="font-size:0.8rem;" x-text="insp.brand ? ' — ' + insp.brand + ' ' + insp.model : ''"></span>
                        </td>
                        <td class="font-mono" x-text="insp.inspection_date"></td>
                        <td x-text="insp.inspected_by || '—'"></td>
                        <td x-text="insp.contract_number || '—'"></td>
                        <td>
                            <span x-show="insp.overall_condition" class="badge" :class="conditionBadge(insp.overall_condition)" x-text="insp.overall_condition || ''"></span>
                            <span x-show="!insp.overall_condition" class="text-muted">—</span>
                        </td>
                        <td><span class="badge" :class="statusBadge(insp.status)" x-text="statusLabel(insp.status)"></span></td>
                        <td>
                            <a :href="'<?= base_url('inspections/show') ?>?id=' + insp.id"
                               class="btn btn-xs btn-ghost">View</a>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;"
         x-show="totalPages > 1">
        <span class="text-secondary text-sm"
              x-text="'Page ' + page + ' of ' + totalPages"></span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-sm btn-ghost" :disabled="page <= 1" @click="page--; loadInspections()">Previous</button>
            <button class="btn btn-sm btn-ghost" :disabled="page >= totalPages" @click="page++; loadInspections()">Next</button>
        </div>
    </div>
    </div><!-- /card -->

</div><!-- /inspections-table -->

<style>
.stat-card[style*="cursor:pointer"]:hover { transform: translateY(-1px); transition: transform 0.15s; }
.stat-card.ring-active { box-shadow: 0 0 0 2px var(--color-primary); }
</style>

<script>
function inspectionsKpis() {
    return {
        activeTile: '',
        kpis: {
            total:              <?= json_encode($kpiTotal) ?>,
            draft:              <?= json_encode($kpiDraft) ?>,
            complete:           <?= json_encode($kpiComplete) ?>,
            signed_this_month:  <?= json_encode($kpiSigned) ?>,
        },
        async loadKpis() {
            const r = await FF_Api.get('<?= base_url('api/v1/inspections/kpis') ?>');
            if (r.success) Object.assign(this.kpis, r.data);
        },
        drill(filter, val) {
            const el = document.getElementById('inspections-table');
            if (!el) return;
            const d = Alpine.$data(el);
            d.filters[filter] = d.filters[filter] === val ? '' : val;
            d.page = 1;
            d.loadInspections();
        },
    };
}

function inspectionList() {
    return {
        inspections: [],
        loading:     true,
        total:       0,
        page:        1,
        totalPages:  1,
        sort:        'inspection_date',
        dir:         'DESC',
        // Bulk select
        selectedIds: [],
        selectAll:   false,
        bulkWorking: false,
        filters: {
            q:               '',
            status:          '',
            inspection_type: '',
        },

        loadInspections() {
            this.clearSelection();
            this.loading = true;
            const p = new URLSearchParams({
                page:     this.page,
                per_page: 25,
                sort:     this.sort,
                dir:      this.dir,
            });
            if (this.filters.q)               p.set('q',               this.filters.q);
            if (this.filters.status)          p.set('status',          this.filters.status);
            if (this.filters.inspection_type) p.set('inspection_type', this.filters.inspection_type);

            FF_Api.get('<?= base_url('api/v1/inspections/index.php') ?>?' + p.toString())
                .then(d => {
                    if (d && d.error) {
                        this.inspections = [];
                        this.total       = 0;
                    } else {
                        this.inspections = d.data?.items        ?? [];
                        this.total       = d.data?.pagination?.total      ?? 0;
                        this.totalPages  = d.data?.pagination?.total_pages ?? 1;
                    }
                    this.loading = false;
                });
        },

        setSort(col) {
            if (this.sort === col) {
                this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sort = col;
                this.dir  = 'DESC';
            }
            this.page = 1;
            this.loadInspections();
        },

        setFilter(key, val) {
            this.filters[key] = val;
            this.page = 1;
            this.loadInspections();
        },

        clearFilters() {
            this.filters = { q: '', status: '', inspection_type: '' };
            this.sort = 'inspection_date';
            this.dir  = 'DESC';
            this.page = 1;
            this.loadInspections();
        },

        // ── Bulk select ──────────────────────────────────────────────────
        toggleSelect(id) {
            const idx = this.selectedIds.indexOf(id);
            if (idx === -1) this.selectedIds.push(id);
            else this.selectedIds.splice(idx, 1);
            this.selectAll = this.inspections.length > 0 && this.selectedIds.length === this.inspections.length;
        },
        toggleSelectAll() {
            if (this.selectAll) { this.selectedIds = []; this.selectAll = false; }
            else { this.selectedIds = this.inspections.map(i => i.id); this.selectAll = true; }
        },
        clearSelection() { this.selectedIds = []; this.selectAll = false; },
        async bulkDelete() {
            if (!this.selectedIds.length || this.bulkWorking) return;
            const count = this.selectedIds.length;
            const confirmed = await FF_Confirm.ask(
                'Permanently delete ' + count + ' inspection' + (count === 1 ? '' : 's') + '? ' +
                'This cannot be undone.'
            );
            if (!confirmed) return;
            this.bulkWorking = true;
            try {
                const res = await FF_Api.post('<?= base_url('api/v1/inspections/bulk_delete') ?>', { ids: this.selectedIds });
                if (res.success) {
                    const d = res.data;
                    if (d.actioned > 0) FF_Toast.success(d.actioned + ' permanently deleted' + (d.skipped > 0 ? ', ' + d.skipped + ' skipped' : '') + '.');
                    if (d.errors?.length) FF_Toast.error(d.errors.length + ' failed: ' + d.errors.slice(0, 3).map(e => e.reason).join('; ') + (d.errors.length > 3 ? '…' : ''));
                    this.clearSelection();
                    await this.loadInspections();
                } else { FF_Toast.error(res.error?.message || 'Bulk delete failed.'); }
            } catch (e) { FF_Toast.error('Network error.'); }
            finally { this.bulkWorking = false; }
        },

        // ── Badge helpers ────────────────────────────────────────────────
        typeBadge(t) {
            return {
                pre_lease:  'badge-info',
                post_lease: 'badge-warning',
                periodic:   'badge-neutral',
                damage:     'badge-danger',
                compliance: 'badge-success',
            }[t] ?? 'badge-neutral';
        },
        typeLabel(t) {
            return {
                pre_lease:  'Pre-Lease',
                post_lease: 'Post-Lease',
                periodic:   'Periodic',
                damage:     'Damage',
                compliance: 'Compliance',
            }[t] ?? t;
        },
        statusBadge(s) {
            return { draft: 'badge-warning', complete: 'badge-info', signed: 'badge-success' }[s] ?? 'badge-neutral';
        },
        statusLabel(s) {
            return { draft: 'Draft', complete: 'Complete', signed: 'Signed' }[s] ?? s;
        },
        conditionBadge(c) {
            return {
                excellent: 'badge-success',
                good:      'badge-success',
                fair:      'badge-warning',
                poor:      'badge-danger',
                damaged:   'badge-danger',
            }[c] ?? 'badge-neutral';
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
