<?php
/**
 * api/v1/chat/messages/poll.php
 *
 * GET ?channel_id=X&after_id=Y
 * Returns ONLY new messages after given ID. Called every 5 seconds.
 * Also returns updated unread count for this channel.
 * Lightweight — optimised for performance. Target < 100ms.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_GET['channel_id'] ?? null);
$afterId   = clean_int($_GET['after_id'] ?? 0) ?? 0;

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

// Verify membership (fast single-column check)
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

// New messages since afterId
$messages = db_select(
    "SELECT cm.id, cm.channel_id, cm.user_id, cm.message, cm.type,
            cm.is_edited, cm.edited_at, cm.is_archived,
            cm.reply_to_id, cm.mentions, cm.created_at,
            u.name AS user_name
     FROM chat_messages cm
     JOIN users u ON u.id = cm.user_id
     WHERE cm.channel_id = ? AND cm.id > ?
     ORDER BY cm.id ASC
     LIMIT 100",
    [$channelId, $afterId]
);

// For each message, append display_text and empty arrays
foreach ($messages as &$msg) {
    $msg['display_text'] = $msg['is_archived'] ? null : $msg['message'];
    $msg['attachments']  = [];
    $msg['reactions']    = [];
    $msg['mentions']     = $msg['mentions'] ? json_decode($msg['mentions'], true) : [];
}
unset($msg);

// Batch attachments if any messages
if (!empty($messages)) {
    $ids = array_column($messages, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $atts = db_select("SELECT * FROM chat_attachments WHERE message_id IN ($ph)", $ids);
    $attMap = [];
    foreach ($atts as $att) $attMap[$att['message_id']][] = $att;
    foreach ($messages as &$msg) $msg['attachments'] = $attMap[$msg['id']] ?? [];
    unset($msg);
}

// Unread count for this channel
$membership = db_row(
    "SELECT last_read_message_id FROM chat_channel_members WHERE channel_id = ? AND user_id = ?",
    [$channelId, $userId]
);
$unread = db_count(
    "SELECT COUNT(*) FROM chat_messages WHERE channel_id = ? AND is_archived = 0 AND (? IS NULL OR id > ?)",
    [$channelId, $membership['last_read_message_id'], $membership['last_read_message_id']]
);

json_success([
    'messages'     => $messages,
    'unread_count' => $unread,
]);
