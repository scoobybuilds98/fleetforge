<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/promise_to_pay/create.php
 *
 * Record a customer's promise to pay by a specific date.
 *
 * @method  POST
 * @body    customer_id, promised_amount, promise_date, invoice_id?, promised_by?, notes?
 * @auth    Session required; require_permission('journal_entries','create')
 * @returns 201 { id }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Collections — Promise to pay)
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

$customerId    = clean_int($input['customer_id'] ?? null);
$promisedAmt   = clean_decimal($input['promised_amount'] ?? null);
$promiseDate   = clean_date($input['promise_date'] ?? null);

if (!$customerId) $fields['customer_id'] = 'Please select a customer.';
if ($promisedAmt === null || $promisedAmt === '') {
    $fields['promised_amount'] = 'Promised amount is required.';
} elseif (bccomp($promisedAmt, '0', 2) <= 0) {
    $fields['promised_amount'] = 'Promised amount must be greater than zero.';
}
if (!$promiseDate) $fields['promise_date'] = 'Promise date is required.';

if ($fields) {
    json_validation_error($fields);
}

if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404, [
        'fields' => ['customer_id' => 'Customer not found.'],
    ]);
}

$invoiceId  = clean_int($input['invoice_id'] ?? null);
$promisedBy = clean_string($input['promised_by'] ?? null);
$notes      = clean_string($input['notes'] ?? null, 2000);

if ($invoiceId) {
    if (!db_exists('invoices', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$invoiceId, $customerId])) {
        json_error('NOT_FOUND', 'Invoice not found for this customer.', 404, [
            'fields' => ['invoice_id' => 'Invoice not found for this customer.'],
        ]);
    }
}

$id = db_insert('acc_promise_to_pay', [
    'customer_id'    => $customerId,
    'invoice_id'     => $invoiceId,
    'promised_amount' => $promisedAmt,
    'promise_date'   => $promiseDate,
    'promised_by'    => $promisedBy,
    'status'         => 'pending',
    'notes'          => $notes,
    'created_by'     => current_user_id(),
]);

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'create',
    'module'      => 'accounting',
    'entity_type' => 'promise_to_pay',
    'entity_id'   => $id,
    'notes'       => "Promise to pay {$promisedAmt} by {$promiseDate} for customer #{$customerId}",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $id], 201);
