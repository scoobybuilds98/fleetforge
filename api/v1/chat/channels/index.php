<?php
/**
 * api/v1/chat/channels/index.php
 *
 * GET — Returns all channels, DMs, and customer conversations the current
 * user is a member of. Includes per-channel unread count.
 *
 * WHY: Separate result sets by type so the Alpine component can render
 *      three distinct sidebar sections without client-side filtering.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId = current_user_id();

// ── Helper: build unread subquery string ───────────────────────────────────
// Returns count of messages in channel after user's last read message,
// excluding messages sent by the user themselves.
// WHY: Correlated subquery per channel avoids N+1; this form is inlined.

$unreadSub = "(
    SELECT COUNT(*) FROM chat_messages cm
    WHERE cm.channel_id = cc.id
      AND cm.is_deleted = 0
      AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
      AND cm.user_id != {$userId}
)";

// ── Channels (type = 'channel') ───────────────────────────────────────────
$channels = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.description, cc.type,
            cc.is_private, cc.is_archived, cc.last_message_at,
            cc.last_message_preview, ccm.role, ccm.is_muted,
            ccm.last_read_message_id,
            {$unreadSub} AS unread_count,
            (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = cc.id) AS member_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.type = 'channel' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC, cc.name ASC",
    [$userId]
);

// ── Direct messages (type = 'direct') ────────────────────────────────────
$dms = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.type, cc.is_archived,
            cc.last_message_at, cc.last_message_preview,
            ccm.role, ccm.is_muted, ccm.last_read_message_id,
            {$unreadSub} AS unread_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.type = 'direct' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC",
    [$userId]
);

// Attach the other participant's info to each DM
foreach ($dms as &$dm) {
    $other = db_row(
        "SELECT u.id, u.name
         FROM chat_channel_members ccm2
         JOIN users u ON u.id = ccm2.user_id
         WHERE ccm2.channel_id = ? AND ccm2.user_id != ? AND ccm2.user_id IS NOT NULL
         LIMIT 1",
        [$dm['id'], $userId]
    );
    $dm['other_user'] = $other ?: null;
}
unset($dm);

// ── Customer conversations (type = 'customer') ────────────────────────────
$customerConvos = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.type, cc.description, cc.is_archived,
            cc.last_message_at, cc.last_message_preview, cc.customer_id,
            ccm.role, ccm.is_muted, ccm.last_read_message_id,
            {$unreadSub} AS unread_count,
            c.company_name AS customer_company_name
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     LEFT JOIN customers c ON c.id = cc.customer_id
     WHERE cc.type = 'customer' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC",
    [$userId]
);

json_success([
    'channels'               => $channels,
    'direct_messages'        => $dms,
    'customer_conversations' => $customerConvos,
]);
