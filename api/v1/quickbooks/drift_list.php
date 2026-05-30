<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/drift_list.php
 *
 * Paginated + filtered list of acc_qbo_drift_events rows + the 4
 * summary card counts (unresolved / resolved-30d / by-category /
 * last-detected) in a single roundtrip — backs the Drift admin page.
 *
 * Status filter (S-QBO-25 / D-QBO-25-1 state machine):
 *   • unresolved (default) — OPEN events only (resolved_at IS NULL).
 *       Importantly EXCLUDES suppressed: a row with resolution_type
 *       =suppressed has resolved_at SET so it's naturally excluded from
 *       the "resolved_at IS NULL" predicate, plus we explicitly hide
 *       suppressed events from any view that would otherwise surface
 *       them (per spec §15.4 "Suppressed events are hidden from drift
 *       dashboard but logged"). Default view stays operator-actionable.
 *   • resolved — only events with resolution_type='resolved' (manual or
 *       auto-resolved on parity). Excludes accepted + suppressed.
 *   • accepted — only events with resolution_type='accepted'.
 *   • suppressed — only events with resolution_type='suppressed'
 *       (explicit operator opt-in to see them).
 *   • all — every event regardless of resolution_type.
 *
 * @method  GET
 * @auth    Session required; require_permission('quickbooks', 'view')
 * @returns 200 { rows, total, page, per_page, total_pages, filters_applied, summary }
 *
 * Spec ref: §15.1 (drift dashboard structure), §15.4 (resolution-state view)
 * Session:  S-QBO-4 (initial), S-QBO-25 (resolution_type filtering)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('quickbooks', 'view');

$status    = isset($_GET['status'])    ? (string) $_GET['status']    : 'unresolved'; // unresolved | resolved | accepted | suppressed | all
$category  = isset($_GET['category'])  ? (string) $_GET['category']  : '';
$entity    = isset($_GET['entity'])    ? (string) $_GET['entity']    : '';
$source    = isset($_GET['source'])    ? (string) $_GET['source']    : '';
$range     = isset($_GET['range'])     ? (string) $_GET['range']     : '30'; // 7 | 30 | 90 | all
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

$allowedCategory = ['count_mismatch','field_mismatch','missing_in_qbo','missing_in_ff','amount_drift','balance_drift','push_failed','pull_failed','stale_object_unresolved'];
$allowedSource   = ['drift_cron','push_failure','pull_failure','manual'];
$allowedStatus   = ['unresolved','resolved','accepted','suppressed','all'];
if (!in_array($status, $allowedStatus, true)) {
    $status = 'unresolved';
}

$where  = [];
$params = [];

if ($status === 'unresolved') {
    // OPEN events only. resolution_type IS NULL is redundant with
    // resolved_at IS NULL (the migration backfilled resolved_at NOT NULL
    // rows to 'resolved') but kept for defense-in-depth.
    $where[] = 'resolved_at IS NULL';
    $where[] = 'resolution_type IS NULL';
} elseif ($status === 'resolved') {
    // Only resolution_type='resolved' (manual or auto-resolved on parity).
    $where[] = "resolution_type = 'resolved'";
} elseif ($status === 'accepted') {
    $where[] = "resolution_type = 'accepted'";
} elseif ($status === 'suppressed') {
    // Only show suppressed when explicitly requested — they're hidden
    // from the default unresolved view per spec §15.4.
    $where[] = "resolution_type = 'suppressed'";
}
// 'all' applies no resolution filter — caller sees every event.
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
    // 'Unresolved' = OPEN only (not accepted, not suppressed, not resolved).
    $sumUnresolved = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events
          WHERE resolved_at IS NULL AND resolution_type IS NULL",
        []
    );
    // 'Resolved (30d)' includes everything terminal in the last 30 days
    // (resolved + accepted + suppressed) — it's the throughput counter.
    $sumResolved30 = (int) db_count(
        "SELECT COUNT(*) FROM acc_qbo_drift_events
          WHERE resolved_at IS NOT NULL
            AND resolved_at >= NOW() - INTERVAL 30 DAY",
        []
    );
    $topCategories = db_select(
        "SELECT category, COUNT(*) AS n FROM acc_qbo_drift_events
          WHERE resolved_at IS NULL AND resolution_type IS NULL
          GROUP BY category
          ORDER BY n DESC
          LIMIT 3",
        []
    );
    $lastUnresolved = db_row(
        "SELECT MAX(detected_at) AS last_detected
           FROM acc_qbo_drift_events
          WHERE resolved_at IS NULL AND resolution_type IS NULL",
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
                d.queue_id, d.resolved_at, d.resolution_type,
                d.resolved_by_user_id, d.resolution_note,
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
