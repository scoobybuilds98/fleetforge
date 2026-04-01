<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/create.php
 *
 * Creates a new equipment unit. The unit_number must be globally unique.
 * The template's default values are used as fallbacks but can be overridden
 * per-unit. Initial status is always 'available'. Logs status_log entry
 * and audit_log entry in the same transaction.
 *
 * @method   POST
 * @body     JSON
 * @required template_id, unit_number, ownership_type
 * @optional vin, year, gps_device_id, samsara_vehicle_url, tracking_provider,
 *           owner_company_id, yard_location, length_ft, height_ft, width_ft,
 *           weight_capacity_lbs, wheel_size, tire_size, axle_count,
 *           license_plate, license_state,
 *           cvi_expiry, registration_expiry, mvi_expiry, insurance_expiry,
 *           cvi_interval_days, mvi_interval_days, registration_interval_days,
 *           insurance_interval_days,
 *           mileage, notes, inspection_notes, internal_notes, tags,
 *           acquired_date, acquisition_cost
 * @auth     Session required; require_permission('equipment','create')
 * @returns  201 { id, unit_number }
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.4 Equipment Units
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'create');

$body = json_body();

// ── Required fields ────────────────────────────────────────────
$templateId  = clean_int($body['template_id'] ?? null);
$unitNumber  = clean_string($body['unit_number'] ?? null, 100);
$rawOwnership = $body['ownership_type'] ?? null;

$validOwnership = ['owned','leased','brokered'];
$ownershipType  = ($rawOwnership && in_array($rawOwnership, $validOwnership, true)) ? $rawOwnership : null;

if (!$templateId)   json_error('VALIDATION_ERROR', 'template_id is required.', 422);
if (!$unitNumber)   json_error('VALIDATION_ERROR', 'unit_number is required.', 422);
if (!$ownershipType) json_error('VALIDATION_ERROR', 'ownership_type must be one of: owned, leased, brokered.', 422);

// ── Verify template exists ─────────────────────────────────────
$template = db_row(
    "SELECT id FROM equipment_templates WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
    [$templateId]
);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment template not found or inactive.', 404);
}

// ── Unique unit_number check ───────────────────────────────────
// WHY: unit_number has a UNIQUE index — clean error better than PDO exception
if (db_exists('equipment_units', 'unit_number = ?', [$unitNumber])) {
    json_error('ALREADY_EXISTS', 'A unit with this unit number already exists.', 409);
}

// ── Optional fields ────────────────────────────────────────────
$vin             = clean_string($body['vin'] ?? null, 50);
$year            = clean_int($body['year'] ?? null);
$gpsDeviceId     = clean_string($body['gps_device_id'] ?? null, 100);
$samsaraUrl      = clean_string($body['samsara_vehicle_url'] ?? null, 500);

$rawTracking     = $body['tracking_provider'] ?? 'none';
$trackingProvider = in_array($rawTracking, ['samsara','none'], true) ? $rawTracking : 'none';

$ownerCompanyId  = clean_int($body['owner_company_id'] ?? null);
$yardLocation    = clean_string($body['yard_location'] ?? null, 100);
$lengthFt        = isset($body['length_ft'])  ? clean_decimal($body['length_ft'])  : null;
$heightFt        = isset($body['height_ft'])  ? clean_decimal($body['height_ft'])  : null;
$widthFt         = isset($body['width_ft'])   ? clean_decimal($body['width_ft'])   : null;
$weightCap       = clean_int($body['weight_capacity_lbs'] ?? null);
$wheelSize       = clean_string($body['wheel_size'] ?? null, 50);
$tireSize        = clean_string($body['tire_size'] ?? null, 50);
$axleCount       = clean_int($body['axle_count'] ?? null);
$licensePlate    = clean_string($body['license_plate'] ?? null, 50);
$licenseState    = clean_string($body['license_state'] ?? null, 50);

$cviExpiry       = clean_date($body['cvi_expiry'] ?? null);
$regExpiry       = clean_date($body['registration_expiry'] ?? null);
$mviExpiry       = clean_date($body['mvi_expiry'] ?? null);
$insExpiry       = clean_date($body['insurance_expiry'] ?? null);

$cviInterval     = clean_int($body['cvi_interval_days'] ?? null);
$mviInterval     = clean_int($body['mvi_interval_days'] ?? null);
$regInterval     = clean_int($body['registration_interval_days'] ?? null);
$insInterval     = clean_int($body['insurance_interval_days'] ?? null);

$mileage         = clean_int($body['mileage'] ?? null) ?? 0;
$notes           = clean_string($body['notes'] ?? null, 5000);
$inspNotes       = clean_string($body['inspection_notes'] ?? null, 5000);
$internalNotes   = clean_string($body['internal_notes'] ?? null, 5000);

// Tags: JSON array stored as JSON column
$tagsInput = is_array($body['tags'] ?? null) ? $body['tags'] : [];
$tags      = array_values(array_filter($tagsInput, fn($t) => is_string($t) && $t !== ''));
$tagsJson  = !empty($tags) ? json_encode($tags) : null;

$acquiredDate    = clean_date($body['acquired_date'] ?? null);
$acquisitionCost = isset($body['acquisition_cost']) ? clean_decimal($body['acquisition_cost']) : null;

$userId = current_user_id();
$newId  = null;

// ── Insert (transaction: unit + status_log + audit_log) ────────
db_transaction(function () use (
    &$newId, $userId,
    $templateId, $unitNumber, $vin, $year,
    $gpsDeviceId, $samsaraUrl, $trackingProvider,
    $ownershipType, $ownerCompanyId, $yardLocation,
    $lengthFt, $heightFt, $widthFt, $weightCap,
    $wheelSize, $tireSize, $axleCount,
    $licensePlate, $licenseState,
    $cviExpiry, $regExpiry, $mviExpiry, $insExpiry,
    $cviInterval, $mviInterval, $regInterval, $insInterval,
    $mileage, $notes, $inspNotes, $internalNotes, $tagsJson,
    $acquiredDate, $acquisitionCost
): void {
    $newId = db_insert('equipment_units', [
        'template_id'                => $templateId,
        'unit_number'                => $unitNumber,
        'vin'                        => $vin,
        'year'                       => $year,
        'gps_device_id'              => $gpsDeviceId,
        'samsara_vehicle_url'        => $samsaraUrl,
        'tracking_provider'          => $trackingProvider,
        'ownership_type'             => $ownershipType,
        'owner_company_id'           => $ownerCompanyId,
        'yard_location'              => $yardLocation,
        'length_ft'                  => $lengthFt,
        'height_ft'                  => $heightFt,
        'width_ft'                   => $widthFt,
        'weight_capacity_lbs'        => $weightCap,
        'wheel_size'                 => $wheelSize,
        'tire_size'                  => $tireSize,
        'axle_count'                 => $axleCount,
        'license_plate'              => $licensePlate,
        'license_state'              => $licenseState,
        'cvi_expiry'                 => $cviExpiry,
        'registration_expiry'        => $regExpiry,
        'mvi_expiry'                 => $mviExpiry,
        'insurance_expiry'           => $insExpiry,
        'cvi_interval_days'          => $cviInterval,
        'mvi_interval_days'          => $mviInterval,
        'registration_interval_days' => $regInterval,
        'insurance_interval_days'    => $insInterval,
        'status'                     => 'available',
        'mileage'                    => $mileage,
        'notes'                      => $notes,
        'inspection_notes'           => $inspNotes,
        'internal_notes'             => $internalNotes,
        'tags'                       => $tagsJson,
        'acquired_date'              => $acquiredDate,
        'acquisition_cost'           => $acquisitionCost,
        'created_by'                 => $userId,
        'updated_by'                 => $userId,
    ]);

    // WHY: every status creation is recorded in equipment_status_log so the
    // Status Log tab on the unit profile page shows the full history from day 1
    db_insert('equipment_status_log', [
        'equipment_unit_id'   => $newId,
        'old_status'          => 'none',
        'new_status'          => 'available',
        'reason'              => 'Unit registered',
        'changed_by'          => current_user()['name'] ?? 'system',
        'changed_by_user_id'  => $userId,
    ]);

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $newId,
        'entity_label' => $unitNumber,
        'new_values'   => json_encode(['unit_number' => $unitNumber, 'status' => 'available']),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $newId, 'unit_number' => $unitNumber], 201);
