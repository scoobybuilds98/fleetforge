<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/collection_notes/create.php
 *
 * Create a collection note for a customer. Logs phone calls, emails,
 * letters, and in-person contacts related to collections.
 *
 * @method  POST
 * @body    customer_id, note_date, contact_method, note, outcome,
 *          invoice_id?, contact_person?, follow_up_date?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Collections workflow)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'create');

// Validate required fields
$customerId    = clean_int($_POST['customer_id'] ?? null);
$noteDate      = clean_date($_POST['note_date'] ?? null);
$contactMethod = clean_string($_POST['contact_method'] ?? null);
$note          = trim($_POST['note'] ?? '');
$outcome       = clean_string($_POST['outcome'] ?? null);

if (!$customerId)    json_error('VALIDATION_ERROR', 'customer_id is required.', 422);
if (!$noteDate)      json_error('VALIDATION_ERROR', 'note_date is required.', 422);
if (!$contactMethod) json_error('VALIDATION_ERROR', 'contact_method is required.', 422);
if ($note === '')    json_error('VALIDATION_ERROR', 'note is required.', 422);
if (!$outcome)       json_error('VALIDATION_ERROR', 'outcome is required.', 422);

$validMethods = ['phone', 'email', 'letter', 'in_person', 'other'];
if (!in_array($contactMethod, $validMethods, true)) {
    json_error('VALIDATION_ERROR', 'Invalid contact_method. Must be one of: ' . implode(', ', $validMethods), 422);
}

$validOutcomes = ['no_answer', 'left_message', 'spoke_with_customer', 'payment_promised', 'dispute', 'other'];
if (!in_array($outcome, $validOutcomes, true)) {
    json_error('VALIDATION_ERROR', 'Invalid outcome. Must be one of: ' . implode(', ', $validOutcomes), 422);
}

// Verify customer exists
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// Optional fields
$invoiceId     = clean_int($_POST['invoice_id'] ?? null);
$contactPerson = clean_string($_POST['contact_person'] ?? null);
$followUpDate  = clean_date($_POST['follow_up_date'] ?? null);

// Verify invoice belongs to customer if provided
if ($invoiceId) {
    if (!db_exists('invoices', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$invoiceId, $customerId])) {
        json_error('NOT_FOUND', 'Invoice not found for this customer.', 404);
    }
}

$id = db_insert('acc_collection_notes', [
    'customer_id'    => $customerId,
    'invoice_id'     => $invoiceId,
    'note_date'      => $noteDate,
    'contact_method' => $contactMethod,
    'contact_person' => $contactPerson,
    'note'           => $note,
    'outcome'        => $outcome,
    'follow_up_date' => $followUpDate,
    'created_by'     => current_user_id(),
]);

// Audit log
db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'create',
    'module'      => 'accounting',
    'entity_type' => 'collection_note',
    'entity_id'   => $id,
    'notes'       => "Collection note added for customer #{$customerId} — {$contactMethod} ({$outcome})",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $id], 201);
