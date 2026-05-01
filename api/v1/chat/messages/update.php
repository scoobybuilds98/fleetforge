<?php
/**
 * api/v1/chat/messages/update.php
 *
 * POST {message_id, message}
 * Edit a chat message. Author only. Cannot edit deleted messages.
 * Sets is_edited=1, edited_at=NOW().
 *
 * Dependencies: api/bootstrap.php, chat_messages
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$messageId = clean_int($_POST['message_id'] ?? null);
$newText   = clean_string($_POST['message'] ?? null, 10000);

if (!$messageId) json_error('MISSING_REQUIRED', 'message_id is required.', 422);
if (!$newText)   json_error('VALIDATION_ERROR', 'message cannot be empty.', 422);

$msg = db_row("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
if (!$msg)               json_error('NOT_FOUND', 'Message not found.', 404);
if ($msg['is_deleted'])  json_error('VALIDATION_ERROR', 'Cannot edit a deleted message.', 422);
if ((int)$msg['user_id'] !== $userId) json_error('FORBIDDEN', 'You can only edit your own messages.', 403);

db_execute(
    "UPDATE chat_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?",
    [$newText, $messageId]
);

$updated = db_row(
    "SELECT cm.*, COALESCE(u.name, cm.sender_display_name) AS user_name
     FROM chat_messages cm LEFT JOIN users u ON u.id = cm.user_id WHERE cm.id = ?",
    [$messageId]
);
$updated['mentions']    = $updated['mentions'] ? json_decode($updated['mentions'], true) : [];
$updated['attachments'] = db_select(
    "SELECT id, message_id, attachment_type, entity_id, file_name, file_size,
            mime_type, preview_title, preview_subtitle, preview_badge,
            preview_badge_class, preview_url
     FROM chat_attachments WHERE message_id = ?",
    [$messageId]
);
$updated['display_text'] = $updated['message'];

json_success($updated);
