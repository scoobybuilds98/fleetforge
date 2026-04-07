<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-transactions/exclude.php
 *
 * Mark a bank transaction as excluded (e.g. personal charge on business card).
 * Excluded transactions are skipped during reconciliation.
 *
 * @method  POST
 * @body    id, exclude (1 to exclude, 0 to un-exclude)
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
$exclude = clean_int($_POST['exclude'] ?? null);

if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);
if ($exclude === null || !in_array($exclude, [0, 1])) {
    json_error('VALIDATION_ERROR', 'exclude must be 0 or 1.', 422);
}

$txn = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
if (!$txn) json_error('NOT_FOUND', 'Bank transaction not found.', 404);
if ($txn['is_cleared']) {
    json_error('VALIDATION_ERROR', 'Cannot exclude a cleared/reconciled transaction.', 422);
}

$userId = current_user_id();
$newStatus = $exclude ? 'excluded' : 'unmatched';

db_transaction(function () use ($id, $newStatus, $exclude, $txn, $userId) {
    db_update('acc_bank_transactions', [
        'status' => $newStatus,
    ], 'id = ?', [$id]);

    $action = $exclude ? 'excluded' : 'un-excluded';
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'bank_transaction',
        'entity_id'   => $id,
        'notes'       => "Bank transaction {$action}: {$txn['description']}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row("SELECT * FROM acc_bank_transactions WHERE id = ?", [$id]);
json_success($updated);
