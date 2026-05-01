<?php
/**
 * api/v1/chat/channels/archive.php
 *
 * POST {channel_id}
 * Soft-archives a channel. History preserved but channel hidden.
 * Only owner or super_admin can archive.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_POST['channel_id'] ?? null);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

// Only owner or super_admin
$member = db_row(
    "SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $userId]
);
if (!is_super_admin() && (!$member || $member['role'] !== 'owner')) {
    json_error('FORBIDDEN', 'Only channel owners can archive channels.', 403);
}

db_execute("UPDATE chat_channels SET is_archived = 1 WHERE id = ?", [$channelId]);

db_insert('audit_log', [
    'user_id'     => $userId,
    'action'      => 'archived',
    'module'      => 'chat',
    'entity_type' => 'chat_channel',
    'entity_id'   => $channelId,
    'description' => 'Archived channel #' . $channel['name'],
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success(['message' => 'Channel archived.', 'id' => $channelId]);
