<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/channel.php
 *
 * GET — Returns the customer's most recent chat conversation channel.
 * Ensures the portal user is registered as a member (for unread tracking).
 *
 * WHY: Customer conversations are created by admin. Portal users are not
 *      automatically added as members, so we add them on first access.
 *      member record presence enables last_read_message_id tracking.
 *
 * Response: { channel: {...} | null }
 *
 * Spec: CHAT-2
 */

require_once __DIR__ . '/_bootstrap.php';

if ($method !== 'GET') {
    portal_chat_err('METHOD_NOT_ALLOWED', 'GET only.', 405);
}

// Find the most recent non-archived customer conversation for this customer
$channel = db_row(
    "SELECT cc.id, cc.name, cc.last_message_at, cc.last_message_preview
     FROM chat_channels cc
     WHERE cc.type = 'customer'
       AND cc.customer_id = ?
       AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC
     LIMIT 1",
    [$portalCustomerId]
);

if (!$channel) {
    portal_chat_ok(['channel' => null]);
}

$channelId = (int) $channel['id'];

// Ensure the portal user is a member of this channel (for unread tracking).
// WHY: INSERT only if not already present — no unique constraint on (channel_id, portal_user_id)
//      so we check first to avoid duplicates.
$existing = db_row(
    "SELECT id FROM chat_channel_members WHERE channel_id = ? AND portal_user_id = ?",
    [$channelId, $portalUserId]
);
if (!$existing) {
    db_insert('chat_channel_members', [
        'channel_id'     => $channelId,
        'portal_user_id' => $portalUserId,
        'role'           => 'member',
        'joined_at'      => date('Y-m-d H:i:s'),
    ]);
}

portal_chat_ok(['channel' => $channel]);
