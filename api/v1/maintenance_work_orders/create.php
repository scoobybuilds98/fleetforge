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
$body   = json_body();
$fields = [];

// -----------------------------------------------------------------------
// 2. Validate required fields — VALID-2: accumulate every error
// -----------------------------------------------------------------------
$unitId = clean_int($body['equipment_unit_id'] ?? null);
if (!$unitId) {
    $fields['equipment_unit_id'] = 'Please select an equipment unit.';
}

$title = clean_string($body['title'] ?? null, 500);
if (!$title) {
    $fields['title'] = 'Title is required.';
}

$workType       = clean_string($body['work_type'] ?? null);
$validWorkTypes = ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'];
if (!$workType) {
    $fields['work_type'] = 'Please select a work type.';
} elseif (!in_array($workType, $validWorkTypes, true)) {
    $fields['work_type'] = 'Please select a valid work type.';
}

$priority        = clean_string($body['priority'] ?? null);
$validPriorities = ['low', 'medium', 'high', 'emergency'];
if (!$priority) {
    $fields['priority'] = 'Please select a priority.';
} elseif (!in_array($priority, $validPriorities, true)) {
    $fields['priority'] = 'Please select a valid priority.';
}

$requestedDate = clean_date($body['requested_date'] ?? null);
if (!$requestedDate) {
    $fields['requested_date'] = 'Requested date is required.';
}

// VALID-2: mileage_at_service must be >= 0 with specific message
$mileageAtService = null;
if (array_key_exists('mileage_at_service', $body) && $body['mileage_at_service'] !== null && $body['mileage_at_service'] !== '') {
    $mi = clean_int($body['mileage_at_service']);
    if ($mi === null || $mi < 0) {
        $fields['mileage_at_service'] = 'Odometer cannot be negative.';
    } else {
        $mileageAtService = $mi;
    }
}

// VALID-2: scheduled_date must not be before requested_date
$scheduledDate = clean_date($body['scheduled_date'] ?? null);
if ($scheduledDate && $requestedDate && strtotime($scheduledDate) < strtotime($requestedDate)) {
    $fields['scheduled_date'] = 'Scheduled date cannot be before requested date.';
}

if ($fields) {
    json_validation_error($fields);
}

// -----------------------------------------------------------------------
// 3. Verify equipment unit exists, is not deleted, and is allowed in
//    the SERVICE context (i.e. not decommissioned / inactive). [SELECTOR-1]
//    Status is pulled into the SELECT specifically so we can run the
//    same ff_unit_is_selectable() rule the form uses for the disabled
//    attribute, in case the user POSTs an id that bypassed the UI.
// -----------------------------------------------------------------------
$unit = db_row(
    "SELECT eu.id, eu.unit_number, eu.status, et.brand, et.model
     FROM equipment_units eu
     LEFT JOIN equipment_templates et ON et.id = eu.template_id AND et.deleted_at IS NULL
     WHERE eu.id = ? AND eu.deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_validation_error(
        ['equipment_unit_id' => 'Equipment unit not found.'],
        'Equipment unit not found.'
    );
}
if (!ff_unit_is_selectable($unit['status'], 'service')) {
    // 409 mirrors the leases/create.php pattern. UI greys these rows
    // out, but defence-in-depth: a hand-crafted POST or stale page
    // load could submit a decommissioned id, so we reject loudly with
    // the field-level message so FF_Validate can paint the error.
    $msg = ff_unit_unavailable_message((string) $unit['unit_number'], (string) $unit['status'], 'service');
    json_error('UNIT_UNAVAILABLE', $msg, 409, ['fields' => ['equipment_unit_id' => $msg]]);
}

// -----------------------------------------------------------------------
// 4. Optional fields
// -----------------------------------------------------------------------
$vendorId      = clean_int($body['vendor_id'] ?? null);
$description   = clean_string($body['description'] ?? null, 5000);
$notes         = clean_string($body['notes'] ?? null, 5000);
$internalNotes = clean_string($body['internal_notes'] ?? null, 5000);
$assignedTo    = clean_int($body['assigned_to'] ?? null);

// Validate vendor exists if provided
if ($vendorId !== null) {
    if (!db_exists('vendors', 'id = ? AND deleted_at IS NULL', [$vendorId])) {
        json_validation_error(
            ['vendor_id' => 'Vendor not found.'],
            'Vendor not found.'
        );
    }
}

// Validate assigned_to user exists if provided
if ($assignedTo !== null) {
    if (!db_exists('users', 'id = ? AND deleted_at IS NULL', [$assignedTo])) {
        json_validation_error(
            ['assigned_to' => 'Assigned user not found.'],
            'Assigned user not found.'
        );
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
