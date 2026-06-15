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
$odometer    = isset($_POST['odometer_reading']) && $_POST['odometer_reading'] !== ''
                    ? clean_int($_POST['odometer_reading']) : null;
$unit        = isset($_POST['mileage_unit']) && $_POST['mileage_unit'] !== ''
                    ? clean_string($_POST['mileage_unit']) : null;
$logDate     = clean_date($_POST['log_date'] ?? null);
$notes       = isset($_POST['notes']) ? clean_string($_POST['notes'], 1000) : null;

// ── VALID-2: accumulate header errors first
$fields = [];
if (!$id) {
    $fields['id'] = 'Mileage log ID is required.';
}
if (!$lockAt) {
    $fields['created_at'] = 'Optimistic lock token is required.';
}
if ($fields) {
    json_validation_error($fields);
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
    json_error('IMMUTABLE_RECORD',
        "Log type '{$log['log_type']}' is system-generated and cannot be edited.", 422,
        ['fields' => ['id' => "Cannot edit a {$log['log_type']} mileage log — these are system-generated."]]);
}

// ── Optimistic lock: compare created_at (D19 adapted for no updated_at), via the
//    shared gate so the app-wide FF_OPTIMISTIC_LOCKING flag governs it (disabled
//    by default — last-write-wins). $lockAt is required (validated above).
if (!optimistic_lock_matches($lockAt, $log['created_at'])) {
    json_error('STALE_DATA',
        'This mileage log was modified by another user. Refresh and try again.', 409,
        ['fields' => ['created_at' => 'This mileage log was modified by another user. Refresh and try again.']]);
}

// ── Build update set (validate all then short-circuit)
$updates      = [];
$updateParams = [];

if ($odometer !== null && $odometer < 0) {
    $fields['odometer_reading'] = 'Odometer cannot be negative.';
} elseif ($odometer !== null) {
    $updates[]      = 'odometer_reading = ?';
    $updateParams[] = $odometer;
}

if ($unit !== null) {
    $validUnits = ['km', 'miles'];
    if (!in_array($unit, $validUnits, true)) {
        $fields['mileage_unit'] = "Please select a valid mileage unit ('km' or 'miles').";
    } else {
        $updates[]      = 'mileage_unit = ?';
        $updateParams[] = $unit;
    }
}

if ($logDate !== null) {
    if ($logDate > date('Y-m-d')) {
        $fields['log_date'] = 'Log date cannot be in the future.';
    } else {
        $updates[]      = 'log_date = ?';
        $updateParams[] = $logDate;
    }
}

if ($notes !== null) {
    $updates[]      = 'notes = ?';
    $updateParams[] = $notes;
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error(['_general' => 'No updatable fields provided.'], 'No updatable fields provided.');
}

// Advance-check: reject if new odometer is less than the most recent prior reading
if ($odometer !== null) {
    $effectiveDate = $logDate ?? $log['log_date'];
    $prior = db_row(
        "SELECT odometer_reading, log_date FROM mileage_logs
         WHERE equipment_unit_id = ? AND id <> ? AND log_date <= ?
         ORDER BY log_date DESC, id DESC LIMIT 1",
        [$log['equipment_unit_id'], $id, $effectiveDate]
    );
    if ($prior && $odometer < (int)$prior['odometer_reading']) {
        json_validation_error(
            ['odometer_reading' => "Odometer ({$odometer}) cannot be less than the most recent reading of {$prior['odometer_reading']} on {$prior['log_date']}."]
        );
    }
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
