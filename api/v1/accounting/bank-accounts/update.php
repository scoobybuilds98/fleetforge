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

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id = clean_int($input['id'] ?? null);
if (!$id) {
    json_validation_error(['id' => 'Bank account ID is required.']);
}

$existing = db_row("SELECT * FROM acc_bank_accounts WHERE id = ?", [$id]);
if (!$existing) {
    json_error('NOT_FOUND', 'Bank account not found.', 404, [
        'fields' => ['id' => 'Bank account not found.'],
    ]);
}

// Build update data — only update provided fields
$data = [];
$oldValues = [];

if (array_key_exists('name', $input)) {
    $v = clean_string($input['name']);
    if (!$v) {
        $fields['name'] = 'Bank account name cannot be empty.';
    } else {
        $oldValues['name'] = $existing['name'];
        $data['name'] = $v;
    }
}
if (array_key_exists('institution', $input)) {
    $oldValues['institution'] = $existing['institution'];
    $data['institution'] = clean_string($input['institution']);
}
if (array_key_exists('account_number_last4', $input)) {
    $last4 = clean_string($input['account_number_last4'], 4);
    if ($last4 !== null && $last4 !== '' && !preg_match('/^\d{1,4}$/', $last4)) {
        $fields['account_number_last4'] = 'Last 4 digits must be numeric (up to 4 digits).';
    } else {
        $oldValues['account_number_last4'] = $existing['account_number_last4'];
        $data['account_number_last4'] = $last4;
    }
}
if (array_key_exists('routing_number', $input)) {
    $oldValues['routing_number'] = $existing['routing_number'];
    $data['routing_number'] = clean_string($input['routing_number'], 20);
}
if (array_key_exists('gl_account_id', $input) && ($v = clean_int($input['gl_account_id']))) {
    $glAccount = db_row("SELECT id, is_active FROM acc_accounts WHERE id = ?", [$v]);
    if (!$glAccount) {
        $fields['gl_account_id'] = 'GL account not found.';
    } elseif (!$glAccount['is_active']) {
        $fields['gl_account_id'] = 'GL account is inactive.';
    } else {
        $oldValues['gl_account_id'] = $existing['gl_account_id'];
        $data['gl_account_id'] = $v;
    }
}
if (array_key_exists('opening_balance', $input)) {
    $oldValues['opening_balance'] = $existing['opening_balance'];
    $data['opening_balance'] = clean_decimal($input['opening_balance']) ?? '0.00';
}
if (array_key_exists('opening_balance_date', $input)) {
    $oldValues['opening_balance_date'] = $existing['opening_balance_date'];
    $data['opening_balance_date'] = clean_date($input['opening_balance_date']);
}
if (array_key_exists('notes', $input)) {
    $oldValues['notes'] = $existing['notes'];
    $data['notes'] = clean_string($input['notes'], 2000);
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($data) && !array_key_exists('is_default', $input)) {
    json_validation_error(['_general' => 'No fields to update.']);
}

$userId = current_user_id();

db_transaction(function () use ($id, &$data, $oldValues, $existing, $userId, $input) {
    // Handle is_default toggle
    if (array_key_exists('is_default', $input)) {
        $newDefault = (int) $input['is_default'];
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
