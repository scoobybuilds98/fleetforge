<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/promise_to_pay/update.php
 *
 * Update a promise-to-pay status (kept, broken, cancelled).
 *
 * @method  POST
 * @body    id (required), status (required), actual_payment_date?, notes?
 * @auth    Session required; require_permission('journal_entries','edit')
 * @returns 200 success
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (Collections — Promise to pay)
 * Session: S031
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'edit');

$id     = clean_int($_POST['id'] ?? null);
$status = clean_string($_POST['status'] ?? null);

if (!$id)     json_error('VALIDATION_ERROR', 'id is required.', 422);
if (!$status) json_error('VALIDATION_ERROR', 'status is required.', 422);

$validStatuses = ['kept', 'broken', 'cancelled'];
if (!in_array($status, $validStatuses, true)) {
    json_error('VALIDATION_ERROR', 'status must be one of: ' . implode(', ', $validStatuses), 422);
}

$promise = db_row("SELECT * FROM acc_promise_to_pay WHERE id = ?", [$id]);
if (!$promise) json_error('NOT_FOUND', 'Promise record not found.', 404);

if ($promise['status'] !== 'pending') {
    json_error('INVALID_TRANSITION', "Cannot change status from '{$promise['status']}' — only pending promises can be updated.", 409);
}

$updateData = [
    'status' => $status,
];

if ($status === 'kept') {
    $updateData['actual_payment_date'] = clean_date($_POST['actual_payment_date'] ?? null) ?? date('Y-m-d');
}

$notes = clean_string($_POST['notes'] ?? null, 2000);
if ($notes !== null) {
    $updateData['notes'] = $notes;
}

db_update('acc_promise_to_pay', $updateData, 'id = ?', [$id]);

db_insert('audit_log', [
    'user_id'     => current_user_id(),
    'action'      => 'update',
    'module'      => 'accounting',
    'entity_type' => 'promise_to_pay',
    'entity_id'   => $id,
    'old_values'  => json_encode(['status' => $promise['status']]),
    'new_values'  => json_encode(['status' => $status]),
    'notes'       => "Promise #{$id} marked as {$status}",
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['id' => $id, 'status' => $status]);
