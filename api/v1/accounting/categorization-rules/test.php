<?php
declare(strict_types=1);

/**
 * api/v1/accounting/categorization-rules/test.php
 *
 * Test the auto-categorization rules engine against a sample bill.
 * Returns the suggested GL account for each line item.
 * Does NOT create or modify anything — read-only test.
 *
 * @method  POST
 * @body    vendor_id, lines[] (each: description, amount)
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { suggestions[] }
 *
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('accounts_payable', 'view');

use FleetForge\Accounting\AccountingService;

$vendorId = clean_int($_POST['vendor_id'] ?? null);
$rawLines = $_POST['lines'] ?? null;
if (is_string($rawLines)) {
    $rawLines = json_decode($rawLines, true);
}

if (!$vendorId) json_error('VALIDATION_ERROR', 'vendor_id is required.', 422);
if (!is_array($rawLines) || count($rawLines) === 0) {
    json_error('VALIDATION_ERROR', 'At least one line is required.', 422);
}

$vendor = db_row(
    "SELECT id, name, vendor_type FROM vendors WHERE id = ? AND deleted_at IS NULL",
    [$vendorId]
);
if (!$vendor) json_error('NOT_FOUND', 'Vendor not found.', 404);

// Check if auto-categorization is enabled globally
$enabled = AccountingService::setting('accounting.auto_categorization_enabled', true);
if (!$enabled) {
    json_success(['suggestions' => [], 'message' => 'Auto-categorization is disabled.']);
}

// Load all active rules, ordered by priority
$rules = db_select(
    "SELECT r.*, a.code AS account_code, a.name AS account_name
     FROM acc_categorization_rules r
     JOIN acc_accounts a ON a.id = r.account_id
     WHERE r.is_active = 1
     ORDER BY r.priority ASC",
    []
);

// Also load the expense_account_map as fallback
$expenseMap = AccountingService::setting('accounting.expense_account_map', []);
if (is_string($expenseMap)) {
    $expenseMap = json_decode($expenseMap, true) ?? [];
}

$suggestions = [];

foreach ($rawLines as $i => $line) {
    $lineDesc   = strtolower(clean_string($line['description'] ?? '', 500) ?? '');
    $lineAmount = clean_decimal($line['amount'] ?? '0') ?? '0.00';

    $matched = null;
    $matchedRule = null;

    // Try each rule in priority order
    foreach ($rules as $rule) {
        $score = 0;

        // Vendor-specific rule
        if ($rule['vendor_id'] && (int)$rule['vendor_id'] !== $vendorId) continue;

        // Vendor name pattern match
        if ($rule['vendor_name_pattern']) {
            $pattern = strtolower($rule['vendor_name_pattern']);
            if (strpos(strtolower($vendor['name']), $pattern) === false) continue;
        }

        // Vendor type match
        if ($rule['vendor_type'] && $rule['vendor_type'] !== $vendor['vendor_type']) continue;

        // Description keyword match
        if ($rule['description_keywords']) {
            $keywords = array_map('trim', explode(',', strtolower($rule['description_keywords'])));
            $keywordMatch = false;
            foreach ($keywords as $kw) {
                if ($kw && strpos($lineDesc, $kw) !== false) {
                    $keywordMatch = true;
                    break;
                }
            }
            if (!$keywordMatch) continue;
        }

        // Amount range match
        if ($rule['amount_min'] && bccomp($lineAmount, (string)$rule['amount_min'], 2) < 0) continue;
        if ($rule['amount_max'] && bccomp($lineAmount, (string)$rule['amount_max'], 2) > 0) continue;

        // Rule matched
        $matched = [
            'account_id'   => (int) $rule['account_id'],
            'account_code' => $rule['account_code'],
            'account_name' => $rule['account_name'],
        ];
        $matchedRule = $rule['name'];
        break; // First matching rule wins (priority order)
    }

    // Fallback: try expense_account_map by vendor type
    if (!$matched && $vendor['vendor_type'] && isset($expenseMap[$vendor['vendor_type']])) {
        $code = $expenseMap[$vendor['vendor_type']];
        $acct = db_row("SELECT id, code, name FROM acc_accounts WHERE code = ? AND is_active = 1", [(string) $code]);
        if ($acct) {
            $matched = [
                'account_id'   => (int) $acct['id'],
                'account_code' => $acct['code'],
                'account_name' => $acct['name'],
            ];
            $matchedRule = 'expense_account_map (vendor type: ' . $vendor['vendor_type'] . ')';
        }
    }

    $suggestions[] = [
        'line_index'  => $i,
        'description' => $line['description'] ?? '',
        'amount'      => $lineAmount,
        'suggestion'  => $matched,
        'rule_name'   => $matchedRule,
        'is_auto'     => $matched !== null,
    ];
}

json_success(['suggestions' => $suggestions]);
