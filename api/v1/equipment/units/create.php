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
 *           (tracking_provider is auto-set to 'samsara' when Samsara creation succeeds)
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
 * @session  SAMSARA-3 — auto-create trailer in Samsara after DB insert
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/GPS/SamsaraClient.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'create');

$body   = json_body();
$fields = [];

// ── Required fields — VALID-2: accumulate errors, one 422 at end ─
$templateId   = clean_int($body['template_id'] ?? null);
if (!$templateId) {
    $fields['template_id'] = 'Please select an equipment template.';
}

$unitNumber   = clean_string($body['unit_number'] ?? null, 100);
if (!$unitNumber) {
    $fields['unit_number'] = 'Unit number is required.';
}

$rawOwnership   = $body['ownership_type'] ?? null;
$validOwnership = ['owned', 'leased', 'brokered'];
$ownershipType  = ($rawOwnership && in_array($rawOwnership, $validOwnership, true)) ? $rawOwnership : null;
if (!$ownershipType) {
    $fields['ownership_type'] = 'Please select an ownership type (owned, leased, or brokered).';
}

// VALID-2: year must be 1900 – current+1 with specific message
$currentYear = (int) date('Y');
$maxYear     = $currentYear + 1;
$yearRaw     = null;
if (isset($body['year']) && $body['year'] !== '' && $body['year'] !== null) {
    $yearRaw = clean_int($body['year']);
    if ($yearRaw === null || $yearRaw < 1900 || $yearRaw > $maxYear) {
        $fields['year'] = "Year must be between 1900 and {$maxYear}.";
        $yearRaw = null;
    }
}
$year = $yearRaw;

// VALID-2: mileage/odometer >= 0 with specific message
$mileageRaw = null;
if (isset($body['mileage']) && $body['mileage'] !== '' && $body['mileage'] !== null) {
    $mi = clean_int($body['mileage']);
    if ($mi === null || $mi < 0) {
        $fields['mileage'] = 'Odometer cannot be negative.';
        $mileageRaw = 0;
    } else {
        $mileageRaw = $mi;
    }
} else {
    $mileageRaw = 0;
}

// Short-circuit if required-field / format errors — the uniqueness / FK
// checks below require valid values for template_id and unit_number
if ($fields) {
    json_validation_error($fields);
}

// ── Verify template exists ─────────────────────────────────────
// SAMSARA-3: pull brand/model/category so we can mirror them to Samsara
$template = db_row(
    "SELECT id, brand, model, category FROM equipment_templates WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
    [$templateId]
);
if (!$template) {
    json_error('NOT_FOUND', 'Equipment template not found or inactive.', 404,
        ['fields' => ['template_id' => 'Equipment template not found or inactive.']]);
}

// ── Unique unit_number check ───────────────────────────────────
// FIX #25: exclude soft-deleted units so a deleted unit_number can be reused
if (db_exists('equipment_units', 'unit_number = ? AND deleted_at IS NULL', [$unitNumber])) {
    json_error('ALREADY_EXISTS', 'Unit number already exists.', 409,
        ['fields' => ['unit_number' => 'Unit number already exists.']]);
}

// ── Optional fields ────────────────────────────────────────────
$vin             = clean_string($body['vin'] ?? null, 50);
// (year + mileage already validated and stored in $year / $mileageRaw above)
$gpsDeviceId     = clean_string($body['gps_device_id'] ?? null, 100);
$samsaraUrl      = clean_string($body['samsara_vehicle_url'] ?? null, 500);

$rawTracking     = $body['tracking_provider'] ?? 'none';
$trackingProvider = in_array($rawTracking, ['samsara','none'], true) ? $rawTracking : 'none';

$ownerCompanyId  = clean_int($body['owner_company_id'] ?? null);
$yardLocation    = clean_string($body['yard_location'] ?? null, 100);
// FIX #20: dimensions must be positive (not negative or zero)
$lengthFt        = isset($body['length_ft'])  ? clean_positive_decimal($body['length_ft'])  : null;
$heightFt        = isset($body['height_ft'])  ? clean_positive_decimal($body['height_ft'])  : null;
$widthFt         = isset($body['width_ft'])   ? clean_positive_decimal($body['width_ft'])   : null;
// FIX #21 / #22: weight and axle count must be positive
$weightCap       = isset($body['weight_capacity_lbs']) ? clean_positive_int($body['weight_capacity_lbs']) : null;
$wheelSize       = clean_string($body['wheel_size'] ?? null, 50);
$tireSize        = clean_string($body['tire_size'] ?? null, 50);
$axleCount       = isset($body['axle_count']) ? clean_positive_int($body['axle_count']) : null;
$licensePlate    = clean_string($body['license_plate'] ?? null, 50);
$licenseState    = clean_string($body['license_state'] ?? null, 50);

$cviExpiry       = clean_date($body['cvi_expiry'] ?? null);
$regExpiry       = clean_date($body['registration_expiry'] ?? null);
$mviExpiry       = clean_date($body['mvi_expiry'] ?? null);
$insExpiry       = clean_date($body['insurance_expiry'] ?? null);

// FIX #23: interval days must be positive
$cviInterval     = isset($body['cvi_interval_days'])          ? clean_positive_int($body['cvi_interval_days'])          : null;
$mviInterval     = isset($body['mvi_interval_days'])          ? clean_positive_int($body['mvi_interval_days'])          : null;
$regInterval     = isset($body['registration_interval_days']) ? clean_positive_int($body['registration_interval_days']) : null;
$insInterval     = isset($body['insurance_interval_days'])    ? clean_positive_int($body['insurance_interval_days'])    : null;

// Mileage already validated above into $mileageRaw
$mileage         = $mileageRaw;
$notes           = clean_string($body['notes'] ?? null, 5000);
$inspNotes       = clean_string($body['inspection_notes'] ?? null, 5000);
$internalNotes   = clean_string($body['internal_notes'] ?? null, 5000);

// Tags: JSON array stored as JSON column
$tagsInput = is_array($body['tags'] ?? null) ? $body['tags'] : [];
$tags      = array_values(array_filter($tagsInput, fn($t) => is_string($t) && $t !== ''));
$tagsJson  = !empty($tags) ? json_encode($tags) : null;

$acquiredDate    = clean_date($body['acquired_date'] ?? null);
// FIX #19: acquisition cost must be >= 0
$acquisitionCost = isset($body['acquisition_cost']) ? clean_non_negative_decimal($body['acquisition_cost']) : null;

// VALID: enforce DB column ranges so an out-of-range value returns a clean 422
// instead of a PDO SQLSTATE[22003] overflow (same root cause as Sentry
// FLEETFORGE-M, which fired on the update path). Column types:
//   length_ft/height_ft/width_ft DECIMAL(6,2) → 9999.99
//   weight_capacity_lbs INT UNSIGNED          → 4294967295
//   axle_count TINYINT UNSIGNED               → 255
foreach (['length_ft' => ['Length', $lengthFt],
          'height_ft' => ['Height', $heightFt],
          'width_ft'  => ['Width',  $widthFt]] as $f => [$label, $val]) {
    if ($val !== null && bccomp($val, '9999.99', 4) > 0) {
        $fields[$f] = "{$label} must be 9999.99 ft or less.";
    }
}
if ($weightCap !== null && $weightCap > 4294967295) {
    $fields['weight_capacity_lbs'] = 'Weight capacity must be 4294967295 or less.';
}
if ($axleCount !== null && $axleCount > 255) {
    $fields['axle_count'] = 'Axle count must be 255 or less.';
}
if ($fields) {
    json_validation_error($fields);
}

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

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'equipment.created',
            title:      "New unit added",
            message:    "New unit {$unitNumber} added to fleet",
            entityType: 'equipment_unit',
            entityId:   $newId,
            url:        '/fleetforge/equipment/show?id=' . $newId
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF equipment.created] ' . $e->getMessage());
    }
});

// ── SAMSARA-3: Mirror the new unit to Samsara ──────────────────
// WHY after the transaction: the FleetForge record is the source of
// truth — it must exist in the DB before we try to push it out.
// WHY non-blocking: if Samsara is unreachable or returns an error, we
// still return 201 and include a samsara_warning so the caller can
// surface it as a soft alert. The unit is always created in FleetForge;
// staff can use the Samsara Mapping tab to link it manually if needed.
//
// All FleetForge units push to Samsara via POST /fleet/trailers — the
// created asset appears in the Samsara unified Assets list regardless
// of equipment template category. No type filtering needed.
$samsaraId      = null;
$samsaraWarning = null;

try {
    $samsara = new \FleetForge\GPS\SamsaraClient();
    $created = $samsara->createTrailer($unitNumber, [
        'vin'           => $vin,
        'year'          => $year,
        'make'          => $template['brand'] ?? null,
        'model'         => $template['model'] ?? null,
        'license_plate' => $licensePlate,
        'notes'         => 'Created in FleetForge — unit #' . $newId,
    ]);

    if ($created && !empty($created['id'])) {
        $samsaraId = $created['id'];
        // Write the Samsara ID back so the unit is immediately linked
        db_execute(
            "UPDATE equipment_units
                SET samsara_vehicle_id   = ?,
                    samsara_entity_type  = 'trailer',
                    samsara_vehicle_name = ?,
                    tracking_provider    = 'samsara',
                    updated_at           = NOW()
              WHERE id = ?",
            [$samsaraId, $unitNumber, $newId]
        );
    } else {
        $samsaraWarning = 'Unit created in FleetForge but could not be pushed to Samsara. Link it manually via the Samsara Mapping tab.';
    }
} catch (\Throwable $e) {
    // Never let a Samsara error break a FleetForge create
    $samsaraWarning = 'Samsara push failed: ' . $e->getMessage() . '. Link manually via the Mapping tab.';
}

$response = ['id' => $newId, 'unit_number' => $unitNumber];
if ($samsaraId)      $response['samsara_id']      = $samsaraId;
if ($samsaraWarning) $response['samsara_warning']  = $samsaraWarning;

json_success($response, 201);
