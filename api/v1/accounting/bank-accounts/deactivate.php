<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-accounts/deactivate.php
 *
 * Toggle a bank account active/inactive status.
 * Cannot deactivate if it's the default account and others exist for same currency.
 *
 * @method  POST
 * @body    id, is_active (0 or 1)
 * @auth    Session required; require_permission('bank_accounts','edit')
 * @returns 200 updated bank account
 *
 * Session: S033
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('bank_accounts', 'edit');

$id = clean_int($_POST['id'] ?? null);
$isActive = clean_int($_POST['is_active'] ?? null);

if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);
if ($isActive === null || !in_array($isActive, [0, 1])) {
    json_error('VALIDATION_ERROR', 'is_active must be 0 or 1.', 422);
}

$existing = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
if (!$existing) json_error('NOT_FOUND', 'Bank account not found.', 404);

// WHY: Cannot deactivate the default account — must assign a new default first
if ($isActive === 0 && $existing['is_default']) {
    json_error('VALIDATION_ERROR', 'Cannot deactivate the default account. Assign a new default first.', 422);
}

// Check for unreconciled transactions if deactivating
if ($isActive === 0) {
    $unreconciledCount = db_count(
        "SELECT COUNT(*) FROM acc_bank_transactions WHERE bank_account_id = ? AND is_cleared = 0 AND status != 'excluded'",
        [$id]
    );
    if ($unreconciledCount > 0) {
        json_error('VALIDATION_ERROR', "Cannot deactivate — {$unreconciledCount} unreconciled transactions remain.", 422);
    }
}

$userId = current_user_id();

db_transaction(function () use ($id, $isActive, $existing, $userId) {
    db_update('acc_bank_accounts', ['is_active' => $isActive], 'id = ?', [$id]);

    $action = $isActive ? 'reactivated' : 'deactivated';
    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'status_change',
        'module'      => 'accounting',
        'entity_type' => 'bank_account',
        'entity_id'   => $id,
        'notes'       => "Bank account '{$existing['name']}' {$action}",
        'old_values'  => json_encode(['is_active' => $existing['is_active']]),
        'new_values'  => json_encode(['is_active' => $isActive]),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
json_success($updated);
