<?php
declare(strict_types=1);

/**
 * api/v1/compliance/update.php
 *
 * Update a compliance document's from/expiry dates for a single equipment unit.
 * Accepts a doc_type (cvi/registration/insurance) and sets both the from_date
 * and expiry_date in one atomic write — the two dates are always updated together
 * so the UI can submit whatever the user typed without a second round-trip.
 *
 * Either date may be null (empty string → null) to clear it independently.
 *
 * @method  POST
 * @body    unit_id     (int, required)
 *          doc_type    (string, one of: cvi | registration | insurance)
 *          expiry_date (string Y-m-d | null — the "to" date)
 *          from_date   (string Y-m-d | null — the "valid from" date)
 *          updated_at  (string Y-m-d H:i:s, required — D19 optimistic lock)
 * @auth    Session required; require_permission('compliance','edit')
 * @returns 200 { unit_id, doc_type, expiry_date, from_date, updated_at }
 *          409 STALE_DATA
 *          422 VALIDATION_ERROR
 *
 * Decisions: D5 (soft delete), D19 (optimistic lock)
 * Session: S020 (updated: doc_type pair replaces single-field approach)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('compliance', 'edit');

$body = json_body();

// -----------------------------------------------------------------------
// 1. Validate unit_id
// -----------------------------------------------------------------------
$unitId = clean_int($body['unit_id'] ?? null);
if (!$unitId) {
    json_error('MISSING_REQUIRED', 'unit_id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Validate doc_type
// -----------------------------------------------------------------------
$allowedDocTypes = ['cvi', 'registration', 'insurance'];
$docType = clean_string($body['doc_type'] ?? null, 20);
if (!$docType || !in_array($docType, $allowedDocTypes, true)) {
    json_error('VALIDATION_ERROR', 'doc_type must be one of: cvi, registration, insurance.', 422);
}

// -----------------------------------------------------------------------
// 3. Parse + validate date values (empty string → null)
// -----------------------------------------------------------------------
$expiryRaw = clean_string($body['expiry_date'] ?? null);
$fromRaw   = clean_string($body['from_date']   ?? null);

// Validate Y-m-d format when a value is provided
foreach ([['expiry_date', $expiryRaw], ['from_date', $fromRaw]] as [$label, $val]) {
    if ($val !== null && $val !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) || !checkdate(
            (int)substr($val, 5, 2),
            (int)substr($val, 8, 2),
            (int)substr($val, 0, 4)
        )) {
            json_error('VALIDATION_ERROR', "$label must be a valid date in Y-m-d format or empty.", 422);
        }
    }
}

$expiryDate = ($expiryRaw !== null && $expiryRaw !== '') ? $expiryRaw : null;
$fromDate   = ($fromRaw   !== null && $fromRaw   !== '') ? $fromRaw   : null;

// -----------------------------------------------------------------------
// 4. Load unit + D19 optimistic lock
// -----------------------------------------------------------------------
$unit = db_row(
    "SELECT id, unit_number, updated_at FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$unitId]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('VALIDATION_ERROR', 'updated_at is required for optimistic locking.', 422);
}
if ($unit['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'This unit was modified by another user. Refresh the grid and try again.', 409);
}

// -----------------------------------------------------------------------
// 5. Build column names from doc_type (safe — allowlisted above)
// -----------------------------------------------------------------------
$expiryCol = $docType . '_expiry';
$fromCol   = $docType . '_from_date';

// -----------------------------------------------------------------------
// 6. Update + audit_log in one transaction
// -----------------------------------------------------------------------
$docLabel = ucfirst($docType);

db_transaction(function () use ($unitId, $unit, $expiryCol, $fromCol, $expiryDate, $fromDate, $docLabel) {
    db_update(
        'equipment_units',
        [$expiryCol => $expiryDate, $fromCol => $fromDate],
        'id = ?',
        [$unitId]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'compliance',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $unitId,
        'entity_label' => $unit['unit_number'],
        'notes'        => "{$docLabel} compliance dates updated for unit {$unit['unit_number']}: from={$fromDate}, expiry={$expiryDate}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// -----------------------------------------------------------------------
// 7. Return fresh updated_at so client keeps D19 in sync
// -----------------------------------------------------------------------
$fresh = db_row("SELECT updated_at FROM equipment_units WHERE id = ?", [$unitId]);

json_success([
    'unit_id'     => $unitId,
    'doc_type'    => $docType,
    'expiry_date' => $expiryDate,
    'from_date'   => $fromDate,
    'updated_at'  => $fresh['updated_at'],
]);
