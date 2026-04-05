<?php
declare(strict_types=1);

/**
 * app/admin/tracking/index.php
 *
 * Fleet GPS Tracking — full-page map showing all GPS-equipped equipment
 * units with real-time locations from Samsara.
 *
 * Features:
 *   - Leaflet.js map with OpenStreetMap tiles (no API key needed)
 *   - Sidebar panel with searchable unit list and status indicators
 *   - Auto-refresh toggle (60-second interval by default)
 *   - Click unit in sidebar or marker popup to zoom + highlight
 *   - Marker popups with unit info, speed, address, link to profile
 *   - Color-coded markers by unit status (available, on_lease, etc.)
 *   - Graceful handling when Samsara API is not configured (dev mode)
 *
 * Data loaded via API: GET /api/v1/gps/locations
 * Alpine.js component: FF_FleetTracking()
 *
 * Permission: equipment:view
 *
 * @depends config/app.php, includes/auth.php, includes/header.php,
 *          includes/footer.php, api/v1/gps/locations.php
 * @session S026
 */

require_once realpath(dirname(__DIR__, 3) . '/config/app.php');
require_once FF_ROOT . '/includes/auth.php';

require_auth();
require_permission('equipment', 'view');

$pageTitle = 'Fleet Tracking';
require_once FF_ROOT . '/includes/header.php';

// Count GPS-equipped units for the header badge
$gpsUnitCount = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE deleted_at IS NULL AND tracking_provider = 'samsara'
       AND gps_device_id IS NOT NULL AND gps_device_id != ''"
);

// Check if Samsara API is configured
$samsaraKey = settings_get('gps.samsara_api_key', '');
$gpsConfigured = ($samsaraKey !== '');
?>

<!-- Leaflet CSS & JS (lightweight open-source mapping library) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div x-data="FF_FleetTracking()" x-init="init()">

<!-- ── Page Header ────────────────────────────────────────────────────────── -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-header-title">
            Fleet Tracking
            <span class="badge badge-info" style="font-size:0.75rem;vertical-align:middle;margin-left:6px;">
                <?= e((string)$gpsUnitCount) ?> GPS Unit<?= $gpsUnitCount !== 1 ? 's' : '' ?>
            </span>
        </h1>
        <p style="margin:4px 0 0;font-size:0.8125rem;color:var(--text-muted);">
            Real-time GPS tracking via Samsara
            <span x-show="lastUpdated" x-cloak style="margin-left:8px;">
                · Updated <span x-text="formatTime(lastUpdated)"></span>
            </span>
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <!-- Auto-refresh toggle -->
        <label style="display:flex;align-items:center;gap:6px;font-size:0.8125rem;color:var(--text-secondary);cursor:pointer;">
            <input type="checkbox" x-model="autoRefresh" style="accent-color:var(--color-primary);">
            Auto-refresh
        </label>
        <!-- Manual refresh -->
        <button class="btn btn-secondary btn-sm" @click="refresh()" :disabled="loading">
            <span x-show="!loading">Refresh</span>
            <span x-show="loading" x-cloak>Loading…</span>
        </button>
        <!-- Settings link -->
        <?php if (can('settings', 'edit')): ?>
        <a href="<?= base_url('settings?tab=integrations') ?>" class="btn btn-ghost btn-sm" title="GPS Settings">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
            </svg>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$gpsConfigured): ?>
<!-- Dev mode banner -->
<div class="toast toast-warning" style="position:relative;margin-bottom:16px;animation:none;">
    <span class="toast-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg></span>
    <div class="toast-body">
        <div class="toast-title">GPS Not Configured</div>
        <div class="toast-message">Samsara API key is not set. Configure it in <a href="<?= base_url('settings?tab=integrations') ?>" style="color:inherit;text-decoration:underline;">Settings → Integrations</a> to enable live tracking.</div>
    </div>
</div>
<?php endif; ?>

<!-- Error banner -->
<template x-if="error">
    <div class="toast toast-danger" style="position:relative;margin-bottom:16px;animation:none;">
        <span class="toast-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg></span>
        <div class="toast-body"><div class="toast-message" x-text="error"></div></div>
    </div>
</template>

<!-- ── Main Tracking Layout ───────────────────────────────────────────────── -->
<div style="display:flex;height:calc(100vh - 240px);min-height:500px;border:1px solid var(--border-default);border-radius:8px;overflow:hidden;background:var(--bg-card);">

    <!-- ── Sidebar Panel ──────────────────────────────────────────────────── -->
    <div style="width:320px;flex-shrink:0;border-right:1px solid var(--border-default);display:flex;flex-direction:column;background:var(--bg-page);">

        <!-- Search -->
        <div style="padding:12px;border-bottom:1px solid var(--border-default);">
            <input type="text" x-model="search" placeholder="Search units…"
                   class="form-control" style="font-size:0.8125rem;">
        </div>

        <!-- Stats row -->
        <div style="padding:8px 12px;display:flex;gap:8px;border-bottom:1px solid var(--border-default);font-size:0.75rem;">
            <span class="badge badge-success badge-no-dot" style="font-size:0.7rem;">
                <span x-text="onlineCount"></span> Online
            </span>
            <span class="badge badge-neutral badge-no-dot" style="font-size:0.7rem;">
                <span x-text="offlineCount"></span> No Signal
            </span>
            <span class="badge badge-info badge-no-dot" style="font-size:0.7rem;">
                <span x-text="units.length"></span> Total
            </span>
        </div>

        <!-- Unit list -->
        <div style="flex:1;overflow-y:auto;">
            <template x-if="loading && units.length === 0">
                <div style="padding:20px;text-align:center;">
                    <template x-for="n in 5" :key="n">
                        <div class="skeleton skeleton-row" style="margin-bottom:8px;"></div>
                    </template>
                </div>
            </template>

            <template x-if="!loading && filteredUnits.length === 0">
                <div style="padding:30px 16px;text-align:center;color:var(--text-muted);font-size:0.8125rem;">
                    <template x-if="search">
                        <span>No units match "<span x-text="search"></span>"</span>
                    </template>
                    <template x-if="!search">
                        <span>No GPS-equipped units found.</span>
                    </template>
                </div>
            </template>

            <template x-for="unit in filteredUnits" :key="unit.id">
                <div @click="selectUnit(unit)"
                     :style="selectedUnit?.id === unit.id
                         ? 'padding:10px 12px;border-bottom:1px solid var(--border-default);cursor:pointer;background:var(--bg-active);border-left:3px solid var(--color-primary);'
                         : 'padding:10px 12px;border-bottom:1px solid var(--border-default);cursor:pointer;border-left:3px solid transparent;'"
                     @mouseenter="$el.style.background = selectedUnit?.id === unit.id ? 'var(--bg-active)' : 'var(--bg-hover)'"
                     @mouseleave="$el.style.background = selectedUnit?.id === unit.id ? 'var(--bg-active)' : ''">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                        <span class="font-mono" style="font-weight:600;font-size:0.875rem;" x-text="unit.unit_number"></span>
                        <span :class="unit.location ? 'badge badge-success badge-no-dot' : 'badge badge-neutral badge-no-dot'"
                              style="font-size:0.65rem;" x-text="unit.location ? 'Online' : 'Offline'"></span>
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:2px;" x-text="unit.template_name || ''"></div>
                    <div x-show="unit.location?.address" style="font-size:0.7rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                         x-text="unit.location?.address"></div>
                    <div x-show="unit.location?.speed !== null && unit.location?.speed !== undefined"
                         style="font-size:0.7rem;color:var(--text-muted);margin-top:1px;">
                        Speed: <span class="font-mono" x-text="unit.location?.speed ? Math.round(unit.location.speed) + ' mph' : 'Idle'"></span>
                    </div>
                    <div x-show="!unit.location" style="font-size:0.7rem;color:var(--text-muted);">No GPS signal</div>
                </div>
            </template>
        </div>

        <!-- Sidebar footer -->
        <div style="padding:8px 12px;border-top:1px solid var(--border-default);font-size:0.7rem;color:var(--text-muted);text-align:center;">
            Powered by Samsara GPS
        </div>
    </div>

    <!-- ── Map Container ──────────────────────────────────────────────────── -->
    <div id="tracking-map" style="flex:1;z-index:0;"></div>

</div>

<!-- ── Selected Unit Detail Panel ─────────────────────────────────────────── -->
<template x-if="selectedUnit">
    <div class="card" style="margin-top:16px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <span style="font-weight:600;" x-text="'Unit ' + selectedUnit.unit_number"></span>
                <span class="badge" :class="statusBadgeClass(selectedUnit.status)" style="margin-left:6px;font-size:0.7rem;"
                      x-text="selectedUnit.status.replace('_',' ')"></span>
            </div>
            <div style="display:flex;gap:6px;">
                <a :href="'<?= base_url('equipment/show') ?>?id=' + selectedUnit.id"
                   class="btn btn-secondary btn-xs">View Profile</a>
                <a x-show="selectedUnit.samsara_vehicle_url"
                   :href="selectedUnit.samsara_vehicle_url"
                   target="_blank" class="btn btn-ghost btn-xs">Samsara Dashboard →</a>
            </div>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Type</div>
                    <div style="font-size:0.875rem;" x-text="selectedUnit.template_name + (selectedUnit.template_category ? ' · ' + selectedUnit.template_category.replace('_',' ') : '')"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Location</div>
                    <div style="font-size:0.875rem;" x-text="selectedUnit.location?.address || 'Unknown'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Coordinates</div>
                    <div class="font-mono" style="font-size:0.8125rem;"
                         x-text="selectedUnit.location ? selectedUnit.location.latitude.toFixed(6) + ', ' + selectedUnit.location.longitude.toFixed(6) : '—'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Speed</div>
                    <div class="font-mono" style="font-size:0.875rem;"
                         x-text="selectedUnit.location?.speed !== null ? Math.round(selectedUnit.location.speed) + ' mph' : '—'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Heading</div>
                    <div class="font-mono" style="font-size:0.875rem;"
                         x-text="selectedUnit.location?.heading !== null ? Math.round(selectedUnit.location.heading) + '°' : '—'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">GPS Odometer</div>
                    <div class="font-mono" style="font-size:0.875rem;"
                         x-text="selectedUnit.location?.odometer_km ? selectedUnit.location.odometer_km.toLocaleString() + ' km' : '—'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Last GPS Update</div>
                    <div style="font-size:0.8125rem;"
                         x-text="selectedUnit.location?.time ? new Date(selectedUnit.location.time).toLocaleString() : '—'"></div>
                </div>
                <div>
                    <div class="form-label" style="margin-bottom:2px;">Samsara ID</div>
                    <div class="font-mono" style="font-size:0.8125rem;" x-text="selectedUnit.gps_device_id"></div>
                </div>
            </div>
        </div>
    </div>
</template>

</div><!-- /x-data -->

<script>
/**
 * FF_FleetTracking — Alpine.js component for the fleet tracking map.
 *
 * Manages Leaflet map lifecycle, marker creation/updates, sidebar
 * unit list, search filtering, and auto-refresh polling.
 */
function FF_FleetTracking() {
    const BASE = '<?= base_url('') ?>';
    const API_URL = BASE + 'api/v1/gps/locations';

    // WHY: Custom marker icon SVGs for different unit statuses.
    // Leaflet's default blue marker doesn't convey status at a glance.
    function markerIcon(status) {
        const colors = {
            available:      '#16a34a', // green
            on_lease:       '#2563eb', // blue
            reserved:       '#9333ea', // purple
            maintenance:    '#d97706', // amber
            inactive:       '#6b7280', // gray
            decommissioned: '#dc2626', // red
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
        map:             null,
        markers:         {},
        units:           [],
        selectedUnit:    null,
        search:          '',
        autoRefresh:     true,
        refreshInterval: null,
        loading:         false,
        lastUpdated:     null,
        error:           null,

        // ── Computed: filtered unit list ─────────────────────────
        get filteredUnits() {
            if (!this.search) return this.units;
            const q = this.search.toLowerCase();
            return this.units.filter(u =>
                u.unit_number.toLowerCase().includes(q) ||
                (u.template_name || '').toLowerCase().includes(q) ||
                (u.location?.address || '').toLowerCase().includes(q) ||
                (u.yard_location || '').toLowerCase().includes(q)
            );
        },

        get onlineCount() {
            return this.units.filter(u => u.location).length;
        },

        get offlineCount() {
            return this.units.filter(u => !u.location).length;
        },

        // ── Initialize ──────────────────────────────────────────
        async init() {
            // WHY: Default center is approximate North America center.
            // Map will auto-fit to marker bounds after data loads.
            this.map = L.map('tracking-map', {
                center: [43.65, -79.38],
                zoom: 5,
                zoomControl: true,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(this.map);

            // Load initial data
            await this.refresh();

            // Watch auto-refresh toggle
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

        // ── Fetch GPS data from server ──────────────────────────
        async refresh() {
            this.loading = true;
            this.error = null;
            try {
                const r = await FF_Api.get(API_URL);
                if (r.success) {
                    this.units = r.data.units || [];
                    this.updateMarkers();
                    this.lastUpdated = new Date();
                } else {
                    this.error = r.error?.message || 'Failed to load GPS data.';
                }
            } catch (e) {
                this.error = 'Network error. Could not reach GPS service.';
            }
            this.loading = false;
        },

        // ── Sync Leaflet markers with unit data ─────────────────
        updateMarkers() {
            const currentIds = new Set(this.units.map(u => String(u.id)));

            // Remove stale markers
            for (const id of Object.keys(this.markers)) {
                if (!currentIds.has(id)) {
                    this.map.removeLayer(this.markers[id]);
                    delete this.markers[id];
                }
            }

            // Add/update markers for units with GPS location
            const bounds = [];
            for (const unit of this.units) {
                if (!unit.location?.latitude || !unit.location?.longitude) continue;

                const lat = unit.location.latitude;
                const lng = unit.location.longitude;
                bounds.push([lat, lng]);
                const sid = String(unit.id);

                if (this.markers[sid]) {
                    // Update existing marker position + icon + popup
                    this.markers[sid].setLatLng([lat, lng]);
                    this.markers[sid].setIcon(markerIcon(unit.status));
                    this.markers[sid].setPopupContent(this.buildPopup(unit));
                } else {
                    // Create new marker
                    const marker = L.marker([lat, lng], { icon: markerIcon(unit.status) })
                        .addTo(this.map)
                        .bindPopup(this.buildPopup(unit));

                    // WHY: Store reference to unit ID on marker for click handler
                    marker._ffUnitId = unit.id;
                    marker.on('click', () => {
                        this.selectedUnit = this.units.find(u => u.id === marker._ffUnitId) || null;
                    });

                    this.markers[sid] = marker;
                }
            }

            // Fit map to marker bounds (only on first load or if bounds changed significantly)
            if (bounds.length > 0 && !this.lastUpdated) {
                this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
        },

        // ── Build popup HTML for a marker ───────────────────────
        buildPopup(unit) {
            const statusColors = {
                available:'#16a34a', on_lease:'#2563eb', reserved:'#9333ea',
                maintenance:'#d97706', inactive:'#6b7280', decommissioned:'#dc2626',
            };
            const color = statusColors[unit.status] || '#6b7280';
            const statusLabel = (unit.status || '').replace('_', ' ');

            let html = '<div style="min-width:200px;font-family:DM Sans,sans-serif;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
            html += `<span style="font-weight:700;font-size:14px;">${this.esc(unit.unit_number)}</span>`;
            html += `<span style="background:${color};color:white;padding:1px 6px;border-radius:4px;font-size:10px;text-transform:capitalize;">${statusLabel}</span>`;
            html += '</div>';

            if (unit.template_name) {
                html += `<div style="font-size:12px;color:#666;margin-bottom:4px;">${this.esc(unit.template_name)}</div>`;
            }

            if (unit.location?.address) {
                html += `<div style="font-size:11px;margin-bottom:3px;color:#555;">${this.esc(unit.location.address)}</div>`;
            }

            if (unit.location?.speed !== null && unit.location?.speed !== undefined) {
                const speed = Math.round(unit.location.speed);
                html += `<div style="font-size:11px;color:#555;">Speed: <strong>${speed} mph</strong></div>`;
            }

            if (unit.location?.odometer_km) {
                html += `<div style="font-size:11px;color:#555;">Odometer: <strong>${unit.location.odometer_km.toLocaleString()} km</strong></div>`;
            }

            if (unit.location?.time) {
                const t = new Date(unit.location.time);
                html += `<div style="font-size:10px;color:#999;margin-top:4px;">GPS: ${t.toLocaleString()}</div>`;
            }

            html += `<a href="${BASE}equipment/show?id=${unit.id}" style="display:inline-block;margin-top:6px;font-size:11px;color:#2563eb;">View Unit Profile &rarr;</a>`;
            html += '</div>';
            return html;
        },

        // ── Sidebar unit click: zoom to unit ────────────────────
        selectUnit(unit) {
            this.selectedUnit = unit;
            if (unit.location?.latitude && unit.location?.longitude) {
                this.map.setView([unit.location.latitude, unit.location.longitude], 15, { animate: true });
                const sid = String(unit.id);
                if (this.markers[sid]) {
                    this.markers[sid].openPopup();
                }
            }
        },

        // ── Status badge class (mirrors equipment index pattern) ─
        statusBadgeClass(status) {
            return {
                available:'badge-success', on_lease:'badge-info', reserved:'badge-purple',
                maintenance:'badge-warning', inactive:'badge-neutral', decommissioned:'badge-danger',
            }[status] || 'badge-neutral';
        },

        // ── Time formatting ─────────────────────────────────────
        formatTime(d) {
            if (!d) return '—';
            return d.toLocaleTimeString('en-CA', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
        },

        // ── HTML escape helper ──────────────────────────────────
        esc(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },
    };
}
</script>

<style>
/* WHY: Override Leaflet's default marker class to remove shadow/background */
.ff-map-marker {
    background: none !important;
    border: none !important;
}
/* WHY: Ensure Leaflet popups use our font stack */
.leaflet-popup-content {
    font-family: 'DM Sans', sans-serif;
    line-height: 1.5;
}
</style>

<?php require_once FF_ROOT . '/includes/footer.php'; ?>
