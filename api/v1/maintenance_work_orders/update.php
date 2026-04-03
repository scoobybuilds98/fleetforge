<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/update.php
 *
 * Update metadata on an existing work order.
 *
 * Business rules:
 *   - D19 optimistic lock: updated_at must match current DB value.
 *   - Blocked if status is 'completed' or 'cancelled' (IMMUTABLE_RECORD).
 *   - Does NOT change status (use update_status.php).
 *   - Does NOT change equipment_unit_id (work orders are unit-bound).
 *   - labor_cost/parts_cost/total_cost are NOT editable here — managed by line_items.
 *   - Audit log: action='update', old_values vs new_values.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required for D19 lock),
 *               title?, work_type?, priority?, vendor_id?, description?,
 *               mileage_at_service?, requested_date?, scheduled_date?,
 *               notes?, internal_notes?, assigned_to?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, updated_at } | 409 STALE_DATA | 422 IMMUTABLE_RECORD
 *
 * Decisions: D5 (soft delete), D7 (routing), D19 (optimistic lock), §7 (audit log)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Parse body + resolve WO
// -----------------------------------------------------------------------
$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for concurrency control.', 422);
}

$existing = db_row(
    "SELECT * FROM maintenance_work_orders WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$existing) {
    json_error('NOT_FOUND', 'Work order not found.', 404);
}

// -----------------------------------------------------------------------
// 2. D19 optimistic lock
// -----------------------------------------------------------------------
if ($existing['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'This work order was modified by another user. Please refresh and try again.', 409);
}

// -----------------------------------------------------------------------
// 3. Block edits on terminal states
// -----------------------------------------------------------------------
if (in_array($existing['status'], ['completed', 'cancelled'], true)) {
    json_error('IMMUTABLE_RECORD', 'Cannot edit a completed or cancelled work order.', 422);
}

// -----------------------------------------------------------------------
// 4. Resolve updatable fields (fall back to existing values)
// -----------------------------------------------------------------------
$title = clean_string($body['title'] ?? null, 500) ?? $existing['title'];
if (!$title) {
    json_error('MISSING_REQUIRED', 'title cannot be blank.', 422);
}

$workType = clean_string($body['work_type'] ?? null);
$validWorkTypes = ['scheduled_service', 'repair', 'inspection', 'tire', 'electrical', 'body_damage', 'breakdown', 'other'];
if ($workType && !in_array($workType, $validWorkTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid work_type.', 422);
}
$workType = $workType ?? $existing['work_type'];

$priority = clean_string($body['priority'] ?? null);
$validPriorities = ['low', 'medium', 'high', 'emergency'];
if ($priority && !in_array($priority, $validPriorities, true)) {
    json_error('VALIDATION_ERROR', 'Invalid priority.', 422);
}
$priority = $priority ?? $existing['priority'];

// vendor_id: explicit null clears it; absent key keeps existing
$vendorId = array_key_exists('vendor_id', $body)
    ? ($body['vendor_id'] !== null ? clean_int($body['vendor_id']) : null)
    : $existing['vendor_id'];

if ($vendorId !== null) {
    if (!db_exists('vendors', 'id = ? AND deleted_at IS NULL', [$vendorId])) {
        json_error('NOT_FOUND', 'Vendor not found.', 404);
    }
}

$requestedDate = clean_date($body['requested_date'] ?? null) ?? $existing['requested_date'];
$scheduledDate = array_key_exists('scheduled_date', $body)
    ? clean_date($body['scheduled_date'] ?? null)
    : $existing['scheduled_date'];

$description   = array_key_exists('description', $body)
    ? clean_string($body['description'] ?? null, 5000)
    : $existing['description'];

$mileageAtService = array_key_exists('mileage_at_service', $body)
    ? clean_int($body['mileage_at_service'] ?? null)
    : $existing['mileage_at_service'];

$notes = array_key_exists('notes', $body)
    ? clean_string($body['notes'] ?? null, 5000)
    : $existing['notes'];

$internalNotes = array_key_exists('internal_notes', $body)
    ? clean_string($body['internal_notes'] ?? null, 5000)
    : $existing['internal_notes'];

$assignedTo = array_key_exists('assigned_to', $body)
    ? ($body['assigned_to'] !== null ? clean_int($body['assigned_to']) : null)
    : $existing['assigned_to'];

if ($assignedTo !== null) {
    if (!db_exists('users', 'id = ? AND deleted_at IS NULL', [$assignedTo])) {
        json_error('NOT_FOUND', 'Assigned user not found.', 404);
    }
}

// -----------------------------------------------------------------------
// 5. Persist inside transaction + audit log
// -----------------------------------------------------------------------
$newUpdatedAt = null;

db_transaction(function() use (
    $id, $title, $workType, $priority, $vendorId, $requestedDate, $scheduledDate,
    $description, $mileageAtService, $notes, $internalNotes, $assignedTo,
    $existing, &$newUpdatedAt
) {
    db_execute(
        "UPDATE maintenance_work_orders SET
             title = ?, work_type = ?, priority = ?, vendor_id = ?,
             requested_date = ?, scheduled_date = ?, description = ?,
             mileage_at_service = ?, notes = ?, internal_notes = ?,
             assigned_to = ?
         WHERE id = ?",
        [
            $title, $workType, $priority, $vendorId,
            $requestedDate, $scheduledDate, $description,
            $mileageAtService, $notes, $internalNotes,
            $assignedTo, $id,
        ]
    );

    $fresh = db_row("SELECT updated_at FROM maintenance_work_orders WHERE id = ?", [$id]);
    $newUpdatedAt = $fresh['updated_at'];

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $id,
        'entity_label' => $existing['work_order_number'],
        'old_values'   => json_encode([
            'title'     => $existing['title'],
            'work_type' => $existing['work_type'],
            'priority'  => $existing['priority'],
            'vendor_id' => $existing['vendor_id'],
        ]),
        'new_values'   => json_encode([
            'title'     => $title,
            'work_type' => $workType,
            'priority'  => $priority,
            'vendor_id' => $vendorId,
        ]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'updated_at' => $newUpdatedAt]);
