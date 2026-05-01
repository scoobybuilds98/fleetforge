<?php
/**
 * api/v1/chat/messages/create.php
 *
 * POST {channel_id, message, reply_to_id?, attachments[], mentions[]}
 * Creates a new chat message with optional record attachments.
 * Updates channel last_message_at + preview.
 * Fires notifications to @mentioned users and DM recipients.
 *
 * Attachment format for record attachments:
 *   attachments[0][type] = 'invoice'
 *   attachments[0][entity_id] = 42
 *   attachments[0][preview_title] = 'INV-2026-00042'
 *   attachments[0][preview_subtitle] = 'ABC Trucking · $1,234.00'
 *   attachments[0][preview_badge] = 'sent'
 *   attachments[0][preview_badge_class] = 'badge-info'
 *   attachments[0][preview_url] = '/fleetforge/invoices/show?id=42'
 *
 * WHY: attachment data comes from client after /search/records.php lookup,
 *      so we trust and store it directly rather than re-fetching.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members,
 *               chat_attachments, lib/Notifications/NotificationService.php
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId     = current_user_id();
$user       = current_user();
$channelId  = clean_int($_POST['channel_id'] ?? null);
$message    = clean_string($_POST['message'] ?? null, 10000);
$replyToId  = clean_int($_POST['reply_to_id'] ?? null);
$mentionIds = array_filter(array_map('intval', (array)($_POST['mentions'] ?? [])));
$attachData = (array)($_POST['attachments'] ?? []);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);
if (!$message && empty($attachData)) {
    json_error('VALIDATION_ERROR', 'Message or attachment is required.', 422);
}

// Verify channel membership
$channel = db_row("SELECT * FROM chat_channels WHERE id = ? AND is_archived = 0", [$channelId]);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

// Validate reply_to exists in same channel
if ($replyToId) {
    if (!db_exists('chat_messages', 'id = ? AND channel_id = ? AND is_deleted = 0', [$replyToId, $channelId])) {
        $replyToId = null;
    }
}

// sender_display_name: for customer channels show company name
$senderDisplayName = match($channel['type']) {
    'customer' => 'Mainland Truck & Trailer',
    default    => $user['name'] ?? 'Unknown',
};

$type = (!$message && !empty($attachData)) ? 'attachment' : 'text';

// Build mentions JSON
$mentionsJson = null;
if (!empty($mentionIds)) {
    $mentionList = [];
    foreach ($mentionIds as $mid) {
        $mu = db_row("SELECT id, name FROM users WHERE id = ?", [$mid]);
        if ($mu) $mentionList[] = ['type' => 'user', 'id' => $mu['id'], 'name' => $mu['name']];
    }
    $mentionsJson = empty($mentionList) ? null : json_encode($mentionList);
}

$messageId = null;
db_transaction(function() use (
    $channelId, $userId, $senderDisplayName, $message, $type, $replyToId,
    $mentionsJson, $attachData, &$messageId
) {
    $messageId = db_insert('chat_messages', [
        'channel_id'          => $channelId,
        'user_id'             => $userId,
        'sender_display_name' => $senderDisplayName,
        'message'             => $message,
        'type'                => $type,
        'reply_to_id'         => $replyToId,
        'mentions'            => $mentionsJson,
    ]);

    // Save record attachments
    foreach ($attachData as $att) {
        if (!is_array($att)) continue;
        $attType = clean_string($att['type'] ?? null, 50);
        if (!$attType) continue;

        // Validate attachment_type enum value
        $validTypes = ['invoice','lease','customer','payment','work_order','damage_claim',
                       'document','reservation','equipment','file','image'];
        if (!in_array($attType, $validTypes, true)) continue;

        db_insert('chat_attachments', [
            'message_id'         => $messageId,
            'attachment_type'    => $attType,
            'entity_id'          => clean_int($att['entity_id'] ?? null),
            'file_name'          => clean_string($att['file_name'] ?? null, 255),
            'file_size'          => clean_int($att['file_size'] ?? null),
            'mime_type'          => clean_string($att['mime_type'] ?? null, 100),
            'preview_title'      => clean_string($att['preview_title'] ?? null, 255),
            'preview_subtitle'   => clean_string($att['preview_subtitle'] ?? null, 255),
            'preview_badge'      => clean_string($att['preview_badge'] ?? null, 100),
            'preview_badge_class'=> clean_string($att['preview_badge_class'] ?? null, 50),
            'preview_url'        => clean_string($att['preview_url'] ?? null, 500),
        ]);
    }

    // Update channel last_message
    $preview = $message ? mb_substr($message, 0, 100) : '[attachment]';
    db_execute(
        "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
        [$preview, $channelId]
    );
});

// Fire notifications (non-critical — wrapped in try/catch)
try {
    require_once dirname(__DIR__, 4) . '/lib/Notifications/NotificationService.php';
    $chanName = ($channel['type'] === 'channel') ? '#' . $channel['name'] : $channel['name'];
    $preview  = $message ? mb_substr($message, 0, 100) : '[attachment]';

    // @mention notifications
    foreach ($mentionIds as $mentionedId) {
        if ($mentionedId === $userId) continue;
        \FleetForge\Notifications\NotificationService::notify(
            'chat.mention',
            '@' . ($user['name'] ?? '') . ' mentioned you in ' . $chanName,
            $preview,
            'chat_channel',
            $channelId,
            '/fleetforge/chat?channel=' . $channelId . '&message=' . $messageId,
            [$mentionedId],
            'info'
        );
    }

    // DM notification to other participant
    if ($channel['type'] === 'direct') {
        $other = db_row(
            "SELECT user_id FROM chat_channel_members WHERE channel_id = ? AND user_id != ?",
            [$channelId, $userId]
        );
        if ($other && $other['user_id']) {
            \FleetForge\Notifications\NotificationService::notify(
                'chat.direct_message',
                'New message from ' . ($user['name'] ?? 'Someone'),
                $preview,
                'chat_channel',
                $channelId,
                '/fleetforge/chat?channel=' . $channelId,
                [$other['user_id']],
                'info'
            );
        }
    }
} catch (Throwable $e) {
    error_log('[CHAT] Notification failed: ' . $e->getMessage());
}

// Return full message
$created = db_row(
    "SELECT cm.*, COALESCE(u.name, cm.sender_display_name, 'Unknown') AS user_name
     FROM chat_messages cm
     LEFT JOIN users u ON u.id = cm.user_id
     WHERE cm.id = ?",
    [$messageId]
);

$parts = explode(' ', trim($created['user_name'] ?? '?'));
$created['initials']    = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($parts, 0, 2))));
$created['attachments'] = db_select(
    "SELECT id, message_id, attachment_type, entity_id, file_name, file_size,
            mime_type, preview_title, preview_subtitle, preview_badge,
            preview_badge_class, preview_url
     FROM chat_attachments WHERE message_id = ?",
    [$messageId]
);
$created['reactions']   = [];
$created['mentions']    = $created['mentions'] ? json_decode($created['mentions'], true) : [];
$created['display_text'] = $created['message'];

json_success($created, 201);
