<?php
/**
 * api/v1/chat/messages/create.php
 *
 * POST — Creates a new chat message with optional attachments and @mentions.
 * Updates channel.last_message_at and last_message_preview.
 * Fires notifications to @mentioned users and DM recipients.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members,
 *               chat_attachments, lib/Notifications/NotificationService.php
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId      = current_user_id();
$channelId   = clean_int($_POST['channel_id'] ?? null);
$message     = clean_string($_POST['message'] ?? null, 5000);
$replyToId   = clean_int($_POST['reply_to_id'] ?? null);
$mentionIds  = array_filter(array_map('intval', (array)($_POST['mentions'] ?? [])));
$attachments = (array)($_POST['attachments'] ?? []);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);
if (!$message && empty($attachments)) json_error('VALIDATION_ERROR', 'Message or attachment is required.', 422);

// Verify membership
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

// Validate reply_to_id exists in same channel
if ($replyToId) {
    if (!db_exists('chat_messages', 'id = ? AND channel_id = ?', [$replyToId, $channelId])) {
        $replyToId = null;
    }
}

$type = (!$message && !empty($attachments)) ? 'attachment' : 'text';
$messageId = null;

db_transaction(function() use (
    $channelId, $userId, $message, $type, $replyToId, $mentionIds,
    $attachments, &$messageId
) {
    $messageId = db_insert('chat_messages', [
        'channel_id'  => $channelId,
        'user_id'     => $userId,
        'message'     => $message,
        'type'        => $type,
        'reply_to_id' => $replyToId,
        'mentions'    => !empty($mentionIds) ? json_encode(array_values($mentionIds)) : null,
    ]);

    // Save attachments
    foreach ($attachments as $att) {
        if (!is_array($att)) continue;
        $attType     = clean_string($att['type'] ?? null, 50);
        $entityId    = clean_int($att['entity_id'] ?? null);
        $previewData = isset($att['preview_data']) ? $att['preview_data'] : null;
        if (!$attType) continue;
        db_insert('chat_attachments', [
            'message_id'   => $messageId,
            'type'         => $attType,
            'entity_id'    => $entityId,
            'preview_data' => is_array($previewData) ? json_encode($previewData) : $previewData,
        ]);
    }

    // Update channel last_message
    $preview = $message ? mb_substr($message, 0, 100) : '[attachment]';
    db_execute(
        "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
        [$preview, $channelId]
    );
});

// Fire notifications for @mentions
if (!empty($mentionIds)) {
    try {
        require_once dirname(__DIR__, 4) . '/lib/Notifications/NotificationService.php';
        $sender   = current_user();
        $channel  = db_row("SELECT name, type FROM chat_channels WHERE id = ?", [$channelId]);
        $chanName = ($channel['type'] === 'channel') ? '#' . $channel['name'] : $channel['name'];
        $preview  = $message ? mb_substr($message, 0, 100) : '[attachment]';
        foreach ($mentionIds as $mentionedId) {
            if ($mentionedId === $userId) continue;
            \FleetForge\Notifications\NotificationService::notify(
                'chat.mention',
                '@' . $sender['name'] . ' mentioned you in ' . $chanName,
                $preview,
                'chat_channel',
                $channelId,
                '/fleetforge/chat?channel=' . $channelId . '&message=' . $messageId,
                [$mentionedId],
                'info'
            );
        }
    } catch (Throwable $e) {
        error_log('[CHAT] Mention notification failed: ' . $e->getMessage());
    }
}

// Fire DM notification to other participant
$channel = db_row("SELECT type FROM chat_channels WHERE id = ?", [$channelId]);
if (($channel['type'] ?? '') === 'direct') {
    try {
        if (!class_exists('\FleetForge\Notifications\NotificationService')) {
            require_once dirname(__DIR__, 4) . '/lib/Notifications/NotificationService.php';
        }
        $sender  = current_user();
        $preview = $message ? mb_substr($message, 0, 100) : '[attachment]';
        // Get the other DM participant
        $other = db_row(
            "SELECT user_id FROM chat_channel_members WHERE channel_id = ? AND user_id != ?",
            [$channelId, $userId]
        );
        if ($other) {
            \FleetForge\Notifications\NotificationService::notify(
                'chat.direct_message',
                'New message from ' . $sender['name'],
                $preview,
                'chat_channel',
                $channelId,
                '/fleetforge/chat?channel=' . $channelId,
                [$other['user_id']],
                'info'
            );
        }
    } catch (Throwable $e) {
        error_log('[CHAT] DM notification failed: ' . $e->getMessage());
    }
}

// Return full message with user info + attachments
$created = db_row(
    "SELECT cm.*, u.name AS user_name
     FROM chat_messages cm
     JOIN users u ON u.id = cm.user_id
     WHERE cm.id = ?",
    [$messageId]
);
$created['attachments'] = db_select("SELECT * FROM chat_attachments WHERE message_id = ?", [$messageId]);
$created['reactions']   = [];
$created['mentions']    = $created['mentions'] ? json_decode($created['mentions'], true) : [];

json_success($created, 201);
