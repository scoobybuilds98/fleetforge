<?php
declare(strict_types=1);

/**
 * api/v1/gps/locations.php
 *
 * Server-side proxy for Samsara GPS vehicle locations.
 * Required because Samsara API does not support CORS —
 * browser cannot call it directly.
 *
 * GET /api/v1/gps/locations           → all GPS-equipped units + locations
 * GET /api/v1/gps/locations?unit_id=N → single unit location
 *
 * Joins equipment_units (tracking_provider='samsara', gps_device_id set)
 * with live Samsara GPS data. Returns normalized JSON for the
 * tracking map and equipment profile GPS tabs.
 *
 * Response envelope:
 *   { "success": true, "data": { "units": [...], "gps_configured": bool, "updated_at": "..." } }
 *
 * Each unit object:
 *   { id, unit_number, template_name, template_category, status, yard_location,
 *     gps_device_id, samsara_vehicle_url,
 *     location: { latitude, longitude, speed, heading, address, time, odometer_km } | null }
 *
 * Permission: equipment:view
 *
 * @depends api/bootstrap.php, lib/GPS/SamsaraClient.php
 * @session S026
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('equipment', 'view');

use FleetForge\GPS\SamsaraClient;

// ── Optional filter: single unit ────────────────────────────────────────────
$unitId = clean_int($_GET['unit_id'] ?? null);

// ── Load GPS-equipped equipment units from DB ───────────────────────────────
if ($unitId) {
    $dbUnits = db_select(
        "SELECT eu.id, eu.unit_number, eu.status, eu.gps_device_id,
                eu.samsara_vehicle_url, eu.yard_location, eu.mileage,
                t.name AS template_name, t.category AS template_category
         FROM equipment_units eu
         JOIN equipment_templates t ON t.id = eu.template_id
         WHERE eu.id = ? AND eu.deleted_at IS NULL
           AND eu.tracking_provider = 'samsara'
           AND eu.gps_device_id IS NOT NULL AND eu.gps_device_id != ''",
        [$unitId]
    );
} else {
    $dbUnits = db_select(
        "SELECT eu.id, eu.unit_number, eu.status, eu.gps_device_id,
                eu.samsara_vehicle_url, eu.yard_location, eu.mileage,
                t.name AS template_name, t.category AS template_category
         FROM equipment_units eu
         JOIN equipment_templates t ON t.id = eu.template_id
         WHERE eu.deleted_at IS NULL
           AND eu.tracking_provider = 'samsara'
           AND eu.gps_device_id IS NOT NULL AND eu.gps_device_id != ''
         ORDER BY eu.unit_number ASC"
    );
}

// ── Fetch live GPS data from Samsara ────────────────────────────────────────
$gps = new SamsaraClient();
$gpsConfigured = true;

if ($unitId && count($dbUnits) === 1) {
    // Single-unit request: use per-vehicle endpoint
    $vehicleId = $dbUnits[0]['gps_device_id'];
    $samsaraData = $gps->getVehicleLocation($vehicleId);
    // Normalize to array keyed by vehicle ID for uniform processing
    $gpsLookup = [];
    if ($samsaraData) {
        $gpsLookup[$vehicleId] = $samsaraData;
    }
} else {
    // Fleet-wide request: use bulk stats endpoint
    $samsaraVehicles = $gps->getVehicleLocations();
    $gpsLookup = [];

    if ($samsaraVehicles === null) {
        // API not configured or failed — dev mode
        $gpsConfigured = false;
    } else {
        // Index by Samsara vehicle ID for O(1) lookup
        foreach ($samsaraVehicles as $v) {
            $vid = (string)($v['id'] ?? '');
            if ($vid !== '') {
                $gpsLookup[$vid] = $v;
            }
        }
    }
}

// ── Merge DB units with Samsara GPS data ────────────────────────────────────
$result = [];
foreach ($dbUnits as $unit) {
    $deviceId = $unit['gps_device_id'];
    $samsara  = $gpsLookup[$deviceId] ?? null;

    // Extract GPS coordinates from Samsara response
    $location = null;
    if ($samsara && isset($samsara['gps'])) {
        $gpsData = $samsara['gps'];
        $location = [
            'latitude'  => (float)($gpsData['latitude'] ?? 0),
            'longitude' => (float)($gpsData['longitude'] ?? 0),
            'speed'     => isset($gpsData['speedMilesPerHour']) ? round((float)$gpsData['speedMilesPerHour'], 1) : null,
            'heading'   => isset($gpsData['headingDegrees']) ? round((float)$gpsData['headingDegrees'], 1) : null,
            'address'   => $gpsData['reverseGeo']['formattedLocation'] ?? null,
            'time'      => $gpsData['time'] ?? null,
        ];

        // Add odometer if available
        if (isset($samsara['obdOdometerMeters']['value'])) {
            $location['odometer_km'] = (int) round((float)$samsara['obdOdometerMeters']['value'] / 1000.0);
        }
    }

    $result[] = [
        'id'                  => (int)$unit['id'],
        'unit_number'         => $unit['unit_number'],
        'template_name'       => $unit['template_name'],
        'template_category'   => $unit['template_category'],
        'status'              => $unit['status'],
        'yard_location'       => $unit['yard_location'],
        'mileage'             => (int)($unit['mileage'] ?? 0),
        'gps_device_id'       => $deviceId,
        'samsara_vehicle_url' => $unit['samsara_vehicle_url'],
        'location'            => $location,
    ];
}

json_success([
    'units'          => $result,
    'total'          => count($result),
    'gps_configured' => $gpsConfigured,
    'updated_at'     => date('c'),
]);
