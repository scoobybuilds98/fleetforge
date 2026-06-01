<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/credit_applications/list.php
 *
 * Paginated list of acc_qbo_credit_application_map rows joined to
 * credit_note_applications + credit_notes + invoices + customers for the
 * "Applications" section of the QBO Credit Memos admin page
 * (D-QBO-CREDIT-MEMO-APPLY-5: applications share the credit_memos.php UI
 * surface — CLASS 12 allowlist).
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-CREDIT-MEMO-APPLY
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = [
    'pending', 'pushed', 'failed', 'skipped_by_mode', 'failed_preflight',
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
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_credit_application_map GROUP BY push_status"
    );
    $kpis = [
        'pending'          => 0,
        'pushed'           => 0,
        'failed'           => 0,
        'failed_preflight' => 0,
        'skipped_by_mode'  => 0,
    ];
    foreach ($kpiRows as $k) {
        if (isset($kpis[$k['push_status']])) {
            $kpis[$k['push_status']] = (int) $k['c'];
        }
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_credit_application_map m WHERE {$where}",
        $params
    );

    // Join the apply row + parent credit note + invoice + customer for display.
    $rows = db_select(
        "SELECT m.id, m.ff_credit_application_id,
                m.ff_credit_note_id_snapshot, m.ff_invoice_id_snapshot,
                m.qbo_payment_id, m.qbo_credit_memo_id_ref, m.qbo_invoice_id_ref,
                m.qbo_total_amt, m.qbo_currency, m.qbo_txn_date,
                m.amount_applied_snapshot, m.push_status, m.push_error,
                m.pushed_at, m.last_synced_at,
                a.amount_applied, a.applied_at,
                cn.credit_note_number, cn.customer_id, cn.currency AS cn_currency,
                inv.invoice_number,
                c.company_name AS customer_name
           FROM acc_qbo_credit_application_map m
      LEFT JOIN credit_note_applications a ON a.id = m.ff_credit_application_id
      LEFT JOIN credit_notes  cn  ON cn.id = m.ff_credit_note_id_snapshot
      LEFT JOIN invoices      inv ON inv.id = m.ff_invoice_id_snapshot
      LEFT JOIN customers     c   ON c.id = cn.customer_id
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
