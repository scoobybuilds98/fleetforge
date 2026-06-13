<?php
declare(strict_types=1);

/**
 * api/v1/samsara/sync.php
 *
 * Refresh live telemetry from Samsara on demand.
 *
 *   POST { equipment_unit_id: int }  → sync ONE unit (Sync Now button)
 *   POST {}                           → sync ALL linked units (dashboard refresh)
 *
 * POST-only: this endpoint writes telemetry columns + a location_history row,
 * so it must be CSRF-protected (api/bootstrap.php enforces the token on POST)
 * and gated on equipment:edit like every other Samsara mutating endpoint —
 * not reachable via a GET (<img src=…/sync>) or by a view-only role.
 *
 * Unlike cron/samsara_sync.php, this endpoint is called by a
 * human clicking a button, so it:
 *   • responds synchronously with the updated payload
 *   • never acquires a MySQL advisory lock (cron owns that lock;
 *     the two code paths update disjoint column sets only when
 *     the same unit is synced twice in a single second, which is
 *     cheap and safe — last write wins)
 *   • writes a light-touch audit row rather than the cron log
 *
 * For each unit synced we:
 *   1. Call SamsaraClient::getEntityStats($type, $id) to refresh
 *      live fields. The dispatcher hits /fleet/vehicles/stats for
 *      vehicles and /fleet/trailers/stats for trailers.    [SAMSARA-2]
 *   2. Update samsara_* live columns on equipment_units
 *   3. If the lat/lng moved since the previous sync, write a new
 *      row to samsara_location_history with the entity_type stamped
 *      so the trail only grows when the trackable actually moved.
 *   4. Stamp samsara_last_synced_at to now
 *
 * Permission: equipment:edit — the endpoint mutates equipment_units +
 * samsara_location_history, so it requires the same write permission as
 * link/unlink/import (was equipment:view, under-privileged for a mutation).
 *
 * @depends api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session SAMSARA-1, SAMSARA-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

use FleetForge\GPS\SamsaraClient;

$client = new SamsaraClient();

/**
 * Sync a single equipment unit from Samsara.
 *
 * Writes the live telemetry columns on equipment_units, and
 * appends a breadcrumb row to samsara_location_history iff the
 * lat/lng changed vs. the previously-stored values (so we don't
 * fill the table with identical rows for parked units).
 *
 * SAMSARA-2: dispatches via getEntityStats() so vehicles hit
 * /fleet/vehicles/stats and trailers hit /fleet/trailers/stats —
 * the wrong path returns HTTP 400 from Samsara.
 *
 * @param array $unit  Row from equipment_units (id, unit_number,
 *                     samsara_vehicle_id, samsara_entity_type,
 *                     samsara_last_location_lat, samsara_last_location_lng).
 * @param SamsaraClient $client
 * @return array  {ok, unit_id, unit_number, synced_at, stats}
 */
function ff_samsara_sync_one(array $unit, SamsaraClient $client): array
{
    $vehicleId  = (string) $unit['samsara_vehicle_id'];
    // Default to 'vehicle' for safety — pre-SAMSARA-2 rows will
    // already have 'vehicle' from the migration default but we
    // also handle missing-key paranoia in case the SELECT was
    // updated upstream and we were called from an older path.
    $entityType = (string) ($unit['samsara_entity_type'] ?? 'vehicle');
    $stats      = $client->getEntityStats($entityType, $vehicleId);
    $now        = date('Y-m-d H:i:s');

    if (empty($stats)) {
        return [
            'ok'          => false,
            'unit_id'     => (int) $unit['id'],
            'unit_number' => (string) $unit['unit_number'],
            'error'       => sprintf('Samsara returned no data for this %s.', $entityType),
        ];
    }

    $gps = $stats['gps'] ?? null;

    // Build only the LIVE telemetry column set — never touch the
    // static identifier columns from the link step (VIN, serial,
    // gateway, name), as those are an intentional snapshot.
    $update = [
        'samsara_battery_pct'           => $stats['battery_pct']      ?? null,
        'samsara_battery_charging'      => $stats['battery_charging'] ?? null,
        'samsara_power_source'          => $stats['power_source']     ?? null,
        'samsara_check_in_mode'         => $stats['check_in_mode']    ?? null,
        'samsara_last_location_lat'     => $gps['lat']     ?? null,
        'samsara_last_location_lng'     => $gps['lng']     ?? null,
        'samsara_last_location_address' => $gps['address'] ?? null,
        'samsara_last_speed_kph'        => $gps['speed_kph'] ?? null,
        'samsara_last_connected_at'     => isset($stats['last_connected_at'])
            ? date('Y-m-d H:i:s', strtotime((string) $stats['last_connected_at']))
            : null,
        'samsara_last_synced_at'        => $now,
        'samsara_odometer_km'           => $stats['odometer_km'] ?? null,
    ];

    // db_update SET clause uses positional ?, so the WHERE fragment
    // must too or PDO throws "mixed named and positional parameters".
    // [SAMSARA-2 bugfix — SAMSARA-1 used :id and would have failed.]
    db_update('equipment_units', $update, 'id = ?', [(int) $unit['id']]);

    // Append breadcrumb only if the location actually CHANGED.
    // WHY: parked trailers would otherwise rack up thousands of
    // identical rows, bloating the table and slowing the replay UI.
    if ($gps && isset($gps['lat'], $gps['lng'])) {
        $prevLat = isset($unit['samsara_last_location_lat']) ? (float) $unit['samsara_last_location_lat'] : null;
        $prevLng = isset($unit['samsara_last_location_lng']) ? (float) $unit['samsara_last_location_lng'] : null;
        $newLat  = (float) $gps['lat'];
        $newLng  = (float) $gps['lng'];

        // Compare as floats rounded to the DB's 7dp — prevents
        // rounding-loop inserts if we round here but the DB stores
        // a different precision. 7dp is ~1cm resolution, so a
        // bit-exact change is a real position change.
        $moved = $prevLat === null
              || $prevLng === null
              || round($prevLat, 7) !== round($newLat, 7)
              || round($prevLng, 7) !== round($newLng, 7);

        if ($moved) {
            db_insert('samsara_location_history', [
                'equipment_unit_id'   => (int) $unit['id'],
                'samsara_vehicle_id'  => $vehicleId,
                'samsara_entity_type' => $entityType,
                'latitude'            => $newLat,
                'longitude'           => $newLng,
                'speed_kph'           => $gps['speed_kph'] ?? null,
                'heading'             => $gps['heading']   ?? null,
                'address'             => $gps['address']   ?? null,
                'recorded_at'         => isset($gps['time'])
                    ? date('Y-m-d H:i:s', strtotime((string) $gps['time']))
                    : $now,
                'synced_at'           => $now,
            ]);
        }
    }

    return [
        'ok'          => true,
        'unit_id'     => (int) $unit['id'],
        'unit_number' => (string) $unit['unit_number'],
        'synced_at'   => $now,
        'stats'       => $stats,
    ];
}


// Route on payload, not HTTP method: an equipment_unit_id present → sync that
// one unit (Sync Now button); absent → sync all linked units (dashboard refresh).
$body   = json_body();
$unitId = clean_int($body['equipment_unit_id'] ?? null);

if ($unitId !== null && $unitId > 0) {
    // ── Single-unit sync (Sync Now button) ──────────────────
    $unit = db_row(
        "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type,
                samsara_last_location_lat, samsara_last_location_lng
           FROM equipment_units
          WHERE id = :id AND deleted_at IS NULL",
        [':id' => $unitId]
    );
    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }
    if (empty($unit['samsara_vehicle_id'])) {
        json_error('NOT_LINKED', 'This unit is not linked to Samsara.', 409);
    }

    $result = ff_samsara_sync_one($unit, $client);

    if (!$result['ok']) {
        json_error('SAMSARA_SYNC_FAILED', $result['error'] ?? 'Sync failed.', 502);
    }

    json_success($result);
}

// ── Sync ALL linked units (no equipment_unit_id in the body) ─
// Used by the Fleet Tracking dashboard's "Refresh now" button.
// Each unit is synced independently so one bad vehicle does not
// short-circuit the whole batch. Failures are collected per-unit
// and returned alongside the successes so the UI can surface them.
$linked = db_select(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type,
            samsara_last_location_lat, samsara_last_location_lng
       FROM equipment_units
      WHERE samsara_vehicle_id IS NOT NULL
        AND samsara_vehicle_id <> ''
        AND deleted_at IS NULL"
);

$synced = [];
$failed = [];
foreach ($linked as $unit) {
    $result = ff_samsara_sync_one($unit, $client);
    if ($result['ok']) {
        $synced[] = $result;
    } else {
        $failed[] = $result;
    }
}

json_success([
    'synced_count' => count($synced),
    'failed_count' => count($failed),
    'synced'       => $synced,
    'failed'       => $failed,
]);
