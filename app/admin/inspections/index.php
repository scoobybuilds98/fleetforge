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
require_once FF_ROOT . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-header-title">Inspections</h1>
    <?php if (can('inspections', 'create')): ?>
    <a href="<?= base_url('inspections/create') ?>" class="btn btn-primary btn-sm">
        + New Inspection
    </a>
    <?php endif; ?>
</div>

<!-- ── KPI tiles ─────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card" style="cursor:pointer;" @click="setFilter('status','')">
        <div class="stat-label">Total Inspections</div>
        <div class="stat-value font-mono"><?= e($kpiTotal) ?></div>
        <div class="stat-delta">all time</div>
    </div>

    <div class="stat-card" style="cursor:pointer;" @click="setFilter('status','draft')">
        <div class="stat-label">Draft</div>
        <div class="stat-value font-mono"><?= e($kpiDraft) ?></div>
        <div class="stat-delta">in progress</div>
    </div>

    <div class="stat-card" style="cursor:pointer;" @click="setFilter('status','complete')">
        <div class="stat-label">Complete</div>
        <div class="stat-value font-mono"><?= e($kpiComplete) ?></div>
        <div class="stat-delta">awaiting signature</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Signed This Month</div>
        <div class="stat-value font-mono"><?= e($kpiSigned) ?></div>
        <div class="stat-delta">finalized</div>
    </div>

</div>

<!-- ── Table (Alpine.js) ──────────────────────────────────────────────────── -->
<div class="card"
     x-data="inspectionList()"
     x-init="loadInspections()">

    <!-- Filters -->
    <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

        <input type="text" class="form-control form-control-sm"
               placeholder="Search inspection #, unit, inspector..."
               style="width:240px;"
               x-model="filters.q"
               @input.debounce.400ms="loadInspections()">

        <select class="form-control form-control-sm" style="width:150px;"
                x-model="filters.status" @change="loadInspections()">
            <option value="">All Statuses</option>
            <option value="draft">Draft</option>
            <option value="complete">Complete</option>
            <option value="signed">Signed</option>
        </select>

        <select class="form-control form-control-sm" style="width:170px;"
                x-model="filters.inspection_type" @change="loadInspections()">
            <option value="">All Types</option>
            <option value="pre_lease">Pre-Lease</option>
            <option value="post_lease">Post-Lease</option>
            <option value="periodic">Periodic</option>
            <option value="damage">Damage</option>
            <option value="compliance">Compliance</option>
        </select>

        <button class="btn btn-sm btn-ghost" @click="clearFilters()">Clear</button>

        <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
              x-text="total + ' result' + (total !== 1 ? 's' : '')"></span>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Inspection #</th>
                    <th>Type</th>
                    <th>Unit</th>
                    <th>Date</th>
                    <th>Inspector</th>
                    <th>Lease</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <template x-if="loading">
                    <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">Loading...</td></tr>
                </template>
                <template x-if="!loading && inspections.length === 0">
                    <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No inspections found.</td></tr>
                </template>
                <template x-for="insp in inspections" :key="insp.id">
                    <tr>
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
</div>

<script>
function inspectionList() {
    return {
        inspections: [],
        loading:     true,
        total:       0,
        page:        1,
        totalPages:  1,
        filters: {
            q:               '',
            status:          '',
            inspection_type: '',
        },

        loadInspections() {
            this.loading = true;
            const p = new URLSearchParams({
                page:     this.page,
                per_page: 25,
                sort:     'inspection_date',
                dir:      'DESC',
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

        setFilter(key, val) {
            this.filters[key] = val;
            this.page = 1;
            this.loadInspections();
        },

        clearFilters() {
            this.filters = { q: '', status: '', inspection_type: '' };
            this.page = 1;
            this.loadInspections();
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
