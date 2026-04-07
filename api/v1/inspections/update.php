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

$body       = json_body();
$fieldErrors = [];

$id        = clean_int($body['id'] ?? null);
$updatedAt = clean_string($body['updated_at'] ?? null);

if (!$id) {
    $fieldErrors['id'] = 'Inspection ID is required.';
}
if (!$updatedAt) {
    $fieldErrors['updated_at'] = 'Optimistic lock token is required.';
}
if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

// ── Fetch existing row (lock not needed here — D19 detects conflict via updated_at comparison)
$existing = db_row("SELECT * FROM inspections WHERE id = ?", [$id]);
if (!$existing) json_error('NOT_FOUND', 'Inspection not found.', 404);

// D19 optimistic lock — reject if another user modified the record since the client loaded it
if ($existing['updated_at'] !== $updatedAt) {
    json_error('STALE_DATA',
        'This inspection was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This inspection was modified by another user. Refresh and try again.']]);
}

// Block edits to completed/signed inspections (immutable — sections+photos have their own rules)
if (in_array($existing['status'], ['complete', 'signed'], true)) {
    json_error('IMMUTABLE_RECORD',
        'Completed or signed inspections cannot be edited.', 422,
        ['fields' => ['status' => "Cannot edit a {$existing['status']} inspection."]]);
}

// Validate enums + numeric fields BEFORE building SQL
$validTypes = ['pre_lease', 'post_lease', 'periodic', 'damage', 'compliance'];
if (isset($body['inspection_type']) && !in_array($body['inspection_type'], $validTypes, true)) {
    $fieldErrors['inspection_type'] = 'Please select a valid inspection type.';
}
$validFuel = ['empty', 'quarter', 'half', 'three_quarter', 'full'];
if (isset($body['fuel_level']) && $body['fuel_level'] !== null && $body['fuel_level'] !== ''
    && !in_array($body['fuel_level'], $validFuel, true)) {
    $fieldErrors['fuel_level'] = 'Please select a valid fuel level.';
}
$validConditions = ['excellent', 'good', 'fair', 'poor', 'damaged'];
if (isset($body['overall_condition']) && $body['overall_condition'] !== null && $body['overall_condition'] !== ''
    && !in_array($body['overall_condition'], $validConditions, true)) {
    $fieldErrors['overall_condition'] = 'Please select a valid overall condition.';
}
if (array_key_exists('mileage_at_inspection', $body)
    && $body['mileage_at_inspection'] !== null
    && $body['mileage_at_inspection'] !== '') {
    $mi = clean_int($body['mileage_at_inspection']);
    if ($mi === null || $mi < 0) {
        $fieldErrors['mileage_at_inspection'] = 'Odometer cannot be negative.';
    }
}
if (array_key_exists('reefer_hours', $body)
    && $body['reefer_hours'] !== null
    && $body['reefer_hours'] !== '') {
    $rh = clean_int($body['reefer_hours']);
    if ($rh === null || $rh < 0) {
        $fieldErrors['reefer_hours'] = 'Reefer hours cannot be negative.';
    }
}
if (array_key_exists('condition_score', $body)
    && $body['condition_score'] !== null
    && $body['condition_score'] !== '') {
    $cs = clean_int($body['condition_score']);
    if ($cs === null || $cs < 0 || $cs > 100) {
        $fieldErrors['condition_score'] = 'Condition score must be between 0 and 100.';
    }
}
if (array_key_exists('lease_id', $body)) {
    $leaseId = clean_int($body['lease_id'] ?? null);
    if ($leaseId) {
        $lease = db_row("SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL", [$leaseId]);
        if (!$lease) {
            $fieldErrors['lease_id'] = 'Lease not found.';
        }
    }
}

if ($fieldErrors) {
    json_validation_error($fieldErrors);
}

// ── Optional updatable fields (only update what was sent)
$sqlFields = [];
$params    = [];

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
    $sqlFields[] = 'is_clean = ?';
    $params[]    = $body['is_clean'] === null ? null : (int)(bool)$body['is_clean'];
}

foreach ($updatable as $col => $cleaner) {
    if (array_key_exists($col, $body)) {
        $val = $cleaner($body[$col]);
        $sqlFields[] = "$col = ?";
        $params[]    = $val;
    }
}

// lease_id: allow explicit null to detach (already validated above)
if (array_key_exists('lease_id', $body)) {
    $sqlFields[] = 'lease_id = ?';
    $params[]    = clean_int($body['lease_id'] ?? null);
}

if (!$sqlFields) {
    json_validation_error(['_general' => 'No updatable fields provided.'], 'No updatable fields provided.');
}

$params[] = $id;
$newUpdatedAt = null;

db_transaction(function () use ($sqlFields, $params, $id, $existing, &$newUpdatedAt) {
    db_execute(
        "UPDATE inspections SET " . implode(', ', $sqlFields) . " WHERE id = ?",
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
