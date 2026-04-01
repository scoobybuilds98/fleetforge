<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/update.php
 *
 * Updates an equipment unit. Applies D19 optimistic locking.
 * Status changes are NOT handled here — use the dedicated status-change
 * endpoint (future session) which validates state machine transitions and
 * writes to equipment_status_log. This endpoint handles metadata/spec fields only.
 *
 * @method   POST
 * @body     JSON
 * @required id, updated_at
 * @optional unit_number, vin, year, gps_device_id, samsara_vehicle_url,
 *           tracking_provider, owner_company_id, yard_location,
 *           length_ft, height_ft, width_ft, weight_capacity_lbs,
 *           wheel_size, tire_size, axle_count, license_plate, license_state,
 *           cvi_expiry, registration_expiry, mvi_expiry, insurance_expiry,
 *           cvi_interval_days, mvi_interval_days, registration_interval_days,
 *           insurance_interval_days, mileage, notes, inspection_notes,
 *           internal_notes, tags, acquired_date, acquisition_cost
 * @auth     Session required; require_permission('equipment','edit')
 * @returns  200 { id, updated_at } or 409 STALE_DATA or 404 NOT_FOUND
 *
 * @depends  api/bootstrap.php
 * @decisions D19 (optimistic lock), D20 (not needed here — no financial state)
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.4
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body = json_body();

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        json_error('VALIDATION_ERROR', 'id is required.', 422);
if (!$updatedAt) json_error('VALIDATION_ERROR', 'updated_at is required for optimistic locking.', 422);

// ── Fetch existing ─────────────────────────────────────────────
$existing = db_row(
    "SELECT id, unit_number, status, updated_at FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// ── D19: Optimistic lock ───────────────────────────────────────
if ($existing['updated_at'] !== $updatedAt) {
    json_error('STALE_DATA', 'This unit was modified by another user. Refresh and try again.', 409);
}

// ── Collect updates ────────────────────────────────────────────
$updates = [];

if (isset($body['unit_number'])) {
    $un = clean_string($body['unit_number'], 100);
    if (!$un) json_error('VALIDATION_ERROR', 'unit_number cannot be empty.', 422);
    // FIX #36: filter soft-deleted units so deleted unit_numbers can be reused
    $conflict = db_row(
        "SELECT id FROM equipment_units WHERE unit_number = ? AND id != ? AND deleted_at IS NULL",
        [$un, $id]
    );
    if ($conflict) json_error('ALREADY_EXISTS', 'Another unit with this unit number already exists.', 409);
    $updates['unit_number'] = $un;
}

// String fields
$stringFields = [
    'vin'                => 50,
    'gps_device_id'      => 100,
    'samsara_vehicle_url'=> 500,
    'yard_location'      => 100,
    'wheel_size'         => 50,
    'tire_size'          => 50,
    'license_plate'      => 50,
    'license_state'      => 50,
    'notes'              => 5000,
    'inspection_notes'   => 5000,
    'internal_notes'     => 5000,
    'decommission_reason'=> 5000,
];
foreach ($stringFields as $field => $maxLen) {
    if (array_key_exists($field, $body)) {
        $updates[$field] = clean_string($body[$field], $maxLen);
    }
}

// Decimal fields
foreach (['length_ft','height_ft','width_ft','acquisition_cost'] as $f) {
    if (array_key_exists($f, $body)) {
        $updates[$f] = clean_decimal($body[$f]);
    }
}

// Int fields
foreach (['year','weight_capacity_lbs','axle_count','mileage',
          'cvi_interval_days','mvi_interval_days',
          'registration_interval_days','insurance_interval_days',
          'owner_company_id'] as $f) {
    if (array_key_exists($f, $body)) {
        $updates[$f] = clean_int($body[$f]);
    }
}

// Date fields
foreach (['cvi_expiry','registration_expiry','mvi_expiry','insurance_expiry',
          'acquired_date','decommissioned_date'] as $f) {
    if (array_key_exists($f, $body)) {
        $updates[$f] = clean_date($body[$f]);
    }
}

// Enum fields
if (isset($body['tracking_provider'])) {
    $v = clean_string($body['tracking_provider']);
    $updates['tracking_provider'] = in_array($v, ['samsara','none'], true) ? $v : 'none';
}
if (isset($body['ownership_type'])) {
    $v = clean_string($body['ownership_type']);
    if (!in_array($v, ['owned','leased','brokered'], true)) {
        json_error('VALIDATION_ERROR', 'ownership_type must be owned, leased, or brokered.', 422);
    }
    $updates['ownership_type'] = $v;
}

// Tags — JSON column
if (array_key_exists('tags', $body)) {
    $tagsInput = is_array($body['tags']) ? $body['tags'] : [];
    $tags      = array_values(array_filter($tagsInput, fn($t) => is_string($t) && $t !== ''));
    $updates['tags'] = !empty($tags) ? json_encode($tags) : null;
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No fields provided to update.', 422);
}

// Add updated_by
$updates['updated_by'] = current_user_id();

$userId = current_user_id();
$newUpdatedAt = null;

db_transaction(function () use (&$newUpdatedAt, $id, $updates, $userId, $existing): void {
    db_update('equipment_units', $updates, 'id = ?', [$id]);

    $newUpdatedAt = db_row(
        "SELECT updated_at FROM equipment_units WHERE id = ?", [$id]
    )['updated_at'];

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $id,
        'entity_label' => $existing['unit_number'],
        'old_values'   => json_encode(['unit_number' => $existing['unit_number']]),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'updated_at' => $newUpdatedAt]);
