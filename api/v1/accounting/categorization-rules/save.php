<?php
declare(strict_types=1);

/**
 * api/v1/accounting/categorization-rules/save.php
 *
 * Create or update an auto-categorization rule.
 * If id is provided, updates existing rule (with optimistic lock).
 * If no id, creates new rule.
 *
 * @method  POST
 * @body    id? (for update), name, vendor_id?, vendor_name_pattern?,
 *          description_keywords?, amount_min?, amount_max?, vendor_type?,
 *          account_id, priority?, is_active?, updated_at? (for optimistic lock)
 * @auth    Session required; require_permission('accounts_payable','edit')
 * @returns 200/201 { id }
 *
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'edit');

// VALID-2: accept JSON or form-encoded payloads
$jsonBody = json_body();
$input    = !empty($jsonBody) ? $jsonBody : $_POST;

$fields = [];

$id                  = clean_int($input['id'] ?? null);
$name                = clean_string($input['name'] ?? null);
$vendorId            = clean_int($input['vendor_id'] ?? null);
$vendorNamePattern   = clean_string($input['vendor_name_pattern'] ?? null);
$descriptionKeywords = clean_string($input['description_keywords'] ?? null, 2000);
$amountMin           = clean_decimal($input['amount_min'] ?? null);
$amountMax           = clean_decimal($input['amount_max'] ?? null);
$vendorType          = clean_string($input['vendor_type'] ?? null);
$accountId           = clean_int($input['account_id'] ?? null);
$priority            = clean_int($input['priority'] ?? null) ?? 0;
$isActive            = ((string)($input['is_active'] ?? '1')) === '1' ? 1 : 0;

if (!$name)      $fields['name'] = 'Rule name is required.';
if (!$accountId) $fields['account_id'] = 'Please select a GL account.';

if ($amountMin !== null && $amountMin !== '' && bccomp($amountMin, '0', 2) < 0) {
    $fields['amount_min'] = 'Minimum amount cannot be negative.';
}
if ($amountMax !== null && $amountMax !== '' && bccomp($amountMax, '0', 2) < 0) {
    $fields['amount_max'] = 'Maximum amount cannot be negative.';
}
if ($amountMin !== null && $amountMin !== '' && $amountMax !== null && $amountMax !== ''
    && bccomp($amountMin, $amountMax, 2) > 0) {
    $fields['amount_max'] = 'Maximum amount must be greater than minimum amount.';
}

if ($priority < 0) {
    $fields['priority'] = 'Priority cannot be negative.';
}

if ($fields) {
    json_validation_error($fields);
}

// Validate GL account exists
$account = db_row("SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?", [$accountId]);
if (!$account) {
    json_validation_error(['account_id' => 'GL account not found.'], 'GL account not found.');
}
if ($account['is_header']) {
    json_validation_error(['account_id' => 'Cannot use a header account.'], 'Cannot use a header account.');
}
if (!$account['is_active']) {
    json_validation_error(['account_id' => 'GL account is inactive.'], 'GL account is inactive.');
}

// Validate vendor if specified
if ($vendorId) {
    $vendor = db_row("SELECT id FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
    if (!$vendor) {
        json_validation_error(['vendor_id' => 'Vendor not found.'], 'Vendor not found.');
    }
}

$data = [
    'name'                 => $name,
    'vendor_id'            => $vendorId,
    'vendor_name_pattern'  => $vendorNamePattern,
    'description_keywords' => $descriptionKeywords,
    'amount_min'           => $amountMin,
    'amount_max'           => $amountMax,
    'vendor_type'          => $vendorType,
    'account_id'           => $accountId,
    'priority'             => $priority,
    'is_active'            => $isActive,
];

if ($id) {
    // Update existing rule
    $existing = db_row("SELECT * FROM acc_categorization_rules WHERE id = ?", [$id]);
    if (!$existing) {
        json_error('NOT_FOUND', 'Rule not found.', 404, [
            'fields' => ['id' => 'Rule not found.'],
        ]);
    }

    // D19 optimistic lock
    $submittedUpdatedAt = clean_string($input['updated_at'] ?? null);
    if ($submittedUpdatedAt && $existing['updated_at'] !== $submittedUpdatedAt) {
        json_error('STALE_DATA', 'This rule was modified by another user. Please refresh and try again.', 409, [
            'fields' => ['updated_at' => 'This rule was modified by another user. Please refresh and try again.'],
        ]);
    }

    db_update('acc_categorization_rules', $data, 'id = ?', [$id]);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'update',
        'module'      => 'accounting',
        'entity_type' => 'categorization_rule',
        'entity_id'   => $id,
        'notes'       => "Categorization rule '{$name}' updated",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['id' => $id]);
} else {
    // Create new rule
    $data['created_by'] = current_user_id();
    $newId = db_insert('acc_categorization_rules', $data);

    db_insert('audit_log', [
        'user_id'     => current_user_id(),
        'action'      => 'create',
        'module'      => 'accounting',
        'entity_type' => 'categorization_rule',
        'entity_id'   => $newId,
        'notes'       => "Categorization rule '{$name}' created with priority {$priority}",
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    json_success(['id' => $newId], 201);
}
