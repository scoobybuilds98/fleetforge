<?php
declare(strict_types=1);

/**
 * api/v1/accounting/reports/ap-aging.php
 *
 * AP aging report — groups open bills by vendor and aging bucket.
 * Buckets: Current (not yet due), 1-30, 31-60, 60+ days past due.
 *
 * @method  GET
 * @query   as_of_date? (defaults to today)
 * @auth    Session required; require_permission('accounts_payable','view')
 * @returns 200 { vendors[], totals{} }
 *
 * Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §6 (Vendor aging)
 * Session: S032
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('accounts_payable', 'view');

$asOfDate = clean_date($_GET['as_of_date'] ?? null) ?? date('Y-m-d');

// Fetch all open bills with balance_due > 0
$bills = db_select(
    "SELECT b.id, b.bill_number, b.vendor_id, b.vendor_bill_number,
            b.bill_date, b.due_date, b.balance_due, b.total_amount, b.status,
            v.name AS vendor_name
     FROM acc_bills b
     JOIN vendors v ON v.id = b.vendor_id AND v.deleted_at IS NULL
     WHERE b.status NOT IN ('paid', 'void', 'draft')
       AND b.balance_due > 0
     ORDER BY v.name ASC, b.due_date ASC",
    []
);

$vendorData = [];
$totals = [
    'current'     => '0.00',
    'days_1_30'   => '0.00',
    'days_31_60'  => '0.00',
    'days_60_plus'=> '0.00',
    'total'       => '0.00',
];

foreach ($bills as $bill) {
    $vId = (int) $bill['vendor_id'];
    if (!isset($vendorData[$vId])) {
        $vendorData[$vId] = [
            'vendor_id'    => $vId,
            'vendor_name'  => $bill['vendor_name'],
            'current'      => '0.00',
            'days_1_30'    => '0.00',
            'days_31_60'   => '0.00',
            'days_60_plus' => '0.00',
            'total'        => '0.00',
            'bills'        => [],
        ];
    }

    // Calculate days past due based on due_date
    $dueDate = new \DateTime($bill['due_date']);
    $asOf    = new \DateTime($asOfDate);
    $diff    = (int) $asOf->diff($dueDate)->format('%r%a');
    $daysPastDue = $diff < 0 ? abs($diff) : 0;

    $bucket = match (true) {
        $daysPastDue === 0  => 'current',
        $daysPastDue <= 30  => 'days_1_30',
        $daysPastDue <= 60  => 'days_31_60',
        default             => 'days_60_plus',
    };

    $balance = (string) $bill['balance_due'];

    $vendorData[$vId][$bucket] = bcadd($vendorData[$vId][$bucket], $balance, 2);
    $vendorData[$vId]['total']  = bcadd($vendorData[$vId]['total'], $balance, 2);
    $totals[$bucket] = bcadd($totals[$bucket], $balance, 2);
    $totals['total'] = bcadd($totals['total'], $balance, 2);

    $vendorData[$vId]['bills'][] = [
        'bill_id'           => (int) $bill['id'],
        'bill_number'       => $bill['bill_number'],
        'vendor_bill_number'=> $bill['vendor_bill_number'],
        'bill_date'         => $bill['bill_date'],
        'due_date'          => $bill['due_date'],
        'balance_due'       => $balance,
        'days_past_due'     => $daysPastDue,
        'bucket'            => $bucket,
        'status'            => $bill['status'],
    ];
}

json_success([
    'as_of_date' => $asOfDate,
    'vendors'    => array_values($vendorData),
    'totals'     => $totals,
]);
