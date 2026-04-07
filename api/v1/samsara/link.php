<?php
declare(strict_types=1);

/**
 * api/v1/samsara/link.php
 *
 * POST → Map a FleetForge equipment unit to a Samsara TRACKABLE
 * (vehicle OR trailer) and pull every available data point in one shot.
 *
 * Request body:
 *   {
 *     equipment_unit_id:   int,
 *     samsara_vehicle_id:  string,                  // ID is opaque — works for both types
 *     samsara_entity_type: "vehicle"|"trailer"      // SAMSARA-2; defaults to "vehicle"
 *   }
 *
 * Flow:
 *   1. Validate the unit exists and is not soft-deleted.
 *   2. Validate entity_type is one of {vehicle, trailer}; default
 *      'vehicle' so SAMSARA-1 callers (which never sent the field)
 *      keep working unchanged.
 *   3. Validate the Samsara trackable is not already linked to a
 *      different unit (race-safe via the second SELECT below —
 *      the UI grays it out, but never trust the client).
 *   4. Call SamsaraClient::pingEntity($type, $id) so vehicles hit
 *      /fleet/vehicles + /fleet/vehicles/stats and trailers hit
 *      /fleet/trailers + /fleet/trailers/stats. The two paths return
 *      the same normalized shape, but trailer stats are always null
 *      for the OBD-only fields (battery, power, fuel, etc.).
 *   5. Persist all 17 samsara_* columns (16 from SAMSARA-1 + the new
 *      samsara_entity_type from SAMSARA-2). Static fields are a
 *      snapshot taken at link time and are never re-synced (we don't
 *      chase Samsara renames — see column comments in SAMSARA-1_schema).
 *   6. If the ping returned location data, also write the first row
 *      of breadcrumb history so the breadcrumb trail starts from the
 *      moment of linking, not the moment of the next cron tick. The
 *      seed row carries the entity_type so historical reports always
 *      know what kind of trackable wrote each breadcrumb.
 *   7. Audit log the link with both old and new mapping values.
 *
 * Returns: { unit_id, samsara_vehicle_id, samsara_vehicle_name,
 *            samsara_entity_type, synced_at }
 *
 * Permission: equipment:edit
 *
 * @depends api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session SAMSARA-1, SAMSARA-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

use FleetForge\GPS\SamsaraClient;

// ── Input validation ────────────────────────────────────────
// SAMSARA-2: entity_type tells us which Samsara API path to use.
// Defaults to 'vehicle' so any old client that POSTs without the
// field continues to work — same behaviour as SAMSARA-1.
$body             = json_body();
$unitId           = clean_int($body['equipment_unit_id'] ?? null);
$samsaraVehicleId = clean_string($body['samsara_vehicle_id'] ?? null, 100);
$entityType       = strtolower((string) ($body['samsara_entity_type'] ?? 'vehicle'));

$errors = [];
if ($unitId === null || $unitId <= 0) {
    $errors['equipment_unit_id'] = 'Equipment unit is required.';
}
if ($samsaraVehicleId === null || $samsaraVehicleId === '') {
    $errors['samsara_vehicle_id'] = 'Samsara trackable is required.';
}
if (!in_array($entityType, ['vehicle', 'trailer'], true)) {
    // Reject unknown values explicitly rather than silently coercing
    // to 'vehicle' — a typo here could quietly route a trailer
    // through the wrong API path and store empty stats forever.
    $errors['samsara_entity_type'] = 'Entity type must be "vehicle" or "trailer".';
}
if (!empty($errors)) {
    json_validation_error($errors);
}

// ── Verify the unit exists and is not deleted ───────────────
$unit = db_row(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type
       FROM equipment_units
      WHERE id = :id AND deleted_at IS NULL",
    [':id' => $unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// ── Race-safe re-check: is this Samsara trackable linked elsewhere? ──
// The UI grays out already-linked entries in its dropdown, but a
// concurrent link from another browser tab could have grabbed this
// one between page load and submit. Reject explicitly with the
// other unit's name so the user understands the conflict.
$conflict = db_row(
    "SELECT id, unit_number
       FROM equipment_units
      WHERE samsara_vehicle_id = :sid
        AND id <> :uid
        AND deleted_at IS NULL
      LIMIT 1",
    [':sid' => $samsaraVehicleId, ':uid' => $unitId]
);
if ($conflict) {
    json_error(
        'ALREADY_LINKED',
        sprintf(
            'This Samsara trackable is already linked to unit %s.',
            $conflict['unit_number']
        ),
        409
    );
}

// ── Pull full trackable profile from Samsara ────────────────
// pingEntity() dispatches to pingVehicle() or pingTrailer() based
// on $entityType so we hit the correct API path. Both helpers
// return the same normalized shape; trailer responses just have
// the OBD-only fields (battery, power, fuel) set to null.
$client = new SamsaraClient();
$ping   = $client->pingEntity($entityType, $samsaraVehicleId);

if (empty($ping)) {
    json_error(
        'SAMSARA_VEHICLE_NOT_FOUND',
        sprintf(
            'Samsara could not return data for that %s. The API key may be wrong or the entity may have been removed.',
            $entityType
        ),
        502
    );
}

$stats = $ping['stats'] ?? [];
$gps   = $stats['gps']  ?? null;
$now   = date('Y-m-d H:i:s');

// ── Build the column update set ─────────────────────────────
// Static fields (name, vin, serial, gateway, entity_type) are
// snapshotted ONCE here at link time. The cron only refreshes the
// live telemetry fields below — see cron/samsara_sync.php.
//
// SAMSARA-2: For trailers, the OBD-only fields will be null because
// the trailer stats endpoint only returns gps + gpsOdometerMeters —
// see SamsaraClient::normalizeTrailerStats().
$update = [
    'samsara_vehicle_id'           => $samsaraVehicleId,
    'samsara_entity_type'          => $entityType,
    'samsara_vehicle_name'         => $ping['name']          ?? null,
    'samsara_vin'                  => $ping['vin']           ?? null,
    'samsara_serial_number'        => $ping['serial_number'] ?? null,
    'samsara_gateway_id'           => $ping['gateway_id']    ?? null,
    'samsara_battery_pct'          => $stats['battery_pct']        ?? null,
    'samsara_battery_charging'     => $stats['battery_charging']   ?? null,
    'samsara_power_source'         => $stats['power_source']       ?? null,
    'samsara_check_in_mode'        => $stats['check_in_mode']      ?? null,
    'samsara_last_location_lat'    => $gps['lat']     ?? null,
    'samsara_last_location_lng'    => $gps['lng']     ?? null,
    'samsara_last_location_address'=> $gps['address'] ?? null,
    'samsara_last_speed_kph'       => $gps['speed_kph'] ?? null,
    'samsara_last_connected_at'    => isset($stats['last_connected_at'])
        ? date('Y-m-d H:i:s', strtotime((string) $stats['last_connected_at']))
        : null,
    'samsara_last_synced_at'       => $now,
    'samsara_odometer_km'          => $stats['odometer_km'] ?? null,
];

// ── Persist + write history + audit_log inside one transaction ──
// All-or-nothing: if any step fails, the unit stays unlinked
// rather than half-linked with stale data. db_transaction()
// rolls back on any thrown exception.
db_transaction(static function () use ($unitId, $update, $gps, $samsaraVehicleId, $entityType, $now, $unit) {
    // db_update sanitizes column names and uses positional ? for the
    // SET clause; the WHERE fragment must use positional ? too or PDO
    // throws "mixed named and positional parameters". [SAMSARA-2 bugfix]
    db_update('equipment_units', $update, 'id = ?', [$unitId]);

    // Seed the breadcrumb trail with the first known position so the
    // location history isn't empty until the next cron tick.
    // SAMSARA-2: stamp entity_type so reports built off the history
    // table can tell vehicle pings apart from trailer pings even if
    // the unit is later re-typed or unlinked + re-linked differently.
    if ($gps && isset($gps['lat'], $gps['lng'])) {
        db_insert('samsara_location_history', [
            'equipment_unit_id'   => $unitId,
            'samsara_vehicle_id'  => $samsaraVehicleId,
            'samsara_entity_type' => $entityType,
            'latitude'            => $gps['lat'],
            'longitude'           => $gps['lng'],
            'speed_kph'           => $gps['speed_kph'] ?? null,
            'heading'             => $gps['heading']   ?? null,
            'address'             => $gps['address']   ?? null,
            'recorded_at'         => isset($gps['time'])
                ? date('Y-m-d H:i:s', strtotime((string) $gps['time']))
                : $now,
            'synced_at'           => $now,
        ]);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $unitId,
        'entity_label' => 'Unit ' . $unit['unit_number'],
        'old_values'   => json_encode([
            'samsara_vehicle_id'  => $unit['samsara_vehicle_id'],
            'samsara_entity_type' => $unit['samsara_entity_type'] ?? null,
        ]),
        'new_values'   => json_encode([
            'samsara_vehicle_id'  => $samsaraVehicleId,
            'samsara_entity_type' => $entityType,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR']     ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'notes'        => sprintf(
            'Linked to Samsara %s: %s',
            $entityType,
            $update['samsara_vehicle_name'] ?? $samsaraVehicleId
        ),
    ]);
});

json_success([
    'unit_id'              => $unitId,
    'samsara_vehicle_id'   => $samsaraVehicleId,
    'samsara_entity_type'  => $entityType,
    'samsara_vehicle_name' => $update['samsara_vehicle_name'],
    'synced_at'            => $now,
]);
