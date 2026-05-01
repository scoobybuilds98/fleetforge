<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/poll.php
 *
 * GET ?channel_id=N&after_id=N
 * Returns new messages since after_id for the customer conversation channel.
 * Marks new messages as read.
 *
 * WHY: Separate poll endpoint so the portal page can update without
 *      reloading the full message list. Polled every 10 seconds.
 *
 * Spec: CHAT-2
 */

require_once __DIR__ . '/_bootstrap.php';

if ($method !== 'GET') {
    portal_chat_err('METHOD_NOT_ALLOWED', 'GET only.', 405);
}

$channelId = clean_int($_GET['channel_id'] ?? null);
$afterId   = clean_int($_GET['after_id'] ?? null) ?? 0;

if (!$channelId) {
    portal_chat_err('MISSING_REQUIRED', 'channel_id is required.', 422);
}

// Verify channel belongs to portal user's customer
$chan = db_row(
    "SELECT id FROM chat_channels WHERE id = ? AND type = 'customer' AND customer_id = ? AND is_archived = 0",
    [$channelId, $portalCustomerId]
);
if (!$chan) {
    portal_chat_err('NOT_FOUND', 'Channel not found.', 404);
}

$messages = db_select(
    "SELECT cm.id, cm.user_id, cm.portal_user_id, cm.sender_display_name,
            cm.message, cm.type, cm.is_deleted, cm.is_edited,
            cm.created_at,
            COALESCE(u.name, cm.sender_display_name) AS author_name,
            ca.id AS att_id, ca.attachment_type, ca.entity_id,
            ca.preview_title, ca.preview_subtitle, ca.preview_badge,
            ca.preview_badge_class, ca.preview_url,
            ca.file_name, ca.mime_type
     FROM chat_messages cm
     LEFT JOIN users u ON u.id = cm.user_id
     LEFT JOIN chat_attachments ca ON ca.message_id = cm.id
     WHERE cm.channel_id = ?
       AND cm.id > ?
       AND cm.is_deleted = 0
     ORDER BY cm.id ASC",
    [$channelId, $afterId]
);

// Group attachments onto parent messages
$msgMap = [];
foreach ($messages as $row) {
    $id = (int) $row['id'];
    if (!isset($msgMap[$id])) {
        $msgMap[$id] = [
            'id'                  => $id,
            'user_id'             => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'portal_user_id'      => $row['portal_user_id'] !== null ? (int) $row['portal_user_id'] : null,
            'sender_display_name' => $row['sender_display_name'],
            'message'             => $row['message'],
            'type'                => $row['type'],
            'is_deleted'          => (bool) $row['is_deleted'],
            'is_edited'           => (bool) $row['is_edited'],
            'created_at'          => $row['created_at'],
            'author_name'         => $row['author_name'],
            'attachments'         => [],
        ];
    }
    if ($row['att_id']) {
        $msgMap[$id]['attachments'][] = [
            'id'              => (int) $row['att_id'],
            'attachment_type' => $row['attachment_type'],
            'entity_id'       => $row['entity_id'] ? (int) $row['entity_id'] : null,
            'preview_title'   => $row['preview_title'],
            'preview_subtitle'=> $row['preview_subtitle'],
            'preview_badge'   => $row['preview_badge'],
            'preview_badge_class' => $row['preview_badge_class'],
            'preview_url'     => $row['preview_url'],
            'file_name'       => $row['file_name'],
            'mime_type'       => $row['mime_type'],
        ];
    }
}

$result = array_values($msgMap);

// Mark as read
if (!empty($result)) {
    $latestId = $result[count($result) - 1]['id'];
    db_execute(
        "UPDATE chat_channel_members SET last_read_message_id = ?, last_read_at = NOW()
         WHERE channel_id = ? AND portal_user_id = ?
           AND (last_read_message_id IS NULL OR last_read_message_id < ?)",
        [$latestId, $channelId, $portalUserId, $latestId]
    );
}

portal_chat_ok(['messages' => $result]);
