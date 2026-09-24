<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/thread.php
 *
 * GET               — the customer's thread: latest page + attach types (marks read)
 * GET ?after=M      — messages newer than M (poll; marks read)
 * GET ?before=M     — the page before M ("load earlier")
 *
 * Response: { conversation_id|null, messages[], has_more, attach_types[] }
 * No thread exists until someone writes — the page shows an empty state and
 * send.php creates it on the first message.
 *
 * @session S-CHAT-REBUILD
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Chat\Conversations;
use FleetForge\Chat\RecordRefs;

if ($method !== 'GET') portal_chat_err('METHOD_NOT_ALLOWED', 'GET only.', 405);
session_write_close();   // polled — don't hold the session lock

$after  = max(0, (int) ($_GET['after'] ?? 0));
$before = max(0, (int) ($_GET['before'] ?? 0));
$types  = array_map(fn($t) => ['type' => $t, 'label' => RecordRefs::TYPES[$t][0]], RecordRefs::attachableTypes($portalViewer, null));

$cv = Conversations::portalThread($portalViewer);
if (!$cv) {
    portal_chat_ok(['conversation_id' => null, 'messages' => [], 'has_more' => false, 'attach_types' => $types]);
}

$page = Conversations::messages($cv, $portalViewer, $after, $before);
if ($before === 0 && $page['messages']) {
    Conversations::markRead($cv, $portalViewer, (int) end($page['messages'])['id']);
}
portal_chat_ok($page + [
    'conversation_id' => (int) $cv['id'],
    'attach_types'    => $types,
    'receipt'         => $before === 0 ? Conversations::receipt($cv, $portalViewer) : null,   // "Seen" once the team has read it
]);
