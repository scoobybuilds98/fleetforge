<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_list.php
 *
 * Paginated + filtered list of acc_qbo_drift_events rows + the 4
 * summary card counts (unresolved / resolved-30d / by-category /
 * last-detected) in a single roundtrip — backs the Drift admin page.
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { rows, total, page, per_page, total_pages, filters_applied, summary }
 *
 * Spec ref: §15.1 (drift dashboard structure)
 * Session:  S-QBO-4
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status    = isset($_GET['status'])    ? (string) $_GET['status']    : 'unresolved'; // unresolved | resolved | all
$category  = isset($_GET['category'])  ? (string) $_GET['category']  : '';
$entity    = isset($_GET['entity'])    ? (string) $_GET['entity']    : '';
$source    = isset($_GET['source'])    ? (string) $_GET['source']    : '';
$range     = isset($_GET['range'])     ? (string) $_GET['range']     : '30'; // 7 | 30 | 90 | all
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

$allowedCategory = ['count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved'];
$allowedSource   = ['drift_cron','push_failure','pull_failure','manual'];

$where  = [];
$params = [];

if ($status === 'unresolved') {
    $where[] = 'resolved_at IS NULL';
} elseif ($status === 'resolved') {
    $where[] = 'resolved_at IS NOT NULL';
}
if ($category !== '' && in_array($category, $allowedCategory, true)) {
    $where[]  = 'category = ?';
    $params[] = $category;
}
if ($entity !== '') {
    $where[]  = 'entity_type = ?';
    $params[] = $entity;
}
if ($source !== '' && in_array($source, $allowedSource, true)) {
    $where[]  = 'detection_source = ?';
    $params[] = $source;
}
if (in_array($range, ['7','30','90'], true)) {
    $where[]  = 'detected_at >= NOW() - INTERVAL ? DAY';
    $params[] = (int) $range;
}

$whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    // ── Summary cards ─────────────────────────────────────
    $sumUnresolved = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events WHERE resolved_at IS NULL",
        []
    );
    $sumResolved30 = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events
          WHERE resolved_at IS NOT NULL
            AND resolved_at >= NOW() - INTERVAL 30 DAY",
        []
    );
    $topCategories = db_select(
        "SELECT category, COUNT(*) AS n FROM acc_qbo_drift_events
          WHERE resolved_at IS NULL
          GROUP BY category
          ORDER BY n DESC
          LIMIT 3",
        []
    );
    $lastUnresolved = db_row(
        "SELECT MAX(detected_at) AS last_detected
           FROM acc_qbo_drift_events
          WHERE resolved_at IS NULL",
        []
    );

    // ── Page rows ─────────────────────────────────────────
    $total = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events {$whereSql}",
        $params
    );
    $offset = ($page - 1) * $perPage;

    $rows = db_select(
        "SELECT d.id, d.detected_at, d.detection_source, d.category,
                d.entity_type, d.entity_id, d.qbo_entity_id,
                d.ff_value, d.qbo_value, d.drift_amount, d.description,
                d.queue_id, d.resolved_at, d.resolved_by_user_id, d.resolution_note,
                d.realm_id, d.environment,
                u.name AS resolver_name
           FROM acc_qbo_drift_events d
           LEFT JOIN users u ON u.id = d.resolved_by_user_id
           {$whereSql}
           ORDER BY d.detected_at DESC, d.id DESC
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
            'status'   => $status,
            'category' => $category,
            'entity'   => $entity,
            'source'   => $source,
            'range'    => $range,
        ],
        'summary' => [
            'unresolved'     => $sumUnresolved,
            'resolved_30d'   => $sumResolved30,
            'top_categories' => $topCategories,
            'last_detected'  => $lastUnresolved['last_detected'] ?? null,
        ],
    ]);
} catch (\Throwable $e) {
    \FleetForge\Observability\Sentry::captureException($e);
    json_error('INTERNAL_ERROR', 'Drift list fetch failed: ' . $e->getMessage(), 500);
}
