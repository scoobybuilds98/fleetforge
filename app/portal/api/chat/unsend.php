<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/unsend.php
 *
 * POST { message_id } — remove my own message.
 *
 * @session S-CHAT-REBUILD
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Chat\Conversations;

if ($method !== 'POST') portal_chat_err('METHOD_NOT_ALLOWED', 'POST only.', 405);

$id = (int) (portal_chat_input()['message_id'] ?? 0);
if ($id <= 0 || !Conversations::unsend($id, $portalViewer)) {
    portal_chat_err('NOT_FOUND', 'You can only unsend your own messages.', 404);
}
portal_chat_ok(['unsent' => $id]);
