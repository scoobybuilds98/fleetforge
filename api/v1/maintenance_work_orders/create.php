<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/create.php
 *
 * Create a new maintenance work order.
 *
 * Business rules:
 *   - equipment_unit_id and title and work_type and priority and requested_date are required.
 *   - work_order_number generated atomically via generate_id('WO', year) inside transaction (Trap 9).
 *   - vendor_id is optional (nullable FK → vendors).
 *   - labor_cost/parts_cost/total_cost start at 0.00 — updated by line_items module.
 *   - status always starts as 'open'.
 *   - Audit log: action='create', module='maintenance', entity_type='work_order'.
 *
 * @method  POST
 * @body    JSON: equipment_unit_id (required), title (required), work_type (required),
 *               priority (required), requested_date (required), vendor_id?,
 *               description?, mileage_at_service?, scheduled_date?,
 *               notes?, internal_notes?, assigned_to?
 * @auth    Session required; require_permission('maintenance','create')
 * @returns 201 { id, work_order_number }
 *
 * Decisions: D5 (soft delete), D7 (routing), D16 (bcmath), §7 (audit log), Trap 9 (atomic WO#)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'create');

// -----------------------------------------------------------------------
// 1. Parse JSON body
// -----------------------------------------------------------------------
$body = json_body();

// -----------------------------------------------------------------------
// 2. Validate required fields
// -----------------------------------------------------------------------
$unitId = clean_int($body['equipment_unit_id'] ?? null);
if (!$unitId) {
    json_error('MISSING_REQUIRED', 'equipment_unit_id is required.', 422);
}

$title = clean_string($body['title'] ?? null, 500);
if (!$title) {
    json_error('MISSING_REQUIRED', 'title is required.', 422);
}

$workType = clean_string($body['work_type'] ?? null);
$validWorkTypes = ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'];
if (!$workType || !in_array($workType, $validWorkTypes, true)) {
    json_error('VALIDATION_ERROR', 'work_type is required and must be one of: ' . implode(', ', $validWorkTypes) . '.', 422);
}

$priority = clean_string($body['priority'] ?? null);
$validPriorities = ['low', 'medium', 'high', 'emergency'];
if (!$priority || !in_array($priority, $validPriorities, true)) {
    json_error('VALIDATION_ERROR', 'priority is required and must be one of: ' . implode(', ', $validPriorities) . '.', 422);
}

$requestedDate = clean_date($body['requested_date'] ?? null);
if (!$requestedDate) {
    json_error('MISSING_REQUIRED', 'requested_date is required (Y-m-d).', 422);
}

// -----------------------------------------------------------------------
// 3. Verify equipment unit exists (not deleted)
// -----------------------------------------------------------------------
$unit = db_row(
    "SELECT eu.id, eu.unit_number, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.id = ? AND eu.deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// -----------------------------------------------------------------------
// 4. Optional fields
// -----------------------------------------------------------------------
$vendorId         = clean_int($body['vendor_id'] ?? null);
$description      = clean_string($body['description'] ?? null, 5000);
// WHY: mileage must be >= 0 — a negative odometer reading is nonsensical
$mileageAtService = clean_non_negative_int($body['mileage_at_service'] ?? null);
$scheduledDate    = clean_date($body['scheduled_date'] ?? null);
$notes            = clean_string($body['notes'] ?? null, 5000);
$internalNotes    = clean_string($body['internal_notes'] ?? null, 5000);
$assignedTo       = clean_int($body['assigned_to'] ?? null);

// Validate vendor exists if provided
if ($vendorId !== null) {
    if (!db_exists('vendors', 'id = ? AND deleted_at IS NULL', [$vendorId])) {
        json_error('NOT_FOUND', 'Vendor not found.', 404);
    }
}

// Validate assigned_to user exists if provided
if ($assignedTo !== null) {
    if (!db_exists('users', 'id = ? AND deleted_at IS NULL', [$assignedTo])) {
        json_error('NOT_FOUND', 'Assigned user not found.', 404);
    }
}

// -----------------------------------------------------------------------
// 5. Insert inside transaction with atomic WO number generation (Trap 9)
// -----------------------------------------------------------------------
$newId = null;
$woNumber = null;

db_transaction(function() use (
    $unitId, $vendorId, $workType, $priority, $title, $description,
    $mileageAtService, $requestedDate, $scheduledDate, $notes,
    $internalNotes, $assignedTo, $unit, &$newId, &$woNumber
) {
    // Atomic WO number — generate_id must be called inside transaction (Trap 9)
    $woNumber = generate_id('WO', date('Y'));

    $newId = db_insert('maintenance_work_orders', [
        'work_order_number'  => $woNumber,
        'equipment_unit_id'  => $unitId,
        'vendor_id'          => $vendorId,
        'work_type'          => $workType,
        'priority'           => $priority,
        'status'             => 'open',
        'title'              => $title,
        'description'        => $description,
        'mileage_at_service' => $mileageAtService,
        'requested_date'     => $requestedDate,
        'scheduled_date'     => $scheduledDate,
        'notes'              => $notes,
        'internal_notes'     => $internalNotes,
        'assigned_to'        => $assignedTo,
        'labor_cost'         => '0.00',
        'parts_cost'         => '0.00',
        'total_cost'         => '0.00',
        'created_by'         => current_user_id(),
    ]);

    // §7 audit log
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $newId,
        'entity_label' => $woNumber,
        'new_values'   => json_encode([
            'work_order_number'  => $woNumber,
            'equipment_unit_id'  => $unitId,
            'unit_number'        => $unit['unit_number'],
            'work_type'          => $workType,
            'priority'           => $priority,
            'status'             => 'open',
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $newId, 'work_order_number' => $woNumber], 201);
