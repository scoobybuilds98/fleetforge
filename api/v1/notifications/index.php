<?php
declare(strict_types=1);

/**
 * api/v1/notifications/index.php
 *
 * Paginated list of in-app notifications for the current admin user.
 * Powers BOTH the topbar bell dropdown (?per_page=10) AND the full
 * /notifications page (?per_page=25 with filters).
 *
 * @method  GET
 * @auth    require_auth_api
 * @query   page          int   default 1
 *          per_page      int   default 25, max 100
 *          is_read       0|1|all  default 'all'
 *          category      string  optional, e.g. "leases", "invoices"
 *          date_range    today|week|month|all  default 'all'
 *          limit         int   alias of per_page (kept for legacy FF_Notifications JS)
 *          order         unread_first (default) | recent
 *                        recent = strictly newest first — the bell's Updates
 *                        tab, which draws a "new since you last looked" line
 *                        where the unread rows end (S-ATTENTION-INBOX)
 *
 * @returns 200 paginated envelope with `data.items[]` and `data.meta.total_unread`
 *
 * Items are returned newest-first within their read/unread group: every
 * unread row first (is_read ASC) then read rows, both ordered by created_at DESC
 * (or strictly newest-first with order=recent).
 *
 * S-ATTENTION-INBOX: each item carries group_count (a burst of the same update
 * folds into one row, "42 invoices created") and day_label (Today / Yesterday
 * / "Mon 22 Sep", business timezone). Money in title/message is scrubbed at
 * serve time for users without payments:view (can_view_financials()) — the
 * same redact-at-serve-time rule as the dashboard.
 *
 * @session NOTIF-1
 * @depends api/bootstrap.php (require_auth_api, json_paginated)
 *          NotificationService (for unread count + category mapping)
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}

// ── Inputs ─────────────────────────────────────────────────────────────────
// `limit` is the legacy param the FF_Notifications JS sends; honour both.
$perPage = clean_int($_GET['per_page'] ?? null)
        ?? clean_int($_GET['limit'] ?? null)
        ?? 25;
$perPage = max(1, min(100, $perPage));

$page    = max(1, clean_int($_GET['page'] ?? null) ?? 1);
$offset  = ($page - 1) * $perPage;

$isReadParam = strtolower(trim((string) ($_GET['is_read'] ?? 'all')));
$category    = trim((string) ($_GET['category'] ?? ''));
$dateRange   = strtolower(trim((string) ($_GET['date_range'] ?? 'all')));
$orderRecent = ($_GET['order'] ?? '') === 'recent';

// ── Build WHERE ─────────────────────────────────────────────────────────────
$where  = ['user_id = ?', 'deleted_at IS NULL'];
$params = [$userId];

if ($isReadParam === '0' || $isReadParam === 'unread') {
    $where[]  = 'is_read = 0';
} elseif ($isReadParam === '1' || $isReadParam === 'read') {
    $where[]  = 'is_read = 1';
}

// Category filter — allowlist to known UI categories
$allowedCategories = [
    'leases', 'invoices', 'payments', 'customers', 'equipment',
    'compliance', 'maintenance', 'damage', 'reservations',
    'samsara', 'accounting', 'quickbooks', 'system',
];
if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $where[]  = 'category = ?';
    $params[] = $category;
}

// Date range filter
switch ($dateRange) {
    case 'today':
        // created_at is UTC; UTC_DATE() made "Today" reset at 5pm Pacific (4pm winter).
        // Company-local midnight as a UTC instant, pushed in fragment order.
        $where[]  = 'created_at >= ?';
        $params[] = ff_local_day_start_utc();
        break;
    case 'week':
        $where[] = 'created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)';
        break;
    case 'month':
        $where[] = 'created_at >= (UTC_TIMESTAMP() - INTERVAL 30 DAY)';
        break;
    // 'all' or anything else: no extra clause
}

$whereSql = implode(' AND ', $where);

// ── Fetch totals ───────────────────────────────────────────────────────────
$total = db_count("SELECT COUNT(*) FROM notifications WHERE $whereSql", $params);

// total_unread is GLOBAL (not filtered) — used by the bell badge so it
// stays accurate regardless of filters the user has applied.
$totalUnread = db_count(
    "SELECT COUNT(*) FROM notifications
      WHERE user_id = ? AND deleted_at IS NULL AND is_read = 0",
    [$userId]
);

// ── Fetch page ─────────────────────────────────────────────────────────────
// LIMIT/OFFSET are SAFE int interpolation (validated above)
$rows = db_select(
    "SELECT id, title, message, type, category, url, entity_type, entity_id,
            group_count, severity, is_read, read_at, created_at
       FROM notifications
      WHERE $whereSql
   ORDER BY " . ($orderRecent ? 'created_at DESC, id DESC' : 'is_read ASC, created_at DESC') . "
      LIMIT $perPage OFFSET $offset",
    $params
);

// ── Decorate ───────────────────────────────────────────────────────────────
// time_ago is computed server-side so the UI doesn't need a date library.
$now      = time();
$canMoney = can_view_financials();
$items = array_map(static function (array $row) use ($now, $canMoney): array {
    $createdTs = strtotime((string) $row['created_at'] . ' UTC') ?: $now;
    // Redact at serve time (never stored pre-redacted): dispatchers keep the
    // update, minus the dollar amounts.
    $title   = (string) $row['title'];
    $message = (string) $row['message'];
    if (!$canMoney) {
        $title   = ff_scrub_money_text($title);
        $message = ff_scrub_money_text($message);
    }
    return [
        'id'          => (int) $row['id'],
        'title'       => $title,
        'message'     => $message,
        'type'        => $row['type'],
        'category'    => $row['category'] ?? 'system',
        'url'         => $row['url'] ?? '#',
        'entity_type' => $row['entity_type'],
        'entity_id'   => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
        'severity'    => $row['severity'],
        'is_read'     => (int) $row['is_read'] === 1,
        'read_at'     => $row['read_at'],
        'created_at'  => $row['created_at'],
        'time_ago'    => _notif_time_ago($now - $createdTs),
        'group_count' => (int) ($row['group_count'] ?? 1),
        'day_label'   => _notif_day_label((string) $row['created_at']),
    ];
}, $rows);

// json_paginated wraps items + pagination but does NOT carry custom meta;
// fall through to json_success with the same shape so we can add total_unread.
json_success([
    'items'      => $items,
    'pagination' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ],
    'meta' => [
        'total_unread' => $totalUnread,
    ],
    // legacy alias used by app.js FF_Notifications.load() which reads data.data.items
    'data'         => $items,
    'total_unread' => $totalUnread,
]);

/**
 * _notif_time_ago — render seconds-elapsed as a short human label.
 *
 * Returns labels like "just now", "5 min ago", "2 hr ago", "3 days ago",
 * "Mar 7" for older items. Kept inline so we don't pollute the global helper file.
 */
function _notif_time_ago(int $secondsAgo): string
{
    if ($secondsAgo < 60)        return 'just now';
    if ($secondsAgo < 3600)      return floor($secondsAgo / 60) . ' min ago';
    if ($secondsAgo < 86400)     return floor($secondsAgo / 3600) . ' hr ago';
    if ($secondsAgo < 86400 * 7) return floor($secondsAgo / 86400) . ' days ago';
    return date('M j', time() - $secondsAgo);
}

/**
 * _notif_day_label — "Today" / "Yesterday" / "Mon 22 Sep" for a UTC stamp,
 * in the business timezone (S-ATTENTION-INBOX: the Updates tab groups by day).
 *
 * @param  string $utc  created_at (UTC DATETIME)
 * @return string
 */
function _notif_day_label(string $utc): string
{
    try {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(ff_business_timezone());
    } catch (Throwable) {
        return '';
    }
    $day   = $local->format('Y-m-d');
    $today = ff_today();
    if ($day === $today) {
        return 'Today';
    }
    if ($day === date('Y-m-d', strtotime($today . ' -1 day'))) {
        return 'Yesterday';
    }
    return $local->format('D j M');
}
