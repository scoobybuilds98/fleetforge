<?php
/**
 * api/v1/chat/channels/create.php
 *
 * POST — Creates a new chat channel and adds members.
 * Creator is added as 'admin'; other members as 'member'.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId      = current_user_id();
$name        = clean_string($_POST['name'] ?? null, 100);
$description = clean_string($_POST['description'] ?? null, 500);
$isPrivate   = (int)($_POST['is_private'] ?? 0) ? 1 : 0;
$memberIds   = array_filter(array_map('intval', (array)($_POST['member_ids'] ?? [])));

if (!$name) {
    json_error('VALIDATION_ERROR', 'Channel name is required.', 422);
}

// Generate slug from name
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
$slug = trim($slug, '-');
if (!$slug) $slug = 'channel';

// Ensure unique slug
$baseSlug = $slug;
$suffix = 1;
while (db_exists('chat_channels', 'slug = ?', [$slug])) {
    $slug = $baseSlug . '-' . $suffix++;
}

// Add creator to member list
$allMembers = array_unique(array_merge([$userId], $memberIds));

$channelId = null;
db_transaction(function() use ($name, $slug, $description, $isPrivate, $userId, $allMembers, &$channelId) {
    $channelId = db_insert('chat_channels', [
        'name'         => $name,
        'slug'         => $slug,
        'description'  => $description,
        'type'         => 'channel',
        'is_private'   => $isPrivate,
        'created_by'   => $userId,
        'member_count' => count($allMembers),
    ]);

    foreach ($allMembers as $uid) {
        db_execute(
            "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, ?)",
            [$channelId, $uid, $uid === $userId ? 'admin' : 'member']
        );
    }
});

$channel = db_row(
    "SELECT cc.*, ccm.role, ccm.last_read_at, ccm.is_muted
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.id = ?",
    [$userId, $channelId]
);

json_success($channel, 201);
