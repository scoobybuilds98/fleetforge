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
 *   period_start       string  required  — datetime; a naive value (no offset) is read as
 *                                           company-local wall time (settings.company.timezone)
 *   period_end         string  required  — datetime, same rule. When BOTH bounds are plain
 *                                           'Y-m-d' dates the window is the whole local days
 *                                           [start 00:00, end+1 00:00) via BusinessDayWindow
 *                                           — the exact window invoices bill (S-GPS-LOCAL-WINDOW)
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
 * @depends  api/bootstrap.php, lib/GPS/SamsaraClient.php, lib/GPS/BusinessDayWindow.php
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

use FleetForge\GPS\BusinessDayWindow;
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

// ── Build the UTC window ──────────────────────────────────────────────
// S-GPS-LOCAL-WINDOW: date-only pairs are business days and go through the
// same BusinessDayWindow conversion as InvoiceGenerator, so the distance shown
// here for "Sep 1 → Sep 30" matches what the September invoice bills. Values
// with a time part (the equipment page sends datetime-local wall time) are
// parsed in the business timezone rather than PHP's process default, so an
// operator whose company.timezone differs from APP_TIMEZONE still gets their
// own wall clock. An explicit offset in the string always wins.
$dateOnly = '/^\d{4}-\d{2}-\d{2}$/';
try {
    if (preg_match($dateOnly, $periodStart) && preg_match($dateOnly, $periodEnd)) {
        $window   = BusinessDayWindow::toUtc($periodStart, $periodEnd);
        $startUtc = $window['start'];
        $endUtc   = $window['end'];
    } else {
        $bizTz = BusinessDayWindow::timezone();
        $utc   = new DateTimeZone('UTC');
        // A lone date on one side still means that whole local day.
        if (preg_match($dateOnly, $periodEnd)) {
            $periodEnd .= ' 23:59:59';
        }
        $startUtc = (new DateTimeImmutable($periodStart, $bizTz))->setTimezone($utc);
        $endUtc   = (new DateTimeImmutable($periodEnd,   $bizTz))->setTimezone($utc);
    }
} catch (\Throwable $e) {
    json_error('VALIDATION_ERROR', 'period_start / period_end must be valid dates or datetimes.', 422);
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
$client = new SamsaraClient();
$result = $client->getDistanceForPeriod(
    $eu['samsara_vehicle_id'],
    $startUtc,
    $endUtc,
    $unit,
    $eu['samsara_entity_type'] ?? 'vehicle'
);

// Merge provenance into the result so the caller never needs a second query.
$result['linked']             = true;
$result['samsara_vehicle_id'] = $eu['samsara_vehicle_id'];
$result['entity_type']        = $eu['samsara_entity_type'] ?? 'vehicle';

// Always HTTP 200 — distance===null carries reason+detail for soft-fail.
json_success($result);
