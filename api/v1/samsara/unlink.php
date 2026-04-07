<?php
declare(strict_types=1);

/**
 * api/v1/samsara/unlink.php
 *
 * POST → Remove the Samsara mapping from an equipment unit.
 *
 * Request body:
 *   { equipment_unit_id: int }
 *
 * Clears every nullable samsara_* column on the unit so the show
 * page drops back into the "unmapped" state. The samsara_entity_type
 * column is NOT NULL with a 'vehicle' default — we reset it to the
 * default rather than nulling it so a future re-link of the same
 * unit starts from a clean slate. [SAMSARA-2]
 *
 * Does NOT delete the breadcrumb history rows — those are kept for
 * historical reporting and will be cascade-deleted only if the unit
 * itself is hard-deleted (see the FK CONSTRAINT fk_samsara_loc_unit
 * in SAMSARA-1_schema.sql). The history rows still carry their
 * original samsara_entity_type so reports remain accurate even after
 * the unit is unlinked.
 *
 * Audit log captures the old vehicle id and entity type so we can
 * see in history which unit was mapped to which trackable and when.
 *
 * Permission: equipment:edit
 *
 * @depends api/bootstrap.php
 * @session SAMSARA-1, SAMSARA-2
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

// ── Input validation ────────────────────────────────────────
$body   = json_body();
$unitId = clean_int($body['equipment_unit_id'] ?? null);

if ($unitId === null || $unitId <= 0) {
    json_validation_error(['equipment_unit_id' => 'Equipment unit is required.']);
}

// ── Load the unit and verify it is currently linked ─────────
// Hitting unlink on an already-unlinked unit is an error, not a
// no-op — the UI should never allow it, so if it happens the
// client-side state is stale and we want the user to refresh.
$unit = db_row(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type, samsara_vehicle_name
       FROM equipment_units
      WHERE id = :id AND deleted_at IS NULL",
    [':id' => $unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}
if (empty($unit['samsara_vehicle_id'])) {
    json_error('NOT_LINKED', 'This unit is not currently linked to Samsara.', 409);
}

// ── Clear every samsara_* column on this unit ───────────────
// Using an explicit null-set rather than a wildcard UPDATE so
// nothing ever lingers, and so a future column addition forces
// a conscious decision about whether unlink should clear it too.
//
// SAMSARA-2: samsara_entity_type is NOT NULL DEFAULT 'vehicle' so
// we reset to the default rather than nulling it. A null assignment
// would throw an SQL error from the column constraint.
$clear = [
    'samsara_vehicle_id'            => null,
    'samsara_entity_type'           => 'vehicle',
    'samsara_vehicle_name'          => null,
    'samsara_vin'                   => null,
    'samsara_serial_number'         => null,
    'samsara_gateway_id'            => null,
    'samsara_battery_pct'           => null,
    'samsara_battery_charging'      => null,
    'samsara_power_source'          => null,
    'samsara_check_in_mode'         => null,
    'samsara_last_location_lat'     => null,
    'samsara_last_location_lng'     => null,
    'samsara_last_location_address' => null,
    'samsara_last_speed_kph'        => null,
    'samsara_last_connected_at'     => null,
    'samsara_last_synced_at'        => null,
    'samsara_odometer_km'           => null,
];

db_transaction(static function () use ($unitId, $clear, $unit) {
    // db_update SET clause uses positional ?, so the WHERE fragment
    // must too or PDO throws "mixed named and positional parameters".
    // [SAMSARA-2 bugfix — SAMSARA-1 used :id and would have failed.]
    db_update('equipment_units', $clear, 'id = ?', [$unitId]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $unitId,
        'entity_label' => 'Unit ' . $unit['unit_number'],
        'old_values'   => json_encode([
            'samsara_vehicle_id'   => $unit['samsara_vehicle_id'],
            'samsara_entity_type'  => $unit['samsara_entity_type'] ?? null,
            'samsara_vehicle_name' => $unit['samsara_vehicle_name'],
        ]),
        'new_values'   => json_encode([
            'samsara_vehicle_id'  => null,
            'samsara_entity_type' => 'vehicle',
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR']     ?? '127.0.0.1',
        'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'notes'        => sprintf(
            'Unlinked from Samsara %s: %s',
            $unit['samsara_entity_type'] ?? 'vehicle',
            $unit['samsara_vehicle_name'] ?? $unit['samsara_vehicle_id']
        ),
    ]);
});

json_success(['unit_id' => $unitId, 'unlinked' => true]);
