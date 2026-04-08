<?php
/**
 * api/v1/chat/channels/index.php
 *
 * GET — Returns all chat channels and DMs the current user belongs to.
 * Channels sorted by last_message_at DESC; unread_count per channel
 * computed from messages after last_read_message_id.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId = current_user_id();

// Channels (type = 'channel') the user is a member of
// WHY: LEFT JOIN + subquery for unread count avoids N+1 queries
$channels = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.description, cc.type,
            cc.is_private, cc.is_archived, cc.last_message_at,
            cc.last_message_preview, cc.member_count,
            ccm.role, ccm.last_read_at, ccm.last_read_message_id,
            ccm.is_muted,
            (
                SELECT COUNT(*)
                FROM chat_messages cm
                WHERE cm.channel_id = cc.id
                  AND cm.is_archived = 0
                  AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
            ) AS unread_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.type = 'channel' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC, cc.name ASC",
    [$userId]
);

// Direct messages the user is part of
$dms = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.type, cc.is_archived,
            cc.last_message_at, cc.last_message_preview, cc.member_count,
            ccm.role, ccm.last_read_at, ccm.last_read_message_id, ccm.is_muted,
            (
                SELECT COUNT(*)
                FROM chat_messages cm
                WHERE cm.channel_id = cc.id
                  AND cm.is_archived = 0
                  AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
            ) AS unread_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.type = 'direct' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC",
    [$userId]
);

// For each DM, attach the other participant's info
foreach ($dms as &$dm) {
    $other = db_row(
        "SELECT u.id, u.name
         FROM chat_channel_members ccm2
         JOIN users u ON u.id = ccm2.user_id
         WHERE ccm2.channel_id = ? AND ccm2.user_id != ?
         LIMIT 1",
        [$dm['id'], $userId]
    );
    $dm['other_user'] = $other ?: null;
}
unset($dm);

json_success([
    'channels'        => $channels,
    'direct_messages' => $dms,
]);
