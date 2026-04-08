<?php
/**
 * api/v1/chat/unread/mark_read.php
 *
 * POST — Marks all messages in a channel as read for the current user.
 * Updates last_read_at and last_read_message_id in chat_channel_members.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_POST['channel_id'] ?? null);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'Not a channel member.', 403);
}

// Get the latest message ID in this channel
$latest = db_row(
    "SELECT MAX(id) AS max_id FROM chat_messages WHERE channel_id = ? AND is_archived = 0",
    [$channelId]
);
$maxId = $latest['max_id'] ?? null;

db_execute(
    "UPDATE chat_channel_members
     SET last_read_at = NOW(), last_read_message_id = ?
     WHERE channel_id = ? AND user_id = ?",
    [$maxId, $channelId, $userId]
);

json_success(['channel_id' => $channelId, 'last_read_message_id' => $maxId]);
