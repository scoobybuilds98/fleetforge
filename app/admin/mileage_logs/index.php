<?php
declare(strict_types=1);

// ============================================================
// /mileage_logs — Mileage Logs list
//
// Shows all mileage log entries with:
//   - 4 KPI tiles: total logs, manual, GPS sync, last GPS sync date
//   - Filterable table: unit, log type, date range
//   - Colour-coded log_type badges
//   - Row-click to show page
//
// Permission: maintenance view
// ============================================================

require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_auth();
require_permission('maintenance', 'view');

// ── KPI tiles (server-rendered for speed)
$totalLogs   = db_count("SELECT COUNT(*) FROM mileage_logs", []);
$manualCount = db_count("SELECT COUNT(*) FROM mileage_logs WHERE log_type = 'manual'", []);
$gpsSyncCount = db_count("SELECT COUNT(*) FROM mileage_logs WHERE log_type = 'gps_sync'", []);
$lastGpsSync  = db_row(
    "SELECT log_date FROM mileage_logs WHERE log_type = 'gps_sync' ORDER BY log_date DESC, id DESC LIMIT 1",
    []
);
$lastGpsSyncDate = $lastGpsSync ? format_date($lastGpsSync['log_date']) : '—';

// ── Load units for filter dropdown (same query as create.php).
//    [SELECTOR-1] This is a READ-ONLY filter, so it intentionally
//    shows EVERY non-deleted unit regardless of status — a user
//    filtering by "decommissioned trailer that we sold last year"
//    is a totally legitimate report. The label still includes the
//    status badge for visual confirmation, but no row is disabled.
$filterUnits = db_select(
    "SELECT eu.id, eu.unit_number, eu.status, et.brand, et.model
     FROM equipment_units eu
     JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.deleted_at IS NULL
     ORDER BY eu.unit_number ASC",
    []
);

$pageTitle      = 'Mileage Logs';
$helpModuleSlug = 'mileage-logs';
require_once dirname(__DIR__, 3) . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-header-title">Mileage Logs</h1>
        <p style="color:var(--text-secondary);margin:0;">Odometer history across all equipment units</p>
    </div>
    <div class="page-header-actions">
        <?= help_button('mileage-logs') ?>
        <?php if (can('maintenance', 'create')): ?>
        <a href="<?= base_url('mileage_logs/create') ?>" class="btn btn-primary">
            + Record Mileage
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- TILES-1: KPI tiles now dispatch `ff-mileage-filter` events that the
     FF_MileageLogs Alpine component below listens for. Each click toggles
     the matching log_type filter and reloads page 1. The "Last GPS Sync"
     tile is display-only (no log_type to filter by). -->
<div class="stat-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card"
         style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-mileage-filter', { detail: { log_type: '' } }))">
        <div class="stat-value"><?= number_format($totalLogs) ?></div>
        <div class="stat-label">Total Entries</div>
    </div>
    <div class="stat-card"
         style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-mileage-filter', { detail: { log_type: 'manual' } }))">
        <div class="stat-value"><?= number_format($manualCount) ?></div>
        <div class="stat-label">Manual Entries</div>
    </div>
    <div class="stat-card"
         style="cursor:pointer"
         onclick="window.dispatchEvent(new CustomEvent('ff-mileage-filter', { detail: { log_type: 'gps_sync' } }))">
        <div class="stat-value"><?= number_format($gpsSyncCount) ?></div>
        <div class="stat-label">GPS Sync Entries</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="font-size:1.1rem;"><?= e($lastGpsSyncDate) ?></div>
        <div class="stat-label">Last GPS Sync</div>
    </div>
</div>

<!-- Filter + Table (Alpine.js) -->
<!-- TILES-1: @ff-mileage-filter.window listens for the KPI tile clicks above
     and sets filters.log_type from event.detail.log_type, then reloads. -->
<div class="card" x-data="FF_MileageLogs()" x-init="load()"
     @ff-mileage-filter.window="filters.log_type = (filters.log_type === $event.detail.log_type) ? '' : $event.detail.log_type; load(1)">

    <!-- Filter bar -->
    <div class="card-header" style="flex-wrap:wrap;gap:.75rem;">
        <div class="card-title">Log Entries</div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-left:auto;">
            <select class="form-control form-control-sm" style="width:240px;"
                    x-model="filters.equipment_unit_id" @change="load()">
                <option value="">All Units</option>
                <?php foreach ($filterUnits as $u): ?>
                <?php // [SELECTOR-1] Filter — every status visible & selectable. ?>
                <option value="<?= e($u['id']) ?>" data-status="<?= e($u['status']) ?>">
                    <?= e(ff_unit_selector_label($u)) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select class="form-control form-control-sm" style="width:160px;"
                    x-model="filters.log_type" @change="load()">
                <option value="">All Types</option>
                <option value="manual">Manual</option>
                <option value="gps_sync">GPS Sync</option>
                <option value="lease_start">Lease Start</option>
                <option value="lease_end">Lease End</option>
                <option value="service">Service</option>
            </select>
            <input type="date" class="form-control form-control-sm"
                   x-model="filters.date_from" @change="load()" title="From date">
            <input type="date" class="form-control form-control-sm"
                   x-model="filters.date_to" @change="load()" title="To date">
            <button class="btn btn-ghost btn-sm" @click="clearFilters()">Clear</button>
        </div>
    </div>

    <div class="card-body" style="padding:0;">

        <!-- Loading skeleton -->
        <template x-if="loading">
            <div style="padding:2rem;text-align:center;color:var(--text-secondary);">Loading…</div>
        </template>

        <!-- Error state -->
        <template x-if="!loading && error">
            <div class="alert alert-danger" style="margin:1rem;">
                Failed to load mileage logs. Please refresh.
            </div>
        </template>

        <!-- Empty state -->
        <template x-if="!loading && !error && items.length === 0">
            <div style="padding:3rem;text-align:center;">
                <div style="color:var(--text-secondary);margin-bottom:.5rem;">No mileage logs found.</div>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('mileage_logs/create') ?>" class="btn btn-primary btn-sm">
                    Record First Entry
                </a>
                <?php endif; ?>
            </div>
        </template>

        <!-- Table -->
        <template x-if="!loading && !error && items.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Unit</th>
                            <th>Equipment</th>
                            <th>Odometer</th>
                            <th>Type</th>
                            <th>Lease</th>
                            <th>Recorded By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="log in items" :key="log.id">
                            <tr style="cursor:pointer;" @click="goToLog(log.id)">
                                <td x-text="formatDate(log.log_date)"></td>
                                <td>
                                    <strong x-text="log.unit_number"></strong>
                                </td>
                                <td>
                                    <span x-text="log.brand + ' ' + log.model"
                                          style="color:var(--text-secondary);font-size:.875rem;"></span>
                                </td>
                                <td class="font-mono" x-text="formatOdometer(log.odometer_reading, log.mileage_unit)"></td>
                                <td>
                                    <span class="badge" :class="typeBadge(log.log_type)"
                                          x-text="typeLabel(log.log_type)"></span>
                                </td>
                                <td>
                                    <template x-if="log.lease_id">
                                        <a :href="`<?= base_url('leases/show') ?>?id=${log.lease_id}`"
                                           class="link" @click.stop x-text="'Lease #' + log.lease_id"></a>
                                    </template>
                                    <template x-if="!log.lease_id">
                                        <span style="color:var(--text-secondary);">—</span>
                                    </template>
                                </td>
                                <td x-text="log.recorded_by_name || '—'"></td>
                                <td>
                                    <a :href="`<?= base_url('mileage_logs/show') ?>?id=${log.id}`"
                                       class="btn btn-ghost btn-sm" @click.stop>View</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Pagination -->
        <template x-if="!loading && pagination.total_pages > 1">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.75rem 1rem;border-top:1px solid var(--border-color);">
                <div style="color:var(--text-secondary);font-size:.875rem;">
                    Showing <span x-text="((pagination.page-1)*pagination.per_page)+1"></span>–<span
                        x-text="Math.min(pagination.page*pagination.per_page, pagination.total)"></span>
                    of <span x-text="pagination.total"></span>
                </div>
                <div style="display:flex;gap:.25rem;">
                    <button class="btn btn-ghost btn-sm" :disabled="pagination.page <= 1"
                            @click="changePage(pagination.page - 1)">← Prev</button>
                    <button class="btn btn-ghost btn-sm" :disabled="pagination.page >= pagination.total_pages"
                            @click="changePage(pagination.page + 1)">Next →</button>
                </div>
            </div>
        </template>

    </div><!-- /card-body -->
</div><!-- /card -->

<script>
function FF_MileageLogs() {
    return {
        items:      [],
        pagination: { page: 1, per_page: 25, total: 0, total_pages: 1 },
        filters:    { log_type: '', date_from: '', date_to: '', equipment_unit_id: '' },
        loading:    true,
        error:      false,

        async load(page = 1) {
            this.loading = true;
            this.error   = false;
            try {
                const params = new URLSearchParams({ page, per_page: 25 });
                if (this.filters.equipment_unit_id) params.set('equipment_unit_id', this.filters.equipment_unit_id);
                if (this.filters.log_type)          params.set('log_type',          this.filters.log_type);
                if (this.filters.date_from)         params.set('date_from',         this.filters.date_from);
                if (this.filters.date_to)           params.set('date_to',           this.filters.date_to);

                const res  = await fetch(`<?= base_url('api/v1/mileage_logs/index') ?>?${params}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'API error');

                this.items      = data.data?.items ?? [];
                this.pagination = data.data?.pagination ?? this.pagination;
            } catch (e) {
                console.error('MileageLogs load error:', e);
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        changePage(p) { this.load(p); },

        clearFilters() {
            this.filters = { log_type: '', date_from: '', date_to: '', equipment_unit_id: '' };
            this.load();
        },

        goToLog(id) {
            window.location.href = `<?= base_url('mileage_logs/show') ?>?id=${id}`;
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T12:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        formatOdometer(val, unit) {
            if (val === null || val === undefined) return '—';
            const label = unit === 'miles' ? 'mi' : 'km';
            return Number(val).toLocaleString('en-CA') + ' ' + label;
        },

        typeBadge(type) {
            const map = {
                manual:      'badge-info',
                gps_sync:    'badge-success',
                lease_start: 'badge-neutral',
                lease_end:   'badge-neutral',
                service:     'badge-warning',
            };
            return map[type] || 'badge-neutral';
        },

        typeLabel(type) {
            const map = {
                manual:      'Manual',
                gps_sync:    'GPS Sync',
                lease_start: 'Lease Start',
                lease_end:   'Lease End',
                service:     'Service',
            };
            return map[type] || type;
        },
    };
}
</script>

<?php require_once dirname(__DIR__, 3) . '/includes/footer.php'; ?>
