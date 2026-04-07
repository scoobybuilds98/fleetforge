<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/ar-aging.php
 *
 * AR aging report — groups open invoices by customer and aging bucket.
 * Buckets: Current (not yet due), 1-30, 31-60, 61-90, 90+ days past due.
 *
 * @method  GET
 * @query   as_of_date? (defaults to today)
 * @auth    Session required; require_permission('journal_entries','view')
 * @returns 200 { customers[], totals{} }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §5 (AR Accounting Layer)
 * Session: S030
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('journal_entries', 'view');

$asOfDate = clean_date($_GET['as_of_date'] ?? null) ?? date('Y-m-d');

// Fetch all open invoices with balance_due > 0
$invoices = db_select(
    "SELECT i.id, i.invoice_number, i.customer_id, i.due_date, i.invoice_date,
            i.balance_due, i.total_amount, i.status,
            c.company_name
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
     WHERE i.status NOT IN ('paid', 'void', 'written_off', 'draft')
       AND i.deleted_at IS NULL
       AND i.balance_due > 0
     ORDER BY c.company_name ASC, i.due_date ASC",
    []
);

// Group by customer and aging bucket
$customerData = [];
$totals = [
    'current' => '0.00',
    'days_1_30' => '0.00',
    'days_31_60' => '0.00',
    'days_61_90' => '0.00',
    'days_90_plus' => '0.00',
    'total' => '0.00',
];

foreach ($invoices as $inv) {
    $custId = (int) $inv['customer_id'];
    if (!isset($customerData[$custId])) {
        $customerData[$custId] = [
            'customer_id'   => $custId,
            'company_name'  => $inv['company_name'],
            'current'       => '0.00',
            'days_1_30'     => '0.00',
            'days_31_60'    => '0.00',
            'days_61_90'    => '0.00',
            'days_90_plus'  => '0.00',
            'total'         => '0.00',
            'invoices'      => [],
        ];
    }

    // Calculate days past due
    $dueDate = new \DateTime($inv['due_date']);
    $asOf    = new \DateTime($asOfDate);
    $diff    = (int) $asOf->diff($dueDate)->format('%r%a');
    // diff is negative when past due, positive when not yet due
    $daysPastDue = $diff < 0 ? abs($diff) : 0;

    // Determine bucket
    $bucket = match (true) {
        $daysPastDue === 0  => 'current',
        $daysPastDue <= 30  => 'days_1_30',
        $daysPastDue <= 60  => 'days_31_60',
        $daysPastDue <= 90  => 'days_61_90',
        default             => 'days_90_plus',
    };

    $balance = (string) $inv['balance_due'];

    $customerData[$custId][$bucket] = bcadd($customerData[$custId][$bucket], $balance, 2);
    $customerData[$custId]['total']  = bcadd($customerData[$custId]['total'], $balance, 2);
    $totals[$bucket] = bcadd($totals[$bucket], $balance, 2);
    $totals['total'] = bcadd($totals['total'], $balance, 2);

    $customerData[$custId]['invoices'][] = [
        'invoice_id'     => (int) $inv['id'],
        'invoice_number' => $inv['invoice_number'],
        'invoice_date'   => $inv['invoice_date'],
        'due_date'       => $inv['due_date'],
        'balance_due'    => $balance,
        'days_past_due'  => $daysPastDue,
        'bucket'         => $bucket,
        'status'         => $inv['status'],
    ];
}

json_success([
    'as_of_date' => $asOfDate,
    'customers'  => array_values($customerData),
    'totals'     => $totals,
]);
