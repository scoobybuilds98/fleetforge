<?php
declare(strict_types=1);

/**
 * api/v1/samsara/fleet.php
 *
 * GET → The single source of truth for the Fleet Tracking dashboard.
 *
 * Returns every equipment unit, partitioned by Samsara link state:
 *
 *   {
 *     "linked":   [ {id, unit_number, status, ..., samsara_*, samsara_entity_type} ],
 *     "unlinked": [ {id, unit_number, status, ...} ],
 *     "alerts":   [ {type, severity, unit_id, unit_number, message} ],
 *     "stats": {
 *       total, linked, unlinked, online, offline,
 *       linked_by_type: { vehicle: int, trailer: int },          // SAMSARA-2
 *       alert_counts: { battery_critical, battery_low,
 *                       not_connected_24h, not_connected_8h, no_gps }
 *     }
 *   }
 *
 * WHY one endpoint: the dashboard needs ALL of these in one paint
 * (the tabs share state), and they all key off the same equipment_units
 * row, so a single SELECT is much cheaper than three.
 *
 * This endpoint reads ONLY from FleetForge's database — it does NOT
 * call Samsara live. The 5-minute cron (cron/samsara_sync.php) is
 * what populates the samsara_* columns this endpoint reads. That makes
 * the fleet dashboard near-instant and removes Samsara as a runtime
 * dependency for browse-only users.
 *
 * SAMSARA-2: linked units now carry samsara_entity_type so the
 * dashboard can render a [Vehicle]/[Trailer] pill and suppress
 * battery / power columns for trailers (which never report them).
 * Battery and not-connected alerts already key off `!== null`, so
 * trailers (whose battery and last_connected_at columns are NULL by
 * definition) silently skip those rules without any extra logic.
 *
 * Permission: equipment:view
 *
 * @depends api/bootstrap.php
 * @session SAMSARA-1, SAMSARA-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

// ── Pull every active unit + its current customer (if leased) ─
// LEFT JOIN on leases so we can show "Customer" inline in the
// list view. Filtering for active leases only — historical leases
// don't matter for live tracking. Also pull the linked customer
// name so the list view doesn't need a second round-trip.
$rows = db_select(
    "SELECT
        u.id, u.unit_number, u.status, u.yard_location, u.year,
        u.samsara_vehicle_id, u.samsara_entity_type, u.samsara_vehicle_name,
        u.samsara_battery_pct, u.samsara_battery_charging,
        u.samsara_power_source, u.samsara_check_in_mode,
        u.samsara_last_location_lat, u.samsara_last_location_lng,
        u.samsara_last_location_address, u.samsara_last_speed_kph,
        u.samsara_last_connected_at, u.samsara_last_synced_at,
        u.samsara_odometer_km,
        t.name     AS template_name,
        t.category AS template_category,
        c.id           AS customer_id,
        c.company_name AS customer_name,
        l.id           AS active_lease_id
       FROM equipment_units u
       JOIN equipment_templates t ON t.id = u.template_id
       LEFT JOIN leases l
         ON l.equipment_unit_id = u.id
        AND l.status = 'active'
        AND l.deleted_at IS NULL
       LEFT JOIN customers c
         ON c.id = l.customer_id
        AND c.deleted_at IS NULL
      WHERE u.deleted_at IS NULL
        AND u.status <> 'decommissioned'
      ORDER BY u.unit_number"
);

// ── Partition + accumulate alerts in a single pass ──────────
// We walk the row set once and bucket every unit into linked or
// unlinked, then evaluate the linked ones against the alert
// thresholds. This is O(n) and avoids loading data twice.
$linked   = [];
$unlinked = [];
$alerts   = [];
$now      = time();

// Alert thresholds — kept here as named constants so future tweaks
// (e.g., dropping the 24h to 12h) only need one place to change.
$BATTERY_CRITICAL_PCT = 10;
$BATTERY_LOW_PCT      = 25;
$NOT_CONN_HARD_HOURS  = 24;  // red
$NOT_CONN_WARN_HOURS  = 8;   // amber

$alertCounts = [
    'battery_critical'  => 0,
    'battery_low'       => 0,
    'not_connected_24h' => 0,
    'not_connected_8h'  => 0,
    'no_gps'            => 0,
];

// SAMSARA-2: track linked counts split by entity type so the
// dashboard header can show "X vehicles, Y trailers" instead of
// just one combined "linked" number.
$linkedByType = ['vehicle' => 0, 'trailer' => 0];

foreach ($rows as $r) {
    // Cast nullable numerics into typed scalars so the JSON
    // payload doesn't sprinkle "12.34" strings into the front-end.
    $base = [
        'id'                 => (int) $r['id'],
        'unit_number'        => (string) $r['unit_number'],
        'status'             => (string) $r['status'],
        'yard_location'      => $r['yard_location'],
        'year'               => $r['year'] ? (int) $r['year'] : null,
        'template_name'      => $r['template_name'],
        'template_category'  => $r['template_category'],
        'customer_id'        => $r['customer_id'] ? (int) $r['customer_id'] : null,
        'customer_name'      => $r['customer_name'],
        'active_lease_id'    => $r['active_lease_id'] ? (int) $r['active_lease_id'] : null,
    ];

    if (empty($r['samsara_vehicle_id'])) {
        $unlinked[] = $base;
        continue;
    }

    // SAMSARA-2: default the type to 'vehicle' for paranoia even
    // though the column is NOT NULL DEFAULT 'vehicle'.
    $entityType = (string) ($r['samsara_entity_type'] ?? 'vehicle');

    $unit = $base + [
        'samsara_vehicle_id'           => $r['samsara_vehicle_id'],
        'samsara_entity_type'          => $entityType,
        'samsara_vehicle_name'         => $r['samsara_vehicle_name'],
        'samsara_battery_pct'          => $r['samsara_battery_pct'] !== null ? (int) $r['samsara_battery_pct'] : null,
        'samsara_battery_charging'     => $r['samsara_battery_charging'] !== null ? (int) $r['samsara_battery_charging'] : null,
        'samsara_power_source'         => $r['samsara_power_source'],
        'samsara_check_in_mode'        => $r['samsara_check_in_mode'],
        'samsara_last_location_lat'    => $r['samsara_last_location_lat'] !== null ? (float) $r['samsara_last_location_lat'] : null,
        'samsara_last_location_lng'    => $r['samsara_last_location_lng'] !== null ? (float) $r['samsara_last_location_lng'] : null,
        'samsara_last_location_address'=> $r['samsara_last_location_address'],
        'samsara_last_speed_kph'       => $r['samsara_last_speed_kph'] !== null ? (float) $r['samsara_last_speed_kph'] : null,
        'samsara_last_connected_at'    => $r['samsara_last_connected_at'],
        'samsara_last_synced_at'       => $r['samsara_last_synced_at'],
        'samsara_odometer_km'          => $r['samsara_odometer_km'] !== null ? (float) $r['samsara_odometer_km'] : null,
    ];

    $linked[] = $unit;
    if (isset($linkedByType[$entityType])) {
        $linkedByType[$entityType]++;
    }

    // ── Alert evaluation (linked units only) ────────────
    // Each rule emits at most one alert per unit so the alert
    // panel doesn't double-count noisy outliers.

    // Battery critical/low
    if ($unit['samsara_battery_pct'] !== null) {
        $batt = $unit['samsara_battery_pct'];
        if ($batt <= $BATTERY_CRITICAL_PCT) {
            $alerts[] = [
                'type'        => 'battery_critical',
                'severity'    => 'critical',
                'unit_id'     => $unit['id'],
                'unit_number' => $unit['unit_number'],
                'message'     => sprintf('Battery critical: %d%%', $batt),
            ];
            $alertCounts['battery_critical']++;
        } elseif ($batt <= $BATTERY_LOW_PCT) {
            $alerts[] = [
                'type'        => 'battery_low',
                'severity'    => 'warning',
                'unit_id'     => $unit['id'],
                'unit_number' => $unit['unit_number'],
                'message'     => sprintf('Battery low: %d%%', $batt),
            ];
            $alertCounts['battery_low']++;
        }
    }

    // Connection staleness — based on samsara_last_connected_at
    // (Samsara's "we last heard from the gateway" time), NOT
    // samsara_last_synced_at (FleetForge's last cron tick).
    if (!empty($unit['samsara_last_connected_at'])) {
        $lastConnTs = strtotime($unit['samsara_last_connected_at']);
        if ($lastConnTs !== false) {
            $hoursSince = ($now - $lastConnTs) / 3600;
            if ($hoursSince >= $NOT_CONN_HARD_HOURS) {
                $alerts[] = [
                    'type'        => 'not_connected_24h',
                    'severity'    => 'critical',
                    'unit_id'     => $unit['id'],
                    'unit_number' => $unit['unit_number'],
                    'message'     => sprintf('Offline for %d hrs', (int) $hoursSince),
                ];
                $alertCounts['not_connected_24h']++;
            } elseif ($hoursSince >= $NOT_CONN_WARN_HOURS) {
                $alerts[] = [
                    'type'        => 'not_connected_8h',
                    'severity'    => 'warning',
                    'unit_id'     => $unit['id'],
                    'unit_number' => $unit['unit_number'],
                    'message'     => sprintf('Offline for %d hrs', (int) $hoursSince),
                ];
                $alertCounts['not_connected_8h']++;
            }
        }
    }

    // No GPS fix at all — distinct from "stale GPS"; this is
    // the unit just never returned coordinates since linking.
    if ($unit['samsara_last_location_lat'] === null) {
        $alerts[] = [
            'type'        => 'no_gps',
            'severity'    => 'warning',
            'unit_id'     => $unit['id'],
            'unit_number' => $unit['unit_number'],
            'message'     => 'No GPS fix',
        ];
        $alertCounts['no_gps']++;
    }
}

// ── Online/offline counts for the header chips ──────────────
// "Online" = has a lat/lng AND was last connected within the
// 8-hour warning window. Anything else is treated as offline.
$onlineCount = 0;
$offlineCount = 0;
foreach ($linked as $u) {
    $isOnline = false;
    if ($u['samsara_last_location_lat'] !== null && !empty($u['samsara_last_connected_at'])) {
        $lastTs = strtotime($u['samsara_last_connected_at']);
        if ($lastTs !== false && ($now - $lastTs) < ($NOT_CONN_WARN_HOURS * 3600)) {
            $isOnline = true;
        }
    }
    if ($isOnline) $onlineCount++; else $offlineCount++;
}

json_success([
    'linked'   => $linked,
    'unlinked' => $unlinked,
    'alerts'   => $alerts,
    'stats'    => [
        'total'          => count($linked) + count($unlinked),
        'linked'         => count($linked),
        'unlinked'       => count($unlinked),
        'online'         => $onlineCount,
        'offline'        => $offlineCount,
        'linked_by_type' => $linkedByType,
        'alert_counts'   => $alertCounts,
    ],
]);
