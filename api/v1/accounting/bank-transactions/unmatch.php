<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transactions/unmatch.php
 *
 * Remove the match from a bank transaction, reverting it to unmatched.
 * Cannot unmatch transactions that are already cleared/reconciled.
 *
 * @method  POST
 * @body    id
 * @auth    Session required; require_permission('bank_accounts','edit')
 * @returns 200 updated transaction
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Transaction ID is required.']);
}

$txn = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
if (!$txn) {
    json_error('NOT_FOUND', 'Bank transaction not found.', 404, [
        'fields' => ['id' => 'Bank transaction not found.'],
    ]);
}
if ($txn['status'] !== 'matched') {
    json_validation_error(
        ['id' => 'Transaction is not currently matched.'],
        'Transaction is not currently matched.'
    );
}
if ($txn['is_cleared']) {
    json_validation_error(
        ['id' => 'Cannot unmatch a cleared/reconciled transaction.'],
        'Cannot unmatch a cleared/reconciled transaction.'
    );
}

$userId = current_user_id();

db_transaction(function () use ($id, $txn, $userId) {
    db_update('acc_bank_transactions', [
        'status'       => 'unmatched',
        'matched_type' => null,
        'matched_id'   => null,
        'matched_at'   => null,
        'matched_by'   => null,
    ], 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'bank_transaction',
        'entity_id'   => $id,
        'notes'       => "Bank transaction unmatched (was {$txn['matched_type']} #{$txn['matched_id']})",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
json_success($updated);
