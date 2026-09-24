<?php
/**
 * api/v1/chat/unsend.php
 *
 * POST { message_id } — remove my own message (text + records). A small
 * "Message unsent" placeholder stays so the thread still reads in order.
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('POST');
require_auth_api();

use FleetForge\Chat\Conversations;

$id = clean_int($_POST['message_id'] ?? null) ?? 0;
if (!$id || !Conversations::unsend($id, Conversations::staffViewer())) {
    json_error('NOT_FOUND', 'You can only unsend your own messages.', 404);
}
json_success(['unsent' => $id]);
