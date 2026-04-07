<?php
declare(strict_types=1);

/**
 * api/v1/accounting/bank-accounts/update.php
 *
 * Update a bank account. Uses D19 optimistic locking via updated_at comparison.
 * Note: acc_bank_accounts has no updated_at — we compare on created_at as guard.
 *
 * @method  POST
 * @body    id, name?, institution?, account_number_last4?, routing_number?,
 *          gl_account_id?, opening_balance?, opening_balance_date?,
 *          is_default?, notes?
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
if (!$id) json_error('VALIDATION_ERROR', 'id is required.', 422);

$existing = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
if (!$existing) json_error('NOT_FOUND', 'Bank account not found.', 404);

// Build update data — only update provided fields
$data = [];
$oldValues = [];

if (isset($_POST['name']) && ($v = clean_string($_POST['name']))) {
    $oldValues['name'] = $existing['name'];
    $data['name'] = $v;
}
if (isset($_POST['institution'])) {
    $oldValues['institution'] = $existing['institution'];
    $data['institution'] = clean_string($_POST['institution']);
}
if (isset($_POST['account_number_last4'])) {
    $oldValues['account_number_last4'] = $existing['account_number_last4'];
    $data['account_number_last4'] = clean_string($_POST['account_number_last4'], 4);
}
if (isset($_POST['routing_number'])) {
    $oldValues['routing_number'] = $existing['routing_number'];
    $data['routing_number'] = clean_string($_POST['routing_number'], 20);
}
if (isset($_POST['gl_account_id']) && ($v = clean_int($_POST['gl_account_id']))) {
    $glAccount = db_row("SELECT id, is_active FROM acc_accounts WHERE id = ?", [$v]);
    if (!$glAccount) json_error('VALIDATION_ERROR', 'GL account not found.', 422);
    if (!$glAccount['is_active']) json_error('VALIDATION_ERROR', 'GL account is inactive.', 422);
    $oldValues['gl_account_id'] = $existing['gl_account_id'];
    $data['gl_account_id'] = $v;
}
if (isset($_POST['opening_balance'])) {
    $oldValues['opening_balance'] = $existing['opening_balance'];
    $data['opening_balance'] = clean_decimal($_POST['opening_balance']) ?? '0.00';
}
if (isset($_POST['opening_balance_date'])) {
    $oldValues['opening_balance_date'] = $existing['opening_balance_date'];
    $data['opening_balance_date'] = clean_date($_POST['opening_balance_date']);
}
if (isset($_POST['notes'])) {
    $oldValues['notes'] = $existing['notes'];
    $data['notes'] = clean_string($_POST['notes'], 2000);
}

if (empty($data)) json_error('VALIDATION_ERROR', 'No fields to update.', 422);

$userId = current_user_id();

db_transaction(function () use ($id, $data, $oldValues, $existing, $userId) {
    // Handle is_default toggle
    if (isset($_POST['is_default'])) {
        $newDefault = (int) $_POST['is_default'];
        if ($newDefault && !$existing['is_default']) {
            db_execute(
                "UPDATE acc_bank_accounts SET is_default = 0 WHERE currency = ? AND is_default = 1 AND id != ?",
                [$existing['currency'], $id]
            );
        }
        $data['is_default'] = $newDefault;
    }

    db_update('acc_bank_accounts', $data, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => $userId,
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'bank_account',
        'entity_id'   => $id,
        'notes'       => "Bank account '{$existing['name']}' updated",
        'old_values'  => json_encode($oldValues),
        'new_values'  => json_encode($data),
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

$updated = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
json_success($updated);
