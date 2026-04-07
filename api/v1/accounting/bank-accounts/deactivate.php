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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id = clean_int($input['id'] ?? null);
$isActiveRaw = $input['is_active'] ?? null;
$isActive = $isActiveRaw === null ? null : (int) $isActiveRaw;

if (!$id) $fields['id'] = 'Bank account ID is required.';
if ($isActive === null || !in_array($isActive, [0, 1], true)) {
    $fields['is_active'] = 'Active status must be 0 or 1.';
}

if ($fields) {
    json_validation_error($fields);
}

$existing = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Bank account not found.', 404, [
        'fields' => ['id' => 'Bank account not found.'],
    ]);
}

// WHY: Cannot deactivate the default account — must assign a new default first
if ($isActive === 0 && $existing['is_default']) {
    json_validation_error(
        ['id' => 'Cannot deactivate the default account. Assign a new default first.'],
        'Cannot deactivate the default account. Assign a new default first.'
    );
}

// Check for unreconciled transactions if deactivating
if ($isActive === 0) {
    $unreconciledCount = db_count(
        "SELECT COUNT(*) FROM acc_bank_transactions WHERE bank_account_id = ? AND is_cleared = 0 AND status != 'excluded'",
        [$id]
    );
    if ($unreconciledCount > 0) {
        json_validation_error(
            ['id' => "Cannot deactivate — {$unreconciledCount} unreconciled transactions remain."],
            "Cannot deactivate — {$unreconciledCount} unreconciled transactions remain."
        );
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
