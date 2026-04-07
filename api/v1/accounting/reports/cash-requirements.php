<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/cash-requirements.php
 *
 * Cash requirements forecast — shows bills due in next 7, 14, 30, 60 days.
 * Grouped by due date period. Compared against current bank balance.
 *
 * @method  GET
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { periods[], bank_balance, total_due }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (Cash requirements report)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

use FleetForge\Accounting\AccountingService;

$today = date('Y-m-d');

// Define period boundaries
$periods = [
    ['label' => 'Overdue',       'from' => null,    'to' => date('Y-m-d', strtotime('-1 day'))],
    ['label' => 'Due Today',     'from' => $today,  'to' => $today],
    ['label' => 'Next 7 Days',   'from' => date('Y-m-d', strtotime('+1 day')), 'to' => date('Y-m-d', strtotime('+7 days'))],
    ['label' => 'Next 14 Days',  'from' => date('Y-m-d', strtotime('+8 days')), 'to' => date('Y-m-d', strtotime('+14 days'))],
    ['label' => 'Next 30 Days',  'from' => date('Y-m-d', strtotime('+15 days')), 'to' => date('Y-m-d', strtotime('+30 days'))],
    ['label' => 'Next 60 Days',  'from' => date('Y-m-d', strtotime('+31 days')), 'to' => date('Y-m-d', strtotime('+60 days'))],
];

$totalDue = '0.00';
$periodResults = [];

foreach ($periods as $p) {
    $where = "b.status IN ('approved','partially_paid') AND b.balance_due > 0";
    $params = [];

    if ($p['from'] === null) {
        // Overdue: due_date < today
        $where .= " AND b.due_date < ?";
        $params[] = $today;
    } else {
        $where .= " AND b.due_date >= ? AND b.due_date <= ?";
        $params[] = $p['from'];
        $params[] = $p['to'];
    }

    $bills = db_select(
        "SELECT b.id, b.bill_number, b.vendor_id, v.name AS vendor_name,
                b.due_date, b.balance_due, b.status
         FROM acc_bills b
         JOIN vendors v ON v.id = b.vendor_id AND v.deleted_at IS NULL
         WHERE {$where}
         ORDER BY b.due_date ASC, v.name ASC",
        $params
    );

    $periodTotal = '0.00';
    $billRows = [];
    foreach ($bills as $bill) {
        $bal = (string) $bill['balance_due'];
        $periodTotal = bcadd($periodTotal, $bal, 2);
        $billRows[] = [
            'bill_id'     => (int) $bill['id'],
            'bill_number' => $bill['bill_number'],
            'vendor_name' => $bill['vendor_name'],
            'due_date'    => $bill['due_date'],
            'balance_due' => $bal,
            'status'      => $bill['status'],
        ];
    }

    $totalDue = bcadd($totalDue, $periodTotal, 2);

    $periodResults[] = [
        'label'      => $p['label'],
        'date_from'  => $p['from'],
        'date_to'    => $p['to'],
        'total'      => $periodTotal,
        'bill_count' => count($billRows),
        'bills'      => $billRows,
    ];
}

// Get current bank balance from default cash account
$cashAccountId = AccountingService::setting('accounting.default_cash_account_id');
$bankBalance = $cashAccountId ? AccountingService::accountBalance((int)$cashAccountId) : '0.00';

// Cash shortfall check
$shortfall = bcsub($bankBalance, $totalDue, 2);

json_success([
    'as_of_date'   => $today,
    'periods'      => $periodResults,
    'total_due'    => $totalDue,
    'bank_balance' => $bankBalance,
    'shortfall'    => $shortfall,
    'has_shortfall'=> bccomp($shortfall, '0.00', 2) < 0,
]);
