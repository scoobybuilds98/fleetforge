<?php
/**
 * api/v1/chat/dm/create.php
 *
 * POST {user_id}
 * Find existing DM between current user and target user, or create one.
 * WHY: Prevents duplicate DM channels for the same pair.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$myId     = current_user_id();
$targetId = clean_int($_POST['user_id'] ?? null);

if (!$targetId)          json_error('MISSING_REQUIRED', 'user_id is required.', 422);
if ($targetId === $myId) json_error('VALIDATION_ERROR', 'Cannot DM yourself.', 422);

$target = db_row("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL", [$targetId]);
if (!$target) json_error('NOT_FOUND', 'User not found.', 404);

// Look for an existing DM between exactly these two users
$existing = db_row(
    "SELECT cc.* FROM chat_channels cc
     WHERE cc.type = 'direct'
       AND cc.is_archived = 0
       AND EXISTS (SELECT 1 FROM chat_channel_members WHERE channel_id = cc.id AND user_id = ?)
       AND EXISTS (SELECT 1 FROM chat_channel_members WHERE channel_id = cc.id AND user_id = ?)
       AND (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = cc.id) = 2
     LIMIT 1",
    [$myId, $targetId]
);

if ($existing) {
    $existing['other_user']   = $target;
    $existing['unread_count'] = 0;
    json_success($existing);
}

$myUser = current_user();
$name   = ($myUser['name'] ?? 'User') . ', ' . $target['name'];
$slug   = 'dm_' . min($myId, $targetId) . '_' . max($myId, $targetId);

$baseSl = $slug;
$suf    = 1;
while (db_exists('chat_channels', 'slug = ?', [$slug])) {
    $slug = $baseSl . '_' . $suf++;
}

$channelId = null;
db_transaction(function() use ($name, $slug, $myId, $targetId, &$channelId) {
    $channelId = db_insert('chat_channels', [
        'name'       => $name,
        'slug'       => $slug,
        'type'       => 'direct',
        'created_by' => $myId,
    ]);
    db_insert('chat_channel_members', ['channel_id' => $channelId, 'user_id' => $myId,     'role' => 'owner']);
    db_insert('chat_channel_members', ['channel_id' => $channelId, 'user_id' => $targetId, 'role' => 'member']);
});

$channel                  = db_row("SELECT * FROM chat_channels WHERE id = ?", [$channelId]);
$channel['other_user']    = $target;
$channel['unread_count']  = 0;

json_success($channel, 201);
