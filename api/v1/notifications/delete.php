<?php
declare(strict_types=1);

/**
 * api/v1/notifications/delete.php
 *
 * Soft-delete a notification (or all read notifications) for the current user.
 *
 * @method  POST
 * @auth    require_auth_api  + CSRF (enforced by api/bootstrap.php)
 * @body    { notification_id: int }     — single
 *      OR  { clear_all_read: true }     — clear ALL already-read rows
 * @returns 200 { deleted: int, unread_count: int }
 *
 * Soft-delete writes deleted_at = UTC_TIMESTAMP() — preserves the row for
 * audit purposes. The notifications table is in SOFT_DELETE_TABLES so all
 * SELECTs in this module already filter out deleted rows.
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

// ── Parse body ─────────────────────────────────────────────────────────────
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

$clearAllRead   = !empty($body['clear_all_read']);
$notificationId = clean_int($body['notification_id'] ?? null);

$deleted = 0;

if ($clearAllRead) {
    $deleted = db_execute(
        'UPDATE notifications
            SET deleted_at = UTC_TIMESTAMP()
          WHERE user_id = ? AND is_read = 1 AND deleted_at IS NULL',
        [$userId]
    );
} elseif ($notificationId !== null) {
    $exists = db_row(
        'SELECT id FROM notifications
          WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
        [$notificationId, $userId]
    );
    if (!$exists) {
        json_error('NOT_FOUND', 'Notification not found.', 404);
    }
    $deleted = db_execute(
        'UPDATE notifications
            SET deleted_at = UTC_TIMESTAMP()
          WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
        [$notificationId, $userId]
    );
} else {
    json_error('VALIDATION_ERROR', 'Either notification_id or clear_all_read is required.', 422);
}

$unreadCount = db_count(
    'SELECT COUNT(*) FROM notifications
       WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL',
    [$userId]
);

json_success([
    'deleted'      => (int) $deleted,
    'unread_count' => $unreadCount,
]);
