<?php
/**
 * api/v1/chat/unread/count.php
 *
 * GET — Returns total unread count across all channels + per-channel breakdown.
 * CRITICAL — called every 5 seconds. Target < 50ms.
 * WHY: Single optimised query avoids N+1. Excludes messages sent by self.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId = current_user_id();

$rows = db_select(
    "SELECT ccm.channel_id,
            COUNT(cm.id) AS unread_count
     FROM chat_channel_members ccm
     LEFT JOIN chat_messages cm
           ON  cm.channel_id = ccm.channel_id
           AND cm.is_deleted = 0
           AND cm.user_id != ?
           AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
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
