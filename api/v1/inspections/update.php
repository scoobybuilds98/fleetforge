<?php
declare(strict_types=1);

/**
 * api/v1/inspections/update.php
 *
 * Update inspection header fields. D19 optimistic lock on updated_at.
 *
 * Business rules:
 *   - Requires id + updated_at (optimistic lock — D19).
 *   - Blocks update if status = 'complete' or 'signed' (IMMUTABLE_RECORD).
 *   - inspection_number and status are NOT updatable here — use update_status.php for status.
 *   - inspection_type, equipment_unit_id, lease_id, inspection_date, inspected_by,
 *     mileage_at_inspection, reefer_hours, fuel_level, cvi_expiry, is_clean, notes,
 *     overall_condition, condition_score are all updatable.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required), [inspection fields to update]
 * @auth    Session required; require_permission('inspections','edit')
 * @returns 200 { updated_at }
 *
 * Decisions: D7 (routing), D19 (optimistic lock), §7 (audit log)
 * Session: S016
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('inspections', 'edit');

$body = json_body();

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id)        json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$updatedAt) json_error('MISSING_REQUIRED', 'updated_at is required.', 422);

// ── Fetch existing row (lock not needed here — D19 detects conflict via updated_at comparison)
$existing = db_row("SELECT * FROM inspections WHERE id = ?", [$id]);
if (!$existing) json_error('NOT_FOUND', 'Inspection not found.', 404);

// D19 optimistic lock — reject if another user modified the record since the client loaded it
if ($existing['updated_at'] !== $updatedAt) {
    json_error('STALE_DATA', 'Inspection was modified by another user. Refresh and try again.', 409);
}

// Block edits to completed/signed inspections (immutable — sections+photos have their own rules)
if (in_array($existing['status'], ['complete', 'signed'], true)) {
    json_error('IMMUTABLE_RECORD', 'Completed or signed inspections cannot be edited.', 422);
}

// ── Optional updatable fields (only update what was sent)
$fields  = [];
$params  = [];

$updatable = [
    'inspection_type'       => fn($v) => clean_string($v),
    'inspection_date'       => fn($v) => clean_date($v),
    'inspected_by'          => fn($v) => clean_string($v),
    'inspected_by_user_id'  => fn($v) => clean_int($v),
    'mileage_at_inspection' => fn($v) => clean_int($v),
    'reefer_hours'          => fn($v) => clean_int($v),
    'fuel_level'            => fn($v) => clean_string($v),
    'cvi_expiry'            => fn($v) => clean_date($v),
    'overall_condition'     => fn($v) => clean_string($v),
    'condition_score'       => fn($v) => clean_int($v),
    'notes'                 => fn($v) => clean_string($v, 5000),
];

// is_clean needs special boolean handling
if (array_key_exists('is_clean', $body)) {
    $fields[]  = 'is_clean = ?';
    $params[]  = $body['is_clean'] === null ? null : (int)(bool)$body['is_clean'];
}

foreach ($updatable as $col => $cleaner) {
    if (array_key_exists($col, $body)) {
        $val = $cleaner($body[$col]);
        $fields[]  = "$col = ?";
        $params[]  = $val;
    }
}

// lease_id: allow explicit null to detach
if (array_key_exists('lease_id', $body)) {
    $leaseId = clean_int($body['lease_id'] ?? null);
    if ($leaseId) {
        $lease = db_row("SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL", [$leaseId]);
        if (!$lease) json_error('NOT_FOUND', 'Lease not found.', 404);
    }
    $fields[]  = 'lease_id = ?';
    $params[]  = $leaseId;
}

if (!$fields) json_error('VALIDATION_ERROR', 'No updatable fields provided.', 422);

// Validate enums if provided
$validTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
if (isset($body['inspection_type']) && !in_array($body['inspection_type'], $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid inspection type.', 422, ['fields' => ['inspection_type' => 'Invalid type.']]);
}
$validFuel = ['empty', 'quarter', 'half', 'three_quarter', 'full'];
if (isset($body['fuel_level']) && $body['fuel_level'] !== null && !in_array($body['fuel_level'], $validFuel, true)) {
    json_error('VALIDATION_ERROR', 'Invalid fuel level.', 422, ['fields' => ['fuel_level' => 'Invalid value.']]);
}
$validConditions = ['excellent', 'good', 'fair', 'poor', 'damaged'];
if (isset($body['overall_condition']) && $body['overall_condition'] !== null && !in_array($body['overall_condition'], $validConditions, true)) {
    json_error('VALIDATION_ERROR', 'Invalid overall condition.', 422, ['fields' => ['overall_condition' => 'Invalid value.']]);
}

$params[] = $id;
$newUpdatedAt = null;

db_transaction(function () use ($fields, $params, $id, $existing, &$newUpdatedAt) {
    db_execute(
        "UPDATE inspections SET " . implode(', ', $fields) . " WHERE id = ?",
        $params
    );

    $updated      = db_row("SELECT updated_at FROM inspections WHERE id = ?", [$id]);
    $newUpdatedAt = $updated['updated_at'];

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'inspections',
        'entity_type'  => 'inspection',
        'entity_id'    => $id,
        'entity_label' => $existing['inspection_number'],
        'old_values'   => json_encode(['status' => $existing['status'], 'updated_at' => $existing['updated_at']]),
        'new_values'   => json_encode(['updated_at' => $newUpdatedAt]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['updated_at' => $newUpdatedAt]);
