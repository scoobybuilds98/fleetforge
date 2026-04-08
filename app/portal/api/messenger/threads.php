<?php
declare(strict_types=1);

/**
 * app/portal/api/messenger/threads.php
 *
 * GET — List every open messenger thread the current portal user can see.
 *
 * Visibility rules (enforced here, not in MessengerService):
 *   - All threads where customer_id = my customer_id AND scope='customer'
 *   - PLUS threads where scope='portal_user' AND portal_user_id = my id
 *
 * Never reveals threads belonging to other customers or threads pinned to
 * a sibling portal user.
 *
 * @session MSGR-1
 */

require_once __DIR__ . '/_bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
    portal_msgr_err('METHOD_NOT_ALLOWED', 'Use GET.', 405);
}

// Correlated subquery computes per-portal-user unread count. A message is
// unread when:
//   - it came from the admin side (our own sends never count), AND
//   - our read cursor is either missing or older than the message id.
$threads = db_select(
    "SELECT mt.id,
            mt.customer_id,
            mt.scope,
            mt.portal_user_id,
            mt.subject,
            mt.last_message_at,
            mt.last_message_preview,
            mt.last_message_by,
            mt.created_at,
            (
                SELECT COUNT(*)
                  FROM messenger_messages mm
                  LEFT JOIN messenger_thread_reads mtr
                         ON mtr.thread_id = mt.id AND mtr.portal_user_id = ?
                 WHERE mm.thread_id = mt.id
                   AND mm.sender_type = 'admin'
                   AND mm.is_archived = 0
                   AND (mtr.last_read_message_id IS NULL OR mm.id > mtr.last_read_message_id)
            ) AS unread_count
       FROM messenger_threads mt
      WHERE mt.is_archived = 0
        AND mt.customer_id = ?
        AND (
             mt.scope = 'customer'
             OR (mt.scope = 'portal_user' AND mt.portal_user_id = ?)
        )
      ORDER BY COALESCE(mt.last_message_at, mt.created_at) DESC",
    [$portalUserId, $portalCustomerId, $portalUserId]
);

foreach ($threads as &$t) {
    $t['id']             = (int) $t['id'];
    $t['customer_id']    = (int) $t['customer_id'];
    $t['portal_user_id'] = $t['portal_user_id'] !== null ? (int) $t['portal_user_id'] : null;
    $t['unread_count']   = (int) $t['unread_count'];
}
unset($t);

portal_msgr_ok(['threads' => $threads]);
