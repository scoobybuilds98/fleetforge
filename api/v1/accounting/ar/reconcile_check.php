<?php
declare(strict_types=1);

/**
 * api/v1/accounting/ar/reconcile_check.php
 *
 * AR subledger reconciliation check — verifies that the GL AR balance
 * equals the sum of open invoice balances. Any discrepancy means
 * the accounting entries and billing module are out of sync.
 *
 * @method  GET
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { gl_balance, subledger_balance, difference, is_reconciled, detail }
 *
 * Decisions: A9 (AR subledger is the FleetForge billing module)
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (AR reconciliation)
 * Session: S030
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

use FleetForge\Accounting\AccountingService;

$arCheck = AccountingService::arReconciliationCheck();

// Build detail breakdown by customer for diagnostics
$customerBreakdown = [];
if (!$arCheck['is_reconciled']) {
    // Get per-customer totals from invoices (subledger)
    $custTotals = db_select(
        "SELECT c.id, c.company_name,
                COALESCE(SUM(i.balance_due), 0) AS subledger_balance
         FROM customers c
         LEFT JOIN invoices i ON i.customer_id = c.id
             AND i.status NOT IN ('paid','void','written_off')
             AND i.deleted_at IS NULL
         WHERE c.deleted_at IS NULL
         GROUP BY c.id
         HAVING subledger_balance > 0
         ORDER BY subledger_balance DESC
         LIMIT 25",
        []
    );

    // Get per-customer GL AR balances
    $arAccountId = AccountingService::setting('accounting.ar_account_id');
    if ($arAccountId) {
        foreach ($custTotals as $ct) {
            $glRow = db_row(
                "SELECT COALESCE(SUM(jel.debit), 0) AS td, COALESCE(SUM(jel.credit), 0) AS tc
                 FROM acc_journal_entry_lines jel
                 JOIN acc_journal_entries je ON je.id = jel.journal_entry_id
                 WHERE jel.account_id = ? AND jel.customer_id = ? AND je.status = 'posted'",
                [$arAccountId, $ct['id']]
            );
            $glBalance = $glRow ? bcsub($glRow['td'], $glRow['tc'], 2) : '0.00';
            $diff = bcsub($glBalance, $ct['subledger_balance'], 2);

            if (bccomp($diff, '0', 2) !== 0) {
                $customerBreakdown[] = [
                    'customer_id'       => (int) $ct['id'],
                    'company_name'      => $ct['company_name'],
                    'gl_balance'        => $glBalance,
                    'subledger_balance' => $ct['subledger_balance'],
                    'difference'        => $diff,
                ];
            }
        }
    }
}

json_success([
    'gl_balance'         => $arCheck['gl_balance'],
    'subledger_balance'  => $arCheck['subledger_balance'],
    'difference'         => $arCheck['difference'],
    'is_reconciled'      => $arCheck['is_reconciled'],
    'customer_breakdown' => $customerBreakdown,
    'checked_at'         => date('Y-m-d H:i:s'),
]);
