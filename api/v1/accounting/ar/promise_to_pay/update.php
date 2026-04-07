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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id     = clean_int($input['id'] ?? null);
$status = clean_string($input['status'] ?? null);

if (!$id) $fields['id'] = 'Promise ID is required.';

$validStatuses = ['kept', 'broken', 'cancelled'];
if (!$status) {
    $fields['status'] = 'Please select a status.';
} elseif (!in_array($status, $validStatuses, true)) {
    $fields['status'] = 'Status must be kept, broken, or cancelled.';
}

if ($fields) {
    json_validation_error($fields);
}

$promise = db_row("SELECT * FROM acc_promise_to_pay WHERE id = ?", [$id]);
if (!$promise) {
    json_error('NOT_FOUND', 'Promise record not found.', 404, [
        'fields' => ['id' => 'Promise record not found.'],
    ]);
}

if ($promise['status'] !== 'pending') {
    json_error('INVALID_TRANSITION',
        "Cannot change status from '{$promise['status']}' — only pending promises can be updated.", 409,
        ['fields' => ['status' => "Cannot change status from '{$promise['status']}' — only pending promises can be updated."]]);
}

$updateData = [
    'status' => $status,
];

if ($status === 'kept') {
    $updateData['actual_payment_date'] = clean_date($input['actual_payment_date'] ?? null) ?? date('Y-m-d');
}

$notes = clean_string($input['notes'] ?? null, 2000);
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
