<?php declare(strict_types=1);

/**
 * api/v1/accounting/accounts/update.php
 *
 * Update an existing chart-of-accounts entry. System accounts cannot have
 * their code or account_type changed. Uses optimistic locking via updated_at
 * to prevent silent overwrites from concurrent edits.
 *
 * Dependencies: bootstrap.php
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required — optimistic lock),
 *               name, description, account_subtype, parent_id, is_header,
 *               currency, normal_balance, tax_line_code, coa_group, notes,
 *               code (rejected on system accounts), account_type (rejected on system accounts)
 * @auth    Session required; require_permission('chart_of_accounts','edit')
 * @returns 200 { id, updated_at } | 409 STALE_DATA | 422 IMMUTABLE_FIELD
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md SS3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('chart_of_accounts', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$body     = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

// -----------------------------------------------------------------------
// 1. Required identifiers
// -----------------------------------------------------------------------
$id = clean_int($body['id'] ?? null);
if (!$id) $fields['id'] = 'Account ID is required.';

// WHY: Optimistic lock — the client must send the updated_at it loaded so we can
// detect if another user saved changes in between.
$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Concurrency token (updated_at) is required.';
}

if ($fields) {
    json_validation_error($fields);
}

// -----------------------------------------------------------------------
// 2. Load existing account
// -----------------------------------------------------------------------
$account = db_row(
    "SELECT id, code, name, account_type, is_system, updated_at
     FROM acc_accounts WHERE id = ?",
    [$id]
);
if (!$account) {
    json_error('NOT_FOUND', 'Account not found.', 404, [
        'fields' => ['id' => 'Account not found.'],
    ]);
}

// Optimistic lock check
if (!optimistic_lock_matches($submittedUpdatedAt, $account['updated_at'])) {
    json_error(
        'STALE_DATA',
        'This account was modified by another user. Please refresh and try again.',
        409,
        ['fields' => ['updated_at' => 'This account was modified by another user. Please refresh and try again.']]
    );
}

// -----------------------------------------------------------------------
// 3. System account guard — cannot change code or account_type
// -----------------------------------------------------------------------
$isSystem = (int) $account['is_system'] === 1;

if ($isSystem) {
    if (array_key_exists('code', $body) && clean_string($body['code'] ?? null) !== $account['code']) {
        $fields['code'] = 'Cannot change the code of a system account.';
    }
    if (array_key_exists('account_type', $body) && clean_string($body['account_type'] ?? null) !== $account['account_type']) {
        $fields['account_type'] = 'Cannot change the account type of a system account.';
    }
    if ($fields) {
        json_error('IMMUTABLE_FIELD', 'System account has immutable fields.', 422, ['fields' => $fields]);
    }
}

// -----------------------------------------------------------------------
// 4. Build update set from submitted fields
// -----------------------------------------------------------------------
$validTypes = [
    'asset', 'liability', 'equity', 'revenue',
    'cost_of_revenue', 'operating_expense', 'other_income', 'other_expense',
];

$updates = [];

if (array_key_exists('code', $body)) {
    $newCode = clean_string($body['code'], 20);
    if (!$newCode) {
        $fields['code'] = 'Account code cannot be empty.';
    } elseif ($newCode !== $account['code']) {
        $dup = db_row("SELECT id FROM acc_accounts WHERE code = ? AND id != ?", [$newCode, $id]);
        if ($dup) {
            $fields['code'] = "Account code '{$newCode}' already exists.";
        }
    }
    if (!isset($fields['code'])) $updates['code'] = $newCode;
}

if (array_key_exists('name', $body)) {
    $newName = clean_string($body['name'], 255);
    if (!$newName) {
        $fields['name'] = 'Account name cannot be empty.';
    } else {
        $updates['name'] = $newName;
    }
}

if (array_key_exists('account_type', $body)) {
    $newType = clean_string($body['account_type'] ?? null);
    if (!$newType || !in_array($newType, $validTypes, true)) {
        $fields['account_type'] = 'Account type must be one of: ' . implode(', ', $validTypes) . '.';
    } else {
        $updates['account_type'] = $newType;
    }
}

if (array_key_exists('account_subtype', $body)) {
    $updates['account_subtype'] = clean_string($body['account_subtype'] ?? null, 100);
}

if (array_key_exists('parent_id', $body)) {
    $newParentId = clean_int($body['parent_id'] ?? null);
    if ($newParentId) {
        // WHY: Prevent circular references — cannot parent to self
        if ($newParentId === $id) {
            $fields['parent_id'] = 'An account cannot be its own parent.';
        } else {
            $parent = db_row("SELECT id, is_header, name FROM acc_accounts WHERE id = ?", [$newParentId]);
            if (!$parent) {
                $fields['parent_id'] = 'Parent account not found.';
            } elseif ((int) $parent['is_header'] !== 1) {
                $fields['parent_id'] = "Parent account '{$parent['name']}' is not a header account.";
            }
        }
    }
    if (!isset($fields['parent_id'])) $updates['parent_id'] = $newParentId;
}

if (array_key_exists('is_header', $body)) {
    $updates['is_header'] = (int) (($body['is_header'] ?? false) ? 1 : 0);
}

if (array_key_exists('currency', $body)) {
    $cur = strtoupper(clean_string($body['currency'] ?? 'CAD') ?? 'CAD');
    if (!in_array($cur, ['CAD', 'USD'], true)) {
        $fields['currency'] = 'Currency must be CAD or USD.';
    } else {
        $updates['currency'] = $cur;
    }
}

if (array_key_exists('normal_balance', $body)) {
    $nb = clean_string($body['normal_balance'] ?? null);
    if ($nb && !in_array($nb, ['debit', 'credit'], true)) {
        $fields['normal_balance'] = 'Normal balance must be debit or credit.';
    } else {
        $updates['normal_balance'] = $nb;
    }
}

if (array_key_exists('tax_line_code', $body)) {
    $updates['tax_line_code'] = clean_string($body['tax_line_code'] ?? null, 50);
}

if (array_key_exists('coa_group', $body)) {
    $updates['coa_group'] = clean_string($body['coa_group'] ?? null, 100);
}

if (array_key_exists('notes', $body)) {
    $updates['notes'] = clean_string($body['notes'] ?? null, 2000);
}

if (array_key_exists('description', $body)) {
    $updates['description'] = clean_string($body['description'] ?? null, 2000);
}

if ($fields) {
    json_validation_error($fields);
}

if (empty($updates)) {
    json_validation_error(['_general' => 'No editable fields provided.']);
}

$updates['updated_at'] = date('Y-m-d H:i:s');

// -----------------------------------------------------------------------
// 5. Persist inside transaction (update + audit log)
// -----------------------------------------------------------------------
db_transaction(function () use ($id, $updates, $account) {
    db_update('acc_accounts', $updates, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'update',
        'module'       => 'accounting',
        'entity_type'  => 'account',
        'entity_id'    => $id,
        'entity_label' => $account['code'] . ' — ' . $account['name'],
        'notes'        => "Updated chart of accounts entry: {$account['code']} ({$account['name']}).",
        'old_values'   => json_encode($account),
        'new_values'   => json_encode($updates),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'updated_at' => $updates['updated_at']]);
