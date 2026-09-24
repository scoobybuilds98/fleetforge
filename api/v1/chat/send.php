<?php
/**
 * api/v1/chat/send.php
 *
 * POST { conversation_id, body, records: [{type, id}, ...] }
 * → { message } (the new message, shaped like conversation.php returns it)
 *
 * Records are validated server-side for this user AND this conversation
 * (customer threads accept only that customer's lease / invoice / payment).
 * Nothing about a record is taken from the client except its type + id.
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('POST');
require_auth_api();

use FleetForge\Chat\Conversations;

$viewer = Conversations::staffViewer();
$id     = clean_int($_POST['conversation_id'] ?? null) ?? 0;
$body   = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
$refs   = is_array($_POST['records'] ?? null) ? $_POST['records'] : [];

$cv = $id ? Conversations::find($id, $viewer) : null;
if (!$cv) json_error('NOT_FOUND', 'Conversation not found.', 404);

try {
    $msgId = Conversations::send($cv, $viewer, $body, $refs);
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
}

$page = Conversations::messages($cv, $viewer, $msgId - 1);
json_success([
    'message' => $page['messages'][0] ?? null,
    'receipt' => Conversations::receipt($cv, $viewer),
], 201);
