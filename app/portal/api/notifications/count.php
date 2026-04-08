<?php
declare(strict_types=1);

/**
 * app/portal/api/notifications/count.php
 *
 * Lightweight unread-count for the portal bell badge. Polled every 60s.
 *
 * @method  GET
 * @auth    portal session
 * @returns 200 { unread_count }
 *
 * @session NOTIF-1
 */

require_once __DIR__ . '/_bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
    portal_json_err('METHOD_NOT_ALLOWED', 'Use GET.', 405);
}

$count = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$portalUserId]
);

portal_json_ok(['unread_count' => $count]);
