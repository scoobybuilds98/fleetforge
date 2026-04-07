<?php declare(strict_types=1);

/**
 * api/v1/accounting/accounts/show.php
 *
 * Fetch a single chart-of-accounts entry with its computed balance and the
 * last 20 journal entry lines that touched this account.
 *
 * Dependencies: bootstrap.php, AccountingService (balance calc)
 *
 * @method  GET
 * @query   id (required)
 * @auth    Session required; require_permission('chart_of_accounts','view')
 * @returns 200 { account + balance + recent_transactions[] }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md SS3
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('chart_of_accounts', 'view');

use FleetForge\Accounting\AccountingService;

// -----------------------------------------------------------------------
// 1. Validate required ID
// -----------------------------------------------------------------------
$id = clean_int($_GET['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

// -----------------------------------------------------------------------
// 2. Load the account
// -----------------------------------------------------------------------
$account = db_row(
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
        a.created_by,
        a.created_at,
        a.updated_at
     FROM acc_accounts a
     WHERE a.id = ?",
    [$id]
);

if (!$account) {
    json_error('NOT_FOUND', 'Account not found.', 404);
}

// -----------------------------------------------------------------------
// 3. Compute balance from posted JE lines (null for headers)
// -----------------------------------------------------------------------
if ((int) $account['is_header'] === 1) {
    $account['balance'] = null;
} else {
    $account['balance'] = AccountingService::accountBalance((int) $account['id']);
}

// -----------------------------------------------------------------------
// 4. Load recent transactions — last 20 posted JE lines for this account
// -----------------------------------------------------------------------
$account['recent_transactions'] = db_select(
    "SELECT
        jel.id           AS line_id,
        jel.journal_entry_id,
        je.entry_number,
        je.entry_date,
        je.description   AS entry_description,
        je.source_type,
        je.source_id,
        jel.line_number,
        jel.description  AS line_description,
        jel.debit,
        jel.credit,
        jel.created_at
     FROM acc_journal_entry_lines jel
     JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = ?
       AND je.status = 'posted'
     ORDER BY je.entry_date DESC, je.id DESC
     LIMIT 20",
    [$id]
);

json_success($account);
