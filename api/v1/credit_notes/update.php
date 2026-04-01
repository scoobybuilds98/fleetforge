<?php
declare(strict_types=1);

/**
 * api/v1/credit_notes/update.php
 *
 * Update metadata-only fields on a credit note.
 * Financial fields (amount, amount_remaining, currency, status) are immutable
 * after creation — only reason, internal_notes, and expires_at may be changed.
 *
 * Business rules:
 *   - D12/§12.5: Financial fields are immutable — reject any attempt to change them
 *   - D19: Optimistic lock — caller must pass updated_at; 409 STALE_DATA if modified concurrently
 *   - Cannot update a voided credit note (IMMUTABLE_RECORD)
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required for D19),
 *               reason?, internal_notes?, expires_at?
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 { id, credit_note_number, updated_at }
 *
 * Decisions: D12/D19, §12.5 Financial Immutability
 * Session: S011
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// D19: Optimistic lock — caller sends the updated_at they last saw
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for concurrency control.', 422);
}

// Load existing record
$cn = db_row(
    "SELECT id, credit_note_number, status, updated_at FROM credit_notes WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$cn) {
    json_error('NOT_FOUND', 'Credit note not found.', 404);
}

// Cannot edit a voided credit note (D12 §12.5)
if ($cn['status'] === 'void') {
    json_error('IMMUTABLE_RECORD', 'A voided credit note cannot be modified.', 422);
}

// D19: Compare updated_at — reject if record was modified since caller loaded it
if ($cn['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'This credit note was modified by another user. Please refresh and try again.', 409);
}

// Collect allowed metadata updates
$updates = [];

if (array_key_exists('reason', $body)) {
    $reason = clean_string($body['reason'] ?? null, 2000);
    if (!$reason) {
        json_error('VALIDATION_ERROR', 'reason cannot be empty.', 422);
    }
    $updates['reason'] = $reason;
}

if (array_key_exists('internal_notes', $body)) {
    $updates['internal_notes'] = clean_string($body['internal_notes'] ?? null, 2000);
}

if (array_key_exists('expires_at', $body)) {
    $expiresAt = $body['expires_at'] === null ? null : clean_date($body['expires_at']);
    if ($body['expires_at'] !== null && !$expiresAt) {
        json_error('VALIDATION_ERROR', 'expires_at must be a valid date (YYYY-MM-DD) or null.', 422);
    }
    $updates['expires_at'] = $expiresAt;
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No updatable fields provided. Allowed: reason, internal_notes, expires_at.', 422);
}

$updates['updated_at'] = date('Y-m-d H:i:s');

// Capture old values for audit trail
$oldValues = [
    'reason'         => $cn['reason']         ?? null,
    'internal_notes' => $cn['internal_notes']  ?? null,
    'expires_at'     => $cn['expires_at']      ?? null,
];

db_transaction(function () use ($id, $updates, $oldValues, $cn) {
    db_update('credit_notes', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'credit_note',
        'entity_id'    => $id,
        'entity_label' => $cn['credit_note_number'],
        'old_values'   => json_encode($oldValues),
        'new_values'   => json_encode($updates),
        'notes'        => "Metadata updated on credit note {$cn['credit_note_number']}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row(
    "SELECT id, credit_note_number, reason, internal_notes, expires_at, updated_at FROM credit_notes WHERE id = ?",
    [$id]
);

json_success($updated);
