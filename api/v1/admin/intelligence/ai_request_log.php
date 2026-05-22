<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/ai_request_log.php
 *
 * Filterable, paginated view of ai_query_log (F10). Powers the AI
 * Request Log section on the Intelligence tab.
 *
 * Query params:
 *   query_type — filter by feature ('briefing', 'anomaly', 'chat', etc.)
 *   user_id    — filter by requesting user
 *   since      — ISO date filter (rows since this date)
 *   page, page_size (default 25, max 200)
 *
 * @method  GET
 * @auth    require_permission('settings', 'view')
 * @session S-INTEL-V2 Phase A
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();
require_permission('settings', 'view');

$queryType = trim((string) ($_GET['query_type'] ?? ''));
$userId    = isset($_GET['user_id']) && (int) $_GET['user_id'] > 0 ? (int) $_GET['user_id'] : null;
$since     = trim((string) ($_GET['since'] ?? ''));
$page      = max(1, (int) ($_GET['page'] ?? 1));
$pageSize  = max(1, min(200, (int) ($_GET['page_size'] ?? 25)));

try {
    $where  = ['1=1'];
    $params = [];
    if ($queryType !== '') {
        $where[]  = "q.query_type = ?";
        $params[] = $queryType;
    }
    if ($userId !== null) {
        $where[]  = "q.user_id = ?";
        $params[] = $userId;
    }
    if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) {
        $where[]  = "q.created_at >= ?";
        $params[] = $since;
    }
    $whereSql = implode(' AND ', $where);

    $total = (int) db_count("SELECT COUNT(*) FROM ai_query_log q WHERE {$whereSql}", $params);
    $offset = ($page - 1) * $pageSize;

    $rows = db_select(
        "SELECT q.id, q.user_id, u.name AS user_name, q.query_type,
                q.prompt_tokens, q.completion_tokens, q.total_tokens,
                q.cost_usd, q.latency_ms, q.was_cached, q.created_at
           FROM ai_query_log q
           LEFT JOIN users u ON u.id = q.user_id
          WHERE {$whereSql}
          ORDER BY q.id DESC
          LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );

    // Distinct query types for filter dropdown.
    $types = db_select("SELECT DISTINCT query_type FROM ai_query_log ORDER BY query_type");
    $typeList = array_map(static fn($r) => (string) $r['query_type'], $types);

    $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

    json_success([
        'rows'         => $rows,
        'query_types'  => $typeList,
        'pagination'   => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages],
    ]);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'AI request log failed: ' . $e->getMessage(), 500);
}
