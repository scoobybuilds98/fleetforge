<?php declare(strict_types=1);

/**
 * api/v1/accounting/accounts/index.php
 *
 * List chart of accounts with optional hierarchical nesting, filtering, and
 * balance calculation. Default mode returns a tree rooted at top-level accounts
 * (parent_id IS NULL) with children nested. Pass ?flat=1 for a simple list.
 *
 * Dependencies: bootstrap.php, AccountingService (balance calc)
 *
 * @method  GET
 * @query   flat (0|1), type (account_type filter), active (0|1), search (code/name)
 * @auth    Session required; require_permission('chart_of_accounts','view')
 * @returns 200 { accounts[] } — hierarchical tree or flat list with balances
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md SS3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('chart_of_accounts', 'view');

use FleetForge\Accounting\AccountingService;

// -----------------------------------------------------------------------
// 1. Parse filters
// -----------------------------------------------------------------------
$flat   = ($_GET['flat'] ?? '0') === '1';
$type   = clean_string($_GET['type'] ?? null);
$active = isset($_GET['active']) ? (($_GET['active'] === '0') ? 0 : 1) : null;
$search = clean_string($_GET['search'] ?? null);

// -----------------------------------------------------------------------
// 2. Build query — always load all accounts for tree building, apply
//    filters at the SQL level for performance
// -----------------------------------------------------------------------
$where  = [];
$params = [];

// WHY: Valid ENUM values from acc_accounts.account_type — reject anything else.
$validTypes = [
    'asset', 'liability', 'equity', 'revenue',
    'cost_of_revenue', 'operating_expense', 'other_income', 'other_expense',
];

if ($type && in_array($type, $validTypes, true)) {
    $where[]  = 'a.account_type = ?';
    $params[] = $type;
}

if ($active !== null) {
    $where[]  = 'a.is_active = ?';
    $params[] = $active;
}

if ($search) {
    $where[]  = '(a.code LIKE ? OR a.name LIKE ?)';
    $like     = '%' . addcslashes($search, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_select(
    "SELECT
        a.id,
        a.code,
        a.name,
        a.description,
        a.account_type,
        a.account_subtype,
        a.parent_id,
        a.is_header,
        a.currency,
        a.normal_balance,
        a.is_system,
        a.is_active,
        a.is_bank_account,
        a.tax_line_code,
        a.coa_group,
        a.sort_order,
        a.notes,
        a.created_at,
        a.updated_at
     FROM acc_accounts a
     {$whereSQL}
     ORDER BY a.sort_order ASC, a.code ASC",
    $params
);

// -----------------------------------------------------------------------
// 3. Attach balance from posted JE lines (skip headers — they hold no balance)
// -----------------------------------------------------------------------
foreach ($rows as &$row) {
    if ((int) $row['is_header'] === 1) {
        $row['balance'] = null;
    } else {
        $row['balance'] = AccountingService::accountBalance((int) $row['id']);
    }
}
unset($row);

// -----------------------------------------------------------------------
// 4. Return flat list or build hierarchical tree
// -----------------------------------------------------------------------
if ($flat) {
    json_success(['accounts' => $rows]);
}

// WHY: Build a keyed lookup first, then nest children under parents.
// Two-pass approach handles arbitrary depth without recursion limits.
$indexed  = [];
$topLevel = [];

foreach ($rows as $row) {
    $row['children'] = [];
    $indexed[(int) $row['id']] = $row;
}

foreach ($indexed as $id => $row) {
    $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

    if ($parentId === null || !isset($indexed[$parentId])) {
        // Top-level account or parent filtered out
        $topLevel[] = &$indexed[$id];
    } else {
        $indexed[$parentId]['children'][] = &$indexed[$id];
    }
}

json_success(['accounts' => array_values($topLevel)]);
