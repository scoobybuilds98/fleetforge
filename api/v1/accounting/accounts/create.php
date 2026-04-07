<?php declare(strict_types=1);

/**
 * api/v1/accounting/accounts/create.php
 *
 * Create a new chart-of-accounts entry. Validates code uniqueness, parent
 * header requirement, and account_type against the schema ENUM.
 *
 * Dependencies: bootstrap.php
 *
 * @method  POST
 * @body    JSON: code (required, unique), name (required), account_type (required),
 *               account_subtype, parent_id, is_header, currency, normal_balance,
 *               tax_line_code, coa_group, notes, description
 * @auth    Session required; require_permission('chart_of_accounts','create')
 * @returns 201 { id, code, name }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md SS3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('chart_of_accounts', 'create');

$body = json_body();

// -----------------------------------------------------------------------
// 1. Required fields
// -----------------------------------------------------------------------
$code = clean_string($body['code'] ?? null, 20);
if (!$code) {
    json_error('VALIDATION_ERROR', 'code is required.', 422);
}

$name = clean_string($body['name'] ?? null, 255);
if (!$name) {
    json_error('VALIDATION_ERROR', 'name is required.', 422);
}

// WHY: Validate against the DB ENUM — reject anything the column would reject.
$validTypes = [
    'asset', 'liability', 'equity', 'revenue',
    'cost_of_revenue', 'operating_expense', 'other_income', 'other_expense',
];
$accountType = clean_string($body['account_type'] ?? null);
if (!$accountType || !in_array($accountType, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'account_type is required and must be one of: ' . implode(', ', $validTypes) . '.', 422);
}

// -----------------------------------------------------------------------
// 2. Optional fields
// -----------------------------------------------------------------------
$accountSubtype = clean_string($body['account_subtype'] ?? null, 100);
$parentId       = clean_int($body['parent_id'] ?? null);
$isHeader       = (int) (($body['is_header'] ?? false) ? 1 : 0);
$currency       = strtoupper(clean_string($body['currency'] ?? 'CAD') ?? 'CAD');
$taxLineCode    = clean_string($body['tax_line_code'] ?? null, 50);
$coaGroup       = clean_string($body['coa_group'] ?? null, 100);
$notes          = clean_string($body['notes'] ?? null, 2000);
$description    = clean_string($body['description'] ?? null, 2000);

// Normal balance: default by account type if not provided
$normalBalance = clean_string($body['normal_balance'] ?? null);
if ($normalBalance && !in_array($normalBalance, ['debit', 'credit'], true)) {
    json_error('VALIDATION_ERROR', 'normal_balance must be debit or credit.', 422);
}
if (!$normalBalance) {
    // WHY: Assets and expenses are debit-normal; liabilities, equity, and revenue are credit-normal.
    $normalBalance = in_array($accountType, ['asset', 'cost_of_revenue', 'operating_expense', 'other_expense'], true)
        ? 'debit'
        : 'credit';
}

// Currency validation
if (!in_array($currency, ['CAD', 'USD'], true)) {
    json_error('VALIDATION_ERROR', 'currency must be CAD or USD.', 422);
}

// -----------------------------------------------------------------------
// 3. Business rule validation
// -----------------------------------------------------------------------

// Code must be unique
$existing = db_row("SELECT id FROM acc_accounts WHERE code = ?", [$code]);
if ($existing) {
    json_error('DUPLICATE_CODE', "Account code '{$code}' already exists.", 422);
}

// If parent_id is set, the parent must exist and be a header account
if ($parentId) {
    $parent = db_row("SELECT id, is_header, name FROM acc_accounts WHERE id = ?", [$parentId]);
    if (!$parent) {
        json_error('INVALID_PARENT', 'Parent account not found.', 422);
    }
    if ((int) $parent['is_header'] !== 1) {
        json_error('INVALID_PARENT', "Parent account '{$parent['name']}' is not a header account. Only header accounts can have children.", 422);
    }
}

// -----------------------------------------------------------------------
// 4. Insert inside a transaction (row + audit log)
// -----------------------------------------------------------------------
$result = null;

db_transaction(function () use (
    $code, $name, $accountType, $accountSubtype, $parentId, $isHeader,
    $currency, $normalBalance, $taxLineCode, $coaGroup, $notes, $description,
    &$result
) {
    $accountId = db_insert('acc_accounts', [
        'code'            => $code,
        'name'            => $name,
        'description'     => $description,
        'account_type'    => $accountType,
        'account_subtype' => $accountSubtype,
        'parent_id'       => $parentId,
        'is_header'       => $isHeader,
        'currency'        => $currency,
        'normal_balance'  => $normalBalance,
        'tax_line_code'   => $taxLineCode,
        'coa_group'       => $coaGroup,
        'notes'           => $notes,
        'created_by'      => current_user_id(),
    ]);

    // Audit log
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => 'create',
        'module'       => 'accounting',
        'entity_type'  => 'account',
        'entity_id'    => $accountId,
        'entity_label' => "{$code} — {$name}",
        'notes'        => "Created chart of accounts entry: {$code} ({$name}), type={$accountType}.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = [
        'id'   => $accountId,
        'code' => $code,
        'name' => $name,
    ];
});

json_success($result, 201);
