<?php
declare(strict_types=1);

/**
 * app/admin/compliance/index.php
 *
 * Fleet-wide compliance dashboard — grid view of all equipment units with
 * CVI, Registration, and Insurance document status (MVI excluded).
 *
 * Each document column shows:
 *   - "Valid From" date (stored in cvi_from_date / registration_from_date / insurance_from_date)
 *   - "Expiry (To)" date with colour coding
 *   - Document icon linking to the PDF when one is on file
 *
 * Clicking a document cell opens a modal with TWO editable date fields:
 *   Valid From  — when the document became effective
 *   Expiry (To) — when the document expires
 * Both are saved in one API call (api/v1/compliance/update.php, doc_type-based).
 *
 * KPI tiles are Alpine-driven and refresh after every inline save via
 * api/v1/compliance/kpis.php — no full page reload needed.
 *
 * Features:
 *   - Color-coded expiry cells: Red=expired / Yellow=≤30d / Green=>30d / Gray=null
 *   - Filter bar: yard, status, window (7/14/30/60/90 days)
 *   - CSV export via ?export=csv — includes from/to dates + status per column
 *   - 3 KPI tiles refresh dynamically after every save
 *   - Sidebar badge (compliance_alerts) auto-updates on every page load
 *
 * D5:  equipment_units soft-delete always filtered.
 * D19: optimistic lock on update modal — row updated_at tracked per unit.
 * D30: asset_url() for static assets, base_url() for app routes.
 * D32: only CSS classes confirmed in app.css.
 * Trap 7: doc URLs come from API (StorageClient::url()) — never raw paths.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php, includes/footer.php
 *          api/v1/compliance/index.php, api/v1/compliance/update.php,
 *          api/v1/compliance/kpis.php, api/v1/storage/serve.php
 * @decisions D5/D7/D9/D19/D30/D32
 * @permission compliance / view (edit gated by can('compliance','edit'))
 * Spec ref: §7.9 Compliance & Expiry
 * Session: S020-EXT (from+expiry modal, dynamic KPI tiles, direct from_date storage)
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('compliance', 'view');

// -----------------------------------------------------------------------
// CSV export — before any HTML output so headers can be set cleanly.
// -----------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $where  = ["eu.deleted_at IS NULL", "eu.status NOT IN ('inactive','decommissioned')"];
    $params = [];

    if ($yard = clean_string($_GET['yard'] ?? null)) {
        $where[]  = 'eu.yard_location LIKE ?';
        $params[] = '%' . $yard . '%';
    }
    $validStatuses = ['available', 'reserved', 'on_lease', 'maintenance'];
    if ($statusFilter = clean_string($_GET['status'] ?? null)) {
        if (in_array($statusFilter, $validStatuses, true)) {
            $where[]  = 'eu.status = ?';
            $params[] = $statusFilter;
        }
    }
    $window = clean_int($_GET['window'] ?? 0) ?? 0;
    if ($window > 0) {
        $where[]  = "(
            (eu.cvi_expiry IS NOT NULL AND eu.cvi_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY))
            OR (eu.registration_expiry IS NOT NULL AND eu.registration_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY))
            OR (eu.insurance_expiry IS NOT NULL AND eu.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY))
        )";
        $params[] = $window;
        $params[] = $window;
        $params[] = $window;
    }

    $whereSQL = implode(' AND ', $where);

    $rows = db_select(
        "SELECT
             eu.unit_number,
             et.name AS template_name,
             eu.yard_location,
             eu.status,
             eu.cvi_from_date        AS cvi_from,
             eu.cvi_expiry,
             eu.registration_from_date AS registration_from,
             eu.registration_expiry,
             eu.insurance_from_date  AS insurance_from,
             eu.insurance_expiry
         FROM equipment_units eu
         JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
         WHERE $whereSQL
         ORDER BY eu.unit_number ASC",
        $params
    );

    $today = date('Y-m-d');

    /** Returns 'Expired' | 'Expiring Soon' | 'Valid' | 'Not Set' */
    $expiryStatus = static function(?string $date) use ($today): string {
        if ($date === null) return 'Not Set';
        if ($date <= $today) return 'Expired';
        $threshold = date('Y-m-d', strtotime('+30 days'));
        if ($date <= $threshold) return 'Expiring Soon';
        return 'Valid';
    };

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compliance_export_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Unit Number', 'Equipment Type', 'Yard', 'Status',
        'CVI From', 'CVI Expiry', 'CVI Status',
        'Registration From', 'Registration Expiry', 'Registration Status',
        'Insurance From', 'Insurance Expiry', 'Insurance Status',
    ], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['unit_number'],
            $r['template_name'],
            $r['yard_location'] ?? '',
            $r['status'],
            $r['cvi_from'] ?? '',
            $r['cvi_expiry'] ?? '',
            $expiryStatus($r['cvi_expiry']),
            $r['registration_from'] ?? '',
            $r['registration_expiry'] ?? '',
            $expiryStatus($r['registration_expiry']),
            $r['insurance_from'] ?? '',
            $r['insurance_expiry'] ?? '',
            $expiryStatus($r['insurance_expiry']),
        ], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------
// KPI tiles — initial server-render values (Alpine re-fetches after saves)
// -----------------------------------------------------------------------
$today = date('Y-m-d');
$in30  = date('Y-m-d', strtotime('+30 days'));

$initTotalUnits = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')"
);

$initExpiredCount = db_count(
    "SELECT COUNT(DISTINCT id) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
       AND (
           (cvi_expiry IS NOT NULL AND cvi_expiry < ?)
           OR (registration_expiry IS NOT NULL AND registration_expiry < ?)
           OR (insurance_expiry IS NOT NULL AND insurance_expiry < ?)
       )",
    [$today, $today, $today]
);

$initExpiringSoonCount = db_count(
    "SELECT COUNT(DISTINCT id) FROM equipment_units
     WHERE deleted_at IS NULL AND status NOT IN ('inactive','decommissioned')
       AND (
           (cvi_expiry IS NOT NULL AND cvi_expiry >= ? AND cvi_expiry <= ?)
           OR (registration_expiry IS NOT NULL AND registration_expiry >= ? AND registration_expiry <= ?)
           OR (insurance_expiry IS NOT NULL AND insurance_expiry >= ? AND insurance_expiry <= ?)
       )",
    [$today, $in30, $today, $in30, $today, $in30]
);

// Yard list for filter dropdown
$yards = db_select(
    "SELECT DISTINCT yard_location FROM equipment_units
     WHERE deleted_at IS NULL AND yard_location IS NOT NULL AND yard_location != ''
     ORDER BY yard_location ASC",
    []
);

$pageTitle = 'Compliance';
$helpModuleSlug = 'compliance';
require_once FF_ROOT . '/includes/header.php';
?>

<style>
/* WHY: clear, clickable PDF badge in compliance grid — replaces ambiguous 📄 emoji.
   Each column gets its own subtle colour so they're visually distinguishable at a glance. */
.compliance-pdf-link {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 7px;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1;
    border-radius: 4px;
    text-decoration: none;
    flex-shrink: 0;
    white-space: nowrap;
    transition: background 0.15s, border-color 0.15s;
}
/* CVI — red (inspection/safety urgency) */
.compliance-pdf-link--cvi {
    color: #dc2626; background: #fef2f2; border: 1px solid #fecaca;
}
.compliance-pdf-link--cvi:hover {
    background: #fee2e2; border-color: #f87171; color: #b91c1c;
}
/* Registration — blue (official/government) */
.compliance-pdf-link--reg {
    color: #2563eb; background: #eff6ff; border: 1px solid #bfdbfe;
}
.compliance-pdf-link--reg:hover {
    background: #dbeafe; border-color: #60a5fa; color: #1d4ed8;
}
/* Insurance — teal/green (coverage/protection) */
.compliance-pdf-link--ins {
    color: #0d9488; background: #f0fdfa; border: 1px solid #99f6e4;
}
.compliance-pdf-link--ins:hover {
    background: #ccfbf1; border-color: #5eead4; color: #0f766e;
}

/* Clickable date cells — clear interactive affordance */
.compliance-cell-edit {
    padding: 3px 6px;
    border-radius: 4px;
    transition: background 0.15s;
    position: relative;
    cursor: pointer;
}
.compliance-cell-edit:hover {
    background: rgba(255,255,255,0.45);
}
.compliance-cell-edit .compliance-edit-hint {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 0.625rem;
    font-weight: 500;
    color: var(--text-tertiary);
    margin-top: 2px;
    transition: color 0.15s;
}
.compliance-cell-edit:hover .compliance-edit-hint {
    color: var(--color-primary);
}
</style>

<div class="page-header">
    <h1 class="page-header-title">Compliance</h1>
    <div style="display:flex;gap:8px;align-items:center;">
        <?php if (can('compliance', 'view')): ?>
        <button class="btn btn-secondary btn-sm" @click.prevent="exportCsv()">
            Export CSV
        </button>
        <?php endif; ?>
    </div>
    <div class="page-header-actions">
        <?= help_button('compliance') ?>
    </div>
</div>

<!-- ── Alpine.js component wraps tiles + grid so tiles can refresh after save ── -->
<div x-data="FF_Compliance()" x-init="init()">

<!-- ── KPI tiles ──────────────────────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">

    <div class="stat-card stat-card--blue" style="cursor:pointer;"
         @click="filters = {q:'',yard:'',status:'',window:'0',expired_only:''}; load(1)"
         :class="{ 'ring-active': filters.expired_only === '' && filters.window === '0' && !filters.q && !filters.yard && !filters.status }">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">Units Tracked</div>
        <div class="stat-value font-mono" x-text="kpis.total"></div>
        <div class="stat-delta">active fleet (excl. inactive/decommissioned)</div>
    </div>

    <div class="stat-card stat-card--red" style="cursor:pointer;"
         :style="kpis.expired > 0 ? 'border-left:3px solid var(--color-danger);' : ''"
         @click="filters.window = '0'; filters.expired_only = (filters.expired_only === '1' ? '' : '1'); load(1)"
         :class="{ 'ring-active': filters.expired_only === '1' }">
        <span class="stat-icon stat-icon--red"><svg><use href="#icon-exclamation-triangle"/></svg></span>
        <div class="stat-label">Expired Documents</div>
        <div class="stat-value font-mono"
             :style="kpis.expired > 0 ? 'color:var(--color-danger);' : ''"
             x-text="kpis.expired"></div>
        <div class="stat-delta">units with at least one past-due doc</div>
    </div>

    <div class="stat-card stat-card--amber" style="cursor:pointer;"
         :style="kpis.expiringSoon > 0 ? 'border-left:3px solid var(--color-warning);' : ''"
         @click="filters.expired_only = ''; filters.window = (filters.window === '30' ? '0' : '30'); load(1)"
         :class="{ 'ring-active': filters.window === '30' }">
        <span class="stat-icon stat-icon--amber"><svg><use href="#icon-clock"/></svg></span>
        <div class="stat-label">Expiring Within 30 Days</div>
        <div class="stat-value font-mono"
             :style="kpis.expiringSoon > 0 ? 'color:var(--color-warning);' : ''"
             x-text="kpis.expiringSoon"></div>
        <div class="stat-delta">units needing renewal soon</div>
    </div>

</div>

<!-- ── Color legend ──────────────────────────────────────────────────────── -->
<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;font-size:0.8125rem;align-items:center;">
    <span style="display:flex;align-items:center;gap:6px;">
        <span style="width:3px;height:14px;border-radius:2px;background:#ef4444;display:inline-block;"></span>
        Expired
    </span>
    <span style="display:flex;align-items:center;gap:6px;">
        <span style="width:3px;height:14px;border-radius:2px;background:#f59e0b;display:inline-block;"></span>
        Expiring within 30 days
    </span>
    <span style="display:flex;align-items:center;gap:6px;">
        <span style="width:3px;height:14px;border-radius:2px;background:#22c55e;display:inline-block;"></span>
        Valid (&gt;30 days)
    </span>
    <span style="display:flex;align-items:center;gap:6px;">
        <span style="width:3px;height:14px;border-radius:2px;background:#d1d5db;display:inline-block;"></span>
        Not set
    </span>
    <?php if (can('compliance', 'edit')): ?>
    <span class="text-secondary" style="margin-left:8px;">Click any cell to edit dates.</span>
    <?php endif; ?>
</div>

    <!-- Filter bar -->
    <div class="card" style="margin-bottom:0;border-bottom:none;border-radius:8px 8px 0 0;">
        <div class="card-header" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

            <input type="text"
                   class="form-control"
                   style="max-width:200px;height:32px;font-size:0.875rem;"
                   placeholder="Search unit…"
                   x-model="filters.q"
                   @input.debounce.400ms="load(1)">

            <select class="form-select form-select-sm"
                    x-model="filters.yard"
                    @change="load(1)">
                <option value="">All Yards</option>
                <?php foreach ($yards as $yard): ?>
                <option value="<?= e($yard['yard_location']) ?>"><?= e($yard['yard_location']) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select form-select-sm"
                    x-model="filters.status"
                    @change="load(1)">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="reserved">Reserved</option>
                <option value="on_lease">On Lease</option>
                <option value="maintenance">Maintenance</option>
            </select>

            <select class="form-select form-select-sm"
                    x-model="filters.window"
                    @change="load(1)">
                <option value="0">All units</option>
                <option value="7">Expiring / expired in 7 days</option>
                <option value="14">Expiring / expired in 14 days</option>
                <option value="30">Expiring / expired in 30 days</option>
                <option value="60">Expiring / expired in 60 days</option>
                <option value="90">Expiring / expired in 90 days</option>
            </select>

            <button class="btn btn-secondary btn-sm"
                    @click="filters = {q:'',yard:'',status:'',window:'0',expired_only:''}; load(1)">Reset</button>

            <span class="text-secondary" style="margin-left:auto;font-size:0.875rem;"
                  x-text="total > 0 ? total + ' unit' + (total === 1 ? '' : 's') : (loading ? '' : 'No units found')"></span>
        </div>
    </div>

    <!-- Table card -->
    <div class="card" style="border-top:none;border-radius:0 0 8px 8px;">

        <!-- Loading -->
        <div class="card-body" x-show="loading" style="text-align:center;padding:32px;">
            <span class="text-secondary">Loading…</span>
        </div>

        <!-- Empty state -->
        <template x-if="!loading && rows.length === 0">
            <div class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No units match the current filters</p>
                    <p class="empty-state-text">Try removing filters, or check that equipment units have been added.</p>
                </div>
            </div>
        </template>

        <!-- Grid table -->
        <template x-if="!loading && rows.length > 0">
            <div class="table-wrapper" style="overflow-x:auto;">
                <table class="table" style="min-width:760px;">
                    <thead>
                        <tr>
                            <th @click="setSort('unit_number')" style="cursor:pointer;white-space:nowrap;">
                                Unit <span x-text="sortIcon('unit_number')"></span>
                            </th>
                            <th @click="setSort('template_name')" style="cursor:pointer;white-space:nowrap;">
                                Type <span x-text="sortIcon('template_name')"></span>
                            </th>
                            <th @click="setSort('yard_location')" style="cursor:pointer;white-space:nowrap;">
                                Yard <span x-text="sortIcon('yard_location')"></span>
                            </th>
                            <th @click="setSort('status')" style="cursor:pointer;">
                                Status <span x-text="sortIcon('status')"></span>
                            </th>
                            <!-- 3 document columns -->
                            <th @click="setSort('cvi_expiry')" style="cursor:pointer;white-space:nowrap;">
                                CVI <span x-text="sortIcon('cvi_expiry')"></span>
                            </th>
                            <th @click="setSort('registration_expiry')" style="cursor:pointer;white-space:nowrap;">
                                Registration <span x-text="sortIcon('registration_expiry')"></span>
                            </th>
                            <th @click="setSort('insurance_expiry')" style="cursor:pointer;white-space:nowrap;">
                                Insurance <span x-text="sortIcon('insurance_expiry')"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                <td>
                                    <a :href="'<?= base_url('equipment/show') ?>?id=' + row.id"
                                       class="link font-mono" x-text="row.unit_number"></a>
                                </td>
                                <td x-text="row.template_name || '—'"></td>
                                <td x-text="row.yard_location || '—'" class="text-secondary"></td>
                                <td>
                                    <span class="badge"
                                          :class="statusBadge(row.status)"
                                          x-text="statusLabel(row.status)"></span>
                                </td>

                                <!-- CVI cell -->
                                <td :style="cellStyle(row.cvi_expiry)"
                                    style="white-space:nowrap;">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <div style="flex:1;"
                                             <?php if (can('compliance', 'edit')): ?>
                                             class="compliance-cell-edit"
                                             @click="openModal(row, 'cvi')"
                                             <?php endif; ?>>
                                            <template x-if="row.cvi_expiry || row.cvi_from">
                                                <div>
                                                    <div x-show="row.cvi_from"
                                                         style="font-size:0.75rem;opacity:0.75;margin-bottom:2px;"
                                                         x-text="'From: ' + row.cvi_from"></div>
                                                    <div class="font-mono" style="font-size:0.875rem;"
                                                         x-text="row.cvi_expiry ? 'To: ' + row.cvi_expiry : '—'"></div>
                                                </div>
                                            </template>
                                            <template x-if="!row.cvi_expiry && !row.cvi_from">
                                                <span class="text-secondary" style="font-size:0.875rem;">—</span>
                                            </template>
                                            <?php if (can('compliance', 'edit')): ?>
                                            <div class="compliance-edit-hint">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <a x-show="row.cvi_doc_url"
                                           :href="row.cvi_doc_url"
                                           target="_blank"
                                           title="View CVI document"
                                           @click.stop
                                           class="compliance-pdf-link compliance-pdf-link--cvi">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            PDF
                                        </a>
                                    </div>
                                </td>

                                <!-- Registration cell -->
                                <td :style="cellStyle(row.registration_expiry)"
                                    style="white-space:nowrap;">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <div style="flex:1;"
                                             <?php if (can('compliance', 'edit')): ?>
                                             class="compliance-cell-edit"
                                             @click="openModal(row, 'registration')"
                                             <?php endif; ?>>
                                            <template x-if="row.registration_expiry || row.registration_from">
                                                <div>
                                                    <div x-show="row.registration_from"
                                                         style="font-size:0.75rem;opacity:0.75;margin-bottom:2px;"
                                                         x-text="'From: ' + row.registration_from"></div>
                                                    <div class="font-mono" style="font-size:0.875rem;"
                                                         x-text="row.registration_expiry ? 'To: ' + row.registration_expiry : '—'"></div>
                                                </div>
                                            </template>
                                            <template x-if="!row.registration_expiry && !row.registration_from">
                                                <span class="text-secondary" style="font-size:0.875rem;">—</span>
                                            </template>
                                            <?php if (can('compliance', 'edit')): ?>
                                            <div class="compliance-edit-hint">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <a x-show="row.registration_doc_url"
                                           :href="row.registration_doc_url"
                                           target="_blank"
                                           title="View Registration document"
                                           @click.stop
                                           class="compliance-pdf-link compliance-pdf-link--reg">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            PDF
                                        </a>
                                    </div>
                                </td>

                                <!-- Insurance cell -->
                                <td :style="cellStyle(row.insurance_expiry)"
                                    style="white-space:nowrap;">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <div style="flex:1;"
                                             <?php if (can('compliance', 'edit')): ?>
                                             class="compliance-cell-edit"
                                             @click="openModal(row, 'insurance')"
                                             <?php endif; ?>>
                                            <template x-if="row.insurance_expiry || row.insurance_from">
                                                <div>
                                                    <div x-show="row.insurance_from"
                                                         style="font-size:0.75rem;opacity:0.75;margin-bottom:2px;"
                                                         x-text="'From: ' + row.insurance_from"></div>
                                                    <div class="font-mono" style="font-size:0.875rem;"
                                                         x-text="row.insurance_expiry ? 'To: ' + row.insurance_expiry : '—'"></div>
                                                </div>
                                            </template>
                                            <template x-if="!row.insurance_expiry && !row.insurance_from">
                                                <span class="text-secondary" style="font-size:0.875rem;">—</span>
                                            </template>
                                            <?php if (can('compliance', 'edit')): ?>
                                            <div class="compliance-edit-hint">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <a x-show="row.insurance_doc_url"
                                           :href="row.insurance_doc_url"
                                           target="_blank"
                                           title="View Insurance document"
                                           @click.stop
                                           class="compliance-pdf-link compliance-pdf-link--ins">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            PDF
                                        </a>
                                    </div>
                                </td>

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
                        @click="load(page - 1)">← Prev</button>
                <span class="text-secondary" style="line-height:32px;font-size:0.875rem;"
                      x-text="'Page ' + page + ' of ' + totalPages"></span>
                <button class="btn btn-secondary btn-sm"
                        :disabled="page >= totalPages"
                        @click="load(page + 1)">Next →</button>
            </div>
        </template>
    </div>

    <!-- ── Update modal — From + Expiry ─────────────────────────────────── -->
    <?php if (can('compliance', 'edit')): ?>
    <div class="modal-overlay" x-show="modal.open" x-cloak @click.self="closeModal()"
         style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" x-text="'Update ' + modal.docLabel + ' Dates'"></h3>
                <button class="modal-close-btn" aria-label="Close" @click="closeModal()">×</button>
            </div>
            <div class="modal-body">
                <p class="text-secondary" style="font-size:0.875rem;margin-bottom:16px;">
                    Unit: <strong x-text="modal.unitNumber"></strong>
                </p>

                <!-- Valid From -->
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:4px;">
                        Valid From
                    </label>
                    <input type="date"
                           class="form-control"
                           x-model="modal.fromDate"
                           style="max-width:200px;">
                    <p class="text-secondary" style="font-size:0.8125rem;margin-top:4px;">
                        When the document became effective.
                    </p>
                </div>

                <!-- Expiry (To) -->
                <div style="margin-bottom:8px;">
                    <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:4px;">
                        Expiry (To)
                    </label>
                    <input type="date"
                           class="form-control"
                           x-model="modal.expiryDate"
                           style="max-width:200px;">
                    <p class="text-secondary" style="font-size:0.8125rem;margin-top:4px;">
                        Leave empty to clear (shows as gray / "Not Set").
                    </p>
                </div>

                <p class="text-danger" x-show="modal.error" x-text="modal.error"
                   style="font-size:0.875rem;margin-top:8px;"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" @click="closeModal()">Cancel</button>
                <button class="btn btn-primary btn-sm"
                        :disabled="modal.saving"
                        @click="saveUpdate()">
                    <span x-text="modal.saving ? 'Saving…' : 'Save'"></span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /x-data -->

<script>
function FF_Compliance() {
    return {
        rows:       [],
        total:      0,
        page:       1,
        totalPages: 1,
        loading:    false,
        sort:       'unit_number',
        dir:        'ASC',
        filters:    { q: '', yard: '', status: '', window: '0', expired_only: '' },

        // KPI tile state — seeded from server-render, refreshed after every save
        kpis: {
            total:        <?= (int) $initTotalUnits ?>,
            expired:      <?= (int) $initExpiredCount ?>,
            expiringSoon: <?= (int) $initExpiringSoonCount ?>,
        },

        modal: {
            open:       false,
            unitId:     null,
            unitNumber: '',
            docType:    '',    // cvi | registration | insurance
            docLabel:   '',    // CVI | Registration | Insurance
            fromDate:   '',
            expiryDate: '',
            updatedAt:  null,
            saving:     false,
            error:      '',
        },

        init() { this.load(); },

        // ── Load grid rows ────────────────────────────────────────────────────
        async load(pg = 1) {
            this.loading = true;
            this.page    = pg;
            const params = new URLSearchParams({ page: pg, per_page: 50, sort: this.sort, dir: this.dir });
            if (this.filters.q)      params.set('q',      this.filters.q);
            if (this.filters.yard)   params.set('yard',   this.filters.yard);
            if (this.filters.status) params.set('status', this.filters.status);
            if (this.filters.window && this.filters.window !== '0')
                params.set('window', this.filters.window);
            if (this.filters.expired_only === '1')
                params.set('expired_only', '1');
            try {
                const r = await FF_Api.get(`<?= base_url('api/v1/compliance/index') ?>?${params}`);
                this.rows       = r.data?.items ?? [];
                this.total      = r.data?.pagination?.total ?? 0;
                this.totalPages = r.data?.pagination?.total_pages ?? 1;
            } catch (e) {
                this.rows = [];
            } finally {
                this.loading = false;
            }
        },

        // ── Refresh KPI tiles from API (called after every save) ──────────────
        async loadKpis() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/compliance/kpis') ?>');
                this.kpis.total        = r.data?.total_units         ?? this.kpis.total;
                this.kpis.expired      = r.data?.expired_count       ?? this.kpis.expired;
                this.kpis.expiringSoon = r.data?.expiring_soon_count ?? this.kpis.expiringSoon;
            } catch (e) {
                // Non-critical — tiles just show stale values until next load
            }
        },

        // ── Sort ──────────────────────────────────────────────────────────────
        setSort(col) {
            if (this.sort === col) this.dir = this.dir === 'ASC' ? 'DESC' : 'ASC';
            else { this.sort = col; this.dir = 'ASC'; }
            this.load(1);
        },
        sortIcon(col) {
            if (this.sort !== col) return '';
            return this.dir === 'ASC' ? ' ↑' : ' ↓';
        },

        // ── CSV export — builds URL from current filters ──────────────────────
        exportCsv() {
            const params = new URLSearchParams({ export: 'csv' });
            if (this.filters.q)      params.set('q',      this.filters.q);
            if (this.filters.yard)   params.set('yard',   this.filters.yard);
            if (this.filters.status) params.set('status', this.filters.status);
            if (this.filters.window && this.filters.window !== '0')
                params.set('window', this.filters.window);
            window.location.href = `<?= base_url('compliance') ?>?${params}`;
        },

        // ── Cell colour ───────────────────────────────────────────────────────
        expiryStatus(date) {
            if (!date) return 'none';
            const today = new Date().toISOString().slice(0, 10);
            const in30  = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
            if (date <= today) return 'expired';
            if (date <= in30)  return 'warning';
            return 'ok';
        },
        // WHY left-border + tinted bg: the coloured left edge anchors the status
        // while the very light fill (8-12% opacity) groups the cell without
        // creating the monotonous "wall of colour" that a full-saturation fill does.
        cellStyle(date) {
            const s = this.expiryStatus(date);
            const styles = {
                expired: 'border-left:3px solid #ef4444;background:rgba(239,68,68,0.08);color:#991b1b;padding:6px 10px;',
                warning: 'border-left:3px solid #f59e0b;background:rgba(245,158,11,0.08);color:#92400e;padding:6px 10px;',
                ok:      'border-left:3px solid #22c55e;background:rgba(34,197,94,0.07);color:#166534;padding:6px 10px;',
                none:    'border-left:3px solid #d1d5db;padding:6px 10px;color:#6b7280;',
            };
            return styles[s] ?? styles.none;
        },

        // ── Status badge helpers ──────────────────────────────────────────────
        statusBadge(s) {
            return { available:'badge-success', reserved:'badge-info', on_lease:'badge-warning', maintenance:'badge-neutral' }[s] ?? 'badge-neutral';
        },
        statusLabel(s) {
            return { available:'Available', reserved:'Reserved', on_lease:'On Lease', maintenance:'Maintenance' }[s] ?? s;
        },

        // ── Open modal with both from + expiry pre-filled ─────────────────────
        openModal(row, docType) {
            const labels = { cvi: 'CVI', registration: 'Registration', insurance: 'Insurance' };
            this.modal = {
                open:       true,
                unitId:     row.id,
                unitNumber: row.unit_number,
                docType,
                docLabel:   labels[docType] ?? docType,
                fromDate:   row[docType + '_from']   ?? '',
                expiryDate: row[docType + '_expiry']  ?? '',
                updatedAt:  row.updated_at,
                saving:     false,
                error:      '',
            };
        },
        closeModal() { this.modal.open = false; },

        // ── Save — posts doc_type + both dates ────────────────────────────────
        async saveUpdate() {
            this.modal.saving = true;
            this.modal.error  = '';
            try {
                const resp = await FF_Api.post('<?= base_url('api/v1/compliance/update') ?>', {
                    unit_id:     this.modal.unitId,
                    doc_type:    this.modal.docType,
                    from_date:   this.modal.fromDate   || null,
                    expiry_date: this.modal.expiryDate || null,
                    updated_at:  this.modal.updatedAt,
                });

                // Update row in-place so grid reflects changes immediately
                const idx = this.rows.findIndex(r => r.id === this.modal.unitId);
                if (idx !== -1) {
                    const docType = this.modal.docType;
                    this.rows[idx][docType + '_from']   = resp.data?.from_date   ?? null;
                    this.rows[idx][docType + '_expiry'] = resp.data?.expiry_date ?? null;
                    this.rows[idx].updated_at           = resp.data?.updated_at  ?? this.rows[idx].updated_at;
                }

                this.modal.open = false;

                // Refresh KPI tiles to reflect any expired/expiring changes
                this.loadKpis();

            } catch (e) {
                this.modal.error = e.message || 'Failed to save. Please try again.';
            } finally {
                this.modal.saving = false;
            }
        },
    };
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
