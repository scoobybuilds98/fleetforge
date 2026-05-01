<?php
/**
 * api/v1/chat/channels/create.php
 *
 * POST {name, description, is_private, member_ids[]}
 * Creates a new channel. Creator gets 'owner' role.
 * Posts a system message on creation.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId      = current_user_id();
$user        = current_user();
$name        = clean_string($_POST['name'] ?? null, 100);
$description = clean_string($_POST['description'] ?? null, 500);
$isPrivate   = (int)($_POST['is_private'] ?? 0) ? 1 : 0;
$memberIds   = array_filter(array_map('intval', (array)($_POST['member_ids'] ?? [])));

if (!$name) {
    json_error('VALIDATION_ERROR', 'Channel name is required.', 422);
}

// Slugify: lowercase, only alphanum + hyphens
$slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
$slug     = trim($slug, '-') ?: 'channel';
$baseSlug = $slug;
$suffix   = 1;
while (db_exists('chat_channels', 'slug = ?', [$slug])) {
    $slug = $baseSlug . '-' . $suffix++;
}

// Creator is always included
$allMembers = array_unique(array_merge([$userId], array_values($memberIds)));

$channelId = null;
db_transaction(function() use ($name, $slug, $description, $isPrivate, $userId, $user, $allMembers, &$channelId) {
    $channelId = db_insert('chat_channels', [
        'name'        => $name,
        'slug'        => $slug,
        'description' => $description,
        'type'        => 'channel',
        'is_private'  => $isPrivate,
        'created_by'  => $userId,
    ]);

    // Add members
    foreach ($allMembers as $uid) {
        db_execute(
            "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, ?)",
            [$channelId, $uid, $uid === $userId ? 'owner' : 'member']
        );
    }

    // System message
    db_insert('chat_messages', [
        'channel_id'         => $channelId,
        'user_id'            => $userId,
        'sender_display_name'=> $user['name'] ?? 'System',
        'message'            => 'Channel created by ' . ($user['name'] ?? 'Unknown'),
        'type'               => 'system',
    ]);

    // Update last_message timestamp
    db_execute(
        "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
        ['Channel created', $channelId]
    );
});

$channel = db_row(
    "SELECT cc.*, ccm.role, ccm.last_read_at, ccm.is_muted,
            0 AS unread_count,
            (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = cc.id) AS member_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.id = ?",
    [$userId, $channelId]
);

db_insert('audit_log', [
    'user_id'     => $userId,
    'action'      => 'created',
    'module'      => 'chat',
    'entity_type' => 'chat_channel',
    'entity_id'   => $channelId,
    'description' => 'Created channel #' . $name,
    'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
]);

json_success($channel, 201);
