<?php
/**
 * api/v1/chat/channels/members/remove.php
 *
 * POST {channel_id, user_id}
 * Removes a member from a channel.
 * Owner/admin can remove anyone. Members can only remove themselves (leave).
 * Posts system message.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$actorId   = current_user_id();
$actor     = current_user();
$channelId = clean_int($_POST['channel_id'] ?? null);
$targetId  = clean_int($_POST['user_id'] ?? null);

if (!$channelId || !$targetId) json_error('MISSING_REQUIRED', 'channel_id and user_id are required.', 422);

$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

$actorMember = db_row(
    "SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $actorId]
);
if (!$actorMember) json_error('FORBIDDEN', 'You are not a member of this channel.', 403);

// Members can only remove themselves; owner/admin can remove anyone
$isSelf    = ($actorId === $targetId);
$isPrivileged = in_array($actorMember['role'], ['owner', 'admin']);
if (!$isSelf && !$isPrivileged) {
    json_error('FORBIDDEN', 'You can only remove yourself from a channel.', 403);
}

$targetMember = db_row(
    "SELECT ccm.*, u.name FROM chat_channel_members ccm JOIN users u ON u.id = ccm.user_id WHERE ccm.channel_id = ? AND ccm.user_id = ?",
    [$channelId, $targetId]
);
if (!$targetMember) json_error('NOT_FOUND', 'User is not a member of this channel.', 404);

// Cannot remove the owner if they're the only owner
if ($targetMember['role'] === 'owner') {
    $ownerCount = db_count("SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = ? AND role = 'owner'", [$channelId]);
    if ($ownerCount <= 1) json_error('VALIDATION_ERROR', 'Cannot remove the last channel owner.', 422);
}

db_execute(
    "DELETE FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $targetId]
);

$verb     = $isSelf ? 'left' : 'was removed from';
$actName  = $actor['name'] ?? 'Unknown';
$targName = $targetMember['name'] ?? 'Unknown';
$sysMsg   = $isSelf
    ? "{$targName} left the channel"
    : "{$actName} removed {$targName} from the channel";

db_insert('chat_messages', [
    'channel_id'          => $channelId,
    'user_id'             => $actorId,
    'sender_display_name' => $actName,
    'message'             => $sysMsg,
    'type'                => 'system',
]);
db_execute("UPDATE chat_channels SET last_message_at = NOW() WHERE id = ?", [$channelId]);

json_success(['message' => $sysMsg]);
