<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/trial-balance.php
 *
 * Trial balance report — all accounts with debit/credit totals from posted JEs.
 * Total debits must equal total credits (the fundamental accounting equation).
 *
 * @method  GET
 * @query   as_of_date? (defaults to today), period_id?
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { accounts[], total_debits, total_credits, is_balanced }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §10 (Financial Statements)
 * Session: S030
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

use FleetForge\Accounting\AccountingService;

$asOfDate = clean_date($_GET['as_of_date'] ?? null) ?? date('Y-m-d');
$periodId = clean_int($_GET['period_id'] ?? null);

// Get all account balances
$balances = AccountingService::allAccountBalances($asOfDate, $periodId);

// Get all active accounts (including those with zero balance for completeness)
$accounts = db_select(
    "SELECT id, code, name, account_type, account_subtype, normal_balance,
            is_header, parent_id, coa_group, sort_order
     FROM acc_accounts
     WHERE is_active = 1
     ORDER BY sort_order ASC, code ASC",
    []
);

$result = [];
$totalDebits  = '0.00';
$totalCredits = '0.00';

foreach ($accounts as $acct) {
    $acctId = (int) $acct['id'];

    // Skip header accounts (they can't have postings)
    if ($acct['is_header']) continue;

    $debitTotal  = $balances[$acctId]['debit_total'] ?? '0.00';
    $creditTotal = $balances[$acctId]['credit_total'] ?? '0.00';

    // Net balance in trial balance is shown as debit or credit column
    $netBalance = bcsub($debitTotal, $creditTotal, 2);

    // Skip accounts with zero activity (unless specifically requested)
    if (bccomp($debitTotal, '0', 2) === 0 && bccomp($creditTotal, '0', 2) === 0) {
        continue;
    }

    // WHY: Trial balance shows the balance in the natural side.
    // Debit-normal accounts show positive in the debit column.
    // Credit-normal accounts show positive in the credit column.
    $trialDebit  = '0.00';
    $trialCredit = '0.00';

    if ($acct['normal_balance'] === 'debit') {
        if (bccomp($netBalance, '0', 2) >= 0) {
            $trialDebit = $netBalance;
        } else {
            // Unusual: debit-normal account with credit balance
            $trialCredit = bcmul($netBalance, '-1', 2);
        }
    } else {
        $netCredit = bcsub($creditTotal, $debitTotal, 2);
        if (bccomp($netCredit, '0', 2) >= 0) {
            $trialCredit = $netCredit;
        } else {
            // Unusual: credit-normal account with debit balance
            $trialDebit = bcmul($netCredit, '-1', 2);
        }
    }

    $totalDebits  = bcadd($totalDebits, $trialDebit, 2);
    $totalCredits = bcadd($totalCredits, $trialCredit, 2);

    $result[] = [
        'account_id'     => $acctId,
        'code'           => $acct['code'],
        'name'           => $acct['name'],
        'account_type'   => $acct['account_type'],
        'normal_balance' => $acct['normal_balance'],
        'coa_group'      => $acct['coa_group'],
        'debit_total'    => $debitTotal,
        'credit_total'   => $creditTotal,
        'trial_debit'    => $trialDebit,
        'trial_credit'   => $trialCredit,
    ];
}

json_success([
    'as_of_date'    => $asOfDate,
    'period_id'     => $periodId,
    'accounts'      => $result,
    'total_debits'  => $totalDebits,
    'total_credits' => $totalCredits,
    'is_balanced'   => bccomp($totalDebits, $totalCredits, 2) === 0,
]);
