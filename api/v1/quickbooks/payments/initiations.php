<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/payments/initiations.php
 *
 * Paginated list of acc_qbo_payment_initiations rows with FF invoice +
 * portal user joins for the QBO Payments admin page (extension per
 * D-UI-COMPLETENESS-1 — initiation visibility on existing /quickbooks/
 * payments page rather than a dedicated /quickbooks/payment_initiations
 * page; operator preference at S-QBO-15 AskUserQuestion).
 *
 * Filters:
 *   - status: comma-separated status whitelist (default: all)
 *   - page, per_page: standard pagination
 *
 * @method  GET
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { rows, kpis, page, per_page, total }
 *
 * @session  S-QBO-15
 * @decision D-QBO-15-1 (status semantics — pending = URL live; completed = handshook)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset  = ($page - 1) * $perPage;

$validStatuses = ['pending', 'completed', 'cancelled', 'expired', 'failed'];
$statusFilter = isset($_GET['status']) && $_GET['status'] !== ''
    ? array_values(array_intersect(explode(',', (string) $_GET['status']), $validStatuses))
    : [];

$where  = '1=1';
$params = [];
if (!empty($statusFilter)) {
    $placeholders = implode(',', array_fill(0, count($statusFilter), '?'));
    $where .= " AND init.status IN ({$placeholders})";
    $params = array_merge($params, $statusFilter);
}

try {
    // KPI rollup across all statuses + expired count (computed live —
    // initiations may have expired by clock without status flip).
    $kpiRows = db_select(
        "SELECT status, COUNT(*) AS c FROM acc_qbo_payment_initiations GROUP BY status"
    );
    $kpis = [
        'pending'   => 0,
        'completed' => 0,
        'cancelled' => 0,
        'expired'   => 0,
        'failed'    => 0,
    ];
    foreach ($kpiRows as $k) {
        $kpis[$k['status']] = (int) $k['c'];
    }
    // Live-expired count for pending rows where clock has passed expires_at.
    $liveExpired = db_count(
        "SELECT COUNT(*) FROM acc_qbo_payment_initiations WHERE status = 'pending' AND expires_at < NOW()"
    );
    $kpis['live_expired_pending'] = (int) $liveExpired;

    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_payment_initiations init WHERE {$where}",
        $params
    );

    $rows = db_select(
        "SELECT init.id, init.ff_invoice_id, init.ff_portal_user_id,
                init.qbo_invoice_id, init.initiation_token,
                init.amount, init.currency, init.realm_id,
                init.generated_at, init.expires_at, init.status,
                init.qbo_payment_id, init.error_message,
                init.completed_at,
                i.invoice_number, i.customer_id,
                c.company_name AS customer_name,
                pu.name AS portal_user_name, pu.email AS portal_user_email,
                (CASE WHEN init.status='pending' AND init.expires_at < NOW() THEN 1 ELSE 0 END) AS live_expired
           FROM acc_qbo_payment_initiations init
      LEFT JOIN invoices i ON i.id = init.ff_invoice_id
      LEFT JOIN customers c ON c.id = i.customer_id
      LEFT JOIN portal_users pu ON pu.id = init.ff_portal_user_id
          WHERE {$where}
          ORDER BY init.generated_at DESC, init.id DESC
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
