<?php
/**
 * api/v1/chat/messages/poll.php
 *
 * GET ?channel_id=X&after_id=Y
 * Returns ONLY new messages after given ID. Called every 5 seconds.
 * LIGHTWEIGHT — target < 100ms.
 * Max 50 messages per poll.
 * Also returns per-channel unread counts for sidebar refresh.
 *
 * WHY: Separate poll endpoint keeps the hot path minimal —
 *      no joins beyond what's needed, no attachment heavy-lift.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_GET['channel_id'] ?? null);
$afterId   = clean_int($_GET['after_id'] ?? 0) ?? 0;

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

// Fast membership check
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'Not a channel member.', 403);
}

// New messages only
$messages = db_select(
    "SELECT cm.id, cm.channel_id, cm.user_id, cm.portal_user_id,
            cm.sender_display_name, cm.message, cm.type,
            cm.is_edited, cm.edited_at, cm.is_deleted,
            cm.reply_to_id, cm.mentions, cm.created_at,
            COALESCE(u.name, cm.sender_display_name, 'Unknown') AS user_name
     FROM chat_messages cm
     LEFT JOIN users u ON u.id = cm.user_id
     WHERE cm.channel_id = ? AND cm.id > ?
     ORDER BY cm.id ASC
     LIMIT 50",
    [$channelId, $afterId]
);

foreach ($messages as &$msg) {
    $parts = explode(' ', trim($msg['user_name'] ?? '?'));
    $msg['initials']    = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($parts, 0, 2))));
    $msg['display_text'] = $msg['is_deleted'] ? null : $msg['message'];
    $msg['attachments']  = [];
    $msg['reactions']    = [];
    $msg['mentions']     = $msg['mentions'] ? json_decode($msg['mentions'], true) : [];
}
unset($msg);

// Batch-load attachments if there are new messages
if (!empty($messages)) {
    $ids = array_column($messages, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $atts = db_select(
        "SELECT id, message_id, attachment_type, entity_id, file_name, file_size,
                mime_type, preview_title, preview_subtitle, preview_badge,
                preview_badge_class, preview_url
         FROM chat_attachments WHERE message_id IN ({$ph})",
        $ids
    );
    $attMap = [];
    foreach ($atts as $att) $attMap[$att['message_id']][] = $att;
    foreach ($messages as &$msg) $msg['attachments'] = $attMap[$msg['id']] ?? [];
    unset($msg);
}

// Current unread count for this channel
$membership = db_row(
    "SELECT last_read_message_id FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $userId]
);
$lastReadId = $membership['last_read_message_id'];
$unread = db_count(
    "SELECT COUNT(*) FROM chat_messages
     WHERE channel_id = ? AND is_deleted = 0 AND user_id != ?
     AND (? IS NULL OR id > ?)",
    [$channelId, $userId, $lastReadId, $lastReadId]
);

json_success([
    'messages'     => $messages,
    'unread_count' => $unread,
]);
