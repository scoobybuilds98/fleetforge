<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/refund_receipts/list.php
 *
 * Paginated list of acc_qbo_refund_receipt_map rows joined to leases +
 * customers for the QBO Refund Receipts admin page (S-QBO-17).
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-17
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = [
    'pending', 'pushed', 'failed', 'skipped_by_mode',
    'failed_preflight', 'failed_preflight_field_too_long',
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
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_refund_receipt_map GROUP BY push_status"
    );
    $kpis = [
        'pending'                         => 0,
        'pushed'                          => 0,
        'failed'                          => 0,
        'failed_preflight'                => 0,
        'failed_preflight_field_too_long' => 0,
        'skipped_by_mode'                 => 0,
    ];
    foreach ($kpiRows as $k) {
        if (isset($kpis[$k['push_status']])) {
            $kpis[$k['push_status']] = (int) $k['c'];
        }
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_refund_receipt_map m WHERE {$where}",
        $params
    );

    $rows = db_select(
        "SELECT m.id, m.ff_lease_id, m.ff_contract_number_snapshot,
                m.qbo_refund_receipt_id, m.qbo_doc_number, m.qbo_total_amt,
                m.qbo_currency, m.qbo_txn_date, m.ff_refund_amount_snapshot,
                m.qbo_deposit_account_id, m.qbo_payment_method_id,
                m.push_status, m.push_error, m.pushed_at, m.last_synced_at,
                l.contract_number, l.currency AS lease_currency,
                l.precharge_balance, l.precharge_refund_settled_at,
                l.customer_id, c.company_name AS customer_name
           FROM acc_qbo_refund_receipt_map m
      LEFT JOIN leases    l ON l.id = m.ff_lease_id
      LEFT JOIN customers c ON c.id = l.customer_id
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
