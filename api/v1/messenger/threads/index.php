<?php
/**
 * api/v1/messenger/threads/index.php
 *
 * GET — List every customer messenger thread the logged-in admin can see.
 *
 * Access model: any admin with customers.view can see ALL threads — the
 * messenger is a shared support inbox. Each row carries the admin-side
 * unread count for the CURRENT admin (from messenger_thread_reads) so the
 * UI can badge threads with new activity.
 *
 * Response shape:
 *   {
 *     "threads": [
 *       {
 *         "id": 12,
 *         "customer_id": 1,
 *         "customer_name": "LP Logistics Inc.",
 *         "scope": "customer" | "portal_user",
 *         "portal_user_id": null | 5,
 *         "portal_user_name": null | "John Apex",
 *         "subject": "…",
 *         "last_message_at": "2026-04-08 14:31:00",
 *         "last_message_preview": "thanks!",
 *         "last_message_by": "portal",
 *         "unread_count": 3
 *       },
 *       ...
 *     ]
 *   }
 *
 * Dependencies: api/bootstrap.php, messenger_threads, messenger_messages,
 *               messenger_thread_reads, customers, portal_users
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$adminId = current_user_id();

// WHY: correlated subquery computes per-admin unread count without an N+1.
// A message counts as unread when:
//   - it came from the portal side (admin shouldn't count their own sends), AND
//   - the admin has no read cursor, OR the message id is newer than their cursor.
$threads = db_select(
    "SELECT mt.id,
            mt.customer_id,
            c.company_name AS customer_name,
            mt.scope,
            mt.portal_user_id,
            pu.name AS portal_user_name,
            mt.subject,
            mt.last_message_at,
            mt.last_message_preview,
            mt.last_message_by,
            mt.created_at,
            (
                SELECT COUNT(*)
                  FROM messenger_messages mm
                  LEFT JOIN messenger_thread_reads mtr
                         ON mtr.thread_id = mt.id AND mtr.admin_user_id = ?
                 WHERE mm.thread_id = mt.id
                   AND mm.sender_type = 'portal'
                   AND mm.is_archived = 0
                   AND (mtr.last_read_message_id IS NULL OR mm.id > mtr.last_read_message_id)
            ) AS unread_count
       FROM messenger_threads mt
       JOIN customers c ON c.id = mt.customer_id AND c.deleted_at IS NULL
       LEFT JOIN portal_users pu ON pu.id = mt.portal_user_id
      WHERE mt.is_archived = 0
      ORDER BY COALESCE(mt.last_message_at, mt.created_at) DESC",
    [$adminId]
);

// Normalize int-ish strings for the frontend
foreach ($threads as &$t) {
    $t['id']             = (int) $t['id'];
    $t['customer_id']    = (int) $t['customer_id'];
    $t['portal_user_id'] = $t['portal_user_id'] !== null ? (int) $t['portal_user_id'] : null;
    $t['unread_count']   = (int) $t['unread_count'];
}
unset($t);

json_success(['threads' => $threads]);
