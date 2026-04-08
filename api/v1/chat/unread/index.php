<?php
/**
 * api/v1/chat/unread/index.php
 *
 * GET — Returns total unread count across ALL channels the user belongs to.
 * Also returns per-channel breakdown. Called every 5 seconds by FF_ChatBadge.
 * Target: < 50ms.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId = current_user_id();

// Single query: unread per channel, summed client-side
// WHY: correlated subquery per channel would be N+1; this is one JOIN
$rows = db_select(
    "SELECT ccm.channel_id,
            COUNT(cm.id) AS unread_count
     FROM chat_channel_members ccm
     LEFT JOIN chat_messages cm
           ON  cm.channel_id = ccm.channel_id
           AND cm.is_archived = 0
           AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
           AND cm.user_id != ?
     WHERE ccm.user_id = ?
     GROUP BY ccm.channel_id",
    [$userId, $userId]
);

$total    = 0;
$channels = [];
foreach ($rows as $r) {
    $count = (int)$r['unread_count'];
    $total += $count;
    $channels[] = ['id' => (int)$r['channel_id'], 'unread' => $count];
}

json_success(['total_unread' => $total, 'channels' => $channels]);
