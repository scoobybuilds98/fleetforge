<?php
/**
 * api/v1/chat/channels/join.php
 *
 * POST — Adds current user to a channel as 'member'.
 * Only works for non-private, non-archived channels.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_POST['channel_id'] ?? null);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND type = 'channel' AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);
if ($channel['is_private']) json_error('FORBIDDEN', 'Cannot join a private channel.', 403);

// Already a member?
if (db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_success(['message' => 'Already a member.']);
}

db_transaction(function() use ($channelId, $userId) {
    db_execute(
        "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')",
        [$channelId, $userId]
    );
    // WHY: increment denormalized member_count in same transaction
    db_execute("UPDATE chat_channels SET member_count = member_count + 1 WHERE id = ?", [$channelId]);
});

json_success(['message' => 'Joined channel.']);
