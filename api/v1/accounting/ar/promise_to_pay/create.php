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

$customerId    = clean_int($_POST['customer_id'] ?? null);
$promisedAmt   = clean_decimal($_POST['promised_amount'] ?? null);
$promiseDate   = clean_date($_POST['promise_date'] ?? null);

if (!$customerId)  json_error('VALIDATION_ERROR', 'customer_id is required.', 422);
if (!$promisedAmt || bccomp($promisedAmt, '0', 2) <= 0) {
    json_error('VALIDATION_ERROR', 'promised_amount must be greater than zero.', 422);
}
if (!$promiseDate) json_error('VALIDATION_ERROR', 'promise_date is required.', 422);

if (!db_exists('customers', 'id = ? AND deleted_at IS NULL', [$customerId])) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

$invoiceId  = clean_int($_POST['invoice_id'] ?? null);
$promisedBy = clean_string($_POST['promised_by'] ?? null);
$notes      = clean_string($_POST['notes'] ?? null, 2000);

if ($invoiceId) {
    if (!db_exists('invoices', 'id = ? AND customer_id = ? AND deleted_at IS NULL', [$invoiceId, $customerId])) {
        json_error('NOT_FOUND', 'Invoice not found for this customer.', 404);
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
