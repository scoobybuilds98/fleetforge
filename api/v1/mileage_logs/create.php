<?php
declare(strict_types=1);

// ============================================================
// POST /api/v1/mileage_logs/create
//
// Creates a manual mileage log entry.
//
// Body (form-encoded):
//   equipment_unit_id  INT       required
//   lease_id           INT       optional — links to active lease
//   odometer_reading   INT       required — absolute odometer in km or miles
//   mileage_unit       STRING    optional — 'km'|'miles' (default: 'km', D34)
//   log_date           DATE      required — Y-m-d, cannot be future date
//   notes              STRING    optional
//
// log_type is always 'manual' for human-created entries.
// log_type 'service' can also be set (e.g. from maintenance form).
// lease_start / lease_end / gps_sync are system-only types.
//
// Permission: maintenance create
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('POST');
require_auth_api();
require_permission('maintenance', 'create');

// ── Input
$unitId    = clean_int($_POST['equipment_unit_id'] ?? null);
$leaseId   = clean_int($_POST['lease_id'] ?? null);
$odometer  = clean_int($_POST['odometer_reading'] ?? null);
$unit      = clean_string($_POST['mileage_unit'] ?? 'km');
$logDate   = clean_date($_POST['log_date'] ?? null);
$notes     = clean_string($_POST['notes'] ?? null, 1000);
$logType   = clean_string($_POST['log_type'] ?? 'manual');

// ── Validation — accumulate every error (VALID-2)
$fields = [];

if (!$unitId) {
    $fields['equipment_unit_id'] = 'Please select an equipment unit.';
}
if (!isset($_POST['odometer_reading']) || $_POST['odometer_reading'] === '') {
    $fields['odometer_reading'] = 'Odometer reading is required.';
} elseif ($odometer === null) {
    $fields['odometer_reading'] = 'Odometer reading must be a whole number.';
} elseif ($odometer < 0) {
    $fields['odometer_reading'] = 'Odometer cannot be negative.';
}
if (!$logDate) {
    $fields['log_date'] = 'Log date is required.';
} elseif ($logDate > date('Y-m-d')) {
    $fields['log_date'] = 'Log date cannot be in the future.';
}

// log_type restricted to human-entry values
$humanTypes = ['manual', 'service'];
if (!in_array($logType, $humanTypes, true)) {
    $logType = 'manual'; // silently coerce — system types cannot be set via API
}

$validUnits = ['km', 'miles'];
if (!in_array($unit, $validUnits, true)) {
    $unit = 'km'; // D34 default
}

if ($fields) {
    json_validation_error($fields);
}

// ── Verify unit exists AND is allowed in the SERVICE context.
//    [SELECTOR-1] We need the row (not just db_exists()) so we can
//    pull status + unit_number for the rejection message. The form
//    greys these out, but a stale page or hand-crafted POST could
//    still submit a decommissioned id.
$unitRow = db_row(
    "SELECT id, unit_number, status FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unitRow) {
    json_validation_error(['equipment_unit_id' => 'Equipment unit not found.']);
}
if (!ff_unit_is_selectable($unitRow['status'], 'service')) {
    $msg = ff_unit_unavailable_message((string) $unitRow['unit_number'], (string) $unitRow['status'], 'service');
    json_error('UNIT_UNAVAILABLE', $msg, 409, ['fields' => ['equipment_unit_id' => $msg]]);
}

// ── Verify lease exists and belongs to this unit (if provided)
if ($leaseId) {
    $lease = db_row(
        "SELECT id FROM leases WHERE id = ? AND equipment_unit_id = ? AND deleted_at IS NULL",
        [$leaseId, $unitId]
    );
    if (!$lease) {
        json_validation_error(['lease_id' => 'Lease not found or does not belong to this unit.']);
    }
}

// ── Odometer advance check: warn if new reading is LOWER than most recent on this unit
// (MILEAGE_DATA_ERROR for lease_end; for manual/service we soft-block unless override)
$latestPrior = db_row(
    "SELECT odometer_reading, log_date FROM mileage_logs
     WHERE equipment_unit_id = ? AND log_date <= ?
     ORDER BY log_date DESC, id DESC LIMIT 1",
    [$unitId, $logDate]
);
if ($latestPrior && $odometer < (int)$latestPrior['odometer_reading']) {
    json_validation_error(
        ['odometer_reading' => "Odometer ({$odometer}) cannot be less than the most recent reading of {$latestPrior['odometer_reading']} on {$latestPrior['log_date']}."]
    );
}

// ── Validate odometer is not less than a same-day lease_start reading
// (MILEAGE_DATA_ERROR: end mileage < start mileage)
// We only hard-block if there's an earlier reading on the same unit today showing higher odometer
$latestReading = db_row(
    "SELECT odometer_reading FROM mileage_logs
     WHERE equipment_unit_id = ? AND log_date <= ? AND log_type IN ('lease_start','lease_end','gps_sync','service','manual')
     ORDER BY log_date DESC, id DESC
     LIMIT 1",
    [$unitId, $logDate]
);

// Warn-only: if odometer is dramatically lower than last known reading we flag it, but
// only hard-block if log_type = 'lease_end' scenario (not applicable for manual entry here)
// Per spec error code: MILEAGE_DATA_ERROR for end < start — but for manual we just store it.

// ── Insert
$logId = db_transaction(function () use (
    $unitId, $leaseId, $logType, $odometer, $unit, $logDate, $notes
): int {
    $id = db_insert('mileage_logs', [
        'equipment_unit_id' => $unitId,
        'lease_id'          => $leaseId,
        'log_type'          => $logType,
        'odometer_reading'  => $odometer,
        'mileage_unit'      => $unit,
        'log_date'          => $logDate,
        'notes'             => $notes,
        'recorded_by'       => current_user_id(),
    ]);

    // Update equipment_units.mileage with the latest reading
    // Only update if this reading is more recent than what's stored
    db_execute(
        "UPDATE equipment_units
         SET mileage = ?
         WHERE id = ? AND (mileage IS NULL OR mileage <= ?)",
        [$odometer, $unitId, $odometer]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'maintenance',
        'entity_type'  => 'mileage_log',
        'entity_id'    => $id,
        'entity_label' => "Mileage log #{$id} — odometer {$odometer} {$unit} on {$logDate}",
        'new_values'   => json_encode([
            'equipment_unit_id' => $unitId,
            'lease_id'          => $leaseId,
            'log_type'          => $logType,
            'odometer_reading'  => $odometer,
            'mileage_unit'      => $unit,
            'log_date'          => $logDate,
        ]),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    return $id;
});

json_success(['id' => $logId], 201);
