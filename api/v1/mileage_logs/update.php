<?php
declare(strict_types=1);

// ============================================================
// POST /api/v1/mileage_logs/update
//
// Updates an existing mileage log entry.
//
// Only log_type = 'manual' or 'service' are editable.
// log_type = 'gps_sync', 'lease_start', 'lease_end' →
//   422 IMMUTABLE_RECORD (system-generated, billing-critical)
//
// Optimistic lock: client must submit created_at from last GET.
// mileage_logs has no updated_at — created_at is immutable
// and serves as the lock field (D19 pattern adapted).
//
// Body (form-encoded):
//   id               INT       required
//   created_at       DATETIME  required — optimistic lock value
//   odometer_reading INT       optional
//   mileage_unit     STRING    optional — 'km'|'miles'
//   log_date         DATE      optional — Y-m-d, cannot be future
//   notes            STRING    optional
//
// Permission: maintenance edit
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// ── Input
$id          = clean_int($_POST['id'] ?? null);
$lockAt      = clean_string($_POST['created_at'] ?? null);
$odometer    = isset($_POST['odometer_reading']) ? clean_int($_POST['odometer_reading']) : null;
$unit        = clean_string($_POST['mileage_unit'] ?? null);
$logDate     = clean_date($_POST['log_date'] ?? null);
$notes       = isset($_POST['notes']) ? clean_string($_POST['notes'], 1000) : null;

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
if (!$lockAt) {
    json_error('MISSING_REQUIRED', 'created_at (optimistic lock) is required.', 422);
}

// ── Load existing record
$log = db_row(
    "SELECT * FROM mileage_logs WHERE id = ?",
    [$id]
);

if (!$log) {
    json_error('NOT_FOUND', 'Mileage log not found.', 404);
}

// ── Block system-generated types
$immutableTypes = ['gps_sync', 'lease_start', 'lease_end'];
if (in_array($log['log_type'], $immutableTypes, true)) {
    json_error('IMMUTABLE_RECORD', "Log type '{$log['log_type']}' is system-generated and cannot be edited.", 422);
}

// ── Optimistic lock: compare created_at (D19 adapted for no updated_at)
if ($log['created_at'] !== $lockAt) {
    json_error('STALE_DATA', 'Record was modified by another process. Refresh and try again.', 409);
}

// ── Build update set
$updates = [];
$updateParams = [];

if ($odometer !== null) {
    if ($odometer < 0) {
        json_error('VALIDATION_ERROR', 'Odometer reading must be non-negative.', 422);
    }
    $updates[]      = 'odometer_reading = ?';
    $updateParams[] = $odometer;
}

if ($unit !== null) {
    $validUnits = ['km', 'miles'];
    if (!in_array($unit, $validUnits, true)) {
        json_error('VALIDATION_ERROR', "Invalid mileage_unit. Must be 'km' or 'miles'.", 422);
    }
    $updates[]      = 'mileage_unit = ?';
    $updateParams[] = $unit;
}

if ($logDate !== null) {
    if ($logDate > date('Y-m-d')) {
        json_error('VALIDATION_ERROR', 'Log date cannot be in the future.', 422);
    }
    $updates[]      = 'log_date = ?';
    $updateParams[] = $logDate;
}

if ($notes !== null) {
    $updates[]      = 'notes = ?';
    $updateParams[] = $notes;
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No fields to update.', 422);
}

// ── Execute update
$updateParams[] = $id;

db_transaction(function () use ($updates, $updateParams, $id, $log, $odometer) {
    db_execute(
        "UPDATE mileage_logs SET " . implode(', ', $updates) . " WHERE id = ?",
        $updateParams
    );

    // Keep equipment_units.mileage in sync if odometer was changed
    if ($odometer !== null) {
        db_execute(
            "UPDATE equipment_units
             SET mileage = ?
             WHERE id = ? AND (mileage IS NULL OR mileage <= ?)",
            [$odometer, $log['equipment_unit_id'], $odometer]
        );
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'mileage_log',
        'entity_id'    => $id,
        'entity_label' => "Mileage log #{$id} updated",
        'old_values'   => json_encode([
            'odometer_reading' => $log['odometer_reading'],
            'mileage_unit'     => $log['mileage_unit'],
            'log_date'         => $log['log_date'],
            'notes'            => $log['notes'],
        ]),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
