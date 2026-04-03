<?php
declare(strict_types=1);

/**
 * app/admin/equipment/show.php
 *
 * Equipment unit detail (command center) page. Shows hero section with unit
 * number, status badge, and health score. Tab navigation: Overview (specs),
 * Compliance (expiry dates), Lease History (lazy-loaded from API), Status Log.
 * Maintenance tab: lazy-loads work orders from api/v1/maintenance_work_orders (S015).
 * Documents tab: placeholder for future session.
 * Status lock: if unit is on_lease the status dropdown shows a warning.
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/equipment/units/show.php
 * @spec    FLEETFORGE_SPEC_FINAL.md §7.4 Equipment Units (Unit Profile)
 * @decisions D30, D32, D33
 * @session S006
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$unitId = clean_int($_GET['id'] ?? null);
if (!$unitId) {
    header('Location: ' . base_url('equipment'));
    exit;
}

// Load unit server-side for <title> and initial render — JS fills in the rest
$unit = db_row(
    "SELECT u.id, u.unit_number, u.status, u.year, u.health_score,
            u.cvi_expiry, u.registration_expiry, u.mvi_expiry, u.insurance_expiry,
            u.yard_location, u.mileage, u.vin, u.updated_at,
            t.name AS template_name, t.category AS template_category
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
      WHERE u.id = ? AND u.deleted_at IS NULL",
    [$unitId]
);

if (!$unit) {
    header('Location: ' . base_url('equipment'));
    exit;
}

$pageTitle = 'Unit ' . e($unit['unit_number']);
require_once FF_ROOT . '/includes/header.php';

// Badge class helper — matches JS version in index.php
function statusBadgeClass(string $status): string {
    return match($status) {
        'available'      => 'badge-success',
        'on_lease'       => 'badge-info',
        'reserved'       => 'badge-purple',
        'maintenance'    => 'badge-warning',
        'inactive'       => 'badge-neutral',
        'decommissioned' => 'badge-danger',
        default          => 'badge-neutral',
    };
}

function healthBadgeClass(?int $score): string {
    if ($score === null) return 'badge-neutral';
    if ($score >= 80) return 'badge-success';
    if ($score >= 50) return 'badge-warning';
    return 'badge-danger';
}

// Compliance days-remaining helper
function daysUntil(?string $date): ?int {
    if (!$date) return null;
    $diff = (new DateTimeImmutable($date))->diff(new DateTimeImmutable('today'));
    return $diff->invert ? -$diff->days : $diff->days;
}
?>

<!-- ============================================================
     Page header — breadcrumb back to list
     ============================================================ -->
<div class="page-header">
    <div>
        <a href="<?= base_url('equipment') ?>" class="btn btn-ghost btn-sm" style="margin-bottom:0.5rem;">
            ← Equipment
        </a>
        <h1 class="page-header-title h4">
            <span class="font-mono"><?= e($unit['unit_number']) ?></span>
            <span class="badge <?= statusBadgeClass($unit['status']) ?>" style="margin-left:0.5rem;font-size:0.75rem;vertical-align:middle;">
                <?= e(str_replace('_', ' ', $unit['status'])) ?>
            </span>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:0.25rem;">
            <?= e($unit['template_name']) ?>
            <?php if ($unit['year']): ?>
                · <?= e($unit['year']) ?>
            <?php endif; ?>
            <?php if ($unit['yard_location']): ?>
                · <?= e($unit['yard_location']) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?php if (can('equipment', 'edit')): ?>
        <a href="<?= base_url('equipment/edit') ?>?id=<?= $unitId ?>" class="btn btn-secondary btn-sm">
            Edit Unit
        </a>
        <?php endif; ?>
        <?php if (can('equipment', 'delete') && $unit['status'] !== 'on_lease'): ?>
        <button class="btn btn-danger btn-sm" onclick="deleteUnit()">Delete Unit</button>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     Hero stat row
     ============================================================ -->
<div class="stat-grid" style="margin-bottom:1.5rem;">

    <div class="stat-card">
        <div class="stat-label">Health Score</div>
        <?php if ($unit['health_score'] !== null): ?>
        <div class="stat-value font-mono">
            <span class="badge badge-no-dot <?= healthBadgeClass((int)$unit['health_score']) ?>" style="font-size:1.1rem;padding:4px 10px;">
                <?= e($unit['health_score']) ?>/100
            </span>
        </div>
        <?php else: ?>
        <div class="stat-value text-secondary">—</div>
        <div class="stat-delta text-secondary">Not calculated yet</div>
        <?php endif; ?>
    </div>

    <div class="stat-card">
        <div class="stat-label">Mileage</div>
        <div class="stat-value font-mono"><?= number_format((int)$unit['mileage']) ?> <span class="text-sm text-secondary">mi</span></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">CVI Expiry</div>
        <?php
        $cviDays = daysUntil($unit['cvi_expiry']);
        if ($unit['cvi_expiry']):
            $cls = $cviDays === null ? 'text-secondary' : ($cviDays < 0 ? 'text-danger' : ($cviDays <= 30 ? 'text-warning' : 'text-success'));
        ?>
        <div class="stat-value font-mono <?= $cls ?>"><?= e(date('M j, Y', strtotime($unit['cvi_expiry']))) ?></div>
        <div class="stat-delta text-secondary">
            <?= $cviDays < 0 ? abs($cviDays) . ' days overdue' : $cviDays . ' days remaining' ?>
        </div>
        <?php else: ?>
        <div class="stat-value text-secondary">—</div>
        <?php endif; ?>
    </div>

    <div class="stat-card">
        <div class="stat-label">VIN</div>
        <div class="stat-value font-mono text-sm"><?= $unit['vin'] ? e($unit['vin']) : '<span class="text-secondary">—</span>' ?></div>
    </div>

</div>

<!-- ============================================================
     TABS (Alpine)
     ============================================================ -->
<div x-data="FF_UnitDetail()" x-init="init()">

    <!-- Tab bar -->
    <div style="display:flex;gap:0;border-bottom:2px solid var(--border-color);margin-bottom:1.5rem;">
        <?php
        $tabs = [
            ['key' => 'overview',       'label' => 'Overview'],
            ['key' => 'compliance',     'label' => 'Compliance'],
            ['key' => 'leases',         'label' => 'Lease History'],
            ['key' => 'damage_claims',  'label' => 'Damage Claims'],
            ['key' => 'mileage_logs',   'label' => 'Mileage Log'],
            ['key' => 'status_log',     'label' => 'Status Log'],
            ['key' => 'maintenance',    'label' => 'Maintenance'],
            ['key' => 'inspections',    'label' => 'Inspections'],
            ['key' => 'documents',      'label' => 'Documents'],
        ];
        foreach ($tabs as $tab):
        ?>
        <button class="btn btn-ghost btn-sm"
                :class="activeTab === '<?= $tab['key'] ?>' ? 'is-active' : ''"
                @click="activeTab = '<?= $tab['key'] ?>'"
                style="border-radius:0;border-bottom:2px solid transparent;margin-bottom:-2px;"
                :style="activeTab === '<?= $tab['key'] ?>' ? 'border-bottom-color:var(--color-accent);color:var(--color-accent);font-weight:600;' : ''">
            <?= $tab['label'] ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── TAB: Overview ──────────────────────────────────────── -->
    <div x-show="activeTab === 'overview'">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Unit Specifications</div>
            </div>
            <div class="card-body">
                <template x-if="loading">
                    <div>
                        <template x-for="n in 6" :key="n">
                            <div class="skeleton skeleton-row" style="margin-bottom:0.5rem;"></div>
                        </template>
                    </div>
                </template>
                <template x-if="!loading && unit">
                    <div class="form-row-3" style="gap:1.5rem 2rem;">

                        <div>
                            <div class="form-label">Template</div>
                            <div class="font-medium" x-text="unit.template_name"></div>
                            <div class="text-secondary text-sm" x-text="unit.template_category ? unit.template_category.replace('_',' ') : ''" style="text-transform:capitalize;"></div>
                        </div>
                        <div>
                            <div class="form-label">Ownership</div>
                            <div x-text="unit.ownership_type || '—'" style="text-transform:capitalize;"></div>
                        </div>
                        <div>
                            <div class="form-label">Tracking</div>
                            <div x-text="unit.tracking_provider || 'none'" style="text-transform:capitalize;"></div>
                        </div>

                        <div>
                            <div class="form-label">Length</div>
                            <div x-text="unit.length_ft ? unit.length_ft + ' ft' : '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Width</div>
                            <div x-text="unit.width_ft ? unit.width_ft + ' ft' : '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Height</div>
                            <div x-text="unit.height_ft ? unit.height_ft + ' ft' : '—'" class="font-mono"></div>
                        </div>

                        <div>
                            <div class="form-label">Axle Count</div>
                            <div x-text="unit.axle_count || '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Tire Size</div>
                            <div x-text="unit.tire_size || '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Wheel Size</div>
                            <div x-text="unit.wheel_size || '—'" class="font-mono"></div>
                        </div>

                        <div>
                            <div class="form-label">License Plate</div>
                            <div x-text="unit.license_plate ? unit.license_plate + (unit.license_state ? ' (' + unit.license_state + ')' : '') : '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Weight Capacity</div>
                            <div x-text="unit.weight_capacity_lbs ? unit.weight_capacity_lbs.toLocaleString() + ' lbs' : '—'" class="font-mono"></div>
                        </div>
                        <div>
                            <div class="form-label">Acquired</div>
                            <div x-text="unit.acquired_date ? formatDate(unit.acquired_date) : '—'"></div>
                        </div>

                    </div>
                </template>
                <template x-if="!loading && unit && unit.notes">
                    <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                        <div class="form-label">Notes</div>
                        <div x-text="unit.notes" class="text-secondary"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── TAB: Compliance ────────────────────────────────────── -->
    <div x-show="activeTab === 'compliance'">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Compliance Documents & Expiry</div>
            </div>
            <div class="card-body">
                <template x-if="loading">
                    <template x-for="n in 4" :key="n">
                        <div class="skeleton skeleton-row" style="margin-bottom:0.5rem;"></div>
                    </template>
                </template>
                <template x-if="!loading && unit">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Expiry Date</th>
                                    <th>Days Remaining</th>
                                    <th>Status</th>
                                    <th>Interval</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="doc in complianceDocs()" :key="doc.label">
                                    <tr>
                                        <td class="font-medium" x-text="doc.label"></td>
                                        <td class="font-mono" x-text="doc.expiry ? formatDate(doc.expiry) : '—'"></td>
                                        <td class="font-mono" x-text="doc.days !== null ? (doc.days < 0 ? Math.abs(doc.days) + ' days overdue' : doc.days + ' days') : '—'"></td>
                                        <td>
                                            <span class="badge badge-no-dot"
                                                  :class="complianceBadge(doc.days)"
                                                  x-text="complianceLabel(doc.days)">
                                            </span>
                                        </td>
                                        <td class="text-secondary text-sm"
                                            x-text="doc.interval ? doc.interval + ' days' : '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── TAB: Lease History ────────────────────────────────────── -->
    <div x-show="activeTab === 'leases'">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Lease History</div>
            </div>

            <div x-show="leasesLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading lease history…</span>
            </div>

            <template x-if="!leasesLoading && leaseHistory.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No lease history</p>
                        <p class="empty-state-text">This unit has not been leased yet.</p>
                    </div>
                </div>
            </template>

            <template x-if="!leasesLoading && leaseHistory.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Contract #</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Start</th>
                                <th>End</th>
                                <th class="text-right">Monthly Rate</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="ls in leaseHistory" :key="ls.id">
                                <tr>
                                    <td class="font-mono" x-text="ls.contract_number"></td>
                                    <td x-text="ls.customer_display_name || ls.company_name_snapshot || '—'"></td>
                                    <td>
                                        <span class="badge badge-no-dot"
                                              :class="leaseBadgeClass(ls.status)"
                                              x-text="ls.status.charAt(0).toUpperCase() + ls.status.slice(1)"></span>
                                    </td>
                                    <td x-text="formatDate(ls.start_date)"></td>
                                    <td x-text="ls.end_date ? formatDate(ls.end_date) : 'Open'"></td>
                                    <td class="text-right font-mono" x-text="'$' + parseFloat(ls.monthly_rate).toFixed(2)"></td>
                                    <td>
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + ls.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── TAB: Damage Claims ──────────────────────────────────── -->
    <div x-show="activeTab === 'damage_claims'">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Damage Claims</div>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('damage_claims/create') ?>?equipment_unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ New Claim</a>
                <?php endif; ?>
            </div>

            <div x-show="damageClaimsLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading damage claims…</span>
            </div>

            <template x-if="!damageClaimsLoading && damageClaims.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No damage claims</p>
                        <p class="empty-state-text">No damage claims have been filed for this unit.</p>
                    </div>
                </div>
            </template>

            <template x-if="!damageClaimsLoading && damageClaims.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Claim #</th>
                                <th>Customer</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th style="text-align:right;">Est. Cost</th>
                                <th>Reported</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="dc in damageClaims" :key="dc.id">
                                <tr>
                                    <td class="font-mono" x-text="dc.claim_number"></td>
                                    <td x-text="dc.customer_name ?? '—'"></td>
                                    <td>
                                        <span class="badge" :class="dcSeverityBadge(dc.severity)"
                                              x-text="dcSeverityLabel(dc.severity)"></span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="dcStatusBadge(dc.status)"
                                              x-text="dcStatusLabel(dc.status)"></span>
                                    </td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="dc.estimated_repair_cost ? '$' + parseFloat(dc.estimated_repair_cost).toLocaleString('en-CA', {minimumFractionDigits:2}) : '—'"></td>
                                    <td x-text="dc.created_at ? dc.created_at.substring(0,10) : '—'"></td>
                                    <td>
                                        <a :href="'<?= base_url('damage_claims/show') ?>?id=' + dc.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── TAB: Mileage Log ──────────────────────────────────── -->
    <div x-show="activeTab === 'mileage_logs'">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Mileage Log</div>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('mileage_logs/create') ?>?equipment_unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ Record Mileage</a>
                <?php endif; ?>
            </div>

            <div x-show="mileageLogsLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading mileage log…</span>
            </div>

            <template x-if="!mileageLogsLoading && mileageLogs.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No mileage entries</p>
                        <p class="empty-state-text">No odometer readings have been recorded for this unit.</p>
                    </div>
                </div>
            </template>

            <template x-if="!mileageLogsLoading && mileageLogs.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Odometer</th>
                                <th>Type</th>
                                <th>Lease</th>
                                <th>Recorded By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="ml in mileageLogs" :key="ml.id">
                                <tr>
                                    <td x-text="ml.log_date"></td>
                                    <td class="font-mono"
                                        x-text="Number(ml.odometer_reading).toLocaleString('en-CA') + ' ' + (ml.mileage_unit === 'miles' ? 'mi' : 'km')">
                                    </td>
                                    <td>
                                        <span class="badge" :class="mlTypeBadge(ml.log_type)"
                                              x-text="mlTypeLabel(ml.log_type)"></span>
                                    </td>
                                    <td>
                                        <template x-if="ml.lease_id">
                                            <a :href="'<?= base_url('leases/show') ?>?id=' + ml.lease_id"
                                               class="link" x-text="'Lease #' + ml.lease_id"></a>
                                        </template>
                                        <template x-if="!ml.lease_id">
                                            <span style="color:var(--text-secondary);">—</span>
                                        </template>
                                    </td>
                                    <td x-text="ml.recorded_by_name || '—'"></td>
                                    <td>
                                        <a :href="'<?= base_url('mileage_logs/show') ?>?id=' + ml.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── TAB: Status Log ────────────────────────────────────── -->
    <div x-show="activeTab === 'status_log'">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Status History</div>
            </div>
            <div class="card-body">
                <template x-if="loadingLog">
                    <template x-for="n in 5" :key="n">
                        <div class="skeleton skeleton-row" style="margin-bottom:0.5rem;"></div>
                    </template>
                </template>
                <template x-if="!loadingLog && statusLog.length === 0">
                    <div class="text-secondary text-sm">No status changes recorded.</div>
                </template>
                <template x-if="!loadingLog && statusLog.length > 0">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Reason</th>
                                    <th>Changed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="log in statusLog" :key="log.id">
                                    <tr>
                                        <td class="font-mono text-sm" x-text="formatDatetime(log.changed_at)"></td>
                                        <td>
                                            <span class="badge"
                                                  :class="statusBadgeClass(log.old_status)"
                                                  x-text="log.old_status.replace('_',' ')">
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge"
                                                  :class="statusBadgeClass(log.new_status)"
                                                  x-text="log.new_status.replace('_',' ')">
                                            </span>
                                        </td>
                                        <td x-text="log.reason || '—'" class="text-secondary text-sm"></td>
                                        <td x-text="log.changed_by" class="text-secondary text-sm"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── TAB: Maintenance ──────────────────────────────────── -->
    <div x-show="activeTab === 'maintenance'">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:600;">Work Order History</span>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('maintenance_work_orders/create') ?>?unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ New Work Order</a>
                <?php endif; ?>
            </div>

            <div x-show="workOrdersLoading" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading work orders…</span>
            </div>

            <template x-if="!workOrdersLoading && workOrders.length === 0">
                <div class="card-body">
                    <div class="empty-state">
                        <p class="empty-state-title">No work orders</p>
                        <p class="empty-state-text">No maintenance work orders have been created for this unit.</p>
                    </div>
                </div>
            </template>

            <template x-if="!workOrdersLoading && workOrders.length > 0">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>WO #</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th style="text-align:right;">Total Cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="wo in workOrders" :key="wo.id">
                                <tr>
                                    <td class="font-mono" x-text="wo.work_order_number"></td>
                                    <td x-text="wo.title" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></td>
                                    <td>
                                        <span class="badge badge-neutral"
                                              x-text="wo.work_type ? wo.work_type.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : '—'"></span>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              :class="woPriorityBadge(wo.priority)"
                                              x-text="wo.priority ? wo.priority.charAt(0).toUpperCase()+wo.priority.slice(1) : '—'"></span>
                                    </td>
                                    <td>
                                        <span class="badge"
                                              :class="woStatusBadge(wo.status)"
                                              x-text="woStatusLabel(wo.status)"></span>
                                    </td>
                                    <td x-text="wo.requested_date ?? '—'"></td>
                                    <td class="font-mono" style="text-align:right;"
                                        x-text="wo.total_cost > 0 ? '$' + parseFloat(wo.total_cost).toLocaleString('en-CA',{minimumFractionDigits:2}) : '—'"></td>
                                    <td>
                                        <a :href="'<?= base_url('maintenance_work_orders/show') ?>?id=' + wo.id"
                                           class="btn btn-secondary btn-sm">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

    <!-- ── TAB: Inspections ──────────────────────────────────── -->
    <div x-show="activeTab === 'inspections'">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <span style="font-weight:600;">Inspections</span>
            <?php if (can('inspections', 'create')): ?>
            <a href="<?= base_url('inspections/create') ?>?unit_id=<?= $unitId ?>" class="btn btn-primary btn-sm">+ New Inspection</a>
            <?php endif; ?>
        </div>
        <div x-show="inspectionsLoading" class="card-body" style="text-align:center;padding:32px;">Loading...</div>
        <template x-if="!inspectionsLoading && inspections.length === 0">
            <div class="card-body empty-state">
                <p class="empty-state-title">No inspections recorded</p>
                <p class="empty-state-text">Pre-lease and post-lease inspections will appear here.</p>
            </div>
        </template>
        <template x-if="!inspectionsLoading && inspections.length > 0">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Inspection #</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Inspector</th>
                            <th>Condition</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="ins in inspections" :key="ins.id">
                            <tr>
                                <td class="font-mono" x-text="ins.inspection_number || ('#'+ins.id)"></td>
                                <td><span class="badge" :class="inspTypeBadge(ins.inspection_type)" x-text="inspTypeLabel(ins.inspection_type)"></span></td>
                                <td class="font-mono" x-text="ins.inspection_date"></td>
                                <td x-text="ins.inspected_by || '—'"></td>
                                <td x-text="ins.overall_condition ? ins.overall_condition.charAt(0).toUpperCase()+ins.overall_condition.slice(1) : '—'"></td>
                                <td><span class="badge" :class="inspStatusBadge(ins.status)" x-text="inspStatusLabel(ins.status)"></span></td>
                                <td><a :href="'<?= base_url('inspections/show') ?>?id='+ins.id" class="btn btn-xs btn-ghost">View</a></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <!-- ── TAB: Documents (stub) ─────────────────────────────── -->
    <div x-show="activeTab === 'documents'">
        <div class="card card-body empty-state">
            <div class="empty-state-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
            </div>
            <p class="empty-state-title">Document uploads coming in S021</p>
            <p class="empty-state-text">CVI, registration, and insurance document uploads will be built during the Documents session.</p>
        </div>
    </div>

</div><!-- /x-data -->

<script>
function FF_UnitDetail() {
    return {
        unit:                null,
        statusLog:           [],
        loading:             true,
        loadingLog:          false,
        activeTab:           'overview',
        leaseHistory:        [],
        leasesLoading:       false,
        leasesLoaded:        false,
        damageClaims:        [],
        damageClaimsLoading: false,
        damageClaimsLoaded:  false,
        mileageLogs:         [],
        mileageLogsLoading:  false,
        mileageLogsLoaded:   false,
        workOrders:          [],
        workOrdersLoading:   false,
        workOrdersLoaded:    false,
        inspections:         [],
        inspectionsLoading:  false,
        inspectionsLoaded:   false,

        async init() {
            await this.loadUnit();
        },

        async loadUnit() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/show') ?>?id=<?= $unitId ?>');
                if (r.success) {
                    this.unit = r.data;
                }
            } catch(e) { /* page already rendered server-side */ }
            this.loading = false;

            // Lazy-load data when tabs are first activated
            this.$watch('activeTab', (tab) => {
                if (tab === 'status_log' && !this.statusLog.length) {
                    this.loadStatusLog();
                }
                if (tab === 'leases' && !this.leasesLoaded) {
                    this.loadLeaseHistory();
                }
                if (tab === 'damage_claims' && !this.damageClaimsLoaded) {
                    this.loadDamageClaims();
                }
                if (tab === 'mileage_logs' && !this.mileageLogsLoaded) {
                    this.loadMileageLogs();
                }
                if (tab === 'maintenance' && !this.workOrdersLoaded) {
                    this.loadWorkOrders();
                }
                if (tab === 'inspections' && !this.inspectionsLoaded) {
                    this.loadInspections();
                }
            });
        },

        async loadStatusLog() {
            this.loadingLog = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/equipment/units/status-log') ?>?unit_id=<?= $unitId ?>'
                );
                if (r.success) this.statusLog = r.data.items ?? r.data;
            } catch(e) { /* non-fatal */ }
            this.loadingLog = false;
        },

        async loadLeaseHistory() {
            this.leasesLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/leases') ?>?unit_id=<?= $unitId ?>&per_page=50&sort=start_date&dir=DESC'
                );
                if (r.success) {
                    this.leaseHistory  = r.data.items || [];
                    this.leasesLoaded  = true;
                }
            } catch(e) { /* non-fatal */ }
            this.leasesLoading = false;
        },

        async loadDamageClaims() {
            this.damageClaimsLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/damage_claims') ?>?equipment_unit_id=<?= $unitId ?>&per_page=50&sort=created_at&dir=DESC'
                );
                if (r.success) {
                    this.damageClaims       = r.data?.items ?? [];
                    this.damageClaimsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.damageClaimsLoading = false;
        },

        async loadMileageLogs() {
            this.mileageLogsLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/mileage_logs/index') ?>?equipment_unit_id=<?= $unitId ?>&per_page=50&sort=log_date&dir=DESC'
                );
                if (r.success) {
                    this.mileageLogs       = r.data?.items ?? [];
                    this.mileageLogsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.mileageLogsLoading = false;
        },

        async loadWorkOrders() {
            this.workOrdersLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/maintenance_work_orders/index.php') ?>?equipment_unit_id=<?= $unitId ?>&per_page=50&sort=requested_date&dir=DESC'
                );
                if (r.success) {
                    this.workOrders       = r.data?.items ?? [];
                    this.workOrdersLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.workOrdersLoading = false;
        },

        woStatusBadge(s) {
            return { open:'badge badge-info', in_progress:'badge badge-warning', waiting_parts:'badge badge-warning',
                     completed:'badge badge-success', cancelled:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },

        woStatusLabel(s) {
            return { open:'Open', in_progress:'In Progress', waiting_parts:'Waiting Parts',
                     completed:'Completed', cancelled:'Cancelled' }[s] ?? s;
        },

        woPriorityBadge(p) {
            return { emergency:'badge badge-danger', high:'badge badge-warning',
                     medium:'badge badge-info', low:'badge badge-neutral' }[p] ?? 'badge badge-neutral';
        },

        mlTypeBadge(t) {
            return { manual:'badge badge-info', gps_sync:'badge badge-success',
                     lease_start:'badge badge-neutral', lease_end:'badge badge-neutral',
                     service:'badge badge-warning' }[t] ?? 'badge badge-neutral';
        },

        mlTypeLabel(t) {
            return { manual:'Manual', gps_sync:'GPS Sync', lease_start:'Lease Start',
                     lease_end:'Lease End', service:'Service' }[t] ?? t;
        },

        dcSeverityBadge(s) {
            return { minor:'badge badge-info', moderate:'badge badge-warning', major:'badge badge-danger', total_loss:'badge badge-danger' }[s] ?? 'badge badge-neutral';
        },

        dcSeverityLabel(s) {
            return { minor:'Minor', moderate:'Moderate', major:'Major', total_loss:'Total Loss' }[s] ?? s;
        },

        dcStatusBadge(s) {
            return { reported:'badge badge-info', assessed:'badge badge-warning', repair_ordered:'badge badge-warning',
                     invoiced:'badge badge-purple', resolved:'badge badge-success', written_off:'badge badge-neutral' }[s] ?? 'badge badge-neutral';
        },

        dcStatusLabel(s) {
            return { reported:'Reported', assessed:'Assessed', repair_ordered:'Repair Ordered',
                     invoiced:'Invoiced', resolved:'Resolved', written_off:'Written Off' }[s] ?? s;
        },

        async loadInspections() {
            this.inspectionsLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/inspections/index.php') ?>?equipment_unit_id=<?= $unitId ?>&per_page=50&sort=inspection_date&dir=DESC'
                );
                if (r.success) {
                    this.inspections       = r.data?.items ?? [];
                    this.inspectionsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.inspectionsLoading = false;
        },

        inspTypeBadge(t) {
            return { pre_lease:'badge badge-info', post_lease:'badge badge-warning', periodic:'badge badge-neutral',
                     damage:'badge badge-danger', compliance:'badge badge-success' }[t] ?? 'badge badge-neutral';
        },
        inspTypeLabel(t) {
            return { pre_lease:'Pre-Lease', post_lease:'Post-Lease', periodic:'Periodic',
                     damage:'Damage', compliance:'Compliance' }[t] ?? t;
        },
        inspStatusBadge(s) {
            return { draft:'badge badge-warning', complete:'badge badge-info', signed:'badge badge-success' }[s] ?? 'badge badge-neutral';
        },
        inspStatusLabel(s) {
            return { draft:'Draft', complete:'Complete', signed:'Signed' }[s] ?? s;
        },

        leaseBadgeClass(status) {
            const m = { active:'badge-success', pending:'badge-info', completed:'badge-neutral', cancelled:'badge-danger' };
            return m[status] || 'badge-neutral';
        },

        complianceDocs() {
            if (!this.unit) return [];
            return [
                { label: 'CVI',          expiry: this.unit.cvi_expiry,          days: this.daysUntil(this.unit.cvi_expiry),          interval: this.unit.cvi_interval_days },
                { label: 'Registration', expiry: this.unit.registration_expiry,  days: this.daysUntil(this.unit.registration_expiry),  interval: this.unit.registration_interval_days },
                { label: 'MVI',          expiry: this.unit.mvi_expiry,           days: this.daysUntil(this.unit.mvi_expiry),           interval: this.unit.mvi_interval_days },
                { label: 'Insurance',    expiry: this.unit.insurance_expiry,     days: this.daysUntil(this.unit.insurance_expiry),     interval: this.unit.insurance_interval_days },
            ];
        },

        daysUntil(dateStr) {
            if (!dateStr) return null;
            const diff = new Date(dateStr) - new Date();
            return Math.ceil(diff / (1000 * 60 * 60 * 24));
        },

        complianceBadge(days) {
            if (days === null) return 'badge-neutral';
            if (days < 0)   return 'badge-danger';
            if (days <= 7)  return 'badge-danger';
            if (days <= 30) return 'badge-warning';
            return 'badge-success';
        },

        complianceLabel(days) {
            if (days === null) return 'Unknown';
            if (days < 0)   return 'Expired';
            if (days <= 7)  return 'Critical';
            if (days <= 30) return 'Expiring Soon';
            return 'OK';
        },

        statusBadgeClass(status) {
            const map = { available:'badge-success', on_lease:'badge-info', reserved:'badge-purple',
                          maintenance:'badge-warning', inactive:'badge-neutral', decommissioned:'badge-danger',
                          none: 'badge-neutral' };
            return map[status] || 'badge-neutral';
        },

        formatDate(d) {
            if (!d) return '—';
            const dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString('en-CA', { year:'numeric', month:'short', day:'numeric' });
        },

        formatDatetime(d) {
            if (!d) return '—';
            const dt = new Date(d);
            return dt.toLocaleString('en-CA', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
        },
    };
}

// FIX #42: delete button handler
function deleteUnit() {
    if (!confirm('Delete this unit? This action cannot be undone.')) return;
    FF_Api.post('<?= base_url('api/v1/equipment/units/delete') ?>', { id: <?= $unitId ?> })
        .then(r => {
            if (r.success) {
                window.location.href = '<?= base_url('equipment') ?>';
            } else {
                alert(r.error?.message || 'Failed to delete unit.');
            }
        });
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
