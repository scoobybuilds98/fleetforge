<?php
declare(strict_types=1);

/**
 * api/v1/invoices/update.php
 *
 * Update invoice metadata. All financial fields are immutable for every
 * status and every role (D14 / CRA defensibility). No super_admin exemption
 * (D-C, S-PROD-1B). Post-send mutable fields (spec §12.5): po_number,
 * internal_notes, sent_to_email, delivery_method. The notes field
 * (customer-visible on PDF) is locked at send.
 *
 * @method  POST
 * @body    id (required), updated_at (required for D19), plus editable fields
 * @auth    Session required; require_permission('invoices','edit')
 * @returns 200 success | 422 IMMUTABLE_RECORD | 409 STALE_DATA
 *
 * Decisions: D14 (invoice immutability — uniform, no role exemptions per D-C),
 *            D19 (optimistic lock, normalized to Unix seconds per S-PROD-1B),
 *            ADV-BILL-1 (advance invoices)
 * Session: S-PROD-1B (#34 invoice immutability guard)
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
    "SELECT id, status, updated_at, invoice_number, generation_source, notes, sent_date
     FROM invoices WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$invoice) {
    json_error('NOT_FOUND', 'Invoice not found.', 404);
}

// ADV-BILL-1 D-C: advance-batch invoices have period/billing/financial fields locked
// regardless of status. Only po_number, notes, and internal_notes are editable,
// but those remain editable in any status (a customer may add a PO weeks after
// the prepaid invoice was sent). Super-admin override does NOT apply — these are
// CRA-correct prepaid records.
$isAdvance = ($invoice['generation_source'] ?? '') === 'advance';

// D19: Optimistic lock — normalized to Unix timestamp seconds (S-PROD-1B T3).
// Uses optimistic_lock_matches() from includes/db.php to handle DST transitions,
// microsecond precision differences, and timezone-format variations.
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_validation_error(['updated_at' => 'Optimistic lock token is required.']);
}
if (!optimistic_lock_matches($submittedUpdatedAt, $invoice['updated_at'])) {
    json_error(
        'STALE_DATA',
        'This invoice was modified by another user. Refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This invoice was modified by another user. Refresh and try again.']]
    );
}

// D14 / D-C: financial fields locked for ALL statuses and ALL roles — no exemptions.
// S-FIX-2 Phase 0.5 Bug C: Path B counter integrity requires that any draft
// total_amount edit emit a corresponding leases.total_invoiced delta — which
// this endpoint does not do. Lock the door so no caller silently drifts counters.
$financialFields = [
    'total_amount', 'subtotal', 'subtotal_after_discount',
    'tax_total', 'tax_gst_amount', 'tax_pst_amount', 'tax_hst_amount',
    'discount_amount', 'discount_value',
    'line_items', 'amount', 'balance_due', 'amount_paid', 'credits_applied',
];
$blockedFinancial = array_values(array_intersect($financialFields, array_keys($body)));
if (!empty($blockedFinancial)) {
    // Audit every blocked attempt on a finalized invoice for CRA defensibility (D14).
    // "On {date}, user {email} attempted to modify financial field(s) [{fields}] on
    //  invoice {number} (status: {status}, sent: {date}). Modification was rejected
    //  by the system per immutability policy D14. Original values preserved."
    if ($invoice['status'] !== 'draft') {
        $actor = current_user();
        db_insert('audit_log', [
            'user_id'      => current_user_id(),
            'user_name'    => $actor['name'] ?? 'unknown',
            'action'       => 'update',
            'module'       => 'invoices',
            'entity_type'  => 'invoice',
            'entity_id'    => $id,
            'entity_label' => $invoice['invoice_number'],
            'notes'        => sprintf(
                'On %s, user %s (%s) attempted to modify financial field(s) [%s] on invoice %s (status: %s, sent: %s). Modification was rejected by the system per immutability policy D14. Original values preserved.',
                date('Y-m-d H:i:s'),
                $actor['name'] ?? 'unknown',
                $actor['email'] ?? 'unknown',
                implode(', ', $blockedFinancial),
                $invoice['invoice_number'],
                $invoice['status'],
                $invoice['sent_date'] ?? 'not sent'
            ),
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }
    json_error(
        'IMMUTABLE_RECORD',
        'Financial fields cannot be edited via update.php. Void and recreate the invoice.',
        422,
        ['fields' => array_fill_keys($blockedFinancial, 'Field is locked. Void and recreate.')]
    );
}

// ADV-BILL-1 D-C: advance invoices restrict additional fields beyond the financial set
if ($isAdvance) {
    $forbidden = ['period_start', 'period_end', 'billing_type', 'subtotal',
                  'tax_amount', 'total_amount', 'line_items', 'amount',
                  'sent_to_email'];
    $sent = array_values(array_intersect($forbidden, array_keys($body)));
    if (!empty($sent)) {
        json_error(
            'IMMUTABLE_RECORD',
            'Advance-billing invoices have period and financial fields frozen at batch creation. Only po_number, notes, and internal_notes are editable.',
            422,
            ['fields' => array_fill_keys($sent, 'Field is locked on advance-billing invoices.')]
        );
    }
}

// D14 §12.5: notes locked at send — customer-visible on PDF, not in the spec mutable
// list for finalized invoices. Only reject if the submitted value actually differs
// from the current DB value (allows full-PUT roundtrips without false positives).
$isLocked = !$isAdvance && $invoice['status'] !== 'draft';
if ($isLocked && array_key_exists('notes', $body)) {
    $submittedNotes = clean_string($body['notes'] ?? null, 2000) ?? '';
    $currentNotes   = $invoice['notes'] ?? '';
    if ($submittedNotes !== $currentNotes) {
        json_error(
            'IMMUTABLE_RECORD',
            "Invoice {$invoice['invoice_number']} notes are locked once sent. Use internal_notes for admin comments.",
            422,
            ['fields' => ['notes' => 'Notes are locked once the invoice is sent. Use internal_notes.']]
        );
    }
}

// Build editable field set (D14 §12.5).
// Mutable on any status for non-advance: po_number, internal_notes, sent_to_email,
// delivery_method. notes additionally editable on draft (invoice still being authored).
$updatable = [
    'po_number'      => clean_string($body['po_number'] ?? null),
    'internal_notes' => clean_string($body['internal_notes'] ?? null, 2000),
];

if (!$isLocked) {
    $updatable['notes'] = clean_string($body['notes'] ?? null, 2000);
}

if (!$isAdvance) {
    $updatable['sent_to_email'] = clean_email($body['sent_to_email'] ?? null);

    // delivery_method: spec §12.5 lists this as mutable post-send (added S-PROD-1B)
    if (array_key_exists('delivery_method', $body)) {
        $dm = clean_string($body['delivery_method'] ?? null);
        if ($dm !== null && !in_array($dm, ['email', 'manual', 'portal'], true)) {
            json_validation_error(['delivery_method' => 'delivery_method must be one of: email, manual, portal.']);
        }
        $updatable['delivery_method'] = $dm;
    }
}

// VALID-2: specific email-format error
if (!$isAdvance
    && array_key_exists('sent_to_email', $body)
    && ($body['sent_to_email'] !== null && $body['sent_to_email'] !== '')
    && $updatable['sent_to_email'] === null) {
    json_validation_error(['sent_to_email' => 'Please enter a valid email address.']);
}

$updateData = [];
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

// S-AUDIT-LIFECYCLE-1 #24b: post-send metadata edits (po_number / billing
// email) must mirror to QBO or the downstream copy drifts (D-QBO-CORE-1).
// Best-effort AFTER commit per D-ENQUEUER-CONTRACT; gate-0 restricts to
// sent/paid/partially_paid and the pusher demotes unmapped invoices.
if (array_intersect(array_keys($updateData), ['po_number', 'sent_to_email'])
    && in_array($invoice['status'], ['sent', 'paid', 'partially_paid'], true)
) {
    \FleetForge\QboPushers\InvoiceEnqueuer::enqueue($id, 'update');
}

json_success(['id' => $id]);
