<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/payments/list.php
 *
 * Paginated list of acc_qbo_payment_map rows with FF payment + customer
 * + linked-invoice snapshots for the QBO Payments admin page. Read-only.
 *
 * Bidirectional surface — unlike invoice/bill admin pages, this one shows
 * BOTH push-originated rows (origin='ff_native', S-QBO-14) and pull-originated
 * rows (origin='qbo_payments_webhook', S-QBO-13). The origin column + the
 * push_status='pulled_from_qbo' terminal state per D-QBO-13-2 distinguish
 * them.
 *
 * Filters:
 *   - status: comma-separated push_status whitelist (default: all)
 *   - origin: comma-separated origin whitelist (default: all)
 *   - page, per_page: standard pagination
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-PAYMENT-SYNC-UI (follow-up to S-QBO-13 + S-QBO-14)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §6.8 + §8.5 (Payment visibility surface)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

// Status filter — whitelist against actual ENUM values per S-QBO-13 migration.
// Note: 'pulled_from_qbo' is the terminal state for webhook-originated rows
// (D-QBO-13-2); they show up in this list but Retry will refuse them.
$validStatuses = [
    'pending', 'pushed', 'voided', 'failed',
    'skipped_voided', 'skipped_by_mode',
    'failed_preflight', 'pulled_from_qbo',
];
$statusFilter = isset($_GET['status']) && $_GET['status'] !== ''
    ? array_values(array_intersect(explode(',', (string) $_GET['status']), $validStatuses))
    : [];

// Origin filter per D-QBO-13-1.
$validOrigins = ['ff_native', 'qbo_payments_webhook', 'qbo_other'];
$originFilter = isset($_GET['origin']) && $_GET['origin'] !== ''
    ? array_values(array_intersect(explode(',', (string) $_GET['origin']), $validOrigins))
    : [];

$where  = '1=1';
$params = [];
if (!empty($statusFilter)) {
    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
    $where .= " AND m.push_status IN ({$placeholders})";
    $params = array_merge($params, $statusFilter);
}
if (!empty($originFilter)) {
    $placeholders = implode(',', array_fill(0, count($originFilter), '?'));
    $where .= " AND m.origin IN ({$placeholders})";
    $params = array_merge($params, $originFilter);
}

try {
    // KPI rollup across all statuses (no filter applied so the operator
    // always sees the full breakdown).
    $kpiRows = db_select(
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_payment_map GROUP BY push_status"
    );
    $kpis = [
        'pending'          => 0,
        'pushed'           => 0,
        'voided'           => 0,
        'failed'           => 0,
        'failed_preflight' => 0,
        'skipped_voided'   => 0,
        'skipped_by_mode'  => 0,
        'pulled_from_qbo'  => 0,
    ];
    foreach ($kpiRows as $k) {
        $kpis[$k['push_status']] = (int) $k['c'];
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_payment_map m WHERE {$where}",
        $params
    );

    // Payments + customer + first linked allocation invoice (most common
    // case is one invoice per payment; the listing shows the first +
    // a +N indicator if multiple).
    $rows = db_select(
        "SELECT m.id, m.ff_payment_id, m.qbo_payment_id, m.qbo_sync_token,
                m.qbo_total_amt, m.qbo_currency, m.qbo_txn_date,
                m.qbo_linked_invoice_id, m.origin, m.realm_id,
                m.push_status, m.push_error,
                m.pushed_at, m.pulled_at, m.last_synced_at,
                p.payment_number, p.customer_id, p.amount AS ff_amount,
                p.currency AS ff_currency, p.payment_method, p.payment_date,
                p.reference_number, p.status AS ff_status, p.origin AS ff_origin,
                c.company_name AS customer_name,
                (SELECT COUNT(*) FROM payment_allocations pa WHERE pa.payment_id = p.id) AS allocation_count,
                (SELECT i.invoice_number
                   FROM payment_allocations pa
                   JOIN invoices i ON i.id = pa.invoice_id
                  WHERE pa.payment_id = p.id
                  ORDER BY pa.id
                  LIMIT 1) AS first_invoice_number
           FROM acc_qbo_payment_map m
      LEFT JOIN payments p ON p.id = m.ff_payment_id
      LEFT JOIN customers c ON c.id = p.customer_id
          WHERE {$where}
          ORDER BY COALESCE(m.last_synced_at, m.pulled_at, m.pushed_at, m.created_at) DESC, m.id DESC
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
