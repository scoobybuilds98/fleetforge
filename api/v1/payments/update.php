<?php
declare(strict_types=1);

/**
 * api/v1/payments/update.php
 *
 * Update payment metadata only — reference_number, bank_name, notes, internal_notes.
 * Financial fields (amount, currency, payment_method, dates) are IMMUTABLE once
 * a payment is recorded with status != 'pending' (D12 / spec §1.3 — CRA compliance).
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required for D19 optimistic lock),
 *               reference_number, bank_name, check_number, notes, internal_notes
 * @auth    Session required; require_permission('payments','edit')
 * @returns 200 { id, updated_at } | 409 STALE_DATA | 422 IMMUTABLE_RECORD
 *
 * Decisions: D5/D13 (soft-delete), D19 (optimistic lock), D12 (immutability)
 * Session: S009
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('payments', 'edit');

$body = json_body();

// VALID-2: accumulate required-field errors
$fields = [];

$id = clean_int($body['id'] ?? null);
if (!$id) {
    $fields['id'] = 'Payment ID is required.';
}

// D19: updated_at must be submitted so we can detect concurrent edits
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}

if ($fields) {
    json_validation_error($fields);
}

// Load existing payment
$payment = db_row(
    "SELECT id, payment_number, status, updated_at FROM payments WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$payment) {
    json_error('NOT_FOUND', 'Payment not found.', 404);
}

// D19: Optimistic lock — reject if record was modified since the form loaded
if (!optimistic_lock_matches($submittedUpdatedAt, $payment['updated_at'])) {
    json_error('STALE_DATA',
        'This payment was modified by another user. Please refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This payment was modified by another user. Please refresh and try again.']]);
}

// D12: Financial fields on non-pending payments are immutable (CRA compliance)
// Only metadata fields (reference, notes) are editable after recording.
// We do NOT reject the entire update — we simply ignore any financial field
// that was submitted and only apply safe metadata fields.

$updates = [];

if (array_key_exists('reference_number', $body)) {
    $updates['reference_number'] = clean_string($body['reference_number'] ?? null, 100);
}
if (array_key_exists('bank_name', $body)) {
    $updates['bank_name'] = clean_string($body['bank_name'] ?? null, 100);
}
if (array_key_exists('check_number', $body)) {
    $updates['check_number'] = clean_string($body['check_number'] ?? null, 50);
}
if (array_key_exists('notes', $body)) {
    $updates['notes'] = clean_string($body['notes'] ?? null, 2000);
}
if (array_key_exists('internal_notes', $body)) {
    $updates['internal_notes'] = clean_string($body['internal_notes'] ?? null, 2000);
}

if (empty($updates)) {
    json_validation_error([], 'No editable fields provided.');
}

$updates['updated_at'] = date('Y-m-d H:i:s');

db_transaction(function () use ($id, $updates, $payment) {
    db_update('payments', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'payments',
        'entity_type'  => 'payment',
        'entity_id'    => $id,
        'entity_label' => $payment['payment_number'],
        'notes'        => "Payment {$payment['payment_number']} metadata updated.",
        'old_values'   => json_encode(['reference_number' => $payment['reference_number'] ?? null]),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

// NOTE (S-QBO-PAYMENT-UPDATE / D-QBO-PAYMENT-UPDATE-1, decision D2):
// PaymentPusher::pushUpdate is now implemented and PaymentEnqueuer accepts
// 'update', but this endpoint deliberately does NOT auto-enqueue a QBO
// push. Of the 5 editable fields above, only `reference_number` maps to a
// QBO payload field (`PaymentRefNum`); the other 4 are FF-only. Auto-
// enqueuing on every metadata edit would queue near-noop QBO re-pushes for
// the common case (bank_name/check_number/notes/internal_notes). Operators
// who need to propagate a `reference_number` change to QBO trigger it
// explicitly via /quickbooks/manual_sync → Force re-sync (payments)
// (S-QBO-26 force_resync path). Re-evaluate if a ticket asks for automatic
// PaymentRefNum sync.

json_success(['id' => $id, 'updated_at' => $updates['updated_at']]);
