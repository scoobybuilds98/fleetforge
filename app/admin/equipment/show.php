<?php
declare(strict_types=1);

/**
 * app/admin/equipment/show.php
 *
 * Equipment unit detail (command center) page. Shows hero section with unit
 * number, status badge, and health score. Tab navigation: Overview (specs),
 * Compliance (expiry dates), Lease History (lazy-loaded from API), Status Log.
 * Maintenance tab: lazy-loads work orders from api/v1/maintenance_work_orders (S015).
 * Documents tab: lazy-loads from api/v1/documents via loadDocuments() (fully implemented).
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

// Load unit server-side for <title> and initial render — JS fills in the rest.
// SAMSARA-1: pull every samsara_* column so the hero "Live" badge, the
// Samsara Mapping tab, and the Overview Samsara section can render from
// the first paint without waiting on a JS round-trip.
$unit = db_row(
    "SELECT u.id, u.unit_number, u.status, u.year, u.health_score,
            u.cvi_expiry, u.registration_expiry, u.mvi_expiry, u.insurance_expiry,
            u.yard_location, u.mileage, u.vin, u.updated_at,
            u.gps_device_id, u.tracking_provider, u.samsara_vehicle_url,
            u.samsara_vehicle_id, u.samsara_entity_type, u.samsara_vehicle_name,
            u.samsara_vin, u.samsara_serial_number, u.samsara_gateway_id,
            u.samsara_battery_pct, u.samsara_battery_charging,
            u.samsara_power_source, u.samsara_check_in_mode,
            u.samsara_last_location_lat, u.samsara_last_location_lng,
            u.samsara_last_location_address, u.samsara_last_speed_kph,
            u.samsara_last_connected_at, u.samsara_last_synced_at,
            u.samsara_odometer_km,
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

// ── Linked fixed asset lookup (PAYOFF-1 tab) ───────────────────
// WHY: The Payoff Analysis tab calls the existing
// /api/v1/accounting/fixed_assets/payoff.php endpoint which takes
// asset_id (not equipment_unit_id). We resolve that link here so the
// tab can either show the payoff UI or an empty state directly from
// first paint — no extra round-trip to a "lookup" endpoint.
//
// NOTE: acc_fixed_assets does not use soft deletes (it has its own
// lifecycle via `status` — active/impaired/fully_depreciated/disposed).
// We exclude disposed assets from the "still paying off" view because
// they've been written off the books.
$linkedAsset = db_row(
    "SELECT id, asset_number, name, acquisition_date, status
       FROM acc_fixed_assets
      WHERE equipment_unit_id = ?
        AND status != 'disposed'
      ORDER BY acquisition_date DESC, id DESC
      LIMIT 1",
    [$unitId]
);
$linkedAssetId = $linkedAsset ? (int) $linkedAsset['id'] : 0;

$pageTitle      = 'Unit ' . e($unit['unit_number']);
$helpModuleSlug = 'equipment';
require_once FF_ROOT . '/includes/header.php';

// statusBadgeClass — page-local alias for the canonical shared helper at
// includes/functions.php:unit_status_badge_class (S-UNIT-STATUS-COLOR
// 2026-05-14). Alias retained so existing call sites in this file don't
// have to change; future pages should call unit_status_badge_class()
// directly.
function statusBadgeClass(string $status): string {
    return unit_status_badge_class($status);
}

// healthBadgeClass — map the canonical 4-band health color (S-CRON-3) to
// our shared badge palette. equipment_health_color() lives in
// includes/functions.php and is the single source of truth for the bands.
function healthBadgeClass(?int $score): string {
    return match (equipment_health_color($score)) {
        'green'   => 'badge-success',
        'yellow'  => 'badge-warning',
        'orange'  => 'badge-warning',
        'red'     => 'badge-danger',
        default   => 'badge-neutral',
    };
}

// Compliance days helper. Convention: POSITIVE = days until a FUTURE expiry,
// NEGATIVE = days a PAST expiry is overdue, 0 = expires today.
// S-CVI-OVERDUE-SIGN-FIX: the diff direction was reversed — `$date->diff(today)`
// inverts for a future date, so a future expiry returned negative and rendered as
// "overdue". Anchor today->$date so future yields invert=0 (positive). Matches the
// JS daysUntil() twin (new Date(expiry) - now).
function daysUntil(?string $date): ?int {
    if (!$date) return null;
    $diff = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date));
    return $diff->invert ? -$diff->days : $diff->days;
}

// Human label for a compliance day-delta (pairs with daysUntil()):
// future -> "expires in N days", past -> "overdue N days", 0 -> "expires today".
function complianceDelta(?int $days): string {
    if ($days === null) return '';
    if ($days === 0)    return 'expires today';
    $n    = abs($days);
    $unit = $n === 1 ? 'day' : 'days';
    return $days < 0 ? "overdue {$n} {$unit}" : "expires in {$n} {$unit}";
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
            <?php
            // SAMSARA-1: Live tracking indicator. Shows a pulsing green dot
            // when this unit is mapped to a Samsara trackable. Sits next to
            // the status badge so dispatchers can spot live units at a glance.
            //
            // SAMSARA-2: Trailer-linked units never get a battery badge
            // because the trailer stats endpoint doesn't return battery
            // data — see SamsaraClient::normalizeTrailerStats(). The Live
            // dot still shows so the unit is visibly being tracked.
            if (!empty($unit['samsara_vehicle_id'])):
                $isTrailer  = ($unit['samsara_entity_type'] ?? 'vehicle') === 'trailer';
                $batteryPct = $unit['samsara_battery_pct'] !== null ? (int) $unit['samsara_battery_pct'] : null;
            ?>
            <span class="ff-live-badge" title="Synced from Samsara <?= $isTrailer ? 'trailer' : 'vehicle' ?>">
                <span class="ff-live-dot"></span>
                Live
            </span>
            <?php if ($isTrailer): ?>
                <span class="badge" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:0.7rem;vertical-align:middle;margin-left:0.25rem;" title="Linked to a Samsara trailer (asset gateway)">
                    Trailer
                </span>
            <?php endif; ?>
            <?php if ($batteryPct !== null): ?>
                <?php
                // Battery color: red <20%, amber 20-49%, green ≥50%.
                $battClass = $batteryPct < 20 ? 'badge-danger' : ($batteryPct < 50 ? 'badge-warning' : 'badge-success');
                ?>
                <span class="badge <?= $battClass ?>" style="font-size:0.7rem;vertical-align:middle;margin-left:0.25rem;" title="Samsara battery level">
                    <?= $batteryPct ?>%
                </span>
            <?php endif; ?>
            <?php endif; ?>
        </h1>
        <div class="text-secondary text-sm" style="margin-top:0.25rem;">
            <?= e($unit['template_name']) ?>
            <?php if ($unit['year']): ?>
                · <?= e($unit['year']) ?>
            <?php endif; ?>
            <?php if ($unit['yard_location']): ?>
                · <?= e($unit['yard_location']) ?>
            <?php endif; ?>
            <?php
            // SAMSARA-1: Last-synced indicator inline with the subtitle
            // so users can see at a glance how stale the live data is.
            if (!empty($unit['samsara_last_synced_at'])):
            ?>
                · <span title="Last Samsara sync">Synced <?= e(format_datetime($unit['samsara_last_synced_at'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <?= help_button('equipment') ?>
        <?php
        // SAMSARA-1: Direct link to the live trackable in the Samsara
        // dashboard. Only shown when mapped — built from the vehicle
        // ID so it works even if samsara_vehicle_url was never set.
        // SAMSARA-2: trailers live under /o/trailers/, vehicles under
        // /o/vehicles/. Picking the right path means the link opens
        // straight to the asset detail page rather than a 404.
        if (!empty($unit['samsara_vehicle_id'])):
            $samsaraPathSegment = (($unit['samsara_entity_type'] ?? 'vehicle') === 'trailer') ? 'trailers' : 'vehicles';
            $samsaraTrackUrl = 'https://cloud.samsara.com/o/' . $samsaraPathSegment . '/' . urlencode((string) $unit['samsara_vehicle_id']);
        ?>
        <a href="<?= e($samsaraTrackUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
            </svg>
            Track in Samsara
        </a>
        <?php endif; ?>
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

<!-- ── AI Summary Card ──────────────────────────────────────── -->
<?php
$aiSummaryEntityType = 'equipment_unit';
$aiSummaryEntityId   = $unit['id'];
$aiSummaryType       = 'unit_analysis';
$aiSummaryTitle      = 'AI Unit Analysis';
include FF_ROOT . '/includes/partials/ai-summary-card.php';
?>

<!-- ============================================================
     Hero stat row
     ============================================================ -->
<div class="stat-grid" style="margin-bottom:1.5rem;">

    <div class="stat-card stat-card--green">
        <span class="stat-icon stat-icon--green"><svg><use href="#icon-heart"/></svg></span>
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

    <div class="stat-card stat-card--blue">
        <span class="stat-icon stat-icon--blue"><svg><use href="#icon-map-pin"/></svg></span>
        <div class="stat-label">Mileage</div>
        <div class="stat-value font-mono"><?= number_format((int)$unit['mileage']) ?> <span class="text-sm text-secondary">mi</span></div>
    </div>

    <div class="stat-card stat-card--<?= ($unit['cvi_expiry'] && daysUntil($unit['cvi_expiry']) < 0) ? 'red' : (($unit['cvi_expiry'] && daysUntil($unit['cvi_expiry']) <= 30) ? 'amber' : 'teal') ?>">
        <span class="stat-icon stat-icon--<?= ($unit['cvi_expiry'] && daysUntil($unit['cvi_expiry']) < 0) ? 'red' : (($unit['cvi_expiry'] && daysUntil($unit['cvi_expiry']) <= 30) ? 'amber' : 'teal') ?>"><svg><use href="#icon-shield-check"/></svg></span>
        <div class="stat-label">CVI Expiry</div>
        <?php
        $cviDays = daysUntil($unit['cvi_expiry']);
        if ($unit['cvi_expiry']):
            $cls = $cviDays === null ? 'text-secondary' : ($cviDays < 0 ? 'text-danger' : ($cviDays <= 30 ? 'text-warning' : 'text-success'));
        ?>
        <div class="stat-value font-mono <?= $cls ?>"><?= e(date('M j, Y', strtotime($unit['cvi_expiry']))) ?></div>
        <div class="stat-delta text-secondary">
            <?= e(complianceDelta($cviDays)) ?>
        </div>
        <?php else: ?>
        <div class="stat-value text-secondary">—</div>
        <?php endif; ?>
    </div>

    <div class="stat-card stat-card--slate">
        <span class="stat-icon stat-icon--slate"><svg><use href="#icon-tag"/></svg></span>
        <div class="stat-label">VIN</div>
        <div class="stat-value stat-value--vin"><?= $unit['vin'] ? e($unit['vin']) : '<span class="text-secondary">—</span>' ?></div>
    </div>

</div>

<!-- ============================================================
     TABS (Alpine)
     ============================================================ -->
<div x-data="FF_UnitDetail()" x-init="init()">

    <!-- Tab bar — modern segmented-pill style -->
    <div class="tab-bar" role="tablist">
        <?php
        $tabs = [
            ['key' => 'overview',       'label' => 'Overview'],
            ['key' => 'payoff',         'label' => 'Payoff Analysis'],
            ['key' => 'compliance',     'label' => 'Compliance'],
            ['key' => 'leases',         'label' => 'Lease History'],
            ['key' => 'damage_claims',  'label' => 'Damage Claims'],
            ['key' => 'mileage_logs',   'label' => 'Mileage Log'],
            ['key' => 'status_log',     'label' => 'Status Log'],
            ['key' => 'maintenance',    'label' => 'Maintenance'],
            ['key' => 'inspections',    'label' => 'Inspections'],
            ['key' => 'documents',      'label' => 'Documents'],
            ['key' => 'tracking',       'label' => 'Samsara Mapping'],
            ['key' => 'activity',       'label' => 'Activity'],
        ];
        foreach ($tabs as $tab):
        ?>
        <button class="tab-btn"
                :class="{ 'is-active': activeTab === '<?= $tab['key'] ?>' }"
                @click="activeTab = '<?= $tab['key'] ?>'"
                :aria-selected="activeTab === '<?= $tab['key'] ?>'"
                role="tab">
            <?= $tab['label'] ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── TAB: Overview ──────────────────────────────────────── -->
    <div x-show="activeTab === 'overview'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <template x-if="loading">
            <div class="card"><div class="card-body">
                <template x-for="n in 6" :key="n">
                    <div class="skeleton skeleton-row" style="margin-bottom:0.5rem;"></div>
                </template>
            </div></div>
        </template>
        <template x-if="!loading && unit">
            <div class="equip-section-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                <!-- ── Identity & Classification ── -->
                <div class="card spec-card" style="margin-bottom:0;">
                    <div class="card-header" style="padding:12px 16px;">
                        <div class="card-title">Identity</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="spec-table">
                            <tr>
                                <td class="spec-label">Equipment Type</td>
                                <td>
                                    <span class="font-medium" x-text="unit.template_name"></span>
                                    <span class="text-secondary text-sm" x-text="unit.template_category ? ' · ' + unit.template_category.replace('_',' ') : ''" style="text-transform:capitalize;"></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="spec-label">License Plate</td>
                                <td><span class="font-mono" x-text="unit.license_plate ? unit.license_plate + (unit.license_state ? ' (' + unit.license_state + ')' : '') : '—'"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Ownership</td>
                                <td><span x-text="unit.ownership_type || '—'" style="text-transform:capitalize;"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Tracking</td>
                                <td><span x-text="unit.tracking_provider || 'None'" style="text-transform:capitalize;"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Acquired</td>
                                <td><span x-text="unit.acquired_date ? formatDate(unit.acquired_date) : '—'"></span></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- ── Physical Specifications ── -->
                <div class="card spec-card" style="margin-bottom:0;">
                    <div class="card-header" style="padding:12px 16px;">
                        <div class="card-title">Specifications</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="spec-table">
                            <tr>
                                <td class="spec-label">Dimensions</td>
                                <td>
                                    <span class="font-mono" x-text="
                                        [unit.length_ft ? unit.length_ft + 'L' : null,
                                         unit.width_ft  ? unit.width_ft  + 'W' : null,
                                         unit.height_ft ? unit.height_ft + 'H' : null]
                                        .filter(Boolean).join(' × ') || '—'
                                    "></span>
                                    <span x-show="unit.length_ft || unit.width_ft || unit.height_ft"
                                          class="text-secondary text-sm"> ft</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="spec-label">Weight Capacity</td>
                                <td><span class="font-mono" x-text="unit.weight_capacity_lbs ? unit.weight_capacity_lbs.toLocaleString() + ' lbs' : '—'"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Axles</td>
                                <td><span class="font-mono" x-text="unit.axle_count || '—'"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Tire Size</td>
                                <td><span class="font-mono" x-text="unit.tire_size || '—'"></span></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Wheel Size</td>
                                <td><span class="font-mono" x-text="unit.wheel_size || '—'"></span></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- ── SAMSARA-1: Live Tracking summary (full width) ── -->
                <!-- Only rendered when the unit is mapped to a Samsara
                     vehicle. Mirrors the most useful fields from the
                     Samsara Mapping tab so users get the live picture
                     without leaving the Overview. -->
                <template x-if="unit.samsara_vehicle_id">
                    <div class="card spec-card" style="margin-bottom:0;grid-column:1/-1;">
                        <div class="card-header" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;">
                            <div class="card-title">Samsara Live Tracking</div>
                            <button @click="activeTab = 'tracking'" class="btn btn-ghost btn-xs" style="color:#fff;opacity:0.85;">Open Mapping →</button>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="equip-samsara-live-grid" style="display:grid;grid-template-columns:repeat(4, 1fr);gap:0;">
                                <!-- Last location -->
                                <div style="padding:14px 16px;border-right:1px solid var(--border-color);">
                                    <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Location</div>
                                    <div style="font-size:0.875rem;font-weight:500;margin-top:4px;line-height:1.35;"
                                         x-text="unit.samsara_last_location_address || '—'"></div>
                                </div>
                                <!-- Speed -->
                                <div style="padding:14px 16px;border-right:1px solid var(--border-color);">
                                    <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Speed</div>
                                    <div class="font-mono" style="font-size:0.95rem;font-weight:600;margin-top:4px;"
                                         x-text="unit.samsara_last_speed_kph !== null && unit.samsara_last_speed_kph !== undefined ? Number(unit.samsara_last_speed_kph).toFixed(1) + ' km/h' : '—'"></div>
                                </div>
                                <!-- Odometer -->
                                <div style="padding:14px 16px;border-right:1px solid var(--border-color);">
                                    <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Odometer</div>
                                    <div class="font-mono" style="font-size:0.95rem;font-weight:600;margin-top:4px;"
                                         x-text="unit.samsara_odometer_km ? Number(unit.samsara_odometer_km).toLocaleString() + ' km' : '—'"></div>
                                </div>
                                <!-- Battery -->
                                <div style="padding:14px 16px;">
                                    <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Battery</div>
                                    <div class="font-mono" style="font-size:0.95rem;font-weight:600;margin-top:4px;">
                                        <span x-show="unit.samsara_battery_pct !== null && unit.samsara_battery_pct !== undefined"
                                              x-text="unit.samsara_battery_pct + '%'"></span>
                                        <span x-show="unit.samsara_battery_pct === null || unit.samsara_battery_pct === undefined">—</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-secondary text-xs" style="padding:8px 16px;border-top:1px solid var(--border-color);">
                                Last synced
                                <span x-text="unit.samsara_last_synced_at ? formatRelative(unit.samsara_last_synced_at) : 'never'"></span>
                                · Linked to <span class="font-mono" x-text="unit.samsara_vehicle_name || unit.samsara_vehicle_id"></span>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ── Distance Travelled (S-UNIT-DISTANCE-SECTION) ─────────
                     S-UNIT-DISTANCE-UNIVERSAL: rendered for EVERY equipment/unit
                     type. Visibility is NO LONGER gated on Samsara linkage — an
                     unlinked unit shows a "not connected" note (below) in place of
                     the calculator controls instead of vanishing. -->
                    <div class="card spec-card" style="margin-bottom:0;grid-column:1/-1;">
                        <div class="card-header" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
                            <div class="card-title">Distance Travelled</div>
                            <!-- km / miles toggle — only meaningful when a GPS device is linked -->
                            <template x-if="unit.samsara_vehicle_id">
                                <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:6px;overflow:hidden;">
                                    <button type="button"
                                            @click="distUnit='km'"
                                            :class="distUnit==='km' ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm'"
                                            style="border-radius:0;border:none;padding:4px 12px;font-size:0.78rem;">km</button>
                                    <button type="button"
                                            @click="distUnit='miles'"
                                            :class="distUnit==='miles' ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm'"
                                            style="border-radius:0;border:none;border-left:1px solid var(--border-color);padding:4px 12px;font-size:0.78rem;">miles</button>
                                </div>
                            </template>
                        </div>
                        <div class="card-body" style="padding:16px;">

                            <!-- LINKED: full distance calculator (presets + pickers + result + saved logs) -->
                            <template x-if="unit.samsara_vehicle_id">
                            <div>

                            <!-- Quick presets -->
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                                <span style="font-size:0.78rem;color:var(--text-secondary);align-self:center;">Presets:</span>
                                <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.78rem;padding:3px 9px;"
                                        @click="distPreset('7d')">Last 7 days</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.78rem;padding:3px 9px;"
                                        @click="distPreset('30d')">Last 30 days</button>
                                <button type="button" class="btn btn-ghost btn-sm" style="font-size:0.78rem;padding:3px 9px;"
                                        @click="distPreset('month')">This month</button>
                            </div>

                            <!-- Date inputs + Calculate -->
                            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                                <div style="flex:1;min-width:180px;">
                                    <label class="form-label" style="font-size:0.8rem;">Start</label>
                                    <input type="datetime-local" class="form-control" x-model="distStart" style="font-size:0.85rem;">
                                </div>
                                <div style="flex:1;min-width:180px;">
                                    <label class="form-label" style="font-size:0.8rem;">End</label>
                                    <input type="datetime-local" class="form-control" x-model="distEnd" style="font-size:0.85rem;">
                                </div>
                                <div>
                                    <button type="button" class="btn btn-primary btn-sm"
                                            @click="calcDistance()"
                                            :disabled="distLoading || !distStart || !distEnd"
                                            style="white-space:nowrap;">
                                        <span x-show="!distLoading">Calculate</span>
                                        <span x-show="distLoading">Calculating…</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Result area — branches MUST share one root <div>: Alpine x-if
                                 renders only a single root element, so the three mutually-
                                 exclusive branch <template>s below have to live inside one
                                 wrapper or the distance-display branch never renders (the bug
                                 that made "Calculate" appear to do nothing). -->
                            <template x-if="distResult">
                              <div>

                                <!-- Not linked -->
                                <template x-if="distResult.linked === false">
                                    <div style="color:var(--text-secondary);font-size:0.875rem;">
                                        This unit is not linked to Samsara — link it above to query distance.
                                    </div>
                                </template>

                                <!-- Linked + got a distance -->
                                <template x-if="distResult.linked && distResult.distance !== null && distResult.distance !== undefined">
                                    <div>
                                        <!-- Warnings banner -->
                                        <template x-if="distResult.warnings && distResult.warnings.length">
                                            <div class="toast toast-warning" style="position:relative;margin-bottom:12px;animation:none;">
                                                <span class="toast-icon">!</span>
                                                <div class="toast-body">
                                                    <div class="toast-message" style="font-size:0.82rem;">
                                                        <strong>Data quality warnings:</strong>
                                                        <ul style="margin:4px 0 0 16px;padding:0;">
                                                            <template x-for="w in distResult.warnings" :key="w">
                                                                <li x-text="w"></li>
                                                            </template>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        <!-- Big number -->
                                        <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:8px;">
                                            <!-- editable distance field (manual edit → source='manual') -->
                                            <input type="text"
                                                   x-model="distEditValue"
                                                   @input="if (distEditValue !== distResult.distance) distEditedManually = true"
                                                   class="form-control font-mono"
                                                   style="width:140px;font-size:1.5rem;font-weight:700;padding:4px 8px;text-align:right;"
                                                   :aria-label="'Distance in ' + distUnit">
                                            <span style="font-size:1.1rem;color:var(--text-secondary);" x-text="distUnit"></span>
                                            <!-- Source badge -->
                                            <span x-show="distResult.source === 'obd'"
                                                  style="display:inline-block;padding:2px 8px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">OBD</span>
                                            <span x-show="distResult.source === 'gps'"
                                                  style="display:inline-block;padding:2px 8px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">GPS</span>
                                            <span x-show="distEditedManually"
                                                  style="display:inline-block;padding:2px 8px;background:#fefce8;color:#854d0e;border:1px solid #fef08a;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Edited</span>
                                        </div>

                                        <!-- Reading window -->
                                        <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:4px;">
                                            <span x-text="distFormatTs(distResult.first_reading_at)"></span>
                                            <span> → </span>
                                            <span x-text="distFormatTs(distResult.last_reading_at)"></span>
                                            <span x-show="distResult.reading_count">
                                                · <span x-text="distResult.reading_count"></span> readings
                                            </span>
                                        </div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);">
                                            Queried <span x-text="distFormatTs(distResult.queried_at)"></span>
                                        </div>

                                        <?php if (can('equipment', 'edit')): ?>
                                        <!-- Save row -->
                                        <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
                                            <input type="text"
                                                   x-model="distSaveLabel"
                                                   class="form-control"
                                                   placeholder="Optional label (e.g. June mileage audit)"
                                                   style="flex:1;min-width:200px;font-size:0.85rem;">
                                            <button type="button" class="btn btn-secondary btn-sm"
                                                    @click="saveDistanceLog()"
                                                    :disabled="distSaving">
                                                <span x-show="!distSaving">Save</span>
                                                <span x-show="distSaving">Saving…</span>
                                            </button>
                                        </div>
                                        <template x-if="distSaveError">
                                            <div style="color:var(--color-danger);font-size:0.82rem;margin-top:6px;" x-text="distSaveError"></div>
                                        </template>
                                        <?php endif; ?>
                                    </div>
                                </template>

                                <!-- Linked but no distance (API failure / no readings) -->
                                <template x-if="distResult.linked && (distResult.distance === null || distResult.distance === undefined)">
                                    <div style="font-size:0.875rem;color:var(--text-secondary);">
                                        No distance data returned for this window.
                                        <template x-if="distResult.reason">
                                            <span>
                                                (<span x-text="distResult.reason"></span>:
                                                <span x-text="distResult.detail"></span>)
                                            </span>
                                        </template>
                                    </div>
                                </template>

                              </div>
                            </template><!-- /distResult -->

                            <!-- Saved logs table -->
                            <template x-if="distLogs.length > 0">
                                <div style="margin-top:20px;">
                                    <div style="font-size:0.82rem;font-weight:600;color:var(--text-secondary);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.05em;">Saved Readings</div>
                                    <div style="overflow-x:auto;">
                                        <table class="spec-table" style="font-size:0.82rem;">
                                            <thead>
                                                <tr>
                                                    <th style="text-align:left;padding:6px 10px;color:var(--text-secondary);font-weight:600;">Period</th>
                                                    <th style="text-align:right;padding:6px 10px;color:var(--text-secondary);font-weight:600;">Distance</th>
                                                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600;">Source</th>
                                                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600;">Label</th>
                                                    <th style="padding:6px 10px;color:var(--text-secondary);font-weight:600;">Saved</th>
                                                    <?php if (can('equipment', 'edit')): ?>
                                                    <th style="padding:6px 10px;"></th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="log in distLogs" :key="log.id">
                                                    <tr>
                                                        <td style="padding:6px 10px;white-space:nowrap;">
                                                            <span x-text="distFormatDate(log.period_start)"></span>
                                                            <span style="color:var(--text-muted);"> → </span>
                                                            <span x-text="distFormatDate(log.period_end)"></span>
                                                        </td>
                                                        <td style="padding:6px 10px;text-align:right;font-family:monospace;font-weight:600;" x-text="log.distance + ' ' + log.unit"></td>
                                                        <td style="padding:6px 10px;">
                                                            <span x-show="log.source==='obd'"
                                                                  style="display:inline-block;padding:1px 6px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:8px;font-size:0.7rem;font-weight:700;text-transform:uppercase;">OBD</span>
                                                            <span x-show="log.source==='gps'"
                                                                  style="display:inline-block;padding:1px 6px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:8px;font-size:0.7rem;font-weight:700;text-transform:uppercase;">GPS</span>
                                                            <span x-show="log.source==='manual'"
                                                                  style="display:inline-block;padding:1px 6px;background:#fefce8;color:#854d0e;border:1px solid #fef08a;border-radius:8px;font-size:0.7rem;font-weight:700;text-transform:uppercase;">Manual</span>
                                                        </td>
                                                        <td style="padding:6px 10px;color:var(--text-secondary);" x-text="log.label || '—'"></td>
                                                        <td style="padding:6px 10px;color:var(--text-muted);white-space:nowrap;" x-text="distFormatDate(log.created_at)"></td>
                                                        <?php if (can('equipment', 'edit')): ?>
                                                        <td style="padding:6px 10px;">
                                                            <button type="button" class="btn btn-danger btn-sm" style="padding:2px 8px;font-size:0.75rem;"
                                                                    @click="deleteDistanceLog(log.id)">Delete</button>
                                                        </td>
                                                        <?php endif; ?>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>

                            </div>
                            </template><!-- /LINKED -->

                            <!-- UNLINKED: no GPS device → note in place of the controls (never hide the section) -->
                            <template x-if="!unit.samsara_vehicle_id">
                                <div style="display:flex;gap:10px;align-items:flex-start;color:var(--text-secondary);font-size:0.9rem;padding:4px 0;">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" style="width:18px;height:18px;flex-shrink:0;margin-top:1px;opacity:0.7;" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                    </svg>
                                    <div>
                                        <strong style="color:var(--text-primary);">This unit isn't connected to any GPS device.</strong>
                                        <div style="font-size:0.82rem;color:var(--text-muted);margin-top:3px;">
                                            Link it to a Samsara device from the
                                            <a href="#" @click.prevent="activeTab='tracking'" style="color:var(--color-primary);text-decoration:underline;">Samsara Mapping</a>
                                            tab to calculate distance travelled over a period.
                                        </div>
                                    </div>
                                </div>
                            </template><!-- /UNLINKED -->

                        </div>
                    </div><!-- /Distance Travelled -->

                <!-- ── SAMSARA-2: Tag pills (lenders + lessees) ─────────────
                     Only rendered when samsara_tags has at least one entry.
                     Lender tags (financing / lien holder) show in amber.
                     Lessee tags (past / current renters) show in blue and
                     link to their customer record.
                     ─────────────────────────────────────────────────────── -->
                <template x-if="unit.samsara_tags && unit.samsara_tags.length > 0">
                    <div class="card spec-card" style="margin-bottom:0;grid-column:1/-1;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Samsara Tags</div>
                            <div class="text-xs text-secondary" style="margin-left:auto;">
                                from Samsara fleet tags
                            </div>
                        </div>
                        <div class="card-body" style="padding:12px 16px;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-start;">
                            <template x-for="tag in unit.samsara_tags" :key="tag.raw || tag.name">
                                <template x-if="tag.type === 'lender'">
                                    <!-- Amber pill for finance / lien holder -->
                                    <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;border-radius:9999px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:0.78rem;font-weight:500;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px;flex-shrink:0;" aria-hidden="true">
                                            <path d="M8 1a4 4 0 100 8A4 4 0 008 1zM2.75 9.5a.75.75 0 00-.75.75v.69c0 .622.447 1.146 1.057 1.244l.96.16a6.496 6.496 0 001.983.144 6.5 6.5 0 002.985-.822A6.5 6.5 0 0013 9.5H2.75z"/>
                                        </svg>
                                        <span>Financed by: </span>
                                        <span x-text="tag.name"></span>
                                    </span>
                                </template>
                                <template x-if="tag.type === 'lessee'">
                                    <!-- Blue pill for lessee — links to customer record -->
                                    <a :href="tag.customer_id ? `<?= base_url('customers/show') ?>?id=${tag.customer_id}` : '#'"
                                       style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.65rem;border-radius:9999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:0.78rem;font-weight:500;text-decoration:none;"
                                       :style="!tag.customer_id ? 'pointer-events:none;' : ''"
                                       title="View customer record">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" style="width:12px;height:12px;flex-shrink:0;" aria-hidden="true">
                                            <path d="M8 8a3 3 0 100-6 3 3 0 000 6zM12.735 14c.618 0 1.093-.561.872-1.139a6.002 6.002 0 00-11.215 0C2.172 13.439 2.647 14 3.265 14H12.735z"/>
                                        </svg>
                                        <span x-text="tag.name"></span>
                                    </a>
                                </template>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- ── Notes (spans full width if present) ── -->
                <template x-if="unit.notes">
                    <div class="card spec-card" style="margin-bottom:0;grid-column:1/-1;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Notes</div>
                        </div>
                        <div class="card-body" style="padding:12px 16px;">
                            <div x-text="unit.notes" class="text-secondary" style="white-space:pre-line;line-height:1.6;"></div>
                        </div>
                    </div>
                </template>

            </div>
        </template>
    </div>

    <!-- ── TAB: Payoff Analysis ───────────────────────────────────
         PAYOFF-1: shows the same analysis the fixed-asset detail modal
         shows, but keyed off this unit's linked acc_fixed_assets row.
         Server-side lookup happened in PHP so we know upfront whether
         the unit has a linked asset — the empty state is a plain static
         card, not a JS-driven template.
         ──────────────────────────────────────────────────────────── -->
    <div x-show="activeTab === 'payoff'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <?php if (!$linkedAsset): ?>
        <!-- ── Empty state: no linked fixed asset ───────────────── -->
        <div class="card">
            <div class="card-body" style="text-align:center;padding:48px 20px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" style="width:48px;height:48px;color:var(--text-muted);margin-bottom:12px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z"/>
                </svg>
                <h3 style="margin:0 0 8px;font-size:1rem;font-weight:600;color:var(--text-primary);">No Linked Fixed Asset</h3>
                <p style="margin:0 0 16px;font-size:0.875rem;color:var(--text-secondary);max-width:460px;margin-left:auto;margin-right:auto;">
                    This unit isn't linked to a fixed asset yet, so there's no acquisition cost to recover.
                    Create a fixed asset for it (or edit an existing one) and set its <strong>Equipment Unit</strong>
                    field to <span class="font-mono"><?= e($unit['unit_number']) ?></span> to see a full payoff analysis here.
                </p>
                <a href="<?= base_url('accounting/fixed-assets') ?>" class="btn btn-primary btn-sm">Go to Fixed Assets →</a>
            </div>
        </div>
        <?php else: ?>

        <!-- ── Header bar: links to full payoff page + fixed-asset ── -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <div>
                <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.06em;margin-bottom:2px;">
                    Linked Fixed Asset
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span class="font-mono" style="font-size:0.9375rem;font-weight:600;"><?= e($linkedAsset['asset_number']) ?></span>
                    <span style="color:var(--text-secondary);font-size:0.875rem;">·</span>
                    <span style="font-size:0.875rem;"><?= e($linkedAsset['name']) ?></span>
                    <span class="badge badge-neutral"><?= e($linkedAsset['status']) ?></span>
                </div>
                <div class="text-secondary text-xs" style="margin-top:2px;">
                    Acquired <span class="font-mono"><?= e($linkedAsset['acquisition_date']) ?></span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <!-- WHY: Primary CTA goes to the dedicated full-detail payoff page
                         (equipment/payoff.php) that shows leases, work orders,
                         damage claims, monthly P&L, depreciation, and utilisation
                         in a single view — rather than bouncing to Fixed Assets. -->
                <a href="<?= base_url('equipment/payoff') ?>?id=<?= $unitId ?>" class="btn btn-primary btn-sm">View Full Analysis →</a>
                <a href="<?= base_url('accounting/fixed-assets') ?>?asset=<?= (int) $linkedAsset['id'] ?>" class="btn btn-secondary btn-sm">Fixed Assets</a>
            </div>
        </div>

        <!-- ── Loading skeleton ──────────────────────────────────── -->
        <template x-if="payoffLoading && !payoff">
            <div class="card">
                <div class="card-body">
                    <div class="skeleton skeleton-row" style="margin-bottom:8px;"></div>
                    <div class="skeleton skeleton-row" style="margin-bottom:8px;"></div>
                    <div class="skeleton skeleton-row" style="margin-bottom:8px;"></div>
                    <div class="skeleton skeleton-row" style="margin-bottom:8px;"></div>
                </div>
            </div>
        </template>

        <!-- ── Error state ──────────────────────────────────────── -->
        <template x-if="payoffError">
            <div class="card">
                <div class="card-body" style="text-align:center;padding:32px 20px;">
                    <div class="text-danger" style="font-size:0.9375rem;font-weight:600;margin-bottom:6px;">Couldn't load payoff analysis</div>
                    <div class="text-secondary text-sm" x-text="payoffError" style="margin-bottom:14px;"></div>
                    <button class="btn btn-secondary btn-sm" @click="loadPayoff()">Retry</button>
                </div>
            </div>
        </template>

        <!-- ── Loaded: full payoff UI ───────────────────────────── -->
        <template x-if="payoff && !payoffError">
            <div>

                <!-- KPI stat cards -->
                <div class="stat-grid stat-grid--4" style="gap:12px;margin-bottom:20px;">
                    <div class="stat-card">
                        <div class="stat-label">Total Invested</div>
                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.acquisition.total)"></div>
                        <div class="text-secondary text-xs" style="margin-top:4px;">
                            Target <span class="font-mono" x-text="formatMoney(payoff.acquisition.adjusted_target)"></span>
                        </div>
                    </div>
                    <div class="stat-card stat-card--green">
                        <div class="stat-label">Net Revenue to Date</div>
                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.totals.net_revenue_to_date)"></div>
                        <div class="text-secondary text-xs" style="margin-top:4px;">
                            <span x-text="payoff.totals.months_since_acquisition + ' months'"></span>
                        </div>
                    </div>
                    <div class="stat-card stat-card--amber">
                        <div class="stat-label">Still to Recover</div>
                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="formatMoney(payoff.totals.still_to_recover)"></div>
                        <div class="text-secondary text-xs" style="margin-top:4px;">
                            <span x-text="'Avg ' + formatMoney(payoff.selected.monthly_net) + '/mo'"></span>
                        </div>
                    </div>
                    <div class="stat-card stat-card--blue">
                        <div class="stat-label">Progress</div>
                        <div class="stat-value font-mono" style="font-size:1.125rem;" x-text="(parseFloat(payoff.totals.progress_pct) >= 0 ? payoff.totals.progress_pct : '0.00') + '%'"></div>
                        <template x-if="payoff.selected.date">
                            <div class="text-secondary text-xs" style="margin-top:4px;">
                                Paid off by <span x-text="payoff.selected.date"></span>
                            </div>
                        </template>
                        <template x-if="!payoff.selected.date && parseFloat(payoff.totals.still_to_recover) <= 0">
                            <div class="text-success text-xs" style="margin-top:4px;"><strong>Fully paid!</strong></div>
                        </template>
                    </div>
                </div>

                <!-- Big progress bar -->
                <div class="card" style="padding:16px;margin-bottom:20px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span class="text-sm text-secondary" style="font-weight:600;">Payoff progress</span>
                        <span class="text-sm font-mono" x-text="payoff.totals.progress_pct + '%'"></span>
                    </div>
                    <div style="width:100%;height:14px;background:var(--bg-tertiary);border-radius:7px;overflow:hidden;border:1px solid var(--border-default);">
                        <div :style="payoffBarStyle()"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:6px;">
                        <span class="text-xs text-secondary">$0</span>
                        <span class="text-xs text-secondary font-mono" x-text="formatMoney(payoff.acquisition.adjusted_target)"></span>
                    </div>
                </div>

                <!-- Projection scenarios (click to re-project) -->
                <h3 class="h6" style="margin:0 0 10px 0;">Projection Scenarios</h3>
                <div class="stat-grid stat-grid--3" style="gap:12px;margin-bottom:20px;">
                    <div class="card"
                         @click="payoffPeriod = 12; reloadPayoff()"
                         :style="(payoffPeriod === 12 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Conservative</div>
                        <div class="text-xs text-secondary" style="margin-bottom:6px;">12-month average</div>
                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.conservative.monthly_net) + '/mo'"></div>
                        <div class="text-sm" style="margin-top:6px;">
                            <template x-if="payoff.scenarios.conservative.months !== null">
                                <span>Paid off in <strong x-text="payoff.scenarios.conservative.months"></strong> months</span>
                            </template>
                            <template x-if="payoff.scenarios.conservative.months === null">
                                <span class="text-secondary">— no projection —</span>
                            </template>
                        </div>
                        <template x-if="payoff.scenarios.conservative.date">
                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.conservative.date"></div>
                        </template>
                    </div>
                    <div class="card"
                         @click="payoffPeriod = 6; reloadPayoff()"
                         :style="(payoffPeriod === 6 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Current</div>
                        <div class="text-xs text-secondary" style="margin-bottom:6px;">6-month average</div>
                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.current.monthly_net) + '/mo'"></div>
                        <div class="text-sm" style="margin-top:6px;">
                            <template x-if="payoff.scenarios.current.months !== null">
                                <span>Paid off in <strong x-text="payoff.scenarios.current.months"></strong> months</span>
                            </template>
                            <template x-if="payoff.scenarios.current.months === null">
                                <span class="text-secondary">— no projection —</span>
                            </template>
                        </div>
                        <template x-if="payoff.scenarios.current.date">
                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.current.date"></div>
                        </template>
                    </div>
                    <div class="card"
                         @click="payoffPeriod = 3; reloadPayoff()"
                         :style="(payoffPeriod === 3 ? 'border:2px solid var(--color-primary);' : 'border:1px solid var(--border-default);') + 'cursor:pointer;padding:14px;'">
                        <div class="text-xs text-secondary" style="text-transform:uppercase;letter-spacing:0.05em;">Optimistic</div>
                        <div class="text-xs text-secondary" style="margin-bottom:6px;">3-month average</div>
                        <div class="font-mono" style="font-size:1rem;font-weight:600;" x-text="formatMoney(payoff.scenarios.optimistic.monthly_net) + '/mo'"></div>
                        <div class="text-sm" style="margin-top:6px;">
                            <template x-if="payoff.scenarios.optimistic.months !== null">
                                <span>Paid off in <strong x-text="payoff.scenarios.optimistic.months"></strong> months</span>
                            </template>
                            <template x-if="payoff.scenarios.optimistic.months === null">
                                <span class="text-secondary">— no projection —</span>
                            </template>
                        </div>
                        <template x-if="payoff.scenarios.optimistic.date">
                            <div class="text-xs text-secondary" style="margin-top:2px;" x-text="'by ' + payoff.scenarios.optimistic.date"></div>
                        </template>
                    </div>
                </div>

                <!-- Chart -->
                <div class="card" style="padding:16px;margin-bottom:20px;">
                    <h3 class="h6" style="margin:0 0 10px 0;">Cumulative Net Revenue vs Payoff Target</h3>
                    <p class="text-secondary text-xs" style="margin:0 0 10px 0;">Last 14 months of revenue, minus maintenance, damage, monthly fixed costs, and financing payments. Dashed green line = total target to recover.</p>
                    <div id="unit-payoff-chart" style="min-height:300px;"></div>
                </div>

                <!-- Two-column split: Acquisition breakdown + Earnings breakdown -->
                <div class="equip-section-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

                    <!-- Acquisition detail -->
                    <div class="card spec-card" style="margin-bottom:0;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Acquisition Cost</div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <table class="spec-table">
                                <tr>
                                    <td class="spec-label">Purchase Cost</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.purchase_cost)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">+ GST</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.gst)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">+ PST</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.pst)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">+ Delivery</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.delivery_cost)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">+ Setup</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.setup_cost)"></td>
                                </tr>
                                <tr style="font-weight:600;">
                                    <td class="spec-label">= Total Invested</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.total)"></td>
                                </tr>
                                <template x-if="parseFloat(payoff.acquisition.extra_costs) > 0">
                                    <tr>
                                        <td class="spec-label">+ Extra Costs</td>
                                        <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.extra_costs)"></td>
                                    </tr>
                                </template>
                                <tr style="font-weight:600;background:var(--bg-surface-2);">
                                    <td class="spec-label">Adjusted Target</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.acquisition.adjusted_target)"></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Earnings breakdown -->
                    <div class="card spec-card" style="margin-bottom:0;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Earnings &amp; Expenses to Date</div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <table class="spec-table">
                                <tr>
                                    <td class="spec-label">Total Revenue</td>
                                    <td class="font-mono text-right text-success" x-text="formatMoney(payoff.totals.total_revenue)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">− Maintenance</td>
                                    <td class="font-mono text-right text-danger" x-text="'−' + formatMoney(payoff.totals.total_maintenance)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">− Damage Claims</td>
                                    <td class="font-mono text-right text-danger" x-text="'−' + formatMoney(payoff.totals.total_damage)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">− Financing Paid</td>
                                    <td class="font-mono text-right text-danger" x-text="'−' + formatMoney(payoff.totals.total_financing_paid)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">− Fixed Costs Paid</td>
                                    <td class="font-mono text-right text-danger" x-text="'−' + formatMoney(payoff.totals.total_fixed_paid)"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Monthly Fixed</td>
                                    <td class="font-mono text-right text-secondary" x-text="formatMoney(payoff.totals.monthly_fixed) + '/mo'"></td>
                                </tr>
                                <tr style="font-weight:600;background:var(--bg-surface-2);">
                                    <td class="spec-label">= Net Revenue</td>
                                    <td class="font-mono text-right" x-text="formatMoney(payoff.totals.net_revenue_to_date)"></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Manual override card -->
                <div class="card" style="padding:14px;border:1px dashed var(--border-default);margin-bottom:20px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <h3 class="h6" style="margin:0;">Manual Override</h3>
                        <template x-if="payoff.custom_projection">
                            <span class="badge badge-info">Custom</span>
                        </template>
                    </div>
                    <p class="text-secondary text-xs" style="margin:0 0 10px 0;">
                        Try your own projected monthly net revenue and any one-time upcoming costs (tires, compliance, major repair) to see a custom payoff date.
                    </p>
                    <div class="equip-override-row" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;">
                        <div>
                            <label class="text-xs text-secondary">Custom Monthly Net ($)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                                   x-model="payoffCustomMonthly" placeholder="e.g. 4000.00">
                        </div>
                        <div>
                            <label class="text-xs text-secondary">Extra One-Time Costs ($)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                                   x-model="payoffCustomExtra" placeholder="e.g. 2500.00">
                        </div>
                        <button class="btn btn-primary btn-sm" @click="applyPayoffCustom()" :disabled="payoffLoading">Apply</button>
                        <button class="btn btn-secondary btn-sm" @click="resetPayoffCustom()" :disabled="payoffLoading">Reset</button>
                    </div>
                    <template x-if="payoff.custom_projection">
                        <div style="margin-top:12px;padding:10px;background:var(--bg-tertiary);border-radius:6px;">
                            <div class="text-sm">
                                At <strong class="font-mono" x-text="formatMoney(payoff.custom_projection.monthly_net) + '/mo'"></strong>,
                                <template x-if="payoff.custom_projection.months !== null">
                                    <span>paid off in <strong x-text="payoff.custom_projection.months"></strong> months
                                    (<span x-text="payoff.custom_projection.date"></span>).</span>
                                </template>
                                <template x-if="payoff.custom_projection.months === null">
                                    <span class="text-secondary">— already fully paid or nothing to project —</span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

            </div>
        </template>

        <?php endif; ?>
    </div>

    <!-- ── TAB: Compliance ────────────────────────────────────── -->
    <div x-show="activeTab === 'compliance'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
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
                                        <td class="font-mono" x-text="complianceDelta(doc.days)"></td>
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
    <div x-show="activeTab === 'leases'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Lease History</div>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.status" @change="applyLeasesFilters()">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.sort" @change="applyLeasesFilters()">
                    <option value="start_date">Sort: Start Date</option>
                    <option value="monthly_rate">Sort: Monthly Rate</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="leasesFilters.dir" @change="applyLeasesFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <div x-show="leasesLoading && leaseHistory.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading lease history…</span>
            </div>

            <div x-show="leasesLoaded && !leasesLoading && leaseHistory.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No lease history found</p>
                    <p class="empty-state-text">No leases match the current filters.</p>
                </div>
            </div>

            <div x-show="leaseHistory.length > 0">
                <div class="tab-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Contract #</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Start</th>
                                <th>End</th>
                                <th class="text-right">Monthly Rate</th>
                                <!-- S-LEASE-MILEAGE: total km + allowance per lease -->
                                <th class="text-right" title="Total km driven over this lease">Total km</th>
                                <th class="text-right" title="Total mileage allowance for this lease">Allowance</th>
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
                                    <td class="text-right font-mono"
                                        x-text="ls.total_distance_km !== null && ls.total_distance_km !== undefined
                                                ? Number(ls.total_distance_km).toLocaleString('en-CA',{maximumFractionDigits:0}) + ' km'
                                                : '—'"></td>
                                    <td class="text-right font-mono"
                                        x-text="ls.estimated_mileage_km && parseFloat(ls.estimated_mileage_km) > 0
                                                ? Number(ls.estimated_mileage_km).toLocaleString('en-CA',{maximumFractionDigits:0}) + ' km'
                                                : '—'"></td>
                                    <td>
                                        <a :href="'<?= base_url('leases/show') ?>?id=' + ls.id"
                                           class="btn btn-sm btn-secondary">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot x-show="leaseHistory.length > 0">
                            <tr style="font-weight:600; background:var(--bg-surface-2);">
                                <td colspan="6" class="text-right">Total km across all leases:</td>
                                <td class="text-right font-mono"
                                    x-text="Number(leaseHistory.reduce((s,l) => s + (parseFloat(l.total_distance_km) || 0), 0)).toLocaleString('en-CA',{maximumFractionDigits:0}) + ' km'"></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="tab-table-footer">
                    <span x-text="`Showing ${leaseHistory.length} of ${leasesTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="leaseHistory.length < leasesTotal"
                            :disabled="leasesLoading"
                            @click="loadMoreLeaseHistory()"
                            x-text="leasesLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Damage Claims ──────────────────────────────────── -->
    <div x-show="activeTab === 'damage_claims'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Damage Claims</div>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('damage_claims/create') ?>?equipment_unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ New Claim</a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.severity" @change="applyDamageClaimsFilters()">
                    <option value="">All Severities</option>
                    <option value="minor">Minor</option>
                    <option value="moderate">Moderate</option>
                    <option value="major">Major</option>
                    <option value="total_loss">Total Loss</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.status" @change="applyDamageClaimsFilters()">
                    <option value="">All Statuses</option>
                    <option value="reported">Reported</option>
                    <option value="assessed">Assessed</option>
                    <option value="repair_ordered">Repair Ordered</option>
                    <option value="invoiced">Invoiced</option>
                    <option value="resolved">Resolved</option>
                    <option value="written_off">Written Off</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="damageClaimsFilters.dir" @change="applyDamageClaimsFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <div x-show="damageClaimsLoading && damageClaims.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading damage claims…</span>
            </div>

            <div x-show="damageClaimsLoaded && !damageClaimsLoading && damageClaims.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No damage claims found</p>
                    <p class="empty-state-text">No claims match the current filters.</p>
                </div>
            </div>

            <div x-show="damageClaims.length > 0">
                <div class="tab-table-container">
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
                <div class="tab-table-footer">
                    <span x-text="`Showing ${damageClaims.length} of ${damageClaimsTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="damageClaims.length < damageClaimsTotal"
                            :disabled="damageClaimsLoading"
                            @click="loadMoreDamageClaims()"
                            x-text="damageClaimsLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Mileage Log ──────────────────────────────────── -->
    <div x-show="activeTab === 'mileage_logs'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="card-title">Mileage Log</div>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('mileage_logs/create') ?>?equipment_unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ Record Mileage</a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="mileageLogsFilters.log_type" @change="applyMileageLogsFilters()">
                    <option value="">All Types</option>
                    <option value="manual">Manual</option>
                    <option value="gps_sync">GPS Sync</option>
                    <option value="lease_start">Lease Start</option>
                    <option value="lease_end">Lease End</option>
                    <option value="service">Service</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="mileageLogsFilters.dir" @change="applyMileageLogsFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <div x-show="mileageLogsLoading && mileageLogs.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading mileage log…</span>
            </div>

            <div x-show="mileageLogsLoaded && !mileageLogsLoading && mileageLogs.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No mileage entries found</p>
                    <p class="empty-state-text">No records match the current filters.</p>
                </div>
            </div>

            <div x-show="mileageLogs.length > 0">
                <div class="tab-table-container">
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
                <div class="tab-table-footer">
                    <span x-text="`Showing ${mileageLogs.length} of ${mileageLogsTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="mileageLogs.length < mileageLogsTotal"
                            :disabled="mileageLogsLoading"
                            @click="loadMoreMileageLogs()"
                            x-text="mileageLogsLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Status Log ────────────────────────────────────── -->
    <div x-show="activeTab === 'status_log'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
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
                    <div class="tab-table-container">
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
    <div x-show="activeTab === 'maintenance'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:600;">Work Order History</span>
                <?php if (can('maintenance', 'create')): ?>
                <a href="<?= base_url('maintenance_work_orders/create') ?>?unit_id=<?= $unitId ?>"
                   class="btn btn-primary btn-sm">+ New Work Order</a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="workOrdersFilters.status" @change="applyWorkOrdersFilters()">
                    <option value="">All Statuses</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="waiting_parts">Waiting Parts</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="workOrdersFilters.work_type" @change="applyWorkOrdersFilters()">
                    <option value="">All Types</option>
                    <option value="repair">Repair</option>
                    <option value="preventive">Preventive</option>
                    <option value="inspection">Inspection</option>
                    <option value="emergency">Emergency</option>
                    <option value="other">Other</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="workOrdersFilters.sort" @change="applyWorkOrdersFilters()">
                    <option value="requested_date">Sort: Requested Date</option>
                    <option value="total_cost">Sort: Total Cost</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="workOrdersFilters.dir" @change="applyWorkOrdersFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <div x-show="workOrdersLoading && workOrders.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading work orders…</span>
            </div>

            <div x-show="workOrdersLoaded && !workOrdersLoading && workOrders.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No work orders found</p>
                    <p class="empty-state-text">No work orders match the current filters.</p>
                </div>
            </div>

            <div x-show="workOrders.length > 0">
                <div class="tab-table-container">
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
                <div class="tab-table-footer">
                    <span x-text="`Showing ${workOrders.length} of ${workOrdersTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="workOrders.length < workOrdersTotal"
                            :disabled="workOrdersLoading"
                            @click="loadMoreWorkOrders()"
                            x-text="workOrdersLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Inspections ──────────────────────────────────── -->
    <div x-show="activeTab === 'inspections'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-weight:600;">Inspections</span>
                <?php if (can('inspections', 'create')): ?>
                <a href="<?= base_url('inspections/create') ?>?unit_id=<?= $unitId ?>" class="btn btn-primary btn-sm">+ New Inspection</a>
                <?php endif; ?>
            </div>

            <!-- Filter bar -->
            <div class="tab-filter-bar">
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="inspectionsFilters.inspection_type" @change="applyInspectionsFilters()">
                    <option value="">All Types</option>
                    <option value="pre_lease">Pre-Lease</option>
                    <option value="post_lease">Post-Lease</option>
                    <option value="periodic">Periodic</option>
                    <option value="damage">Damage</option>
                    <option value="compliance">Compliance</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="inspectionsFilters.status" @change="applyInspectionsFilters()">
                    <option value="">All Statuses</option>
                    <option value="draft">Draft</option>
                    <option value="complete">Complete</option>
                    <option value="signed">Signed</option>
                </select>
                <select class="form-control" style="width:auto;font-size:0.8125rem;padding:5px 10px;"
                        x-model="inspectionsFilters.dir" @change="applyInspectionsFilters()">
                    <option value="DESC">Newest First</option>
                    <option value="ASC">Oldest First</option>
                </select>
            </div>

            <div x-show="inspectionsLoading && inspections.length === 0" class="card-body" style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading inspections…</span>
            </div>

            <div x-show="inspectionsLoaded && !inspectionsLoading && inspections.length === 0" class="card-body empty-state">
                <p class="empty-state-title">No inspections found</p>
                <p class="empty-state-text">No inspections match the current filters.</p>
            </div>

            <div x-show="inspections.length > 0">
                <div class="tab-table-container">
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
                <div class="tab-table-footer">
                    <span x-text="`Showing ${inspections.length} of ${inspectionsTotal}`"></span>
                    <button class="btn btn-secondary btn-sm"
                            x-show="inspections.length < inspectionsTotal"
                            :disabled="inspectionsLoading"
                            @click="loadMoreInspections()"
                            x-text="inspectionsLoading ? 'Loading…' : 'Load more'">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: Documents ────────────────────────────────────── -->
    <div x-show="activeTab === 'documents'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="card-title">Compliance Documents</h3>
                <?php if (can('equipment', 'edit')): ?>
                <button class="btn btn-sm btn-primary"
                        @click="openUploadModal('', '', 'equipment_unit', <?= (int)$unitId ?>)">+ Upload</button>
                <?php endif; ?>
            </div>

            <!-- Loading -->
            <div x-show="docsLoading && documents.length === 0" class="card-body"
                 style="text-align:center;padding:32px;">
                <span class="text-secondary">Loading documents…</span>
            </div>

            <!-- Empty -->
            <div x-show="docsLoaded && !docsLoading && documents.length === 0" class="card-body">
                <div class="empty-state">
                    <p class="empty-state-title">No documents</p>
                    <p class="empty-state-text">Upload CVI, registration, insurance, or other compliance files for this unit.</p>
                </div>
            </div>

            <!-- Table -->
            <div x-show="documents.length > 0" class="tab-table-container">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>File</th>
                                <th>Size</th>
                                <th>Expires</th>
                                <th>Uploaded</th>
                                <th>By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="doc in documents" :key="doc.id">
                                <tr>
                                    <td>
                                        <span class="badge badge-neutral"
                                              x-text="doc.document_type.replace(/_/g,' ')"></span>
                                    </td>
                                    <td class="font-mono text-sm" x-text="doc.file_name"></td>
                                    <td class="font-mono text-sm"
                                        x-text="doc.file_size_kb ? doc.file_size_kb + ' KB' : '—'"></td>
                                    <td x-text="doc.expiration_date ? formatDate(doc.expiration_date) : '—'"></td>
                                    <td class="text-sm" x-text="formatDate(doc.uploaded_at)"></td>
                                    <td class="text-sm text-secondary" x-text="doc.uploaded_by_name || '—'"></td>
                                    <td style="white-space:nowrap;">
                                        <a :href="doc.url" target="_blank" rel="noopener"
                                           class="btn btn-xs btn-ghost">View</a>
                                        <?php if (can('equipment', 'edit')): ?>
                                        <button class="btn btn-xs btn-outline-danger"
                                                @click="confirmDeleteDoc(doc.id)"
                                                style="margin-left:4px;">Remove</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="documents.length > 0" class="tab-table-footer">
                <span x-text="documents.length + ' document' + (documents.length !== 1 ? 's' : '')"></span>
            </div>
        </div>

        <!-- Upload Modal -->
        <template x-if="uploadModal.open">
            <div class="modal-overlay" @click.self="uploadModal.open = false"
                 style="background:rgba(0,0,0,0.70);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);">
                <div class="modal" style="max-width:480px;">
                    <div class="modal-header">
                        <h2 class="modal-title">Upload Document</h2>
                        <button class="modal-close-btn" aria-label="Close" @click="uploadModal.open = false">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div x-show="uploadModal.error" class="alert alert-danger" style="margin-bottom:1rem;"
                             x-text="uploadModal.error"></div>
                        <div style="margin-bottom:1rem;">
                            <label class="form-label">
                                Document Type <span style="color:var(--color-danger);">*</span>
                            </label>
                            <select class="form-select" x-model="uploadModal.docType"
                                    :disabled="uploadModal.uploading">
                                <option value="">— Select type —</option>
                                <option value="cvi">CVI Certificate</option>
                                <option value="registration">Registration Document</option>
                                <option value="insurance">Insurance Certificate</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div style="margin-bottom:1rem;">
                            <label class="form-label">
                                Document File <span style="color:var(--color-danger);">*</span>
                            </label>
                            <input type="file" class="form-control"
                                   accept=".pdf,application/pdf"
                                   @change="uploadModal.file = $event.target.files[0]"
                                   :disabled="uploadModal.uploading">
                            <p style="font-size:0.8rem; color:var(--text-secondary); margin-top:0.25rem;">
                                PDF only &middot; Max 20 MB
                            </p>
                        </div>
                        <div>
                            <label class="form-label">
                                Expiry Date
                                <span style="color:var(--text-secondary); font-weight:400;">(optional)</span>
                            </label>
                            <input type="date" class="form-control"
                                   x-model="uploadModal.expiryDate"
                                   :disabled="uploadModal.uploading">
                            <p style="font-size:0.8rem; color:var(--text-secondary); margin-top:0.25rem;">
                                Also updates the compliance grid expiry date.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary"
                                @click="uploadModal.open = false"
                                :disabled="uploadModal.uploading">Cancel</button>
                        <button class="btn btn-primary"
                                @click="submitUpload()"
                                :disabled="uploadModal.uploading || !uploadModal.file || !uploadModal.docType || !uploadModal.entity_type || !uploadModal.entity_id">
                            <span x-show="!uploadModal.uploading">Upload Document</span>
                            <span x-show="uploadModal.uploading">Uploading…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

    </div>

    <!-- ── TAB: Samsara Mapping ────────────────────────────────── -->
    <!-- SAMSARA-1: Replaces the older "GPS Tracking" tab. Shows two
         possible states based on whether the unit is currently mapped
         to a Samsara vehicle:
           UNMAPPED → dropdown of unmapped Samsara vehicles + Link button
           MAPPED   → live data cards (location, telemetry, identifiers)
                      + mini-map + Sync Now / Unlink buttons
         All inputs go through api/v1/samsara/* endpoints. -->
    <div x-show="activeTab === 'tracking'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">

        <!-- ── UNMAPPED STATE ────────────────────────────────── -->
        <!-- SAMSARA-2: dropdown lists vehicles AND trailers; the
             entity_type tag is rendered as a prefix so users can
             tell at a glance which API path the unit will sync via. -->
        <template x-if="!unit?.samsara_vehicle_id">
            <div class="card spec-card">
                <div class="card-header" style="padding:12px 16px;">
                    <div class="card-title">Link to Samsara</div>
                </div>
                <div class="card-body" style="padding:20px;">
                    <p style="margin:0 0 16px;font-size:0.875rem;color:var(--text-secondary);">
                        Map this unit to a Samsara vehicle or trailer to start
                        syncing GPS data every 5 minutes. Vehicles also report
                        odometer, battery, and power source; trailers only
                        report GPS + odometer.
                    </p>

                    <!-- Loading trackables -->
                    <template x-if="samsaraVehiclesLoading">
                        <div style="color:var(--text-secondary);font-size:0.875rem;">
                            Loading Samsara vehicles &amp; trailers…
                        </div>
                    </template>

                    <!-- Trackables loaded but list is empty -->
                    <template x-if="!samsaraVehiclesLoading && samsaraVehicles.length === 0 && samsaraVehiclesLoaded">
                        <div class="toast toast-warning" style="position:relative;margin:0;animation:none;">
                            <span class="toast-icon">!</span>
                            <div class="toast-body">
                                <div class="toast-message">
                                    No vehicles or trailers found in Samsara. Verify the API key in
                                    <a href="<?= base_url('settings/integrations') ?>" style="text-decoration:underline;">Settings → Integrations</a>.
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Trackable picker -->
                    <template x-if="!samsaraVehiclesLoading && samsaraVehicles.length > 0">
                        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                            <div style="flex:1;min-width:280px;">
                                <label class="form-label" for="samsara-vehicle-select">Samsara Vehicle or Trailer</label>
                                <select id="samsara-vehicle-select"
                                        class="form-control"
                                        x-model="samsaraSelectedVehicleId">
                                    <option value="">-- Select a vehicle or trailer --</option>
                                    <template x-for="v in samsaraVehicles" :key="v.id">
                                        <option :value="v.id"
                                                :disabled="v.is_linked"
                                                x-text="(v.entity_type === 'trailer' ? '[Trailer] ' : '[Vehicle] ') + (
                                                    v.is_linked
                                                    ? (v.name + ' — already linked to ' + v.linked_unit_number)
                                                    : (v.name + (v.vin ? ' · VIN ' + v.vin.slice(-6) : ''))
                                                )">
                                        </option>
                                    </template>
                                </select>
                                <div class="text-secondary text-sm" style="margin-top:4px;">
                                    <span x-text="samsaraVehicles.length"></span> total
                                    (<span x-text="samsaraVehicles.filter(v => v.entity_type !== 'trailer').length"></span> vehicles,
                                    <span x-text="samsaraVehicles.filter(v => v.entity_type === 'trailer').length"></span> trailers) —
                                    <span x-text="samsaraVehicles.filter(v => !v.is_linked).length"></span> available to link
                                </div>
                            </div>
                            <?php if (can('equipment', 'edit')): ?>
                            <button class="btn btn-primary"
                                    @click="linkSamsaraVehicle()"
                                    :disabled="!samsaraSelectedVehicleId || samsaraLinking">
                                <span x-show="!samsaraLinking">Link to Samsara</span>
                                <span x-show="samsaraLinking">Linking…</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </template>

                    <template x-if="samsaraError">
                        <div class="toast toast-error" style="position:relative;margin-top:12px;animation:none;">
                            <span class="toast-icon">!</span>
                            <div class="toast-body">
                                <div class="toast-message" x-text="samsaraError"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <!-- ── MAPPED STATE ──────────────────────────────────── -->
        <template x-if="unit?.samsara_vehicle_id">
            <div>
                <!-- Top bar with vehicle name + Sync / Unlink actions -->
                <!-- SAMSARA-2: entity_type pill makes it obvious whether
                     this unit is wired to /fleet/vehicles or /fleet/trailers,
                     since trailers won't show battery / power / fuel data. -->
                <div class="card spec-card" style="margin-bottom:16px;">
                    <div class="card-body" style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div>
                            <div style="font-weight:600;color:var(--text-primary);font-size:0.95rem;">
                                <span x-show="unit?.samsara_entity_type === 'trailer'"
                                      style="display:inline-block;padding:2px 8px;margin-right:6px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Trailer</span>
                                <span x-show="!unit?.samsara_entity_type || unit?.samsara_entity_type === 'vehicle'"
                                      style="display:inline-block;padding:2px 8px;margin-right:6px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:10px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Vehicle</span>
                                Linked to <span x-text="unit?.samsara_vehicle_name || unit?.samsara_vehicle_id" class="font-mono"></span>
                            </div>
                            <div class="text-secondary text-sm" style="margin-top:2px;">
                                <span x-show="unit?.samsara_last_synced_at">
                                    Last synced <span x-text="formatRelative(unit?.samsara_last_synced_at)"></span>
                                </span>
                                <span x-show="!unit?.samsara_last_synced_at">Never synced</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-primary btn-sm"
                                    @click="syncSamsaraNow()"
                                    :disabled="samsaraSyncing">
                                <span x-show="!samsaraSyncing">Sync Now</span>
                                <span x-show="samsaraSyncing">Syncing…</span>
                            </button>
                            <?php if (can('equipment', 'edit')): ?>
                            <button class="btn btn-danger btn-sm"
                                    @click="unlinkSamsaraVehicle()"
                                    :disabled="samsaraLinking">
                                Unlink
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- No GPS yet (linked but device unreachable) -->
                <template x-if="!unit?.samsara_last_location_lat">
                    <div class="toast toast-warning" style="position:relative;margin-bottom:16px;animation:none;">
                        <span class="toast-icon">!</span>
                        <div class="toast-body">
                            <div class="toast-message">
                                No GPS fix yet. The gateway may be powered off or
                                still initialising. Click <strong>Sync Now</strong>
                                to retry.
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Live data: map + telemetry side-by-side -->
                <div class="equip-tracking-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <!-- Mini Map -->
                    <div class="card spec-card" style="margin-bottom:0;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Current Location</div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div id="unit-tracking-map" style="height:320px;background:var(--bg-page);">
                                <template x-if="!unit?.samsara_last_location_lat">
                                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-muted);font-size:0.875rem;">
                                        No location data available
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Live Telemetry -->
                    <div class="card spec-card" style="margin-bottom:0;">
                        <div class="card-header" style="padding:12px 16px;">
                            <div class="card-title">Live Telemetry</div>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <table class="spec-table">
                                <tr>
                                    <td class="spec-label">Address</td>
                                    <td x-text="unit?.samsara_last_location_address || '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Latitude</td>
                                    <td class="font-mono" x-text="unit?.samsara_last_location_lat ? Number(unit.samsara_last_location_lat).toFixed(6) : '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Longitude</td>
                                    <td class="font-mono" x-text="unit?.samsara_last_location_lng ? Number(unit.samsara_last_location_lng).toFixed(6) : '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Speed</td>
                                    <td class="font-mono" x-text="unit?.samsara_last_speed_kph !== null && unit?.samsara_last_speed_kph !== undefined ? Number(unit.samsara_last_speed_kph).toFixed(1) + ' km/h' : '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Odometer</td>
                                    <td class="font-mono" x-text="unit?.samsara_odometer_km ? Number(unit.samsara_odometer_km).toLocaleString() + ' km' : '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Battery</td>
                                    <td class="font-mono">
                                        <span x-show="unit?.samsara_battery_pct !== null && unit?.samsara_battery_pct !== undefined">
                                            <span x-text="unit?.samsara_battery_pct + '%'"></span>
                                            <span x-show="unit?.samsara_battery_charging == 1" class="text-success" style="margin-left:4px;">⚡ Charging</span>
                                        </span>
                                        <span x-show="unit?.samsara_battery_pct === null || unit?.samsara_battery_pct === undefined">—</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Power Source</td>
                                    <td x-text="unit?.samsara_power_source || '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Check-in Mode</td>
                                    <td x-text="unit?.samsara_check_in_mode || '—'"></td>
                                </tr>
                                <tr>
                                    <td class="spec-label">Last Connected</td>
                                    <td x-text="unit?.samsara_last_connected_at ? formatRelative(unit.samsara_last_connected_at) : '—'"></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Static identifiers (snapshot at link time) -->
                <div class="card spec-card" style="margin-top:16px;margin-bottom:0;">
                    <div class="card-header" style="padding:12px 16px;">
                        <div class="card-title">Samsara Identifiers</div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="spec-table">
                            <tr>
                                <td class="spec-label">Vehicle ID</td>
                                <td class="font-mono" x-text="unit?.samsara_vehicle_id || '—'"></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Vehicle Name</td>
                                <td x-text="unit?.samsara_vehicle_name || '—'"></td>
                            </tr>
                            <tr>
                                <td class="spec-label">VIN</td>
                                <td class="font-mono" x-text="unit?.samsara_vin || '—'"></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Serial Number</td>
                                <td class="font-mono" x-text="unit?.samsara_serial_number || '—'"></td>
                            </tr>
                            <tr>
                                <td class="spec-label">Gateway ID</td>
                                <td class="font-mono" x-text="unit?.samsara_gateway_id || '—'"></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Actions bar -->
                <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="<?= base_url('tracking') ?>" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px;height:14px;margin-right:4px;vertical-align:-2px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z"/>
                        </svg>
                        View on Fleet Map
                    </a>
                    <!-- SAMSARA-2: pick the right URL segment so trailers
                         deep-link to /o/trailers/ rather than 404'ing on
                         /o/vehicles/. -->
                    <a :href="'https://cloud.samsara.com/o/' + (unit?.samsara_entity_type === 'trailer' ? 'trailers' : 'vehicles') + '/' + encodeURIComponent(unit?.samsara_vehicle_id || '')"
                       target="_blank"
                       rel="noopener"
                       class="btn btn-ghost btn-sm">
                        Open in Samsara Dashboard →
                    </a>
                </div>

                <template x-if="samsaraError">
                    <div class="toast toast-error" style="position:relative;margin-top:12px;animation:none;">
                        <span class="toast-icon">!</span>
                        <div class="toast-body">
                            <div class="toast-message" x-text="samsaraError"></div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    <!-- ── TAB: ACTIVITY ─────────────────────────────────────────── -->
    <div x-show="activeTab === 'activity'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
        <div class="card">
            <div class="card-body">
                <?php $activityEntityType = 'equipment_unit'; $activityEntityId = $unitId; ?>
                <?php require_once FF_ROOT . '/includes/partials/activity-log.php'; ?>
            </div>
        </div>
    </div><!-- /activity tab -->

</div><!-- /x-data -->

<!-- WHY: Leaflet CSS/JS loaded here (after content) for unit GPS tracking tab.
     Self-hosted via S-PROD-3 2026-05-14 (was unpkg.com CDN). Pinned v1.9.4.
     Loaded unconditionally because the tab may be opened at any time via direct URL param. -->
<link rel="stylesheet" href="<?= asset_url('assets/vendor/leaflet/leaflet.css') ?>">
<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>

<style>
/* Unit spec cards — elevated card style for visual separation from page bg */
.spec-card {
    border: 1px solid var(--border-color-strong);
    box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.03);
}
.spec-card .card-header {
    background: var(--color-primary);
    border-bottom: none;
}
.spec-card .card-header .card-title {
    color: #fff;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 600;
}
/* Key-value table — alternating rows for readability */
.spec-table { width:100%; border-collapse:collapse; }
.spec-table tr { border-bottom:1px solid var(--border-color); }
.spec-table tr:last-child { border-bottom:none; }
.spec-table tr:nth-child(even) { background:var(--bg-surface-2); }
.spec-table td { padding:11px 16px; font-size:0.875rem; vertical-align:baseline; }
.spec-table .spec-label {
    width:140px;
    color:var(--text-tertiary);
    font-size:0.8125rem;
    font-weight:500;
    white-space:nowrap;
}
.spec-table .spec-value { color:var(--text-primary); font-weight:500; }

/* SAMSARA-1: Pulsing "Live" badge for the hero — green dot + pill.
   Only rendered server-side when samsara_vehicle_id is set so it
   accurately reflects whether the unit is currently linked. */
.ff-live-badge {
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-left:0.5rem;
    padding:3px 8px 3px 7px;
    background:rgba(34, 197, 94, 0.12);
    color:#16a34a;
    border-radius:999px;
    font-size:0.7rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.05em;
    vertical-align:middle;
    line-height:1;
}
.ff-live-dot {
    width:8px;
    height:8px;
    border-radius:50%;
    background:#22c55e;
    box-shadow:0 0 0 0 rgba(34,197,94,0.7);
    animation:ff-live-pulse 2s infinite;
}
@keyframes ff-live-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,0.7); }
    70%  { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}

/* ───────────────────────────────────────────────────────────────
   S-EQUIPMENT-SHOW-RESPONSIVE — page-scoped responsive layer
   Sits ON TOP of RESPONSIVE-1 (app.css). Targets only patterns
   unique to this page: hardcoded inline grid widths in section
   wrappers, the Samsara live-tracking 4-col block, the manual-
   override row, the tracking-tab map+telemetry split, and the
   .tab-table-container horizontal-scroll fallback. The KPI
   stat-grids (Payoff Analysis 4-col, Projection Scenarios 3-col)
   use RESPONSIVE-1's .stat-grid--4 / .stat-grid--3 classes via
   the markup edit, so they're handled globally — no rule needed
   here for those.
   ─────────────────────────────────────────────────────────────── */

/* Tablet (≤768px) — collapse Tracking-tab map+telemetry split */
@media (max-width: 768px) {
    /* WHY: !important needed because the matching grid-template-columns
       is on an inline style attribute (specificity 1,0,0,0). */
    .equip-tracking-grid {
        grid-template-columns: 1fr !important;
    }
}

/* Mobile (≤540px) — collapse all section grids + Samsara 4-col +
   override row + tab-table horizontal-scroll fallback. */
@media (max-width: 540px) {
    .equip-section-grid,
    .equip-samsara-live-grid,
    .equip-override-row {
        grid-template-columns: 1fr !important;
    }

    /* Samsara live-tracking cells use right borders to separate columns
       horizontally; once stacked vertically those borders look broken.
       Swap to bottom borders, drop on the last cell. */
    .equip-samsara-live-grid > div {
        border-right: none !important;
        border-bottom: 1px solid var(--border-color);
    }
    .equip-samsara-live-grid > div:last-child {
        border-bottom: none;
    }

    /* .tab-table-container ships in app.css with overflow-y:auto only;
       on narrow viewports the inner <table class="table"> overflows
       horizontally with no scroll. Add overflow-x as a page-scoped
       fallback. (Same pattern likely needed on customers/show +
       vendors/show — flag for follow-up if those sessions happen.) */
    .tab-table-container {
        overflow-x: auto;
    }
}
</style>

<script>
function FF_UnitDetail() {
    return {
        unit:                null,
        statusLog:           [],
        loading:             true,
        loadingLog:          false,
        activeTab:           'overview',

        // ── Lease History ─────────────────────────────────────────
        leaseHistory:        [],
        leasesTotal:         0,
        leasesPage:          1,
        leasesLoading:       false,
        leasesLoaded:        false,
        leasesFilters:       { status: '', sort: 'start_date', dir: 'DESC' },

        // ── Damage Claims ─────────────────────────────────────────
        damageClaims:        [],
        damageClaimsTotal:   0,
        damageClaimsPage:    1,
        damageClaimsLoading: false,
        damageClaimsLoaded:  false,
        damageClaimsFilters: { severity: '', status: '', sort: 'created_at', dir: 'DESC' },

        // ── Mileage Logs ──────────────────────────────────────────
        mileageLogs:         [],
        mileageLogsTotal:    0,
        mileageLogsPage:     1,
        mileageLogsLoading:  false,
        mileageLogsLoaded:   false,
        mileageLogsFilters:  { log_type: '', sort: 'log_date', dir: 'DESC' },

        // ── Work Orders ───────────────────────────────────────────
        workOrders:          [],
        workOrdersTotal:     0,
        workOrdersPage:      1,
        workOrdersLoading:   false,
        workOrdersLoaded:    false,
        workOrdersFilters:   { status: '', work_type: '', sort: 'requested_date', dir: 'DESC' },

        // ── Inspections ───────────────────────────────────────────
        inspections:         [],
        inspectionsTotal:    0,
        inspectionsPage:     1,
        inspectionsLoading:  false,
        inspectionsLoaded:   false,
        inspectionsFilters:  { inspection_type: '', status: '', sort: 'inspection_date', dir: 'DESC' },

        // ── Documents ─────────────────────────────────────────────
        documents:    [],
        docsLoading:  false,
        docsLoaded:   false,
        // S-DOC-UPLOAD-ENTITY-TYPE-FIX: entity_type + entity_id live in modal
        // state and flow into FormData from state. openUploadModal() receives
        // them from the caller — never hardcoded inside submit.
        uploadModal:  { open: false, entity_type: '', entity_id: '',
                        docType: '', docLabel: '', file: null,
                        expiryDate: '', uploading: false, error: '' },

        // ── Samsara Mapping (SAMSARA-1) ───────────────────────────
        // Tab data is driven by `unit.samsara_*` columns. We only
        // hit the API for: vehicle list (when unmapped), link, unlink,
        // and Sync Now. The mapped-state telemetry comes back to us
        // by re-reading the unit row after each write.
        samsaraVehicles:           [],   // dropdown source from /api/v1/samsara/vehicles
        samsaraVehiclesLoading:    false,
        samsaraVehiclesLoaded:     false,
        samsaraSelectedVehicleId:  '',
        samsaraLinking:            false,
        samsaraSyncing:            false,
        samsaraError:              '',
        trackingMap:               null, // Leaflet instance for the live map

        // ── Distance Travelled (S-UNIT-DISTANCE-SECTION) ──────────
        distUnit:            'km',
        distStart:           '',
        distEnd:             '',
        distLoading:         false,
        distResult:          null,   // full period_distance API response
        distEditValue:       '',     // editable copy of distance before save
        distEditedManually:  false,  // true when user changed distEditValue
        distSaveLabel:       '',
        distSaving:          false,
        distSaveError:       '',
        distLogs:            [],     // saved equipment_distance_logs rows
        distLogsLoaded:      false,

        // ── Payoff Analysis (PAYOFF-1) ────────────────────────────
        // WHY: Driven off the server-side $linkedAssetId lookup — when
        // no asset is linked we never hit the API at all (the PHP
        // branch renders an empty state card instead). When an asset
        // IS linked, we lazy-load on first visit to the Payoff tab.
        payoff:               null,
        payoffLoading:        false,
        payoffLoaded:         false,
        payoffError:          '',
        payoffPeriod:         6,
        payoffCustomMonthly:  '',
        payoffCustomExtra:    '',
        payoffChart:          null,
        linkedAssetId:        <?= (int) $linkedAssetId ?>,

        async init() {
            await this.loadUnit();
        },

        async loadUnit() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/show') ?>?id=<?= $unitId ?>');
                if (r.success) this.unit = r.data;
            } catch(e) { /* page already rendered server-side */ }
            this.loading = false;

            // ── Tab persistence (FF_TabHash) ─────────────────────────────────
            // Shared helper: trigger lazy-loads for a tab on first visit.
            // Called both from $watch (user click) and directly after hash-
            // restore so data loads even when the tab is set without a click.
            const _onTabEnter = (tab) => {
                if (tab === 'status_log'    && !this.statusLog.length)       this.loadStatusLog();
                if (tab === 'leases'        && !this.leasesLoaded)           this.loadLeaseHistory();
                if (tab === 'damage_claims' && !this.damageClaimsLoaded)     this.loadDamageClaims();
                if (tab === 'mileage_logs'  && !this.mileageLogsLoaded)      this.loadMileageLogs();
                if (tab === 'maintenance'   && !this.workOrdersLoaded)       this.loadWorkOrders();
                if (tab === 'inspections'   && !this.inspectionsLoaded)      this.loadInspections();
                if (tab === 'documents'     && !this.docsLoaded)             this.loadDocuments();
                // Overview: load saved distance logs (card lives here now).
                if (tab === 'overview' && this.unit?.samsara_vehicle_id && !this.distLogsLoaded) this.loadDistanceLogs();
                // SAMSARA-1: open Samsara tab — load picker if unmapped,
                // mount the live map if mapped.
                if (tab === 'tracking') this.openSamsaraTab();
                // PAYOFF-1: only call the API when an asset is actually
                // linked — otherwise the PHP empty state handles it.
                if (tab === 'payoff' && this.linkedAssetId && !this.payoffLoaded) this.loadPayoff();
                // When re-entering the payoff tab, re-render the chart
                // because ApexCharts won't repaint while display:none.
                if (tab === 'payoff' && this.payoff) {
                    this.$nextTick(() => this.renderPayoffChart());
                }
            };

            // Valid tab keys — must match every x-show="activeTab === '...'" above.
            const _tabs = ['overview','payoff','compliance','leases','damage_claims',
                           'mileage_logs','status_log','maintenance','inspections',
                           'documents','tracking','activity'];

            // Restore tab from URL hash BEFORE registering $watch so the
            // initial assignment does not trigger the watcher.
            const _initTab = FF_TabHash.init(_tabs, 'overview');
            this.activeTab = _initTab;
            FF_TabHash.write(_initTab);
            _onTabEnter(_initTab);  // lazy-load data for the restored tab

            // Save scroll on unload; restore position after Alpine renders.
            FF_TabHash.watchUnload(() => this.activeTab);
            this.$nextTick(() => FF_TabHash.restoreScroll(_initTab));

            // Track previous tab so onSwitch can save its scroll.
            let _prevTab = _initTab;
            this.$watch('activeTab', (tab) => {
                FF_TabHash.onSwitch(_prevTab, tab);
                _prevTab = tab;
                _onTabEnter(tab);
            });
        },

        async loadStatusLog() {
            this.loadingLog = true;
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment/units/status-log') ?>?unit_id=<?= $unitId ?>');
                if (r.success) this.statusLog = r.data.items ?? r.data;
            } catch(e) { /* non-fatal */ }
            this.loadingLog = false;
        },

        // ── Lease History ─────────────────────────────────────────
        async loadLeaseHistory(append = false) {
            this.leasesLoading = true;
            try {
                const p = new URLSearchParams({ unit_id: <?= $unitId ?>, per_page: 50, page: this.leasesPage, sort: this.leasesFilters.sort, dir: this.leasesFilters.dir });
                if (this.leasesFilters.status) p.set('status', this.leasesFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/leases') ?>?' + p);
                if (r.success) {
                    const items      = r.data.items || [];
                    this.leaseHistory = append ? [...this.leaseHistory, ...items] : items;
                    this.leasesTotal  = r.data.pagination?.total ?? items.length;
                    this.leasesLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.leasesLoading = false;
        },
        loadMoreLeaseHistory()  { this.leasesPage++; this.loadLeaseHistory(true); },
        applyLeasesFilters()    { this.leaseHistory = []; this.leasesPage = 1; this.leasesTotal = 0; this.leasesLoaded = false; this.loadLeaseHistory(); },

        // ── Damage Claims ─────────────────────────────────────────
        async loadDamageClaims(append = false) {
            this.damageClaimsLoading = true;
            try {
                const p = new URLSearchParams({ equipment_unit_id: <?= $unitId ?>, per_page: 50, page: this.damageClaimsPage, sort: this.damageClaimsFilters.sort, dir: this.damageClaimsFilters.dir });
                if (this.damageClaimsFilters.severity) p.set('severity', this.damageClaimsFilters.severity);
                if (this.damageClaimsFilters.status)   p.set('status',   this.damageClaimsFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/damage_claims') ?>?' + p);
                if (r.success) {
                    const items          = r.data?.items ?? [];
                    this.damageClaims    = append ? [...this.damageClaims, ...items] : items;
                    this.damageClaimsTotal  = r.data.pagination?.total ?? items.length;
                    this.damageClaimsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.damageClaimsLoading = false;
        },
        loadMoreDamageClaims()     { this.damageClaimsPage++; this.loadDamageClaims(true); },
        applyDamageClaimsFilters() { this.damageClaims = []; this.damageClaimsPage = 1; this.damageClaimsTotal = 0; this.damageClaimsLoaded = false; this.loadDamageClaims(); },

        // ── Mileage Logs ──────────────────────────────────────────
        async loadMileageLogs(append = false) {
            this.mileageLogsLoading = true;
            try {
                const p = new URLSearchParams({ equipment_unit_id: <?= $unitId ?>, per_page: 50, page: this.mileageLogsPage, sort: this.mileageLogsFilters.sort, dir: this.mileageLogsFilters.dir });
                if (this.mileageLogsFilters.log_type) p.set('log_type', this.mileageLogsFilters.log_type);
                const r = await FF_Api.get('<?= base_url('api/v1/mileage_logs/index') ?>?' + p);
                if (r.success) {
                    const items          = r.data?.items ?? [];
                    this.mileageLogs     = append ? [...this.mileageLogs, ...items] : items;
                    this.mileageLogsTotal  = r.data.pagination?.total ?? items.length;
                    this.mileageLogsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.mileageLogsLoading = false;
        },
        loadMoreMileageLogs()     { this.mileageLogsPage++; this.loadMileageLogs(true); },
        applyMileageLogsFilters() { this.mileageLogs = []; this.mileageLogsPage = 1; this.mileageLogsTotal = 0; this.mileageLogsLoaded = false; this.loadMileageLogs(); },

        // ── Work Orders ───────────────────────────────────────────
        async loadWorkOrders(append = false) {
            this.workOrdersLoading = true;
            try {
                const p = new URLSearchParams({ equipment_unit_id: <?= $unitId ?>, per_page: 50, page: this.workOrdersPage, sort: this.workOrdersFilters.sort, dir: this.workOrdersFilters.dir });
                if (this.workOrdersFilters.status)    p.set('status',    this.workOrdersFilters.status);
                if (this.workOrdersFilters.work_type) p.set('work_type', this.workOrdersFilters.work_type);
                const r = await FF_Api.get('<?= base_url('api/v1/maintenance_work_orders/index.php') ?>?' + p);
                if (r.success) {
                    const items       = r.data?.items ?? [];
                    this.workOrders   = append ? [...this.workOrders, ...items] : items;
                    this.workOrdersTotal  = r.data.pagination?.total ?? items.length;
                    this.workOrdersLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.workOrdersLoading = false;
        },
        loadMoreWorkOrders()     { this.workOrdersPage++; this.loadWorkOrders(true); },
        applyWorkOrdersFilters() { this.workOrders = []; this.workOrdersPage = 1; this.workOrdersTotal = 0; this.workOrdersLoaded = false; this.loadWorkOrders(); },

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

        // ── Inspections ───────────────────────────────────────────
        async loadInspections(append = false) {
            this.inspectionsLoading = true;
            try {
                const p = new URLSearchParams({ equipment_unit_id: <?= $unitId ?>, per_page: 50, page: this.inspectionsPage, sort: this.inspectionsFilters.sort, dir: this.inspectionsFilters.dir });
                if (this.inspectionsFilters.inspection_type) p.set('inspection_type', this.inspectionsFilters.inspection_type);
                if (this.inspectionsFilters.status)          p.set('status',          this.inspectionsFilters.status);
                const r = await FF_Api.get('<?= base_url('api/v1/inspections/index.php') ?>?' + p);
                if (r.success) {
                    const items       = r.data?.items ?? [];
                    this.inspections  = append ? [...this.inspections, ...items] : items;
                    this.inspectionsTotal  = r.data.pagination?.total ?? items.length;
                    this.inspectionsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.inspectionsLoading = false;
        },
        loadMoreInspections()     { this.inspectionsPage++; this.loadInspections(true); },
        applyInspectionsFilters() { this.inspections = []; this.inspectionsPage = 1; this.inspectionsTotal = 0; this.inspectionsLoaded = false; this.loadInspections(); },

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

        // Human day-delta, mirrors the PHP complianceDelta(): future -> "expires in
        // N days", past -> "overdue N days", 0 -> "expires today". (S-CVI-OVERDUE-SIGN-FIX)
        complianceDelta(days) {
            if (days === null) return '—';
            if (days === 0)    return 'expires today';
            const n = Math.abs(days);
            const unit = n === 1 ? 'day' : 'days';
            return days < 0 ? `overdue ${n} ${unit}` : `expires in ${n} ${unit}`;
        },

        // ── Documents tab methods ─────────────────────────────────
        async loadDocuments() {
            this.docsLoading = true;
            try {
                const r = await FF_Api.get(
                    '<?= base_url('api/v1/documents') ?>?entity_type=equipment_unit&entity_id=<?= $unitId ?>'
                );
                if (r.success) {
                    this.documents = r.data.items || [];
                    this.docsLoaded = true;
                }
            } catch(e) { /* non-fatal */ }
            this.docsLoading = false;
        },

        // Return the most recent document for a given type, or null.
        getDoc(docType) {
            return this.documents.find(d => d.document_type === docType) || null;
        },

        // S-DOC-UPLOAD-ENTITY-TYPE-FIX: caller MUST pass entity_type+entity_id.
        // If either is missing, the modal opens with an inline error and the
        // submit button is disabled — we never silently default or guess a type.
        openUploadModal(docType, docLabel, entityType, entityId) {
            const hasCtx = !!entityType && !!entityId;
            this.uploadModal = {
                open: true, docType, docLabel,
                entity_type: entityType || '',
                entity_id:   entityId   || '',
                file: null, expiryDate: '', uploading: false,
                error: hasCtx ? '' : 'Cannot upload — missing context.',
            };
        },

        async submitUpload() {
            // S-DOC-UPLOAD-ENTITY-TYPE-FIX: refuse to submit if context missing.
            if (!this.uploadModal.entity_type || !this.uploadModal.entity_id) {
                this.uploadModal.error = 'Cannot upload — missing context.';
                return;
            }
            if (!this.uploadModal.file) return;
            if (!this.uploadModal.docType) {
                this.uploadModal.error = 'Please select a document type.';
                return;
            }
            this.uploadModal.uploading = true;
            this.uploadModal.error = '';
            try {
                const fd = new FormData();
                fd.append('entity_type',   this.uploadModal.entity_type);
                fd.append('entity_id',     this.uploadModal.entity_id);
                fd.append('document_type', this.uploadModal.docType);
                if (this.uploadModal.expiryDate) {
                    fd.append('expiration_date', this.uploadModal.expiryDate);
                }
                fd.append('document', this.uploadModal.file);

                // WHY: FF_Api.upload() handles multipart FormData — sends no
                // Content-Type so the browser sets the correct boundary,
                // and attaches X-CSRF-Token automatically.
                const r = await FF_Api.upload('<?= base_url('api/v1/documents/upload') ?>', fd);
                if (r.success) {
                    // Replace existing or push new — keeps the list in sync without reload.
                    const idx = this.documents.findIndex(
                        d => d.document_type === this.uploadModal.docType
                    );
                    if (idx >= 0) this.documents[idx] = r.data;
                    else          this.documents.push(r.data);
                    this.uploadModal.open = false;
                } else {
                    // WHY: API error envelope is { error: { message } } not top-level message.
                    this.uploadModal.error = r.error?.message || 'Upload failed. Please try again.';
                }
            } catch(e) {
                this.uploadModal.error = 'Network error. Please try again.';
            }
            this.uploadModal.uploading = false;
        },

        async confirmDeleteDoc(id) {
            if (!(await FF_Confirm.ask('Remove this document? The file will be unlinked from this unit.'))) return;
            const r = await FF_Api.post('<?= base_url('api/v1/documents/delete') ?>', { id });
            if (r.success) {
                this.documents = this.documents.filter(d => d.id !== id);
            } else {
                FF_Toast.error(r.message || 'Could not remove document.');
            }
        },

        // ── Samsara Mapping tab methods (SAMSARA-1) ──────────────

        // Tab dispatcher — picks the right initializer based on
        // whether the unit is currently linked to a Samsara vehicle.
        openSamsaraTab() {
            if (this.unit?.samsara_vehicle_id) {
                // Mapped state — render the live map (must defer one
                // tick so Leaflet sees the now-visible #unit-tracking-map div).
                this.$nextTick(() => this.initSamsaraMap());
            } else {
                // Unmapped — fetch the vehicle dropdown source if we
                // haven't already, so the link form is ready to use.
                if (!this.samsaraVehiclesLoaded) this.loadSamsaraVehicles();
            }
        },

        // GET /api/v1/samsara/vehicles — populates the picker dropdown.
        async loadSamsaraVehicles() {
            this.samsaraVehiclesLoading = true;
            this.samsaraError = '';
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/samsara/vehicles') ?>');
                if (r.success) {
                    this.samsaraVehicles  = r.data?.vehicles || [];
                    this.samsaraVehiclesLoaded = true;
                } else {
                    this.samsaraError = r.error?.message || 'Failed to load Samsara vehicles.';
                }
            } catch (e) {
                this.samsaraError = 'Network error loading Samsara vehicles.';
            }
            this.samsaraVehiclesLoading = false;
        },

        // POST /api/v1/samsara/link — link this unit to selected trackable.
        // SAMSARA-2: looks up the chosen entry's entity_type from the local
        // dropdown source so the backend hits the right API path
        // (/fleet/vehicles/stats vs /fleet/trailers/stats). Defaults to
        // 'vehicle' if the field isn't present (paranoid fallback for
        // partial deploys where the picker hasn't been redeployed yet).
        async linkSamsaraVehicle() {
            if (!this.samsaraSelectedVehicleId) return;
            this.samsaraLinking = true;
            this.samsaraError = '';

            const picked = this.samsaraVehicles.find(v => v.id === this.samsaraSelectedVehicleId);
            const entityType = (picked && picked.entity_type) ? picked.entity_type : 'vehicle';

            try {
                const r = await FF_Api.post('<?= base_url('api/v1/samsara/link') ?>', {
                    equipment_unit_id:    <?= $unitId ?>,
                    samsara_vehicle_id:   this.samsaraSelectedVehicleId,
                    samsara_entity_type:  entityType
                });
                if (r.success) {
                    // Re-load the unit so every samsara_* column on the
                    // page (hero badge, overview section, mapped tab)
                    // updates from one canonical source.
                    await this.loadUnit();
                    this.samsaraSelectedVehicleId = '';
                    this.$nextTick(() => this.initSamsaraMap());
                } else {
                    this.samsaraError = r.error?.message || 'Failed to link to Samsara.';
                }
            } catch (e) {
                this.samsaraError = 'Network error linking to Samsara.';
            }
            this.samsaraLinking = false;
        },

        // POST /api/v1/samsara/unlink — clear the mapping.
        async unlinkSamsaraVehicle() {
            if (!(await FF_Confirm.ask('Unlink this unit from Samsara? Live data will stop syncing.'))) return;
            this.samsaraLinking = true;
            this.samsaraError = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/samsara/unlink') ?>', {
                    equipment_unit_id: <?= $unitId ?>
                });
                if (r.success) {
                    if (this.trackingMap) {
                        this.trackingMap.remove();
                        this.trackingMap = null;
                    }
                    await this.loadUnit();
                    // Refresh the picker so the just-unlinked vehicle is
                    // available to other units immediately.
                    this.samsaraVehiclesLoaded = false;
                    this.loadSamsaraVehicles();
                } else {
                    this.samsaraError = r.error?.message || 'Failed to unlink vehicle.';
                }
            } catch (e) {
                this.samsaraError = 'Network error unlinking vehicle.';
            }
            this.samsaraLinking = false;
        },

        // POST /api/v1/samsara/sync — refresh live telemetry on demand.
        async syncSamsaraNow() {
            this.samsaraSyncing = true;
            this.samsaraError = '';
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/samsara/sync') ?>', {
                    equipment_unit_id: <?= $unitId ?>
                });
                if (r.success) {
                    await this.loadUnit();
                    this.$nextTick(() => this.initSamsaraMap());
                } else {
                    this.samsaraError = r.error?.message || 'Sync failed.';
                }
            } catch (e) {
                this.samsaraError = 'Network error during sync.';
            }
            this.samsaraSyncing = false;
        },

        // Mount/refresh the Leaflet map showing the unit's last position.
        // Pulls lat/lng directly from unit.samsara_last_location_* columns.
        initSamsaraMap() {
            if (!this.unit?.samsara_last_location_lat || typeof L === 'undefined') return;
            const el = document.getElementById('unit-tracking-map');
            if (!el) return;

            const lat = Number(this.unit.samsara_last_location_lat);
            const lng = Number(this.unit.samsara_last_location_lng);
            if (!lat || !lng) return;

            // WHY: Re-mounting Leaflet on the same div throws
            // "already initialized"; remove the prior instance first.
            if (this.trackingMap) {
                this.trackingMap.remove();
                this.trackingMap = null;
            }

            this.trackingMap = L.map('unit-tracking-map').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.trackingMap);

            const unitNum = this.unit?.unit_number || '';
            const addr    = this.unit?.samsara_last_location_address || 'No address available';
            const speed   = this.unit?.samsara_last_speed_kph;
            let popupHtml = `<div style="font-family:DM Sans,sans-serif;"><strong>${unitNum}</strong><br><span style="font-size:12px;">${addr}</span>`;
            if (speed !== null && speed !== undefined) {
                popupHtml += `<br><span style="font-size:11px;">Speed: ${Number(speed).toFixed(1)} km/h</span>`;
            }
            popupHtml += '</div>';

            L.marker([lat, lng])
                .addTo(this.trackingMap)
                .bindPopup(popupHtml)
                .openPopup();
        },

        // ── Distance Travelled helpers (S-UNIT-DISTANCE-SECTION) ──

        // Fill distStart / distEnd from a named preset.
        distPreset(key) {
            const now = new Date();
            const pad  = n => String(n).padStart(2,'0');
            const toLocal = d => d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
                                 + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            if (key === '7d') {
                const s = new Date(now); s.setDate(s.getDate() - 7); s.setHours(0,0,0,0);
                const e = new Date(now); e.setHours(23,59,0,0);
                this.distStart = toLocal(s); this.distEnd = toLocal(e);
            } else if (key === '30d') {
                const s = new Date(now); s.setDate(s.getDate() - 30); s.setHours(0,0,0,0);
                const e = new Date(now); e.setHours(23,59,0,0);
                this.distStart = toLocal(s); this.distEnd = toLocal(e);
            } else if (key === 'month') {
                const s = new Date(now.getFullYear(), now.getMonth(), 1);
                const e = new Date(now); e.setHours(23,59,0,0);
                this.distStart = toLocal(s); this.distEnd = toLocal(e);
            }
        },

        // GET /api/v1/samsara/period_distance — fetch distance for the window.
        async calcDistance() {
            if (!this.distStart || !this.distEnd) return;
            this.distLoading = true;
            this.distResult  = null;
            this.distEditValue      = '';
            this.distEditedManually = false;
            this.distSaveError      = '';
            try {
                const params = new URLSearchParams({
                    equipment_unit_id: <?= $unitId ?>,
                    period_start:      this.distStart,
                    period_end:        this.distEnd,
                    unit:              this.distUnit,
                });
                const r = await FF_Api.get('<?= base_url('api/v1/samsara/period_distance') ?>?' + params);
                if (r.success) {
                    this.distResult    = r.data;
                    this.distEditValue = r.data.distance ?? '';
                } else {
                    FF_Toast.error(r.error?.message || 'Failed to fetch distance.');
                }
            } catch (e) {
                FF_Toast.error('Network error fetching distance.');
            }
            this.distLoading = false;
        },

        // Format an ISO timestamp to a compact local string for the reading window line.
        distFormatTs(val) {
            if (!val) return '—';
            const d = new Date(val);
            if (isNaN(d.getTime())) return val;
            return d.toLocaleString(undefined, { month:'short', day:'numeric',
                                                  hour:'2-digit', minute:'2-digit', timeZoneName:'short' });
        },

        // Format a datetime to a short date for the saved-logs table.
        distFormatDate(val) {
            if (!val) return '—';
            const d = new Date(val);
            if (isNaN(d.getTime())) return val;
            return d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
        },

        // POST /api/v1/equipment_units/distance_logs/create — save current result.
        async saveDistanceLog() {
            if (!this.distResult || this.distEditValue === '') return;
            this.distSaving    = true;
            this.distSaveError = '';
            const source = this.distEditedManually ? 'manual' : (this.distResult.source || 'gps');
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment_units/distance_logs/create') ?>', {
                    equipment_unit_id: <?= $unitId ?>,
                    period_start:      this.distStart,
                    period_end:        this.distEnd,
                    distance:          String(this.distEditValue),
                    unit:              this.distUnit,
                    source:            source,
                    reading_count:     this.distResult.reading_count ?? null,
                    warnings:          this.distResult.warnings ?? null,
                    first_reading_at:  this.distResult.first_reading_at ?? null,
                    last_reading_at:   this.distResult.last_reading_at  ?? null,
                    label:             this.distSaveLabel || null,
                    queried_at:        this.distResult.queried_at ?? null,
                });
                if (r.success) {
                    // Prepend to local list so it appears immediately
                    this.distLogs.unshift(r.data);
                    this.distSaveLabel = '';
                    FF_Toast.success('Distance reading saved.');
                } else {
                    this.distSaveError = r.error?.message || 'Save failed.';
                }
            } catch (e) {
                this.distSaveError = 'Network error saving distance log.';
            }
            this.distSaving = false;
        },

        // GET /api/v1/equipment_units/distance_logs/index — load saved logs.
        async loadDistanceLogs() {
            try {
                const r = await FF_Api.get('<?= base_url('api/v1/equipment_units/distance_logs/index') ?>?equipment_unit_id=<?= $unitId ?>');
                if (r.success) {
                    this.distLogs       = r.data?.items ?? [];
                    this.distLogsLoaded = true;
                }
            } catch (e) { /* non-fatal */ }
        },

        // POST /api/v1/equipment_units/distance_logs/delete — hard-delete a log row.
        async deleteDistanceLog(id) {
            if (!(await FF_Confirm.ask('Delete this saved distance reading? This cannot be undone.'))) return;
            try {
                const r = await FF_Api.post('<?= base_url('api/v1/equipment_units/distance_logs/delete') ?>', {
                    id:                id,
                    equipment_unit_id: <?= $unitId ?>,
                });
                if (r.success) {
                    this.distLogs = this.distLogs.filter(l => l.id !== id);
                    FF_Toast.success('Distance reading deleted.');
                } else {
                    FF_Toast.error(r.error?.message || 'Delete failed.');
                }
            } catch (e) {
                FF_Toast.error('Network error deleting distance log.');
            }
        },

        // Compact "5 minutes ago" / "2 hours ago" formatter used by
        // both the mapping tab and the overview Samsara card so the
        // staleness indicator reads consistently.
        formatRelative(value) {
            if (!value) return '';
            const ts = new Date(value).getTime();
            if (isNaN(ts)) return value;
            const diffSec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
            if (diffSec < 60)    return 'just now';
            if (diffSec < 3600)  return Math.floor(diffSec/60) + ' min ago';
            if (diffSec < 86400) return Math.floor(diffSec/3600) + ' hr ago';
            const days = Math.floor(diffSec/86400);
            if (days < 7) return days + ' day' + (days > 1 ? 's' : '') + ' ago';
            return new Date(value).toLocaleDateString();
        },

        statusBadgeClass(status) {
            const map = { available:'badge-success', on_lease:'badge-info', reserved:'badge-purple',
                          maintenance:'badge-warning', inactive:'badge-neutral', decommissioned:'badge-danger',
                          none: 'badge-neutral' };
            return map[status] || 'badge-neutral';
        },

        // ── Payoff Analysis (PAYOFF-1) ─────────────────────────────
        // All methods mirror the ones on the fixed-asset detail modal
        // so the UX is identical between both places.
        async loadPayoff() {
            if (!this.linkedAssetId) return;
            this.payoffLoading = true;
            this.payoffError   = '';
            try {
                const params = new URLSearchParams();
                params.set('asset_id', this.linkedAssetId);
                params.set('period',   String(this.payoffPeriod));
                if (this.payoffCustomMonthly) params.set('custom_monthly_revenue', this.payoffCustomMonthly);
                if (this.payoffCustomExtra)   params.set('extra_costs',            this.payoffCustomExtra);

                const r = await FF_Api.get('<?= base_url('api/v1/accounting/fixed_assets/payoff.php') ?>?' + params.toString());
                if (r.success) {
                    this.payoff       = r.data;
                    this.payoffLoaded = true;
                    // WHY: Chart element is now in the DOM; defer to next tick
                    // so x-show='payoff' has resolved before we attach ApexCharts.
                    this.$nextTick(() => this.renderPayoffChart());
                } else {
                    this.payoffError = r.error?.message || 'Failed to load payoff analysis.';
                }
            } catch (e) {
                this.payoffError = 'Network error while loading payoff analysis.';
            }
            this.payoffLoading = false;
        },

        // Called by the scenario cards (3/6/12 click) — re-fetches so
        // the "selected" projection and scenario highlight update.
        reloadPayoff() {
            this.loadPayoff();
        },

        applyPayoffCustom() {
            this.loadPayoff();
        },

        resetPayoffCustom() {
            this.payoffCustomMonthly = '';
            this.payoffCustomExtra   = '';
            this.loadPayoff();
        },

        // Progress-bar fill style — red <33%, amber 33-65%, green ≥66%.
        payoffBarStyle() {
            const pct = Math.max(0, Math.min(100, parseFloat(this.payoff?.totals?.progress_pct) || 0));
            let colour = 'var(--color-danger)';
            if (pct >= 66)       colour = 'var(--color-success)';
            else if (pct >= 33)  colour = 'var(--color-warning)';
            return 'width:' + pct + '%;height:100%;background:' + colour + ';transition:width 0.4s ease;';
        },

        // Render the ApexCharts area chart. Theme-aware — rebuilt from
        // scratch on every call so theme switches pick up new colours.
        renderPayoffChart() {
            const el = document.getElementById('unit-payoff-chart');
            if (!el || !this.payoff || !this.payoff.monthly_data) return;
            if (typeof ApexCharts === 'undefined') return;

            // Destroy previous instance to prevent duplicate charts.
            if (this.payoffChart) {
                try { this.payoffChart.destroy(); } catch (e) { /* ignore */ }
                this.payoffChart = null;
            }

            const isLight = (window.FF_Theme && FF_Theme.current() === 'light');
            const cs = getComputedStyle(document.documentElement);
            const cssVar = v => (cs.getPropertyValue(v) || '').trim();
            const primary = cssVar('--color-primary') || '#f97316';
            const success = cssVar('--color-success') || '#22c55e';
            const fgMuted = cssVar('--text-tertiary') || '#64748b';
            const border  = cssVar('--border-default') || '#1d2133';

            const categories = this.payoff.monthly_data.map(r => r.month);
            const series = [{
                name: 'Cumulative Net Revenue',
                data: this.payoff.monthly_data.map(r => parseFloat(r.cumulative_net_revenue)),
            }];

            const target = parseFloat(this.payoff.acquisition.adjusted_target) || 0;

            const opts = {
                chart: {
                    type: 'area',
                    height: 300,
                    background: 'transparent',
                    fontFamily: 'DM Sans, sans-serif',
                    toolbar: { show: false },
                    animations: { enabled: true, speed: 400 },
                },
                theme: { mode: isLight ? 'light' : 'dark' },
                colors: [primary],
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: { opacityFrom: 0.35, opacityTo: 0.05 },
                },
                dataLabels: { enabled: false },
                grid: { borderColor: border },
                xaxis: {
                    categories,
                    labels: { style: { colors: fgMuted, fontFamily: 'DM Mono, monospace' } },
                    axisBorder: { color: border },
                    axisTicks:  { color: border },
                },
                yaxis: {
                    labels: {
                        style: { colors: fgMuted, fontFamily: 'DM Mono, monospace' },
                        formatter: v => '$' + (Math.round(v)).toLocaleString('en-CA'),
                    },
                },
                tooltip: {
                    theme: isLight ? 'light' : 'dark',
                    y: { formatter: v => '$' + parseFloat(v).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                },
                series,
                annotations: target > 0 ? {
                    yaxis: [{
                        y: target,
                        borderColor: success,
                        strokeDashArray: 6,
                        label: {
                            borderColor: success,
                            style: { color: '#fff', background: success, fontFamily: 'DM Mono, monospace' },
                            text: 'Target $' + target.toLocaleString('en-CA'),
                            position: 'right',
                        },
                    }],
                } : {},
            };

            try {
                this.payoffChart = new ApexCharts(el, opts);
                this.payoffChart.render();
            } catch (e) {
                console.error('[UnitShow] Payoff chart render failed', e);
            }
        },

        formatMoney(s) {
            if (s === null || s === undefined || s === '') return '—';
            const n = parseFloat(s);
            if (!Number.isFinite(n)) return '—';
            return '$' + n.toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
// [UI-AUDIT-1:M13] async for FF_Confirm.ask().
async function deleteUnit() {
    if (!(await FF_Confirm.ask('Delete this unit? This action cannot be undone.'))) return;
    FF_Api.post('<?= base_url('api/v1/equipment/units/delete') ?>', { id: <?= $unitId ?> })
        .then(r => {
            if (r.success) {
                window.location.href = '<?= base_url('equipment') ?>';
            } else {
                FF_Toast.error(r.error?.message || 'Failed to delete unit.');
            }
        });
}
</script>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
