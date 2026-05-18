<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/promise_to_pay/delete.php
 *
 * Hard-delete a promise-to-pay record. acc_promise_to_pay has no
 * deleted_at column — promises are operational records (collection
 * commitments), not financial records, so hard delete is acceptable.
 *
 * Guard: cannot delete a fulfilled (status='kept') promise — the kept
 * record is a paper trail tying a customer payment back to a prior
 * collection commitment and must be preserved.
 *
 * @method  POST
 * @body    id (required)
 * @auth    Session required; require_permission('journal_entries','delete')
 * @returns 200 { id }
 *          404 if not found
 *          422 if status='kept'
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §22.5 (CRUD completion)
 * Session: S037-CRUD
 */

require_once dirname(__DIR__, 5) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('journal_entries', 'delete');

$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'Promise ID is required.', 422);
}

$promise = db_row("SELECT * FROM acc_promise_to_pay WHERE id = ?", [$id]);
if (!$promise) {
    json_error('NOT_FOUND', 'Promise record not found.', 404);
}

if ($promise['status'] === 'kept') {
    json_error(
        'IMMUTABLE_RECORD',
        'Cannot delete a fulfilled promise to pay. Kept promises preserve the paper trail linking a customer payment to its original commitment.',
        422,
        ['fields' => ['status' => 'Cannot delete a fulfilled promise to pay.']]
    );
}

db_transaction(function () use ($id, $promise) {
    db_execute("DELETE FROM acc_promise_to_pay WHERE id = ?", [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'delete',
        'module'      => 'accounting',
        'entity_type' => 'promise_to_pay',
        'entity_id'   => $id,
        'old_values'  => json_encode($promise),
        'notes'       => "Promise to pay #{$id} deleted (status was '{$promise['status']}')",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id]);
