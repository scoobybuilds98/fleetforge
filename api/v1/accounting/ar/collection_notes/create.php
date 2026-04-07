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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

// Validate required fields
$customerId    = clean_int($input['customer_id'] ?? null);
$noteDate      = clean_date($input['note_date'] ?? null);
$contactMethod = clean_string($input['contact_method'] ?? null);
$note          = clean_string($input['note'] ?? null, 4000);
$outcome       = clean_string($input['outcome'] ?? null);

if (!$customerId) $fields['customer_id'] = 'Please select a customer.';
if (!$noteDate)   $fields['note_date']   = 'Note date is required.';

$validMethods = ['phone', 'email', 'letter', 'in_person', 'other'];
if (!$contactMethod) {
    $fields['contact_method'] = 'Please select a contact method.';
} elseif (!in_array($contactMethod, $validMethods, true)) {
    $fields['contact_method'] = 'Contact method must be one of: ' . implode(', ', $validMethods) . '.';
}

if (!$note) $fields['note'] = 'Note content is required.';

$validOutcomes = ['no_answer', 'left_message', 'spoke_with_customer', 'payment_promised', 'dispute', 'other'];
if (!$outcome) {
    $fields['outcome'] = 'Please select an outcome.';
} elseif (!in_array($outcome, $validOutcomes, true)) {
    $fields['outcome'] = 'Outcome must be one of: ' . implode(', ', $validOutcomes) . '.';
}

if ($fields) {
    json_validation_error($fields);
}

// Verify customer exists
if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404, [
        'fields' => ['customer_id' => 'Customer not found.'],
    ]);
}

// Optional fields
$invoiceId     = clean_int($input['invoice_id'] ?? null);
$contactPerson = clean_string($input['contact_person'] ?? null);
$followUpDate  = clean_date($input['follow_up_date'] ?? null);

// Verify invoice belongs to customer if provided
if ($invoiceId) {
    if (!db_exists('invoices', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$invoiceId, $customerId])) {
        json_error('NOT_FOUND', 'Invoice not found for this customer.', 404, [
            'fields' => ['invoice_id' => 'Invoice not found for this customer.'],
        ]);
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
