<?php
declare(strict_types=1);

/**
 * app/portal/api/notifications/index.php
 *
 * Paginated list of notifications for the current portal user.
 * Returns the same envelope shape as the admin endpoint so the JS
 * factory in app.js can be reused.
 *
 * @method  GET
 * @auth    portal session
 * @query   per_page, page, is_read
 * @returns 200 { items[], pagination, total_unread }
 *
 * @session NOTIF-1
 */

require_once __DIR__ . '/_bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
    portal_json_err('METHOD_NOT_ALLOWED', 'Use GET.', 405);
}

$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
$perPage = max(1, min(100, $perPage));
$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$where  = ['portal_user_id = ?', 'deleted_at IS NULL'];
$params = [$portalUserId];

$isReadParam = strtolower((string) ($_GET['is_read'] ?? 'all'));
if ($isReadParam === '0' || $isReadParam === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($isReadParam === '1' || $isReadParam === 'read') {
    $where[] = 'is_read = 1';
}

$whereSql = implode(' AND ', $where);

$total = db_count("SELECT COUNT(*) FROM notifications WHERE $whereSql", $params);
$totalUnread = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$portalUserId]
);

$rows = db_select(
    "SELECT id, title, message, type, category, url, entity_type, entity_id,
            severity, is_read, read_at, created_at
       FROM notifications
      WHERE $whereSql
   ORDER BY is_read ASC, created_at DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

// Decorate items with time_ago + boolean is_read
$now = time();
$items = array_map(static function (array $row) use ($now): array {
    $createdTs = strtotime((string) $row['created_at'] . ' UTC') ?: $now;
    $sec = $now - $createdTs;
    $timeAgo = match (true) {
        $sec < 60        => 'just now',
        $sec < 3600      => floor($sec / 60) . ' min ago',
        $sec < 86400     => floor($sec / 3600) . ' hr ago',
        $sec < 86400 * 7 => floor($sec / 86400) . ' days ago',
        default          => date('M j', $createdTs),
    };
    return [
        'id'          => (int) $row['id'],
        'title'       => (string) $row['title'],
        'message'     => (string) $row['message'],
        'type'        => $row['type'],
        'category'    => $row['category'] ?? 'system',
        'url'         => $row['url'] ?? '#',
        'entity_type' => $row['entity_type'],
        'entity_id'   => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
        'severity'    => $row['severity'],
        'is_read'     => (int) $row['is_read'] === 1,
        'created_at'  => $row['created_at'],
        'time_ago'    => $timeAgo,
    ];
}, $rows);

portal_json_ok([
    'items'        => $items,
    'pagination'   => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ],
    'total_unread' => $totalUnread,
]);
