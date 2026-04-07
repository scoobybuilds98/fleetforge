<?php
declare(strict_types=1);

/**
 * api/v1/samsara/current_odometer.php
 *
 * Returns the current live odometer reading from Samsara for a given
 * FleetForge equipment unit. Makes a real HTTP call to Samsara every
 * time (NOT cached) so the value reflects right-now mileage when
 * capturing a starting odometer at lease-start or a current odometer
 * at invoice time.
 *
 * Response shapes:
 *   — Unit not linked:
 *     { linked: false, odometer_km: null,
 *       message: "Unit not linked to Samsara. Enter odometer manually." }
 *   — Linked and fetched:
 *     { linked: true, odometer_km: 1234.56, fetched_at: "2026-04-08T...",
 *       samsara_vehicle_name: "HD145",
 *       message: "Live odometer from Samsara" }
 *   — Linked but Samsara unreachable:
 *     { linked: true, odometer_km: null,
 *       message: "Could not reach Samsara. Enter odometer manually." }
 *
 * @method      GET
 * @params      equipment_unit_id (required, int)
 * @auth        Session required; require_permission('leases','view') is fine
 *              (this is a read-only fetch used by leases + invoices create)
 * @returns     200 with shape above, or 404 if unit not found
 *
 * @depends     api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session     SAMSARA-3
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/GPS/SamsaraClient.php';

require_method('GET');
require_auth_api();
// Any authenticated user with lease/invoice view can call this — the
// lease-create and invoice-create flows both need it, and the fetch
// itself is read-only on FleetForge's side.
require_permission('leases', 'view');

$unitId = clean_int($_GET['equipment_unit_id'] ?? null);
if (!$unitId) {
    json_error('VALIDATION_ERROR', 'equipment_unit_id is required.', 422);
}

// Pull just the columns we need — unit existence + Samsara link state.
$unit = db_row(
    "SELECT id, unit_number, samsara_vehicle_id, samsara_entity_type, samsara_vehicle_name
       FROM equipment_units
      WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// Not linked to Samsara at all — tell the caller so the UI can hide
// the fetch button or show the manual-entry hint.
if (empty($unit['samsara_vehicle_id'])) {
    json_success([
        'linked'               => false,
        'odometer_km'          => null,
        'fetched_at'           => null,
        'samsara_vehicle_name' => null,
        'message'              => 'Unit not linked to Samsara. Enter odometer manually.',
    ]);
}

// Linked — call Samsara live. getEntityStats() dispatches on entity_type
// so trailers hit /fleet/trailers/stats (gps + gpsOdometerMeters) and
// vehicles hit /fleet/vehicles/stats (obdOdometerMeters). Both return
// a normalized array with an `odometer_km` key (float, 2 decimal places)
// or null on any failure (network error, API error, parse error).
$samsara = new \FleetForge\GPS\SamsaraClient();
$stats   = $samsara->getEntityStats(
    (string) ($unit['samsara_entity_type'] ?? 'vehicle'),
    (string) $unit['samsara_vehicle_id']
);

$odometerKm = $stats['odometer_km'] ?? null;

if ($odometerKm === null) {
    // Linked but live fetch failed. Return 200 with a null value so the
    // UI can show a soft warning rather than an error banner.
    json_success([
        'linked'               => true,
        'odometer_km'          => null,
        'fetched_at'           => null,
        'samsara_vehicle_name' => $unit['samsara_vehicle_name'],
        'message'              => 'Could not reach Samsara. Enter odometer manually.',
    ]);
}

json_success([
    'linked'               => true,
    'odometer_km'          => (float) $odometerKm,
    'fetched_at'           => date('c'), // ISO 8601 with timezone offset
    'samsara_vehicle_name' => $unit['samsara_vehicle_name'],
    'message'              => 'Live odometer from Samsara',
]);
