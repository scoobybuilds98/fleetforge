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

$id                  = clean_int($_POST['id'] ?? null);
$name                = clean_string($_POST['name'] ?? null);
$vendorId            = clean_int($_POST['vendor_id'] ?? null);
$vendorNamePattern   = clean_string($_POST['vendor_name_pattern'] ?? null);
$descriptionKeywords = clean_string($_POST['description_keywords'] ?? null, 2000);
$amountMin           = clean_decimal($_POST['amount_min'] ?? null);
$amountMax           = clean_decimal($_POST['amount_max'] ?? null);
$vendorType          = clean_string($_POST['vendor_type'] ?? null);
$accountId           = clean_int($_POST['account_id'] ?? null);
$priority            = clean_int($_POST['priority'] ?? null) ?? 0;
$isActive            = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

if (!$name)      json_error('VALIDATION_ERROR', 'name is required.', 422);
if (!$accountId) json_error('VALIDATION_ERROR', 'account_id is required.', 422);

// Validate GL account exists
$account = db_row("SELECT id, is_header, is_active FROM acc_accounts WHERE id = ?", [$accountId]);
if (!$account) json_error('VALIDATION_ERROR', 'GL account not found.', 422);
if ($account['is_header']) json_error('VALIDATION_ERROR', 'Cannot use a header account.', 422);
if (!$account['is_active']) json_error('VALIDATION_ERROR', 'GL account is inactive.', 422);

// Validate vendor if specified
if ($vendorId) {
    $vendor = db_row("SELECT id FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vendorId]);
    if (!$vendor) json_error('NOT_FOUND', 'Vendor not found.', 404);
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
    if (!$existing) json_error('NOT_FOUND', 'Rule not found.', 404);

    // D19 optimistic lock
    $submittedUpdatedAt = clean_string($_POST['updated_at'] ?? null);
    if ($submittedUpdatedAt && $existing['updated_at'] !== $submittedUpdatedAt) {
        json_error('STALE_DATA', 'Record modified by another user.', 409);
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
