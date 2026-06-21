<?php
declare(strict_types=1);

// ============================================================
// GET /api/v1/gps/mileage?lease_id=N
//
// Called when admin opens the "Close Lease" form to pre-fill
// the actual_mileage field. Spec §10 Feature 2.
//
// Calls SamsaraClient::getMileageForLease() using the unit's
// gps_device_id. Returns km driven since lease start.
//
// NEVER blocking: if GPS unavailable, returns odometer=null
// and source='unavailable'. Close-lease flow continues.
//
// Response:
//   {"success":true,"data":{"distance_km":1450.5,"odometer_estimate":null,"source":"gps"}}
//   {"success":true,"data":{"distance_km":null,"odometer_estimate":null,"source":"unavailable"}}
//
// Permission: leases edit (user must be closing a lease)
// ============================================================

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('leases', 'edit');

use FleetForge\GPS\SamsaraClient;

$leaseId = clean_int($_GET['lease_id'] ?? null);
if (!$leaseId) {
    json_error('MISSING_REQUIRED', 'lease_id is required.', 422);
}

// ── Load lease with unit GPS info
// L07 (audit 2026-06-19): key off samsara_vehicle_id + samsara_entity_type
// (the canonical Samsara link), NOT the dead gps_device_id column which is
// empty for every unit. samsara_entity_type routes trailers to gpsOdometerMeters.
$lease = db_row(
    "SELECT l.id, l.start_date, l.end_date, l.actual_return_date, l.status,
            l.equipment_unit_id, l.mileage_at_start, l.mileage_unit,
            eu.samsara_vehicle_id, eu.samsara_entity_type
     FROM leases l
     JOIN equipment_units eu ON eu.id = l.equipment_unit_id AND eu.deleted_at IS NULL
     WHERE l.id = ? AND l.deleted_at IS NULL",
    [$leaseId]
);

if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// ── Determine date range: lease start → today (or end date if set)
$startDate = $lease['start_date'];
$endDate   = $lease['actual_return_date'] ?? $lease['end_date'] ?? date('Y-m-d');
if ($endDate > date('Y-m-d')) {
    $endDate = date('Y-m-d'); // don't query future dates
}

$vehicleId  = (string) ($lease['samsara_vehicle_id'] ?? '');
$entityType = (string) ($lease['samsara_entity_type'] ?? 'vehicle');

// ── No GPS device configured on this unit
if ($vehicleId === '') {
    json_success([
        'distance_km'       => null,
        'odometer_estimate' => null,
        'source'            => 'no_device',
        'message'           => 'No GPS device configured for this unit.',
    ]);
}

// ── Call Samsara (entity-type aware: trailer → /fleet/trailers gpsOdometerMeters)
$gps          = new SamsaraClient();
$distanceKm   = $gps->getMileageForLease($vehicleId, $startDate, $endDate, $entityType);

if ($distanceKm === null) {
    json_success([
        'distance_km'       => null,
        'odometer_estimate' => null,
        'source'            => 'unavailable',
        'message'           => 'GPS data unavailable. Enter mileage manually.',
    ]);
}

// ── Compute estimated end odometer from lease start + distance
$odometerEstimate = null;
if ($lease['mileage_at_start'] !== null) {
    // distance_km is in km; mileage_at_start is in the unit's preferred unit
    $mileageUnit = $lease['mileage_unit'] ?? 'km';
    if ($mileageUnit === 'miles') {
        // Convert km → miles for consistency with the lease's unit
        $distanceInUnit  = round($distanceKm * 0.621371, 1);
    } else {
        $distanceInUnit  = $distanceKm;
    }
    $odometerEstimate = (int) ($lease['mileage_at_start'] + $distanceInUnit);
}

json_success([
    'distance_km'       => $distanceKm,
    'odometer_estimate' => $odometerEstimate,
    'source'            => 'gps',
    'start_date'        => $startDate,
    'end_date'          => $endDate,
]);
