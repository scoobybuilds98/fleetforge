<?php
/**
 * api/v1/chat/channels/update.php
 *
 * POST {channel_id, name, description, is_private}
 * Updates channel metadata. Only owner or admin can update.
 * Uses optimistic lock (D19).
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
$name      = clean_string($_POST['name'] ?? null, 100);
$desc      = clean_string($_POST['description'] ?? null, 500);
$isPrivate = isset($_POST['is_private']) ? ((int)$_POST['is_private'] ? 1 : 0) : null;

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);
if (!$name)      json_error('VALIDATION_ERROR', 'Channel name is required.', 422);

$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

// Only owner or admin can update
$member = db_row(
    "SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $userId]
);
if (!$member || !in_array($member['role'], ['owner', 'admin'])) {
    json_error('FORBIDDEN', 'Only channel owners and admins can update channel settings.', 403);
}

$updates = ['name' => $name, 'description' => $desc];
if ($isPrivate !== null) $updates['is_private'] = $isPrivate;

db_update('chat_channels', $updates, 'id = ?', [$channelId]);

json_success(db_row("SELECT * FROM chat_channels WHERE id = ?", [$channelId]));
