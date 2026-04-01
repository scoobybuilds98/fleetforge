<?php
declare(strict_types=1);

/**
 * app/admin/equipment/show.php
 *
 * Equipment unit detail (command center) page. Shows hero section with unit
 * number, status badge, and health score. Tab navigation: Overview (specs),
 * Compliance (expiry dates), Lease History (stub), Status Log.
 * GPS, Maintenance, Documents, Analytics tabs are stubbed for future sessions.
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
            ['key' => 'overview',     'label' => 'Overview'],
            ['key' => 'compliance',   'label' => 'Compliance'],
            ['key' => 'leases',       'label' => 'Lease History'],
            ['key' => 'status_log',   'label' => 'Status Log'],
            ['key' => 'maintenance',  'label' => 'Maintenance'],
            ['key' => 'documents',    'label' => 'Documents'],
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

    <!-- ── TAB: Lease History (stub for future session) ──────── -->
    <div x-show="activeTab === 'leases'">
        <div class="card card-body empty-state">
            <div class="empty-state-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
            </div>
            <p class="empty-state-title">Lease history coming in S008</p>
            <p class="empty-state-text">Full lease history timeline will be built during the Leases module session.</p>
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

    <!-- ── TAB: Maintenance (stub) ───────────────────────────── -->
    <div x-show="activeTab === 'maintenance'">
        <div class="card card-body empty-state">
            <div class="empty-state-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"/></svg>
            </div>
            <p class="empty-state-title">Maintenance history coming in S016</p>
            <p class="empty-state-text">Work order history and maintenance cost tracking will be built during the Fleet Operations session.</p>
        </div>
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
        unit:       null,
        statusLog:  [],
        loading:    true,
        loadingLog: false,
        activeTab:  'overview',

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

            // Load status log when tab is first activated
            this.$watch('activeTab', (tab) => {
                if (tab === 'status_log' && !this.statusLog.length) {
                    this.loadStatusLog();
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
            } catch(e) { /* stub endpoint — silently ignore */ }
            this.loadingLog = false;
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
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
