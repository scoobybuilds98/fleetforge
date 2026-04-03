<?php
declare(strict_types=1);

/**
 * api/v1/inspections/create.php
 *
 * Create a new inspection and auto-generate the 9 default sections.
 *
 * Business rules:
 *   - equipment_unit_id, inspection_type, and inspection_date are required.
 *   - lease_id is optional but required for pre_lease/post_lease types in practice.
 *   - inspection_number generated atomically via generate_id('INSP', year) inside transaction (Trap 9).
 *   - 9 default sections auto-inserted in same transaction:
 *       1. Exterior - Left Side      5. Exterior - Roof
 *       2. Exterior - Right Side     6. Exterior - Floor
 *       3. Exterior - Front Wall     7. Interior
 *       4. Exterior - Rear Door      8. Tires (with full position JSON skeleton)
 *                                    9. Trailer Condition (with checklist JSON skeleton)
 *   - status always starts as 'draft'.
 *   - equipment_unit must exist and not be soft-deleted.
 *   - lease_id (if provided) must exist and not be soft-deleted.
 *
 * @method  POST
 * @body    JSON: equipment_unit_id (required), inspection_type (required), inspection_date (required),
 *               lease_id?, inspected_by?, inspected_by_user_id?, mileage_at_inspection?,
 *               reefer_hours?, fuel_level?, cvi_expiry?, is_clean?, notes?
 * @auth    Session required; require_permission('inspections','create')
 * @returns 201 { id, inspection_number }
 *
 * Decisions: D7 (routing), D5 (soft delete checks), §7 (audit log), Trap 9 (atomic INSP#)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'create');

$body = json_body();

// ── Required fields
$unitId         = clean_int($body['equipment_unit_id'] ?? null);
$inspectionType = clean_string($body['inspection_type'] ?? null);
$inspectionDate = clean_date($body['inspection_date'] ?? null);

$errors = [];
if (!$unitId)         $errors['equipment_unit_id'] = 'Equipment unit is required.';
if (!$inspectionType) $errors['inspection_type']   = 'Inspection type is required.';
if (!$inspectionDate) $errors['inspection_date']   = 'Inspection date is required.';

// Validate inspection_type ENUM
$validTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
if ($inspectionType && !in_array($inspectionType, $validTypes, true)) {
    $errors['inspection_type'] = 'Invalid inspection type.';
}

if ($errors) json_error('VALIDATION_ERROR', 'Validation failed.', 422, ['fields' => $errors]);

// ── Optional fields
$leaseId             = clean_int($body['lease_id'] ?? null);
$inspectedBy         = clean_string($body['inspected_by'] ?? null);
$inspectedByUserId   = clean_int($body['inspected_by_user_id'] ?? null);
$mileageAtInspection = clean_int($body['mileage_at_inspection'] ?? null);
$reeferHours         = clean_int($body['reefer_hours'] ?? null);
$fuelLevel           = clean_string($body['fuel_level'] ?? null);
$cviExpiry           = clean_date($body['cvi_expiry'] ?? null);
$isClean             = isset($body['is_clean']) ? (int)(bool)$body['is_clean'] : null;
$notes               = clean_string($body['notes'] ?? null, 5000);

// Validate fuel_level if provided
$validFuelLevels = ['empty', 'quarter', 'half', 'three_quarter', 'full'];
if ($fuelLevel && !in_array($fuelLevel, $validFuelLevels, true)) {
    json_error('VALIDATION_ERROR', 'Invalid fuel level.', 422, ['fields' => ['fuel_level' => 'Invalid fuel level.']]);
}

// ── Validate equipment_unit exists (soft-delete aware — D5)
$unit = db_row(
    "SELECT eu.id, eu.unit_number FROM equipment_units eu WHERE eu.id = ? AND eu.deleted_at IS NULL",
    [$unitId]
);
if (!$unit) json_error('NOT_FOUND', 'Equipment unit not found.', 404);

// ── Validate lease if provided (soft-delete aware — D5)
if ($leaseId) {
    $lease = db_row("SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL", [$leaseId]);
    if (!$lease) json_error('NOT_FOUND', 'Lease not found.', 404);
}

// ── Tire section JSON skeleton — 20 positions (L/R, outer/inner, 5 axles per side)
// Each position: brakes, tread, brand, org (original equipment), wheels (AL=aluminum/STL=steel)
$tirePositions = [];
foreach (['L', 'R'] as $side) {
    for ($axle = 1; $axle <= 5; $axle++) {
        foreach (['O', 'I'] as $pos) { // O=outer, I=inner
            $key = $side . $pos . '.' . $axle;
            $tirePositions[$key] = ['brakes' => '', 'tread' => '', 'brand' => '', 'org' => '', 'wheels' => ''];
        }
    }
}
$tireSectionData = json_encode(['positions' => $tirePositions]);

// ── Trailer Condition JSON skeleton — 7 checklist items
// Values: 'ok' | 'C' (Cut) | 'D' (Dent) | 'S' (Scratch) | 'B' (Bruise) | 'P' (Patch) | 'H' (Hole) | 'missing' | 'na'
$trailerConditionData = json_encode([
    'mud_flaps'    => ['code' => 'ok', 'notes' => ''],
    'lights'       => ['code' => 'ok', 'notes' => ''],
    'canlocks'     => ['code' => 'ok', 'notes' => ''],
    'landing_gear' => ['code' => 'ok', 'notes' => ''],
    'inflation'    => ['code' => 'ok', 'notes' => ''],
    'tray_skirts'  => ['code' => 'ok', 'notes' => ''],
    'rub_rail'     => ['code' => 'ok', 'notes' => ''],
]);

// ── Default sections to auto-create with every inspection
$defaultSections = [
    ['name' => 'Exterior - Left Side',  'sort' => 1, 'data' => null],
    ['name' => 'Exterior - Right Side', 'sort' => 2, 'data' => null],
    ['name' => 'Exterior - Front Wall', 'sort' => 3, 'data' => null],
    ['name' => 'Exterior - Rear Door',  'sort' => 4, 'data' => null],
    ['name' => 'Exterior - Roof',       'sort' => 5, 'data' => null],
    ['name' => 'Exterior - Floor',      'sort' => 6, 'data' => null],
    ['name' => 'Interior',              'sort' => 7, 'data' => null],
    ['name' => 'Tires',                 'sort' => 8, 'data' => $tireSectionData],
    ['name' => 'Trailer Condition',     'sort' => 9, 'data' => $trailerConditionData],
];

$newId      = null;
$inspNumber = null;

db_transaction(function () use (
    $unitId, $inspectionType, $inspectionDate, $leaseId,
    $inspectedBy, $inspectedByUserId, $mileageAtInspection,
    $reeferHours, $fuelLevel, $cviExpiry, $isClean, $notes,
    $defaultSections, &$newId, &$inspNumber
) {
    // Atomic inspection number — generate_id must be inside transaction (Trap 9)
    $inspNumber = generate_id('INSP', date('Y'));

    $newId = db_insert('inspections', [
        'inspection_number'    => $inspNumber,
        'inspection_type'      => $inspectionType,
        'equipment_unit_id'    => $unitId,
        'lease_id'             => $leaseId,
        'status'               => 'draft',
        'inspection_date'      => $inspectionDate,
        'inspected_by'         => $inspectedBy,
        'inspected_by_user_id' => $inspectedByUserId,
        'mileage_at_inspection'=> $mileageAtInspection,
        'reefer_hours'         => $reeferHours,
        'fuel_level'           => $fuelLevel,
        'cvi_expiry'           => $cviExpiry,
        'is_clean'             => $isClean,
        'notes'                => $notes,
    ]);

    // Auto-create 9 default sections in same transaction
    foreach ($defaultSections as $sec) {
        db_insert('inspection_sections', [
            'inspection_id' => $newId,
            'section_name'  => $sec['name'],
            'condition'     => 'ok',
            'notes'         => null,
            'section_data'  => $sec['data'],
            'sort_order'    => $sec['sort'],
        ]);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'inspections',
        'entity_type'  => 'inspection',
        'entity_id'    => $newId,
        'entity_label' => $inspNumber,
        'new_values'   => json_encode([
            'inspection_number' => $inspNumber,
            'inspection_type'   => $inspectionType,
            'equipment_unit_id' => $unitId,
            'lease_id'          => $leaseId,
            'inspection_date'   => $inspectionDate,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $newId, 'inspection_number' => $inspNumber], 201);
