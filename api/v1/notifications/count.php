<?php
declare(strict_types=1);

/**
 * api/v1/notifications/count.php
 *
 * Lightweight unread-count endpoint, called by the topbar bell every 60s
 * to refresh the badge without paying for the full notifications list.
 *
 * Single COUNT query — must respond in <100ms.
 *
 * @method  GET
 * @auth    require_auth_api
 * @returns 200 { unread_count: int }
 *
 * @session NOTIF-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('GET');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}

$count = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$userId]
);

json_success(['unread_count' => $count]);
