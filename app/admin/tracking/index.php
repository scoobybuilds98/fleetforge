<?php
declare(strict_types=1);

/**
 * app/admin/tracking/index.php
 *
 * Fleet Tracking — Samsara command center.
 *
 * Three tabs:
 *   1. Map View       — Leaflet + OSM, color-coded pins by status
 *   2. List View      — sortable table of every linked unit
 *   3. Unlinked Units — every unit that is NOT yet mapped to Samsara
 *
 * Plus an Alerts strip above the tabs that surfaces:
 *   • Battery critical (≤10%)
 *   • Battery low (≤25%)
 *   • Not connected for >24 hrs (red)
 *   • Not connected for >8 hrs  (amber)
 *   • No GPS fix at all
 *
 * Data source: GET /api/v1/samsara/fleet — single round-trip that
 * returns linked, unlinked, alerts, and stats from cached samsara_*
 * columns. Live Samsara API is NOT called here — the 5-min cron
 * (cron/samsara_sync.php) keeps the cache fresh.
 *
 * Alert dismissal: a dismissed alert is suppressed for 24 hours
 * via localStorage so on-call doesn't have to keep clearing the
 * same noisy battery alert all day.
 *
 * Permission: equipment:view
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/samsara/fleet.php,
 *          api/v1/samsara/sync.php
 * @session SAMSARA-1
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle      = 'Fleet Tracking';
$helpModuleSlug = 'tracking';
require_once FF_ROOT . '/includes/header.php';

// Check if Samsara API is configured — used for the dev-mode banner.
$samsaraKey    = settings_get('gps.samsara_api_key', '');
$gpsConfigured = ($samsaraKey !== '');
?>

<!-- Leaflet v1.9.4 (pinned, self-hosted via S-PROD-3 2026-05-14) — open-source mapping library, no API key needed -->
<link rel="stylesheet" href="<?= asset_url('assets/vendor/leaflet/leaflet.css') ?>">
<script src="<?= asset_url('assets/vendor/leaflet/leaflet.js') ?>"></script>

<div x-data="FF_FleetTracking()" x-init="init()">

<!-- ── Page header ───────────────────────────────────────────────── -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-header-title">
            Fleet Tracking
            <span class="badge badge-info" style="font-size:0.75rem;vertical-align:middle;margin-left:6px;">
                <span x-text="stats.linked"></span> Linked
            </span>
            <span class="badge badge-neutral" style="font-size:0.75rem;vertical-align:middle;margin-left:4px;">
                <span x-text="stats.unlinked"></span> Unlinked
            </span>
        </h1>
        <p style="margin:4px 0 0;font-size:0.8125rem;color:var(--text-muted);">
            Live Samsara telemetry · synced every 5 minutes
            <span x-show="lastUpdated" x-cloak style="margin-left:8px;">
                · Updated <span x-text="formatTime(lastUpdated)"></span>
            </span>
        </p>
    </div>
    <div class="page-header-actions" style="align-items:center;">
        <?= help_button('tracking') ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" x-model="autoRefresh" style="accent-color:var(--color-primary);">
            Auto-refresh
        </label>
        <button class="btn btn-secondary btn-sm" @click="refresh()" :disabled="loading">
            <span x-show="!loading">Refresh</span>
            <span x-show="loading" x-cloak>Loading…</span>
        </button>
        <?php if (can('equipment', 'edit')): ?>
        <button class="btn btn-secondary btn-sm" @click="importFromSamsara()" :disabled="importing || syncing"
                title="Fetch all vehicles &amp; trailers from Samsara and create any that are missing in FleetForge. Safe to re-run — already-linked units are skipped.">
            <span x-show="!importing">Import from Samsara</span>
            <span x-show="importing" x-cloak>Importing…</span>
        </button>
        <button class="btn btn-primary btn-sm" @click="syncAllNow()" :disabled="syncing || importing">
            <span x-show="!syncing">Sync All Now</span>
            <span x-show="syncing" x-cloak>Syncing…</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$gpsConfigured): ?>
<!-- Dev mode banner -->
<div class="toast toast-warning" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon">!</span>
    <div class="toast-body">
        <div class="toast-title">Samsara API Key Not Configured</div>
        <div class="toast-message">
            Configure it in
            <a href="<?= base_url('settings/integrations') ?>" style="color:inherit;text-decoration:underline;">Settings → Integrations</a>
            to enable live tracking.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Network error banner -->
<template x-if="error">
    <div class="toast toast-error" style="position:relative;margin-bottom:16px;animation:none;">
        <span class="toast-icon">!</span>
        <div class="toast-body"><div class="toast-message" x-text="error"></div></div>
    </div>
</template>

<!-- ── Alerts strip ─────────────────────────────────────────────── -->
<template x-if="visibleAlerts.length > 0">
    <div style="margin-bottom:16px;border-radius:8px;overflow:hidden;border:1px solid var(--border-color-strong);border-left:3px solid var(--color-danger);">
        <!-- Collapsible header -->
        <div @click="alertsExpanded = !alertsExpanded"
             style="display:flex;align-items:center;gap:10px;padding:11px 16px;cursor:pointer;user-select:none;background:var(--bg-surface-2);">
            <svg viewBox="0 0 20 20" fill="currentColor"
                 :style="'width:14px;height:14px;flex-shrink:0;transition:transform .18s;transform:' + (alertsExpanded ? 'rotate(0deg)' : 'rotate(-90deg)')">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
            <span style="font-weight:600;font-size:0.875rem;">Active Alerts</span>
            <span class="badge badge-danger" style="font-size:0.7rem;" x-text="visibleAlerts.length"></span>
            <span style="font-size:0.75rem;color:var(--text-muted);flex:1;" x-text="alertSeveritySummary()"></span>
            <button class="btn btn-xs btn-ghost" style="color:var(--color-danger-text);" @click.stop="dismissAllAlerts()">Dismiss all</button>
        </div>
        <!-- Rows -->
        <div x-show="alertsExpanded">
            <template x-for="alert in visibleAlerts" :key="alert.unit_id + '-' + alert.type">
                <div style="display:flex;align-items:center;gap:12px;padding:9px 16px;border-top:1px solid var(--border-color);">
                    <span :class="alert.severity === 'critical' ? 'badge badge-danger' : 'badge badge-warning'" style="font-size:0.65rem;text-transform:uppercase;white-space:nowrap;" x-text="alert.severity"></span>
                    <a :href="'<?= base_url('equipment/show') ?>?id=' + alert.unit_id" class="font-mono" style="font-weight:600;font-size:0.8125rem;color:var(--text-primary);text-decoration:none;white-space:nowrap;" x-text="alert.unit_number"></a>
                    <span style="font-size:0.8125rem;color:var(--text-secondary);flex:1;" x-text="alert.message"></span>
                    <button class="btn btn-xs btn-ghost" @click="dismissAlert(alert)" title="Dismiss for 24 hours">Dismiss</button>
                </div>
            </template>
        </div>
    </div>
</template>

<!-- ── Tabs ─────────────────────────────────────────────────────── -->
<div class="tab-bar" role="tablist" style="margin-bottom:16px;">
    <button class="tab-btn"
            :class="{ 'is-active': activeTab === 'map' }"
            @click="activeTab = 'map'; $nextTick(() => initMap())"
            role="tab">
        Map View
    </button>
    <button class="tab-btn"
            :class="{ 'is-active': activeTab === 'list' }"
            @click="activeTab = 'list'"
            role="tab">
        List View
        <span class="badge badge-neutral" style="font-size:0.65rem;margin-left:4px;" x-text="stats.linked"></span>
    </button>
    <button class="tab-btn"
            :class="{ 'is-active': activeTab === 'unlinked' }"
            @click="activeTab = 'unlinked'"
            role="tab">
        Unlinked
        <span class="badge badge-neutral" style="font-size:0.65rem;margin-left:4px;" x-text="stats.unlinked"></span>
    </button>
</div>

<!-- ── TAB: Map View ────────────────────────────────────────────── -->
<div x-show="activeTab === 'map'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
    <div style="display:flex;height:calc(100vh - 320px);min-height:480px;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;background:var(--bg-card);">
        <!-- Sidebar list -->
        <div style="width:300px;flex-shrink:0;border-right:1px solid var(--border-color);display:flex;flex-direction:column;background:var(--bg-page);">
            <div style="padding:10px 12px;border-bottom:1px solid var(--border-color);">
                <input type="text" x-model="mapSearch" placeholder="Search units…" class="form-control" style="font-size:0.8125rem;">
            </div>
            <div style="padding:8px 12px;display:flex;gap:6px;border-bottom:1px solid var(--border-color);font-size:0.7rem;flex-wrap:wrap;">
                <span class="badge badge-success badge-no-dot" style="font-size:0.65rem;">
                    <span x-text="stats.online"></span> Online
                </span>
                <span class="badge badge-neutral badge-no-dot" style="font-size:0.65rem;">
                    <span x-text="stats.offline"></span> Offline
                </span>
            </div>
            <div style="flex:1;overflow-y:auto;">
                <template x-for="unit in filteredMapUnits" :key="unit.id">
                    <div @click="selectUnit(unit)"
                         :style="selectedUnit?.id === unit.id
                             ? 'padding:10px 12px;border-bottom:1px solid var(--border-color);cursor:pointer;background:var(--bg-hover);border-left:3px solid var(--color-primary);'
                             : 'padding:10px 12px;border-bottom:1px solid var(--border-color);cursor:pointer;border-left:3px solid transparent;'">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span class="font-mono" style="font-weight:600;font-size:0.875rem;" x-text="unit.unit_number"></span>
                            <span :class="isOnline(unit) ? 'badge badge-success badge-no-dot' : 'badge badge-neutral badge-no-dot'"
                                  style="font-size:0.6rem;"
                                  x-text="isOnline(unit) ? 'Online' : 'Offline'"></span>
                        </div>
                        <div style="font-size:0.75rem;color:var(--text-secondary);" x-text="unit.template_name || ''"></div>
                        <div x-show="unit.samsara_last_location_address"
                             style="font-size:0.7rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                             x-text="unit.samsara_last_location_address"></div>
                    </div>
                </template>
                <template x-if="!loading && filteredMapUnits.length === 0">
                    <div style="padding:24px 12px;text-align:center;color:var(--text-muted);font-size:0.8125rem;">
                        <span x-show="mapSearch">No units match "<span x-text="mapSearch"></span>"</span>
                        <span x-show="!mapSearch">No linked units yet. Link units from their detail page.</span>
                    </div>
                </template>
            </div>
        </div>
        <!-- Map -->
        <div id="tracking-map" style="flex:1;z-index:0;"></div>
    </div>
</div>

<!-- ── TAB: List View ────────────────────────────────────────────── -->
<div x-show="activeTab === 'list'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
    <div class="card spec-card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div class="card-title">Linked Units</div>
            <input type="text" x-model="listSearch" placeholder="Search…" class="form-control"
                   style="max-width:240px;font-size:0.8125rem;background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.25);">
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <table class="data-table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Status</th>
                        <th>Customer</th>
                        <th>Battery</th>
                        <th>Odometer</th>
                        <th>Speed</th>
                        <th>Last Location</th>
                        <th>Last Connected</th>
                        <th>Last Synced</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="unit in filteredListUnits" :key="unit.id">
                        <tr>
                            <td>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id"
                                   class="font-mono"
                                   style="font-weight:600;color:var(--text-primary);"
                                   x-text="unit.unit_number"></a>
                                <div class="text-xs text-muted" x-text="unit.template_name"></div>
                            </td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(unit.status)" style="font-size:0.7rem;text-transform:capitalize;"
                                      x-text="(unit.status || '').replace('_',' ')"></span>
                            </td>
                            <td>
                                <span x-show="unit.customer_name" x-text="unit.customer_name"></span>
                                <span x-show="!unit.customer_name" class="text-muted">—</span>
                            </td>
                            <td>
                                <template x-if="unit.samsara_battery_pct !== null && unit.samsara_battery_pct !== undefined">
                                    <span class="font-mono" :class="batteryClass(unit.samsara_battery_pct)" x-text="unit.samsara_battery_pct + '%'"></span>
                                </template>
                                <span x-show="unit.samsara_battery_pct === null || unit.samsara_battery_pct === undefined" class="text-muted">—</span>
                            </td>
                            <td class="font-mono"
                                x-text="unit.samsara_odometer_km ? Number(unit.samsara_odometer_km).toLocaleString() + ' km' : '—'"></td>
                            <td class="font-mono"
                                x-text="unit.samsara_last_speed_kph !== null && unit.samsara_last_speed_kph !== undefined ? Number(unit.samsara_last_speed_kph).toFixed(1) + ' km/h' : '—'"></td>
                            <td style="max-width:240px;">
                                <span x-show="unit.samsara_last_location_address"
                                      x-text="unit.samsara_last_location_address"
                                      style="font-size:0.8125rem;"></span>
                                <span x-show="!unit.samsara_last_location_address" class="text-muted">—</span>
                            </td>
                            <td x-text="unit.samsara_last_connected_at ? formatRelative(unit.samsara_last_connected_at) : '—'"></td>
                            <td x-text="unit.samsara_last_synced_at ? formatRelative(unit.samsara_last_synced_at) : '—'"></td>
                            <td>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id" class="btn btn-ghost btn-xs">View</a>
                            </td>
                        </tr>
                    </template>
                    <template x-if="!loading && filteredListUnits.length === 0">
                        <tr>
                            <td colspan="10" style="text-align:center;padding:24px;color:var(--text-muted);">
                                <span x-show="listSearch">No units match "<span x-text="listSearch"></span>"</span>
                                <span x-show="!listSearch">No linked units yet. Link from a unit's detail page.</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── TAB: Unlinked Units ───────────────────────────────────────── -->
<div x-show="activeTab === 'unlinked'" x-transition:enter="ff-tab-enter" x-transition:enter-start="ff-tab-enter-from" x-transition:enter-end="ff-tab-enter-to">
    <div class="card spec-card" style="margin-bottom:0;">
        <div class="card-header" style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div class="card-title">Unlinked Units</div>
            <input type="text" x-model="unlinkedSearch" placeholder="Search…" class="form-control"
                   style="max-width:240px;font-size:0.8125rem;background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.25);">
        </div>
        <div class="card-body" style="padding:0;overflow-x:auto;">
            <table class="data-table" style="margin:0;">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Yard</th>
                        <th>Customer</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="unit in filteredUnlinkedUnits" :key="unit.id">
                        <tr>
                            <td>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id"
                                   class="font-mono"
                                   style="font-weight:600;color:var(--text-primary);"
                                   x-text="unit.unit_number"></a>
                            </td>
                            <td x-text="unit.template_name"></td>
                            <td>
                                <span class="badge" :class="statusBadgeClass(unit.status)" style="font-size:0.7rem;text-transform:capitalize;"
                                      x-text="(unit.status || '').replace('_',' ')"></span>
                            </td>
                            <td x-text="unit.yard_location || '—'"></td>
                            <td>
                                <span x-show="unit.customer_name" x-text="unit.customer_name"></span>
                                <span x-show="!unit.customer_name" class="text-muted">—</span>
                            </td>
                            <td>
                                <a :href="'<?= base_url('equipment/show') ?>?id=' + unit.id + '&tab=tracking'"
                                   class="btn btn-secondary btn-xs">Link to Samsara</a>
                            </td>
                        </tr>
                    </template>
                    <template x-if="!loading && filteredUnlinkedUnits.length === 0">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">
                                <span x-show="unlinkedSearch">No units match "<span x-text="unlinkedSearch"></span>"</span>
                                <span x-show="!unlinkedSearch">All units are linked to Samsara.</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<script>
/**
 * FF_FleetTracking — Alpine.js component for the fleet tracking command center.
 *
 * Owns:
 *   • The data fetch from /api/v1/samsara/fleet (single round-trip)
 *   • Three tab views (map, list, unlinked)
 *   • Leaflet marker management for the map view
 *   • Alert dismissal state via localStorage (24-hour TTL)
 *   • Sync All Now button → POSTs to /api/v1/samsara/sync as a GET
 */
function FF_FleetTracking() {
    // Use base_url() with an explicit path — it handles the slash between
    // FF_BASE_PATH and the path. Concatenating BASE + 'api/...' would
    // produce '/fleetforgeapi/...' (missing slash) because base_url('')
    // intentionally has no trailing slash.
    const FLEET_URL    = '<?= base_url('api/v1/samsara/fleet') ?>';
    const SYNC_URL     = '<?= base_url('api/v1/samsara/sync') ?>';
    const IMPORT_URL   = '<?= base_url('api/v1/samsara/import') ?>';
    const UNIT_SHOW    = '<?= base_url('equipment/show') ?>';
    const DISMISS_KEY  = 'ff_samsara_dismissed_alerts';
    const DISMISS_TTL  = 24 * 3600 * 1000; // 24 hours in ms

    // Marker icon factory — color-coded by status. Each status maps
    // to a tailwind-ish color so the legend stays consistent with
    // the rest of the app's badge palette.
    function markerIcon(status) {
        const colors = {
            available:      '#16a34a',
            on_lease:       '#2563eb',
            reserved:       '#9333ea',
            maintenance:    '#d97706',
            inactive:       '#6b7280',
            decommissioned: '#dc2626',
        };
        const color = colors[status] || '#6b7280';
        return L.divIcon({
            className: 'ff-map-marker',
            html: `<svg width="28" height="36" viewBox="0 0 28 36" xmlns="http://www.w3.org/2000/svg">
                     <path d="M14 0C6.268 0 0 6.268 0 14c0 10.5 14 22 14 22s14-11.5 14-22C28 6.268 21.732 0 14 0z" fill="${color}"/>
                     <circle cx="14" cy="13" r="6" fill="white" opacity="0.9"/>
                     <circle cx="14" cy="13" r="3" fill="${color}"/>
                   </svg>`,
            iconSize: [28, 36],
            iconAnchor: [14, 36],
            popupAnchor: [0, -36],
        });
    }

    return {
        // ── Data ─────────────────────────────────────────────
        linked:         [],
        unlinked:       [],
        alerts:         [],
        stats:          { total: 0, linked: 0, unlinked: 0, online: 0, offline: 0, alert_counts: {} },
        loading:        false,
        syncing:        false,
        importing:      false,
        error:          null,
        lastUpdated:    null,
        autoRefresh:    true,
        refreshInterval:null,

        // ── Alerts panel ─────────────────────────────────────
        alertsExpanded: false,

        // ── Tabs ─────────────────────────────────────────────
        activeTab:      'map',

        // ── Map state ────────────────────────────────────────
        map:            null,
        markers:        {},
        selectedUnit:   null,
        mapSearch:      '',
        mapInitialized: false,

        // ── List filters ─────────────────────────────────────
        listSearch:     '',
        unlinkedSearch: '',

        // ── Dismissed alerts (localStorage-backed) ───────────
        dismissed:      {},

        // ── Filters / computed ───────────────────────────────
        get filteredMapUnits() {
            const q = this.mapSearch.toLowerCase().trim();
            const list = this.linked.filter(u => u.samsara_last_location_lat !== null);
            if (!q) return list;
            return list.filter(u =>
                (u.unit_number || '').toLowerCase().includes(q) ||
                (u.template_name || '').toLowerCase().includes(q) ||
                (u.samsara_last_location_address || '').toLowerCase().includes(q) ||
                (u.customer_name || '').toLowerCase().includes(q)
            );
        },

        get filteredListUnits() {
            const q = this.listSearch.toLowerCase().trim();
            if (!q) return this.linked;
            return this.linked.filter(u =>
                (u.unit_number || '').toLowerCase().includes(q) ||
                (u.template_name || '').toLowerCase().includes(q) ||
                (u.samsara_last_location_address || '').toLowerCase().includes(q) ||
                (u.customer_name || '').toLowerCase().includes(q)
            );
        },

        get filteredUnlinkedUnits() {
            const q = this.unlinkedSearch.toLowerCase().trim();
            if (!q) return this.unlinked;
            return this.unlinked.filter(u =>
                (u.unit_number || '').toLowerCase().includes(q) ||
                (u.template_name || '').toLowerCase().includes(q) ||
                (u.yard_location || '').toLowerCase().includes(q)
            );
        },

        // Visible alerts = raw alerts minus anything still inside
        // its 24-hour dismiss window. Computed on every render so
        // dismissals + new fetches both flow naturally.
        get visibleAlerts() {
            const now = Date.now();
            return this.alerts.filter(a => {
                const key = a.unit_id + '-' + a.type;
                const dismissedAt = this.dismissed[key];
                return !dismissedAt || (now - dismissedAt) > DISMISS_TTL;
            });
        },

        // ── Lifecycle ────────────────────────────────────────
        async init() {
            this.loadDismissed();
            await this.refresh();

            // Restore tab from hash — must happen before map init so we know
            // which tab is active before conditionally mounting the map.
            const _tabs = ['map','list','unlinked'];
            const _initTab = FF_TabHash.init(_tabs, 'map');
            this.activeTab = _initTab;
            FF_TabHash.write(_initTab);
            FF_TabHash.watchUnload(() => this.activeTab);
            let _prevTab = _initTab;
            this.$watch('activeTab', (tab) => {
                FF_TabHash.onSwitch(_prevTab, tab);
                _prevTab = tab;
            });

            // WHY: Only mount map immediately when on the map tab — if restoring
            // to list/unlinked, skip init now (clicking the map tab later calls
            // initMap() via its @click handler, which is idempotent).
            if (_initTab === 'map') {
                this.$nextTick(() => this.initMap());
            }

            this.$watch('autoRefresh', (val) => {
                if (val) this.startAutoRefresh();
                else this.stopAutoRefresh();
            });
            this.startAutoRefresh();
        },

        startAutoRefresh() {
            this.stopAutoRefresh();
            this.refreshInterval = setInterval(() => this.refresh(), 60000);
        },

        stopAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        // ── Data fetch ───────────────────────────────────────
        async refresh() {
            this.loading = true;
            this.error = null;
            try {
                const r = await FF_Api.get(FLEET_URL);
                if (r.success) {
                    this.linked   = r.data.linked   || [];
                    this.unlinked = r.data.unlinked || [];
                    this.alerts   = r.data.alerts   || [];
                    this.stats    = r.data.stats    || this.stats;
                    this.lastUpdated = new Date();
                    if (this.mapInitialized) this.updateMarkers();
                } else {
                    this.error = r.error?.message || 'Failed to load fleet data.';
                }
            } catch (e) {
                this.error = 'Network error loading fleet data.';
            }
            this.loading = false;
        },

        // POST to /api/v1/samsara/sync as GET (sync ALL linked units)
        // and re-pull /fleet so the dashboard updates.
        async syncAllNow() {
            this.syncing = true;
            try {
                await FF_Api.get(SYNC_URL);
                await this.refresh();
            } catch (e) {
                this.error = 'Sync request failed.';
            }
            this.syncing = false;
        },

        // Fetch all Samsara trackables and create any missing in FF.
        // Idempotent: already-linked units are skipped.
        // Different from syncAllNow() — that refreshes telemetry on
        // existing links; this creates the links themselves.
        async importFromSamsara() {
            if (!confirm(
                'Import all vehicles & trailers from Samsara?\n\n' +
                'Already-linked units are skipped — this is safe to run anytime.\n' +
                'New units will be created with status "available".'
            )) return;
            this.importing = true;
            this.error = '';
            try {
                const res = await FF_Api.post(IMPORT_URL, {});
                if (res?.success) {
                    const d = res.data;
                    const msg = `Import complete: ${d.created} created, ` +
                        `${d.skipped_linked} already linked, ` +
                        `${d.skipped_name} name conflicts` +
                        (d.failed > 0 ? `, ${d.failed} failed` : '') +
                        `. Total linked: ${d.total_linked}.`;
                    if (window.FF_Toast) {
                        d.failed > 0
                            ? FF_Toast.warning('Import done', msg)
                            : FF_Toast.success('Import done', msg);
                    } else {
                        alert(msg);
                    }
                    await this.refresh();
                } else {
                    this.error = res?.message || 'Import failed.';
                }
            } catch (e) {
                this.error = 'Import request failed: ' + (e?.message || 'unknown error');
            }
            this.importing = false;
        },

        alertSeveritySummary() {
            const c = this.visibleAlerts.filter(a => a.severity === 'critical').length;
            const w = this.visibleAlerts.filter(a => a.severity !== 'critical').length;
            const parts = [];
            if (c) parts.push(c + ' critical');
            if (w) parts.push(w + ' warning');
            return parts.join(', ');
        },

        // ── Map init/update ──────────────────────────────────
        initMap() {
            if (this.mapInitialized) return;
            const el = document.getElementById('tracking-map');
            if (!el) return;

            this.map = L.map('tracking-map', {
                center: [49.10, -122.66], // Surrey, BC default
                zoom: 9,
                zoomControl: true,
            });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.map);
            this.mapInitialized = true;
            this.updateMarkers();
        },

        updateMarkers() {
            if (!this.map) return;

            const ids = new Set();
            const bounds = [];

            for (const unit of this.linked) {
                if (unit.samsara_last_location_lat === null || unit.samsara_last_location_lng === null) continue;
                const lat = Number(unit.samsara_last_location_lat);
                const lng = Number(unit.samsara_last_location_lng);
                if (!lat || !lng) continue;
                const sid = String(unit.id);
                ids.add(sid);
                bounds.push([lat, lng]);

                if (this.markers[sid]) {
                    this.markers[sid].setLatLng([lat, lng]);
                    this.markers[sid].setIcon(markerIcon(unit.status));
                    this.markers[sid].setPopupContent(this.buildPopup(unit));
                } else {
                    const m = L.marker([lat, lng], { icon: markerIcon(unit.status) })
                        .addTo(this.map)
                        .bindPopup(this.buildPopup(unit));
                    m._ffUnitId = unit.id;
                    m.on('click', () => {
                        this.selectedUnit = this.linked.find(u => u.id === m._ffUnitId) || null;
                    });
                    this.markers[sid] = m;
                }
            }

            // Cull stale markers (e.g., a unit that was unlinked
            // since the last refresh).
            for (const sid of Object.keys(this.markers)) {
                if (!ids.has(sid)) {
                    this.map.removeLayer(this.markers[sid]);
                    delete this.markers[sid];
                }
            }

            // Auto-fit only on the first paint so we don't fight
            // the user's manual pan/zoom on every 60s refresh.
            if (bounds.length > 0 && !this._didFitBounds) {
                this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 12 });
                this._didFitBounds = true;
            }
        },

        buildPopup(unit) {
            const colors = {
                available:'#16a34a', on_lease:'#2563eb', reserved:'#9333ea',
                maintenance:'#d97706', inactive:'#6b7280', decommissioned:'#dc2626',
            };
            const color = colors[unit.status] || '#6b7280';
            const status = (unit.status || '').replace('_', ' ');

            let html = '<div style="min-width:220px;font-family:DM Sans,sans-serif;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
            html += `<span style="font-weight:700;font-size:14px;">${this.esc(unit.unit_number)}</span>`;
            html += `<span style="background:${color};color:white;padding:1px 6px;border-radius:4px;font-size:10px;text-transform:capitalize;">${this.esc(status)}</span>`;
            html += '</div>';
            if (unit.template_name) html += `<div style="font-size:12px;color:#666;margin-bottom:3px;">${this.esc(unit.template_name)}</div>`;
            if (unit.customer_name) html += `<div style="font-size:11px;color:#555;margin-bottom:3px;">Customer: ${this.esc(unit.customer_name)}</div>`;
            if (unit.samsara_last_location_address) html += `<div style="font-size:11px;color:#555;margin-bottom:3px;">${this.esc(unit.samsara_last_location_address)}</div>`;
            if (unit.samsara_last_speed_kph !== null && unit.samsara_last_speed_kph !== undefined) {
                html += `<div style="font-size:11px;color:#555;">Speed: <strong>${Number(unit.samsara_last_speed_kph).toFixed(1)} km/h</strong></div>`;
            }
            if (unit.samsara_battery_pct !== null && unit.samsara_battery_pct !== undefined) {
                html += `<div style="font-size:11px;color:#555;">Battery: <strong>${unit.samsara_battery_pct}%</strong></div>`;
            }
            if (unit.samsara_odometer_km) {
                html += `<div style="font-size:11px;color:#555;">Odometer: <strong>${Number(unit.samsara_odometer_km).toLocaleString()} km</strong></div>`;
            }
            html += `<a href="${UNIT_SHOW}?id=${unit.id}" style="display:inline-block;margin-top:6px;font-size:11px;color:#2563eb;text-decoration:none;">View Unit Profile →</a>`;
            html += '</div>';
            return html;
        },

        selectUnit(unit) {
            this.selectedUnit = unit;
            if (this.map && unit.samsara_last_location_lat) {
                const lat = Number(unit.samsara_last_location_lat);
                const lng = Number(unit.samsara_last_location_lng);
                this.map.setView([lat, lng], 15, { animate: true });
                const sid = String(unit.id);
                if (this.markers[sid]) this.markers[sid].openPopup();
            }
        },

        // ── Alert dismissal ──────────────────────────────────
        loadDismissed() {
            try {
                const raw = localStorage.getItem(DISMISS_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                // Strip any expired entries while we're in here so
                // localStorage doesn't grow unbounded over months.
                const now = Date.now();
                const fresh = {};
                for (const k of Object.keys(parsed)) {
                    if ((now - parsed[k]) <= DISMISS_TTL) fresh[k] = parsed[k];
                }
                this.dismissed = fresh;
                localStorage.setItem(DISMISS_KEY, JSON.stringify(fresh));
            } catch (e) { /* ignore — localStorage is best-effort */ }
        },

        dismissAlert(alert) {
            const key = alert.unit_id + '-' + alert.type;
            this.dismissed = { ...this.dismissed, [key]: Date.now() };
            try { localStorage.setItem(DISMISS_KEY, JSON.stringify(this.dismissed)); } catch {}
        },

        dismissAllAlerts() {
            const now = Date.now();
            const next = { ...this.dismissed };
            for (const a of this.alerts) next[a.unit_id + '-' + a.type] = now;
            this.dismissed = next;
            try { localStorage.setItem(DISMISS_KEY, JSON.stringify(this.dismissed)); } catch {}
        },

        // ── Helpers ──────────────────────────────────────────
        statusBadgeClass(status) {
            return {
                available:'badge-success', on_lease:'badge-info', reserved:'badge-purple',
                maintenance:'badge-warning', inactive:'badge-neutral', decommissioned:'badge-danger',
            }[status] || 'badge-neutral';
        },

        batteryClass(pct) {
            if (pct === null || pct === undefined) return '';
            if (pct < 20) return 'text-danger';
            if (pct < 50) return 'text-warning';
            return 'text-success';
        },

        // Online = has lat AND last_connected within 8 hours
        isOnline(unit) {
            if (!unit.samsara_last_location_lat || !unit.samsara_last_connected_at) return false;
            const ts = new Date(unit.samsara_last_connected_at).getTime();
            return !isNaN(ts) && (Date.now() - ts) < (8 * 3600 * 1000);
        },

        formatTime(d) {
            if (!d) return '—';
            return d.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        },

        formatRelative(value) {
            if (!value) return '';
            const ts = new Date(value).getTime();
            if (isNaN(ts)) return value;
            const diffSec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
            if (diffSec < 60)    return 'just now';
            if (diffSec < 3600)  return Math.floor(diffSec/60) + ' min ago';
            if (diffSec < 86400) return Math.floor(diffSec/3600) + ' hr ago';
            const days = Math.floor(diffSec/86400);
            if (days < 7) return days + 'd ago';
            return new Date(value).toLocaleDateString();
        },

        esc(str) {
            if (str === null || str === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        },
    };
}
</script>

<style>
.ff-map-marker {
    background: none !important;
    border: none !important;
}
.leaflet-popup-content {
    font-family: 'DM Sans', sans-serif;
    line-height: 1.5;
}
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
