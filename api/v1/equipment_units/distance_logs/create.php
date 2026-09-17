<?php
declare(strict_types=1);

/**
 * api/v1/equipment_units/distance_logs/create.php
 *
 * POST — persist a distance-period result for an equipment unit.
 *
 * Body (JSON):
 *   equipment_unit_id  int     required
 *   period_start       string  required  — ISO 8601 or naive datetime (naive = company-local
 *                                           wall time, same rule as samsara/period_distance); stored UTC
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

// ── Normalise datetimes to UTC 'Y-m-d H:i:s' ────────────────────────
// S-UTC-STAMPS: every DATETIME here is a UTC instant. The old
// date('Y-m-d H:i:s', strtotime(...)) stored PHP-local (Pacific) wall time.
//
// period_start / period_end MUST be the same instants the distance was
// queried for, so they use api/v1/samsara/period_distance.php's exact rules
// (S-GPS-LOCAL-WINDOW): a date-only pair is a business-day window
// (BusinessDayWindow::toUtc — local midnight → next local midnight); any
// other naive value (the equipment page's datetime-local input) is company-
// local wall time; an explicit offset/Z always wins.
$utcTz  = new DateTimeZone('UTC');
$dateRe = '/^\d{4}-\d{2}-\d{2}$/';
try {
    if (preg_match($dateRe, $periodStart) && preg_match($dateRe, $periodEnd)) {
        $window    = \FleetForge\GPS\BusinessDayWindow::toUtc($periodStart, $periodEnd);
        $normStart = $window['start']->setTimezone($utcTz)->format('Y-m-d H:i:s');
        $normEnd   = $window['end']->setTimezone($utcTz)->format('Y-m-d H:i:s');
    } else {
        $bizTz = \FleetForge\GPS\BusinessDayWindow::timezone();
        // A lone date on the end side still means that whole local day (period_distance twin).
        $endIn     = preg_match($dateRe, $periodEnd) ? $periodEnd . ' 23:59:59' : $periodEnd;
        $normStart = (new DateTimeImmutable($periodStart, $bizTz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
        $normEnd   = (new DateTimeImmutable($endIn, $bizTz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
    }
} catch (\Throwable) {
    json_error('VALIDATION_ERROR', 'period_start / period_end must be valid dates or datetimes.', 422);
}

/**
 * Normalise an optional Samsara reading/query timestamp to UTC.
 * These arrive as ISO-8601 'Z' strings from period_distance; a bare value is
 * taken as UTC (the API never emits local wall time for them).
 *
 * @param  string|null $v raw client value
 * @return string|null UTC 'Y-m-d H:i:s', or null when absent/unparseable
 */
$toUtc = static function (?string $v) use ($utcTz): ?string {
    if ($v === null || $v === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($v, $utcTz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
    } catch (\Throwable) {
        return null;
    }
};
$normFirstAt = $toUtc($firstAt);
$normLastAt  = $toUtc($lastAt);
$normQueried = $toUtc($queriedAt);

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
    'created_at'        => ff_now_utc(), // S-UTC-STAMPS: UTC like the column DEFAULT
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
