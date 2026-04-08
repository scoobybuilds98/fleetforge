<?php
/**
 * api/v1/chat/messages/index.php
 *
 * GET ?channel_id=X&before_id=Y&limit=50
 * Returns messages for a channel in reverse chronological order.
 * Cursor pagination by message ID. Includes: user info, attachments,
 * reactions, reply_to preview. Archived messages shown as "[message deleted]".
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members,
 *               chat_attachments, chat_reactions, users
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_GET['channel_id'] ?? null);
$beforeId  = clean_int($_GET['before_id'] ?? null);
$limit     = min(50, max(10, clean_int($_GET['limit'] ?? 50) ?? 50));

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

// Verify membership
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

// Build cursor condition
$cursorSQL = $beforeId ? 'AND cm.id < ?' : '';
$params    = $beforeId ? [$channelId, $beforeId, $limit] : [$channelId, $limit];

$messages = db_select(
    "SELECT cm.id, cm.channel_id, cm.user_id, cm.message, cm.type,
            cm.is_edited, cm.edited_at, cm.is_archived,
            cm.reply_to_id, cm.mentions, cm.created_at, cm.updated_at,
            u.name AS user_name,
            rm.message AS reply_to_message,
            ru.name AS reply_to_user_name
     FROM chat_messages cm
     JOIN users u ON u.id = cm.user_id
     LEFT JOIN chat_messages rm ON rm.id = cm.reply_to_id
     LEFT JOIN users ru ON ru.id = rm.user_id
     WHERE cm.channel_id = ? $cursorSQL
     ORDER BY cm.id DESC
     LIMIT ?",
    $params
);

// Reverse to chronological order for display
$messages = array_reverse($messages);

if (empty($messages)) {
    json_success(['messages' => [], 'has_more' => false]);
}

// Batch-load attachments for all messages
$messageIds = array_column($messages, 'id');
$placeholders = implode(',', array_fill(0, count($messageIds), '?'));
$attachments = db_select(
    "SELECT * FROM chat_attachments WHERE message_id IN ($placeholders)",
    $messageIds
);
// Index by message_id
$attachMap = [];
foreach ($attachments as $att) {
    $attachMap[$att['message_id']][] = $att;
}

// Batch-load reactions
$reactions = db_select(
    "SELECT cr.message_id, cr.emoji, cr.user_id, u.name AS user_name
     FROM chat_reactions cr
     JOIN users u ON u.id = cr.user_id
     WHERE cr.message_id IN ($placeholders)",
    $messageIds
);
// Group reactions by message_id then emoji → count + my_reaction flag
$reactionMap = [];
foreach ($reactions as $r) {
    $key = $r['message_id'] . '_' . $r['emoji'];
    if (!isset($reactionMap[$r['message_id']][$r['emoji']])) {
        $reactionMap[$r['message_id']][$r['emoji']] = ['emoji' => $r['emoji'], 'count' => 0, 'mine' => false, 'users' => []];
    }
    $reactionMap[$r['message_id']][$r['emoji']]['count']++;
    $reactionMap[$r['message_id']][$r['emoji']]['users'][] = $r['user_name'];
    if ($r['user_id'] == $userId) {
        $reactionMap[$r['message_id']][$r['emoji']]['mine'] = true;
    }
}

// Build final response
foreach ($messages as &$msg) {
    // Soft-deleted messages shown as placeholder
    if ($msg['is_archived']) {
        $msg['message'] = null;
        $msg['display_text'] = '[message deleted]';
    } else {
        $msg['display_text'] = $msg['message'];
    }
    $msg['attachments'] = $attachMap[$msg['id']] ?? [];
    $reactions_grouped  = [];
    if (!empty($reactionMap[$msg['id']])) {
        foreach ($reactionMap[$msg['id']] as $emoji => $data) {
            $reactions_grouped[] = $data;
        }
    }
    $msg['reactions']  = $reactions_grouped;
    $msg['mentions']   = $msg['mentions'] ? json_decode($msg['mentions'], true) : [];
}
unset($msg);

// Has more if we got a full page
$hasMore = count($messages) >= $limit;

json_success(['messages' => $messages, 'has_more' => $hasMore]);
