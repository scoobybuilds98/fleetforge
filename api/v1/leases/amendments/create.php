<?php
declare(strict_types=1);

/**
 * api/v1/leases/amendments/create.php
 *
 * POST → record a new lease amendment.
 *
 * An amendment is an append-only audit record. It does NOT automatically
 * change the lease rates or dates — the manager updates those separately
 * after recording the amendment. This separates the paper trail from the
 * actual field change so either can happen independently.
 *
 * Body (JSON):
 *   lease_id       int     required
 *   amendment_type string  required — one of the amendment_type enum values
 *   description    string  required — human-readable summary (max 2000 chars)
 *   old_values     object  optional — snapshot of values before the change
 *   new_values     object  optional — snapshot of values after the change
 *
 * @method  POST
 * @session AMEND-1
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();

$leaseId       = clean_int($body['lease_id'] ?? null);
$amendmentType = trim((string)($body['amendment_type'] ?? ''));
$description   = trim((string)($body['description']   ?? ''));
$oldValues     = $body['old_values'] ?? null;
$newValues     = $body['new_values'] ?? null;

// ── Validation ────────────────────────────────────────────────────────────
if (!$leaseId) {
    json_error('VALIDATION_ERROR', 'lease_id is required.', 422);
}
if ($description === '') {
    json_error('VALIDATION_ERROR', 'description is required.', 422);
}
if (mb_strlen($description) > 2000) {
    json_error('VALIDATION_ERROR', 'description must be 2000 characters or fewer.', 422);
}

$allowedTypes = ['rate_change', 'date_extension', 'unit_swap', 'add_on', 'tax_change', 'other'];
if (!in_array($amendmentType, $allowedTypes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid amendment_type.', 422);
}

// Confirm lease exists
$lease = db_row(
    "SELECT id FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$leaseId]
);
if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// ── Encode optional JSON blobs ─────────────────────────────────────────────
$oldValuesJson = ($oldValues !== null && $oldValues !== []) ? json_encode($oldValues) : null;
$newValuesJson = ($newValues !== null && $newValues !== []) ? json_encode($newValues) : null;

// ── Insert ────────────────────────────────────────────────────────────────
$newId = db_insert('lease_amendments', [
    'lease_id'       => $leaseId,
    'amendment_type' => $amendmentType,
    'description'    => $description,
    'old_values'     => $oldValuesJson,
    'new_values'     => $newValuesJson,
    'created_by'     => current_user_id(),
    'created_at'     => date('Y-m-d H:i:s'),
]);

// Return the full row for immediate display in the UI without a reload
$row = db_row(
    "SELECT
        a.id, a.lease_id, a.amendment_type, a.description,
        a.old_values, a.new_values, a.created_by, a.approved_by, a.created_at,
        COALESCE(uc.name, 'System') AS created_by_name,
        COALESCE(ua.name, '')       AS approved_by_name
     FROM lease_amendments a
     LEFT JOIN users uc ON uc.id = a.created_by
     LEFT JOIN users ua ON ua.id = a.approved_by
     WHERE a.id = ?",
    [$newId]
);

// Decode JSON blobs for the response
$row['id']         = (int)$row['id'];
$row['lease_id']   = (int)$row['lease_id'];
$row['old_values'] = $row['old_values'] ? json_decode((string)$row['old_values'], true) : null;
$row['new_values'] = $row['new_values'] ? json_decode((string)$row['new_values'], true) : null;

json_success(['amendment' => $row], 201);
