<?php
declare(strict_types=1);

/**
 * app/portal/api/notifications/mark_read.php
 *
 * Mark a single notification (or all) as read for the current portal user.
 *
 * @method  POST
 * @auth    portal session + CSRF
 * @body    { notification_id: int } | { mark_all: true }
 * @returns 200 { marked, unread_count }
 *
 * @session NOTIF-1
 */

require_once __DIR__ . '/_bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    portal_json_err('METHOD_NOT_ALLOWED', 'Use POST.', 405);
}

$raw = file_get_contents('php://input');
$body = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}

$markAll = !empty($body['mark_all']);
$notificationId = isset($body['notification_id']) ? (int) $body['notification_id'] : 0;

$marked = 0;

if ($markAll) {
    $marked = db_execute(
        'UPDATE notifications
            SET is_read = 1, read_at = UTC_TIMESTAMP()
          WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
        [$portalUserId]
    );
} elseif ($notificationId > 0) {
    $exists = db_row(
        'SELECT id FROM notifications
          WHERE id = ? AND portal_user_id = ? AND deleted_at IS NULL',
        [$notificationId, $portalUserId]
    );
    if (!$exists) portal_json_err('NOT_FOUND', 'Notification not found.', 404);

    $marked = db_execute(
        'UPDATE notifications
            SET is_read = 1, read_at = UTC_TIMESTAMP()
          WHERE id = ? AND portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
        [$notificationId, $portalUserId]
    );
} else {
    portal_json_err('VALIDATION_ERROR', 'Either notification_id or mark_all is required.', 422);
}

$unreadCount = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE portal_user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$portalUserId]
);

portal_json_ok([
    'marked'       => (int) $marked,
    'unread_count' => $unreadCount,
]);
