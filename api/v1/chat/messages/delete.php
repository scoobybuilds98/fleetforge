<?php
/**
 * api/v1/chat/messages/delete.php
 *
 * POST {message_id}
 * Soft-deletes a chat message. Author or channel owner/admin can delete.
 * Sets is_deleted=1, deleted_at=NOW(), message=NULL.
 * WHY: Messages never hard-deleted — history is sacred per spec.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$messageId = clean_int($_POST['message_id'] ?? null);

if (!$messageId) json_error('MISSING_REQUIRED', 'message_id is required.', 422);

$msg = db_row("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
if (!$msg)              json_error('NOT_FOUND', 'Message not found.', 404);
if ($msg['is_deleted']) json_error('VALIDATION_ERROR', 'Message already deleted.', 422);

$isAuthor = ((int)$msg['user_id'] === $userId);
$isAdmin  = db_exists(
    'chat_channel_members',
    "channel_id = ? AND user_id = ? AND role IN ('owner','admin')",
    [$msg['channel_id'], $userId]
);

if (!$isAuthor && !$isAdmin) {
    json_error('FORBIDDEN', 'You cannot delete this message.', 403);
}

db_execute(
    "UPDATE chat_messages SET is_deleted = 1, deleted_at = NOW(), message = NULL WHERE id = ?",
    [$messageId]
);

json_success(['message' => 'Message deleted.', 'id' => $messageId]);
