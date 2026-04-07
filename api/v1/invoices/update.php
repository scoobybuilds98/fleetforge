<?php
declare(strict_types=1);

/**
 * api/v1/invoices/update.php
 *
 * Update a draft invoice. Blocks edits on non-draft invoices (D12: immutability).
 * Uses optimistic locking via updated_at (D19).
 *
 * @method  POST
 * @body    id (required), updated_at (required for D19), plus editable fields
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 422 IMMUTABLE_RECORD | 409 STALE_DATA
 *
 * Decisions: D12 (sent invoices frozen), D19 (optimistic lock)
 * Session: S008
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('invoices', 'edit');

$body = json_body();

$id = clean_int($body['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Invoice ID is required.']);
}

$invoice = db_row(
    "SELECT id, status, updated_at, invoice_number FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// D12 / VALID-2: Only draft invoices are editable. Sent/paid/etc get a specific message.
if ($invoice['status'] !== 'draft' && !is_super_admin()) {
    json_error(
        'IMMUTABLE_RECORD',
        'Sent invoices cannot be edited. Void and recreate.',
        422,
        ['fields' => ['status' => 'Sent invoices cannot be edited. Void and recreate.']]
    );
}

// D19: Optimistic lock
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_validation_error(['updated_at' => 'Optimistic lock token is required.']);
}
if ($invoice['updated_at'] !== $submittedUpdatedAt) {
    json_error(
        'STALE_DATA',
        'This invoice was modified by another user. Refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This invoice was modified by another user. Refresh and try again.']]
    );
}

// Only allow updating non-financial metadata on draft
$updateData = [];
$updatable = [
    'po_number'      => clean_string($body['po_number'] ?? null),
    'notes'          => clean_string($body['notes'] ?? null, 2000),
    'internal_notes' => clean_string($body['internal_notes'] ?? null, 2000),
    'sent_to_email'  => clean_email($body['sent_to_email'] ?? null),
];

// VALID-2: specific email-format error
if (array_key_exists('sent_to_email', $body)
    && ($body['sent_to_email'] !== null && $body['sent_to_email'] !== '')
    && $updatable['sent_to_email'] === null) {
    json_validation_error(['sent_to_email' => 'Please enter a valid email address.']);
}

foreach ($updatable as $col => $val) {
    if (array_key_exists($col, $body)) {
        $updateData[$col] = $val;
    }
}

$updateData['updated_by'] = current_user_id();

// FIX #19: wrap db_update + audit_log in single transaction
db_transaction(function () use ($id, $invoice, $updateData) {
    if (!empty($updateData)) {
        db_update('invoices', $updateData, 'id = ?', [$id]);
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'invoices',
        'entity_type'  => 'invoice',
        'entity_id'    => $id,
        'entity_label' => $invoice['invoice_number'],
        'notes'        => "Invoice {$invoice['invoice_number']} updated",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
