<?php
declare(strict_types=1);

/**
 * api/v1/notifications/mark_read.php
 *
 * Mark a single notification (or ALL notifications) as read for the current user.
 *
 * @method  POST
 * @auth    require_auth_api  + CSRF (enforced by api/bootstrap.php)
 * @body    { notification_id: int }   — single
 *      OR  { mark_all: true }         — bulk
 * @returns 200 { unread_count: int, marked: int }
 *
 * Only the recipient can mark their own notifications as read — the WHERE
 * clause includes user_id so cross-user mark-read is impossible even if the
 * caller submits an arbitrary notification_id.
 *
 * @session NOTIF-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}

// ── Parse body — support JSON OR form-encoded ─────────────────────────────
$raw = file_get_contents('php://input');
$body = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
if (empty($body) && !empty($_POST)) {
    $body = $_POST;
}

$markAll = !empty($body['mark_all']);
$notificationId = clean_int($body['notification_id'] ?? null);

$marked = 0;

if ($markAll) {
    $marked = db_execute(
        'UPDATE notifications
            SET is_read = 1, read_at = UTC_TIMESTAMP()
          WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
        [$userId]
    );
} elseif ($notificationId !== null) {
    // Confirm ownership before marking — no leak of other users' notifications
    $exists = db_row(
        'SELECT id FROM notifications
          WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
        [$notificationId, $userId]
    );
    if (!$exists) {
        json_error('NOT_FOUND', 'Notification not found.', 404);
    }
    $marked = db_execute(
        'UPDATE notifications
            SET is_read = 1, read_at = UTC_TIMESTAMP()
          WHERE id = ? AND user_id = ? AND deleted_at IS NULL AND is_read = 0',
        [$notificationId, $userId]
    );
} else {
    json_error('VALIDATION_ERROR', 'Either notification_id or mark_all is required.', 422);
}

$unreadCount = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$userId]
);

json_success([
    'marked'       => (int) $marked,
    'unread_count' => $unreadCount,
]);
