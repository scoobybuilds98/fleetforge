<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/send.php
 *
 * POST { channel_id: N, message: "text" }
 * Sends a message to the customer conversation channel as the portal user.
 *
 * WHY: Portal users send with portal_user_id (not user_id). The admin chat
 *      endpoints use require_auth_api() which is staff-only — portal users
 *      need their own guarded send endpoint.
 *
 * Spec: CHAT-2
 */

require_once __DIR__ . '/_bootstrap.php';

if ($method !== 'POST') {
    portal_chat_err('METHOD_NOT_ALLOWED', 'POST only.', 405);
}

$body      = portal_chat_input();
$channelId = isset($body['channel_id']) ? (int) $body['channel_id'] : 0;
$message   = trim((string) ($body['message'] ?? ''));

if (!$channelId) {
    portal_chat_err('MISSING_REQUIRED', 'channel_id is required.', 422);
}
if ($message === '') {
    portal_chat_err('MISSING_REQUIRED', 'message is required.', 422);
}
if (mb_strlen($message) > 5000) {
    portal_chat_err('VALIDATION_ERROR', 'Message too long (max 5000 characters).', 422);
}

// Verify channel belongs to portal user's customer
$chan = db_row(
    "SELECT id FROM chat_channels WHERE id = ? AND type = 'customer' AND customer_id = ? AND is_archived = 0",
    [$channelId, $portalCustomerId]
);
if (!$chan) {
    portal_chat_err('NOT_FOUND', 'Channel not found.', 404);
}

// Portal user's display name from session
$portalUser  = portal_user();
$senderName  = $portalUser['name'] ?? ($portalUser['company_name'] ?? 'Customer');

// Insert the message
$msgId = db_insert('chat_messages', [
    'channel_id'           => $channelId,
    'user_id'              => null,                  // WHY: portal_user, not staff
    'portal_user_id'       => $portalUserId,
    'sender_display_name'  => $senderName,
    'message'              => $message,
    'type'                 => 'text',
    'created_at'           => date('Y-m-d H:i:s'),
    'updated_at'           => date('Y-m-d H:i:s'),
]);

// Update channel's last_message_at + preview
$preview = mb_substr($message, 0, 100);
db_update('chat_channels', [
    'last_message_at'      => date('Y-m-d H:i:s'),
    'last_message_preview' => $preview,
], 'id = ?', [$channelId]);

// Mark channel as read for this portal user (their own message)
db_execute(
    "UPDATE chat_channel_members SET last_read_message_id = ?, last_read_at = NOW()
     WHERE channel_id = ? AND portal_user_id = ?",
    [$msgId, $channelId, $portalUserId]
);

portal_chat_ok(['id' => $msgId], 201);
