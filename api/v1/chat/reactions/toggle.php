<?php
/**
 * api/v1/chat/reactions/toggle.php
 *
 * POST — Toggle an emoji reaction on a message.
 * If reaction exists → removes it. If not → adds it.
 * Returns updated reaction counts for the message.
 *
 * Dependencies: api/bootstrap.php, chat_reactions, chat_messages
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$messageId = clean_int($_POST['message_id'] ?? null);
$emoji     = clean_string($_POST['emoji'] ?? null, 10);

if (!$messageId || !$emoji) json_error('MISSING_REQUIRED', 'message_id and emoji are required.', 422);

$msg = db_row("SELECT id, channel_id FROM chat_messages WHERE id = ? AND is_archived = 0", [$messageId]);
if (!$msg) json_error('NOT_FOUND', 'Message not found.', 404);

// Verify user is in channel
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$msg['channel_id'], $userId])) {
    json_error('FORBIDDEN', 'Not a channel member.', 403);
}

// Toggle
$existing = db_exists('chat_reactions', 'message_id = ? AND user_id = ? AND emoji = ?', [$messageId, $userId, $emoji]);
if ($existing) {
    db_execute("DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?", [$messageId, $userId, $emoji]);
    $action = 'removed';
} else {
    db_execute(
        "INSERT IGNORE INTO chat_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)",
        [$messageId, $userId, $emoji]
    );
    $action = 'added';
}

// Return updated counts
$reactions = db_select(
    "SELECT cr.emoji, COUNT(*) AS count,
            MAX(CASE WHEN cr.user_id = ? THEN 1 ELSE 0 END) AS mine
     FROM chat_reactions cr
     WHERE cr.message_id = ?
     GROUP BY cr.emoji
     ORDER BY count DESC",
    [$userId, $messageId]
);

json_success(['action' => $action, 'reactions' => $reactions]);
