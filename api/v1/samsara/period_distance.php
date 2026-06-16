<?php
declare(strict_types=1);

/**
 * api/v1/samsara/period_distance.php
 *
 * GET — return distance travelled by a Samsara-linked equipment unit
 * over a caller-supplied time window.
 *
 * Query params:
 *   equipment_unit_id  int     required
 *   period_start       string  required  — any strtotime-parseable datetime (UTC assumed when no TZ)
 *   period_end         string  required  — maps to end-of-day when only a date is given (23:59:59)
 *   unit               string  optional  — 'km' (default) or 'miles'
 *
 * Return shape — always HTTP 200, never 5xx:
 *
 *   Success (Samsara returned data):
 *   {
 *     linked: true,
 *     distance:          string,   // bcmath decimal (e.g. "125.42") — NEVER float
 *     unit:              "km"|"miles",
 *     source:            "obd"|"gps",
 *     first_reading_at:  string,   // ISO 8601 from Samsara
 *     last_reading_at:   string,
 *     reading_count:     int,
 *     warnings:          string[], // e.g. ["sparse_readings","reading_outside_period"]
 *     queried_at:        string,
 *     samsara_vehicle_id: string,
 *     entity_type:       string
 *   }
 *
 *   No data (Samsara returned null — unit linked but no readings / API down):
 *   {
 *     linked: true,
 *     distance: null,
 *     unit: ...,
 *     reason: string,
 *     detail: string,
 *     queried_at: string,
 *     samsara_vehicle_id: string,
 *     entity_type: string
 *   }
 *
 *   Not linked (unit has no samsara_vehicle_id):
 *   { linked: false }
 *
 * Permission: equipment:view
 *
 * NOTE: S-SAMSARA-PERIOD-DISTANCE-AUTOFETCH reuses this endpoint as-is
 *       (do not rebuild it). getDistanceForPeriod is called directly on
 *       the real Samsara API — fixture_mode '1' routes through FixtureProvider
 *       inside that method (transparent).
 *
 * @method   GET
 * @auth     Session required
 * @session  S-UNIT-DISTANCE-SECTION
 * @depends  api/bootstrap.php, lib/GPS/SamsaraClient.php
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

use FleetForge\GPS\SamsaraClient;

// ── Inputs ──────────────────────────────────────────────────────────
$unitId      = clean_int($_GET['equipment_unit_id'] ?? null);
$periodStart = trim((string) ($_GET['period_start'] ?? ''));
$periodEnd   = trim((string) ($_GET['period_end']   ?? ''));
$unit        = in_array($_GET['unit'] ?? 'km', ['km', 'miles'], true)
               ? ($_GET['unit'] ?? 'km')
               : 'km';

if (!$unitId) {
    json_error('VALIDATION_ERROR', 'equipment_unit_id is required.', 422);
}
if ($periodStart === '') {
    json_error('VALIDATION_ERROR', 'period_start is required.', 422);
}
if ($periodEnd === '') {
    json_error('VALIDATION_ERROR', 'period_end is required.', 422);
}

// ── Map period_end to end-of-day when only a date is given ──────────
// If the caller supplies "2026-06-16" (no time part), treat it as
// "2026-06-16 23:59:59" so the final day's travel is included.
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
    $periodEnd .= ' 23:59:59';
}

$startTs = strtotime($periodStart);
$endTs   = strtotime($periodEnd);
if ($startTs === false || $startTs === 0) {
    json_error('VALIDATION_ERROR', 'period_start is not a valid datetime.', 422);
}
if ($endTs === false || $endTs === 0) {
    json_error('VALIDATION_ERROR', 'period_end is not a valid datetime.', 422);
}

// ── Resolve unit ─────────────────────────────────────────────────────
$eu = db_row(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type
       FROM equipment_units
      WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$eu) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

if (!$eu['samsara_vehicle_id']) {
    json_success(['linked' => false]);
}

// ── Call getDistanceForPeriod ─────────────────────────────────────────
$startUtc = new DateTimeImmutable('@' . $startTs, new DateTimeZone('UTC'));
$endUtc   = new DateTimeImmutable('@' . $endTs,   new DateTimeZone('UTC'));

$client = new SamsaraClient();
$result = $client->getDistanceForPeriod(
    $eu['samsara_vehicle_id'],
    $startUtc,
    $endUtc,
    $unit
);

// Merge provenance into the result so the caller never needs a second query.
$result['linked']             = true;
$result['samsara_vehicle_id'] = $eu['samsara_vehicle_id'];
$result['entity_type']        = $eu['samsara_entity_type'] ?? 'vehicle';

// Always HTTP 200 — distance===null carries reason+detail for soft-fail.
json_success($result);
