<?php
/**
 * api/v1/chat/messages/update.php
 *
 * POST — Edit a chat message (author only).
 * Sets is_edited=1, edited_at=NOW(). Archived messages cannot be edited.
 *
 * Dependencies: api/bootstrap.php, chat_messages
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$messageId = clean_int($_POST['message_id'] ?? null);
$newText   = clean_string($_POST['message'] ?? null, 5000);

if (!$messageId) json_error('MISSING_REQUIRED', 'message_id is required.', 422);
if (!$newText)   json_error('VALIDATION_ERROR', 'message cannot be empty.', 422);

$msg = db_row("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
if (!$msg) json_error('NOT_FOUND', 'Message not found.', 404);
if ($msg['is_archived']) json_error('VALIDATION_ERROR', 'Cannot edit a deleted message.', 422);
if ((int)$msg['user_id'] !== $userId) json_error('FORBIDDEN', 'You can only edit your own messages.', 403);

db_execute(
    "UPDATE chat_messages SET message = ?, is_edited = 1, edited_at = NOW() WHERE id = ?",
    [$newText, $messageId]
);

$updated = db_row(
    "SELECT cm.*, u.name AS user_name FROM chat_messages cm JOIN users u ON u.id = cm.user_id WHERE cm.id = ?",
    [$messageId]
);
$updated['mentions'] = $updated['mentions'] ? json_decode($updated['mentions'], true) : [];

json_success($updated);
