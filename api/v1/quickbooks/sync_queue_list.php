<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/sync_queue_list.php
 *
 * Paginated + filtered list of acc_qbo_sync_queue rows for the
 * Sync Queue admin page (app/admin/quickbooks/sync_queue.php).
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { rows: [...], total: int, page: int, per_page: int, filters_applied: {...} }
 *
 * Spec ref: §6.4 (queue states)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status    = isset($_GET['status'])    ? (string) $_GET['status']    : '';
$entity    = isset($_GET['entity'])    ? (string) $_GET['entity']    : '';
$operation = isset($_GET['operation']) ? (string) $_GET['operation'] : '';
$sort      = isset($_GET['sort'])      ? (string) $_GET['sort']      : 'priority_enqueued';
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

$allowedStatus    = ['queued','processing','failed','skipped','completed'];
$allowedEntity    = ['customer','vendor','invoice','payment','credit_memo','refund_receipt','bill','bill_payment','journal_entry','item','account','tax_code'];
$allowedOperation = ['create','update','void','delete'];

$where  = [];
$params = [];
if ($status !== '' && in_array($status, $allowedStatus, true)) {
    $where[]  = 'status = ?';
    $params[] = $status;
}
if ($entity !== '' && in_array($entity, $allowedEntity, true)) {
    $where[]  = 'entity_type = ?';
    $params[] = $entity;
}
if ($operation !== '' && in_array($operation, $allowedOperation, true)) {
    $where[]  = 'operation = ?';
    $params[] = $operation;
}
$whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

// Sort whitelist (defense against SQL injection on ORDER BY).
$orderBy = match ($sort) {
    'enqueued_desc'      => 'enqueued_at DESC, id DESC',
    'enqueued_asc'       => 'enqueued_at ASC, id ASC',
    'priority_desc'      => 'priority DESC, enqueued_at ASC',
    'retry_desc'         => 'retry_count DESC, enqueued_at ASC',
    default              => 'priority ASC, enqueued_at ASC', // 'priority_enqueued' default — matches worker pickup order
};

try {
    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_sync_queue {$whereSql}",
        $params
    );

    $offset = ($page - 1) * $perPage;
    $rows = db_select(
        "SELECT id, entity_type, entity_id, operation, status, priority, retry_count, max_retries,
                next_retry_at, error_message, error_code, enqueued_at, picked_up_at, completed_at, worker_id
           FROM acc_qbo_sync_queue
           {$whereSql}
           ORDER BY {$orderBy}
           LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    json_success([
        'rows'             => $rows,
        'total'            => $total,
        'page'             => $page,
        'per_page'         => $perPage,
        'total_pages'      => (int) ceil($total / $perPage),
        'filters_applied'  => [
            'status'    => $status,
            'entity'    => $entity,
            'operation' => $operation,
            'sort'      => $sort,
        ],
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Sync queue list fetch failed: ' . $e->getMessage(), 500);
}
