<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/sync_log_search.php
 *
 * Paginated + filtered list of acc_qbo_sync_log rows backing the
 * Sync Log admin page. Returns row metadata only — full payload
 * bodies are fetched separately via sync_log_detail.php (which
 * applies the view_raw_payloads permission gate).
 *
 * Filters:
 *   direction: push | pull
 *   entity:    one of the 12 entity_type values
 *   status:    success (2xx) | client_error (4xx) | server_error (5xx)
 *   error_code:text contains (LIKE %x%)
 *   date_from: YYYY-MM-DD (default: 7 days ago)
 *   date_to:   YYYY-MM-DD (default: now)
 *   q:         free text — searches error_message, endpoint, entity_id, qbo_entity_id
 *   queue_id:  filter to rows for a single queue row (used by Sync Queue's "View Log" link)
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { rows: [...], total: int, page: int, per_page: int, total_pages: int, filters_applied: {...} }
 *
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$direction = isset($_GET['direction']) ? (string) $_GET['direction'] : '';
$entity    = isset($_GET['entity'])    ? (string) $_GET['entity']    : '';
$statusCls = isset($_GET['status'])    ? (string) $_GET['status']    : '';
$errCode   = isset($_GET['error_code'])? trim((string) $_GET['error_code']) : '';
$dateFrom  = isset($_GET['date_from']) ? (string) $_GET['date_from'] : '';
$dateTo    = isset($_GET['date_to'])   ? (string) $_GET['date_to']   : '';
$qText     = isset($_GET['q'])         ? trim((string) $_GET['q'])   : '';
$queueId   = isset($_GET['queue_id'])  ? (int) $_GET['queue_id']     : 0;
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));

if ($dateFrom === '') {
    $dateFrom = date('Y-m-d', strtotime('-7 days'));
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$allowedDirection = ['push', 'pull'];
$allowedEntity    = ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code','companyinfo','query'];
$allowedStatusCls = ['success', 'client_error', 'server_error'];

$where  = [];
$params = [];

// Date window — always applied, default 7d.
$where[]  = 'created_at >= ?';
$params[] = $dateFrom . ' 00:00:00';
$where[]  = 'created_at <= ?';
$params[] = $dateTo . ' 23:59:59';

if ($direction !== '' && in_array($direction, $allowedDirection, true)) {
    $where[]  = 'direction = ?';
    $params[] = $direction;
}
if ($entity !== '' && in_array($entity, $allowedEntity, true)) {
    $where[]  = 'entity_type = ?';
    $params[] = $entity;
}
if (in_array($statusCls, $allowedStatusCls, true)) {
    if ($statusCls === 'success') {
        $where[] = 'response_status >= 200 AND response_status < 300';
    } elseif ($statusCls === 'client_error') {
        $where[] = 'response_status >= 400 AND response_status < 500';
    } else {
        $where[] = '(response_status >= 500 OR response_status IS NULL)';
    }
}
if ($errCode !== '') {
    $where[]  = 'error_code LIKE ?';
    $params[] = '%' . $errCode . '%';
}
if ($queueId > 0) {
    $where[]  = 'queue_id = ?';
    $params[] = $queueId;
}
if ($qText !== '') {
    $where[]  = '(error_message LIKE ? OR endpoint LIKE ? OR CAST(entity_id AS CHAR) LIKE ? OR qbo_entity_id LIKE ?)';
    $params[] = '%' . $qText . '%';
    $params[] = '%' . $qText . '%';
    $params[] = '%' . $qText . '%';
    $params[] = '%' . $qText . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

try {
    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_log {$whereSql}",
        $params
    );

    $offset = ($page - 1) * $perPage;
    $rows = db_select(
        "SELECT id, created_at, direction, entity_type, entity_id, qbo_entity_id,
                operation, http_method, endpoint, response_status, duration_ms,
                error_code, queue_id, realm_id, environment
           FROM acc_qbo_sync_log
           {$whereSql}
           ORDER BY created_at DESC, id DESC
           LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    json_success([
        'rows'            => $rows,
        'total'           => $total,
        'page'            => $page,
        'per_page'        => $perPage,
        'total_pages'     => (int) ceil($total / $perPage),
        'filters_applied' => [
            'direction'  => $direction,
            'entity'     => $entity,
            'status'     => $statusCls,
            'error_code' => $errCode,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'q'          => $qText,
            'queue_id'   => $queueId,
        ],
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Sync log search failed: ' . $e->getMessage(), 500);
}
