<?php
/**
 * api/v1/chat/messages/delete.php
 *
 * POST — Soft-deletes a chat message (author only, or channel admin).
 * Sets is_archived=1. Message shows as "[message deleted]" to others.
 * Messages are never hard-deleted per spec.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channel_members
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId    = current_user_id();
$messageId = clean_int($_POST['message_id'] ?? null);

if (!$messageId) json_error('MISSING_REQUIRED', 'message_id is required.', 422);

$msg = db_row("SELECT * FROM chat_messages WHERE id = ?", [$messageId]);
if (!$msg) json_error('NOT_FOUND', 'Message not found.', 404);

// Author OR channel admin can delete
$isAuthor = ((int)$msg['user_id'] === $userId);
$isAdmin  = db_exists('chat_channel_members', 'channel_id = ? AND user_id = ? AND role = ?', [$msg['channel_id'], $userId, 'admin']);
if (!$isAuthor && !$isAdmin) {
    json_error('FORBIDDEN', 'You cannot delete this message.', 403);
}

db_execute("UPDATE chat_messages SET is_archived = 1 WHERE id = ?", [$messageId]);

json_success(['message' => 'Message deleted.', 'id' => $messageId]);
