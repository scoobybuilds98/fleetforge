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

$id = clean_int($_POST['id'] ?? null);
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$txn = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
if (!$txn) json_error('NOT_FOUND', 'Bank transaction not found.', 404);
if ($txn['status'] !== 'matched') {
    json_error('VALIDATION_ERROR', 'Transaction is not currently matched.', 422);
}
if ($txn['is_cleared']) {
    json_error('VALIDATION_ERROR', 'Cannot unmatch a cleared/reconciled transaction.', 422);
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
