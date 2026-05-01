<?php
/**
 * api/v1/chat/channels/members/add.php
 *
 * POST {channel_id, user_ids[]}
 * Adds one or more staff members to a channel.
 * Any existing member can add others (Slack standard).
 * Posts a system message listing added names.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$user      = current_user();
$channelId = clean_int($_POST['channel_id'] ?? null);
$userIds   = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));

if (!$channelId)       json_error('MISSING_REQUIRED', 'channel_id is required.', 422);
if (empty($userIds))   json_error('MISSING_REQUIRED', 'user_ids[] is required.', 422);

// Requester must be a member
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

$addedNames = [];
db_transaction(function() use ($channelId, $userId, $userIds, &$addedNames) {
    foreach ($userIds as $uid) {
        $u = db_row("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$uid]);
        if (!$u) continue;
        if (db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $uid])) continue;
        db_insert('chat_channel_members', [
            'channel_id' => $channelId,
            'user_id'    => $uid,
            'role'       => 'member',
        ]);
        $addedNames[] = $u['name'];
    }
});

if (empty($addedNames)) {
    json_success(['message' => 'All users already members.', 'added' => []]);
}

// System message
$adderName = $user['name'] ?? 'Someone';
$namesList = implode(', ', $addedNames);
db_insert('chat_messages', [
    'channel_id'          => $channelId,
    'user_id'             => $userId,
    'sender_display_name' => $adderName,
    'message'             => "{$adderName} added {$namesList} to the channel",
    'type'                => 'system',
]);
db_execute(
    "UPDATE chat_channels SET last_message_at = NOW() WHERE id = ?",
    [$channelId]
);

json_success(['message' => count($addedNames) . ' member(s) added.', 'added' => $addedNames]);
