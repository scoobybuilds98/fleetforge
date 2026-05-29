<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/credit_memos/list.php
 *
 * Paginated list of acc_qbo_credit_memo_map rows joined to credit_notes +
 * customers for the QBO Credit Memos admin page (S-QBO-16).
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-16
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
    'skipped_voided', 'skipped_by_mode', 'skipped_soft_deleted',
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
        "SELECT push_status, COUNT(*) AS c FROM acc_qbo_credit_memo_map GROUP BY push_status"
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
        'skipped_by_mode'                    => 0,
        'skipped_soft_deleted'               => 0,
    ];
    foreach ($kpiRows as $k) {
        if (isset($kpis[$k['push_status']])) {
            $kpis[$k['push_status']] = (int) $k['c'];
        }
    }

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_credit_memo_map m WHERE {$where}",
        $params
    );

    // Join credit_notes + customers for display.
    $rows = db_select(
        "SELECT m.id, m.ff_credit_note_id, m.qbo_credit_memo_id, m.qbo_sync_token,
                m.qbo_doc_number, m.qbo_total_amt, m.qbo_balance, m.qbo_currency,
                m.qbo_item_type_used, m.ff_credit_note_snapshot_total,
                m.push_status, m.push_error, m.pushed_at, m.last_synced_at,
                cn.credit_note_number, cn.source, cn.amount AS cn_amount,
                cn.currency AS cn_currency, cn.status AS cn_status,
                cn.customer_id, cn.created_at AS cn_created_at,
                c.company_name AS customer_name
           FROM acc_qbo_credit_memo_map m
      LEFT JOIN credit_notes cn ON cn.id = m.ff_credit_note_id
      LEFT JOIN customers    c  ON c.id = cn.customer_id
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
