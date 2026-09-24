<?php
/**
 * api/v1/chat/delete.php
 *
 * POST { conversation_id } — "Delete chat" (direct / customer) or "Leave
 * group", for the signed-in user ONLY. Nobody else's copy changes: the
 * teammate, the customer and colleagues sharing a customer thread keep every
 * message. A deleted chat comes back when someone writes again, holding only
 * the new messages. The last member to leave a group deletes it.
 *
 * Response: { result: 'deleted' | 'left' | 'removed' }
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php
 * @session S-CHAT-DELETE
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('POST');
require_auth_api();

use FleetForge\Chat\Conversations;

$viewer = Conversations::staffViewer();
$id     = clean_int($_POST['conversation_id'] ?? null) ?? 0;

$cv = $id ? Conversations::find($id, $viewer) : null;
if (!$cv) json_error('NOT_FOUND', 'Conversation not found.', 404);

json_success(['result' => Conversations::deleteForViewer($cv, $viewer)]);
