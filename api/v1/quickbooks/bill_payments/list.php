<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/bill_payments/list.php
 *
 * Paginated list of acc_qbo_bill_payment_map rows with FF ap_payment +
 * vendor + bank account snapshots for the QBO Bill Payments admin page.
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-19
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = [
    'pending', 'pushed', 'voided', 'failed',
    'skipped_voided', 'skipped_unmapped_void', 'skipped_by_mode',
    'failed_preflight', 'failed_preflight_currency_mismatch',
    'failed_preflight_field_too_long',
];
$statusFilter = isset($_GET['status']) && $_GET['status'] !== ''
    ? array_values(array_intersect(explode(',', (string) $_GET['status']), $validStatuses))
    : [];

$where  = '1=1';
$params = [];
if (!empty($statusFilter)) {
    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
    $where .= " AND m.push_status IN ({$placeholders})";
    $params = array_merge($params, $statusFilter);
}

try {
    $kpiRows = db_select(
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_bill_payment_map GROUP BY push_status"
    );
    $kpis = [
        'pending'                            => 0,
        'pushed'                             => 0,
        'voided'                             => 0,
        'failed'                             => 0,
        'failed_preflight'                   => 0,
        'failed_preflight_currency_mismatch' => 0,
        'failed_preflight_field_too_long'    => 0,
        'skipped_voided'                     => 0,
        'skipped_unmapped_void'              => 0,
        'skipped_by_mode'                    => 0,
    ];
    foreach ($kpiRows as $k) {
        $kpis[$k['push_status']] = (int) $k['c'];
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_bill_payment_map m WHERE {$where}",
        $params
    );

    // Join ap_payments + vendors + ap_payment_allocations (count via subquery)
    $rows = db_select(
        "SELECT m.id, m.ff_ap_payment_id, m.qbo_bill_payment_id, m.qbo_sync_token,
                m.qbo_vendor_id, m.qbo_bank_account_id, m.qbo_pay_type,
                m.qbo_total_amt, m.qbo_currency, m.qbo_exchange_rate, m.qbo_txn_date,
                m.ff_payment_snapshot_total,
                m.push_status, m.push_error, m.pushed_at, m.last_synced_at,
                p.payment_number, p.vendor_id, p.bank_account_id,
                p.payment_date, p.payment_method, p.reference_number,
                p.check_number, p.amount AS ff_amount, p.currency AS ff_currency,
                p.status AS ff_status,
                v.name AS vendor_name,
                ba.name AS bank_account_name,
                (SELECT COUNT(*) FROM acc_ap_payment_allocations a WHERE a.ap_payment_id = p.id) AS allocation_count,
                (SELECT b.bill_number FROM acc_ap_payment_allocations a
                   JOIN acc_bills b ON b.id = a.bill_id
                  WHERE a.ap_payment_id = p.id ORDER BY a.id LIMIT 1) AS first_bill_number
           FROM acc_qbo_bill_payment_map m
      LEFT JOIN acc_ap_payments p ON p.id = m.ff_ap_payment_id
      LEFT JOIN vendors v ON v.id = p.vendor_id
      LEFT JOIN acc_accounts ba ON ba.id = p.bank_account_id
          WHERE {$where}
          ORDER BY COALESCE(m.last_synced_at, m.created_at) DESC, m.id DESC
          LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    json_success([
        'rows'     => $rows,
        'kpis'     => $kpis,
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'List failed: ' . $e->getMessage(), 500);
}
