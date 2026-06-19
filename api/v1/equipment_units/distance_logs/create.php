<?php
declare(strict_types=1);

/**
 * api/v1/equipment_units/distance_logs/create.php
 *
 * POST — persist a distance-period result for an equipment unit.
 *
 * Body (JSON):
 *   equipment_unit_id  int     required
 *   period_start       string  required  — ISO 8601 or 'Y-m-d H:i:s'
 *   period_end         string  required
 *   distance           string  required  — decimal string (bcmath); editable before save
 *   unit               string  required  — 'km' | 'miles'
 *   source             string  required  — 'obd' | 'gps' | 'manual'
 *   reading_count      int     optional
 *   warnings           array   optional  — string array
 *   first_reading_at   string  optional
 *   last_reading_at    string  optional
 *   label              string  optional  — max 255 chars
 *   queried_at         string  optional
 *
 * Rules:
 *   - distance must be > 0 or == 0 but non-null (caller must never save null)
 *   - If the user edited the distance before saving, caller sets source='manual'
 *
 * Returns: { success: true, data: { id, ... } }
 *
 * Permission: equipment:edit
 *
 * @method   POST
 * @auth     Session required
 * @session  S-UNIT-DISTANCE-SECTION
 * @depends  api/bootstrap.php
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body = json_body();

$unitId       = clean_int($body['equipment_unit_id'] ?? null);
$periodStart  = trim((string) ($body['period_start']  ?? ''));
$periodEnd    = trim((string) ($body['period_end']    ?? ''));
$distance     = isset($body['distance']) ? trim((string) $body['distance']) : null;
$unit         = in_array($body['unit'] ?? '', ['km', 'miles'], true) ? $body['unit'] : null;
$source       = in_array($body['source'] ?? '', ['obd', 'gps', 'manual'], true) ? $body['source'] : null;
$readingCount = isset($body['reading_count']) ? clean_int($body['reading_count']) : null;
$warnings     = isset($body['warnings']) && is_array($body['warnings']) ? $body['warnings'] : null;
$firstAt      = isset($body['first_reading_at']) && $body['first_reading_at'] !== '' ? trim((string) $body['first_reading_at']) : null;
$lastAt       = isset($body['last_reading_at'])  && $body['last_reading_at']  !== '' ? trim((string) $body['last_reading_at'])  : null;
$label        = isset($body['label']) ? trim((string) $body['label']) : null;
$queriedAt    = isset($body['queried_at']) && $body['queried_at'] !== '' ? trim((string) $body['queried_at']) : null;

// ── Validate ─────────────────────────────────────────────────────────
$errors = [];

if (!$unitId) {
    $errors['equipment_unit_id'] = 'equipment_unit_id is required.';
}
if ($periodStart === '') {
    $errors['period_start'] = 'period_start is required.';
}
if ($periodEnd === '') {
    $errors['period_end'] = 'period_end is required.';
}
if ($distance === null || $distance === '') {
    $errors['distance'] = 'distance is required and must not be null.';
} elseif (!preg_match('/^\d+(\.\d+)?$/', (string) $distance)) {
    // E47: validate with a strict non-negative-decimal regex BEFORE any bcmath.
    // is_numeric() accepts scientific notation ("1e5"), which bccomp() then rejects
    // with a ValueError → uncaught 500. The regex confines $distance to a plain
    // non-negative decimal (also rejects negatives/signs), so downstream bcmath
    // is always safe and a bad value returns a clean 422.
    $errors['distance'] = 'distance must be a non-negative decimal number (e.g. 123.45).';
}
if (!$unit) {
    $errors['unit'] = "unit must be 'km' or 'miles'.";
}
if (!$source) {
    $errors['source'] = "source must be 'obd', 'gps', or 'manual'.";
}
if ($label !== null && strlen($label) > 255) {
    $errors['label'] = 'label must be 255 characters or fewer.';
}

if ($errors) {
    json_error('VALIDATION_ERROR', 'Validation failed.', 422, $errors);
}

// ── Resolve unit ─────────────────────────────────────────────────────
$eu = db_row(
    "SELECT id FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$eu) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// Normalise datetimes: strip any TZ suffix for DB storage (DATETIME col stores as UTC)
$normStart  = date('Y-m-d H:i:s', strtotime($periodStart));
$normEnd    = date('Y-m-d H:i:s', strtotime($periodEnd));
$normFirstAt = $firstAt ? date('Y-m-d H:i:s', strtotime($firstAt)) : null;
$normLastAt  = $lastAt  ? date('Y-m-d H:i:s', strtotime($lastAt))  : null;
$normQueried = $queriedAt ? date('Y-m-d H:i:s', strtotime($queriedAt)) : null;

$userId = current_user_id();

$id = db_insert('equipment_distance_logs', [
    'equipment_unit_id' => $unitId,
    'period_start'      => $normStart,
    'period_end'        => $normEnd,
    'distance'          => $distance,
    'unit'              => $unit,
    'source'            => $source,
    'reading_count'     => $readingCount,
    'warnings'          => $warnings !== null ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null,
    'first_reading_at'  => $normFirstAt,
    'last_reading_at'   => $normLastAt,
    'label'             => $label !== '' ? $label : null,
    'queried_at'        => $normQueried,
    'created_by'        => $userId,
    'created_at'        => date('Y-m-d H:i:s'),
]);

db_insert('audit_log', [
    'user_id'      => $userId,
    'user_name'    => current_user()['name'] ?? 'system',
    'action'       => 'create',
    'module'       => 'equipment',
    'entity_type'  => 'equipment_distance_log',
    'entity_id'    => $id,
    'entity_label' => "unit #{$unitId} {$distance} {$unit} [{$normStart} — {$normEnd}]",
    'notes'        => "Distance log saved. source={$source}" . ($label ? " label={$label}" : ''),
    'old_values'   => null,
    'new_values'   => json_encode(compact('distance', 'unit', 'source', 'label')),
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

$row = db_row(
    "SELECT edl.*, COALESCE(u.name,'System') AS created_by_name
       FROM equipment_distance_logs edl
       LEFT JOIN users u ON u.id = edl.created_by
      WHERE edl.id = ?",
    [$id]
);
if ($row && is_string($row['warnings'])) {
    $row['warnings'] = json_decode($row['warnings'], true) ?? [];
}
if ($row) {
    $row['distance'] = (string) $row['distance'];
}

json_success($row ?? ['id' => $id]);
