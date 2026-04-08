<?php
/**
 * api/v1/chat/channels/leave.php
 *
 * POST — Removes current user from a channel.
 * Decrements member_count. Cannot leave a DM channel.
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

$channel = db_row("SELECT * FROM chat_channels WHERE id = ?", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);
if ($channel['type'] === 'direct') json_error('VALIDATION_ERROR', 'Cannot leave a direct message channel.', 422);

$membership = db_row("SELECT * FROM chat_channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $userId]);
if (!$membership) json_success(['message' => 'Not a member.']);

db_transaction(function() use ($channelId, $userId) {
    db_execute("DELETE FROM chat_channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $userId]);
    db_execute(
        "UPDATE chat_channels SET member_count = GREATEST(0, member_count - 1) WHERE id = ?",
        [$channelId]
    );
});

json_success(['message' => 'Left channel.']);
