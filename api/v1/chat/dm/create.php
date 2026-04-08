<?php
/**
 * api/v1/chat/dm/create.php
 *
 * POST — Creates or returns existing DM channel between current user
 * and target user. DM slug is deterministic: dm_{min_id}_{max_id}.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members, users
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId       = current_user_id();
$targetUserId = clean_int($_POST['user_id'] ?? null);

if (!$targetUserId) json_error('MISSING_REQUIRED', 'user_id is required.', 422);
if ($targetUserId === $userId) json_error('VALIDATION_ERROR', 'Cannot DM yourself.', 422);

$targetUser = db_row("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL AND status = 'active'", [$targetUserId]);
if (!$targetUser) json_error('NOT_FOUND', 'User not found.', 404);

$currentUser = current_user();

// Deterministic slug: dm_min_max
$dmSlug = 'dm_' . min($userId, $targetUserId) . '_' . max($userId, $targetUserId);

// Check existing
$existing = db_row(
    "SELECT cc.*, ccm.role, ccm.last_read_at, ccm.last_read_message_id, ccm.is_muted
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.slug = ? AND cc.type = 'direct'",
    [$userId, $dmSlug]
);

if ($existing) {
    json_success($existing);
}

// Create new DM
$dmName    = $currentUser['name'] . ', ' . $targetUser['name'];
$channelId = null;

db_transaction(function() use ($dmSlug, $dmName, $userId, $targetUserId, &$channelId) {
    $channelId = db_insert('chat_channels', [
        'name'         => $dmName,
        'slug'         => $dmSlug,
        'type'         => 'direct',
        'is_private'   => 1,
        'created_by'   => $userId,
        'member_count' => 2,
    ]);
    db_execute("INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'admin')", [$channelId, $userId]);
    db_execute("INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')", [$channelId, $targetUserId]);
});

$channel = db_row(
    "SELECT cc.*, ccm.role, ccm.last_read_at, ccm.last_read_message_id, ccm.is_muted
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.id = ?",
    [$userId, $channelId]
);

json_success($channel, 201);
