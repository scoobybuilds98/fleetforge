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
    "SELECT id, status, updated_at, invoice_number, generation_source
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// ADV-BILL-1 D-C: advance-batch invoices have period/billing/financial fields locked
// regardless of status. Only po_number / notes / internal_notes are editable, but those
// remain editable in any status (a customer may add a PO weeks after the prepaid invoice
// was sent). Super-admin override does NOT apply — these are CRA-correct prepaid records.
$isAdvance = ($invoice['generation_source'] ?? '') === 'advance';

// D12 / VALID-2: Only draft invoices are editable. Sent/paid/etc get a specific message.
// Advance invoices skip this check (allowed in any status, but field list is restricted below).
if (!$isAdvance && $invoice['status'] !== 'draft' && !is_super_admin()) {
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

// Only allow updating non-financial metadata on draft.
// ADV-BILL-1 D-C: advance invoices are restricted to po_number / notes / internal_notes
// even on draft — period/billing/financial fields are immutable from the moment the batch
// is created so the prepaid CRA snapshot stays intact.
//
// S-FIX-2 Phase 0.5 Bug C (defensive): financial fields are NOT editable here for any
// invoice. If a future caller posts total_amount / subtotal / tax / line_items, reject
// with 422. The Path B canonical truth table requires that any draft total_amount edit
// emit a corresponding leases.total_invoiced delta — which this endpoint does not do.
// Lock the door now so no caller silently drifts the counter.
$financialFields = [
    'total_amount', 'subtotal', 'subtotal_after_discount',
    'tax_total', 'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount',
    'discount_amount', 'discount_value',
    'line_items', 'amount', 'balance_due', 'amount_paid', 'credits_applied',
];
$forbiddenSent = array_intersect($financialFields, array_keys($body));
if (!empty($forbiddenSent)) {
    json_error(
        'IMMUTABLE_RECORD',
        'Financial fields cannot be edited via update.php. Void and recreate the invoice.',
        422,
        ['fields' => array_fill_keys($forbiddenSent, 'Field is locked. Void and recreate.')]
    );
}

$updateData = [];
$updatable = [
    'po_number'      => clean_string($body['po_number'] ?? null),
    'notes'          => clean_string($body['notes'] ?? null, 2000),
    'internal_notes' => clean_string($body['internal_notes'] ?? null, 2000),
];
if (!$isAdvance) {
    $updatable['sent_to_email'] = clean_email($body['sent_to_email'] ?? null);
}

// D-C reject: advance invoice request supplied any forbidden field.
if ($isAdvance) {
    $forbidden = ['period_start', 'period_end', 'billing_type', 'subtotal',
                  'tax_amount', 'total_amount', 'line_items', 'amount',
                  'sent_to_email'];
    $sent = array_intersect($forbidden, array_keys($body));
    if (!empty($sent)) {
        json_error(
            'IMMUTABLE_RECORD',
            'Advance-billing invoices have period and financial fields frozen at batch creation. Only po_number, notes, and internal_notes are editable.',
            422,
            ['fields' => array_fill_keys($sent, 'Field is locked on advance-billing invoices.')]
        );
    }
}

// VALID-2: specific email-format error
if (!$isAdvance
    && array_key_exists('sent_to_email', $body)
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
