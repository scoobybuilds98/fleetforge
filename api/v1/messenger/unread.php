<?php
/**
 * api/v1/messenger/unread.php
 *
 * GET — Returns the total number of threads with at least one unread
 * message from the portal side for the current admin. Used to badge
 * the "Messenger" nav item in the admin sidebar.
 *
 * Response shape:  { "total_unread": 3, "thread_count": 2 }
 *
 * Dependencies: api/bootstrap.php, messenger_messages, messenger_threads,
 *               messenger_thread_reads
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$adminId = current_user_id();

// Each matching row is a thread where this admin's read cursor is older
// than the newest portal-authored message — exactly the set the sidebar
// should badge.
$row = db_row(
    "SELECT
        COUNT(*) AS thread_count,
        COALESCE(SUM(unread_count), 0) AS total_unread
     FROM (
        SELECT mt.id,
               (SELECT COUNT(*)
                  FROM messenger_messages mm
                  LEFT JOIN messenger_thread_reads mtr
                         ON mtr.thread_id = mt.id AND mtr.admin_user_id = ?
                 WHERE mm.thread_id = mt.id
                   AND mm.sender_type = 'portal'
                   AND mm.is_archived = 0
                   AND (mtr.last_read_message_id IS NULL OR mm.id > mtr.last_read_message_id)
               ) AS unread_count
          FROM messenger_threads mt
         WHERE mt.is_archived = 0
     ) t
     WHERE t.unread_count > 0",
    [$adminId]
);

json_success([
    'total_unread' => (int) ($row['total_unread'] ?? 0),
    'thread_count' => (int) ($row['thread_count'] ?? 0),
]);
