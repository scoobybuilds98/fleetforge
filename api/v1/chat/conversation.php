<?php
/**
 * api/v1/chat/conversation.php
 *
 * GET ?id=N                 — header + latest page of messages (marks read)
 * GET ?id=N&after=M         — only messages newer than M (poll; marks read)
 * GET ?id=N&before=M        — the page before M ("load earlier")
 *
 * Response: { conversation, messages[], has_more, attach_types[], receipt }
 * receipt = "Seen"/"Sent" for the newest message when it's on my side
 * (Conversations::receipt); refreshed on every poll so it flips to Seen live.
 * Records on each message are resolved live for THIS user (money redacted
 * without payments.view; "No longer available" when deleted / not visible).
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php, lib/Chat/RecordRefs.php
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('GET');
require_auth_api();

use FleetForge\Chat\Conversations;
use FleetForge\Chat\RecordRefs;

$viewer = Conversations::staffViewer();
session_write_close();

$id     = clean_int($_GET['id'] ?? null) ?? 0;
$after  = clean_int($_GET['after'] ?? null) ?? 0;
$before = clean_int($_GET['before'] ?? null) ?? 0;

$cv = $id ? Conversations::find($id, $viewer) : null;
if (!$cv) json_error('NOT_FOUND', 'Conversation not found.', 404);

$page = Conversations::messages($cv, $viewer, $after, $before);
if ($before === 0 && $page['messages']) {
    Conversations::markRead($cv, $viewer, (int) end($page['messages'])['id']);
}

$out = $page;
if ($before === 0) {
    $out['receipt'] = Conversations::receipt($cv, $viewer);   // "Seen" / "Sent" under the newest message
}
if ($after === 0 && $before === 0) {
    $out['conversation'] = Conversations::header($cv, $viewer);
    $types = RecordRefs::attachableTypes($viewer, $cv['kind'] === 'customer' ? (int) $cv['customer_id'] : null);
    $out['attach_types'] = array_map(fn($t) => ['type' => $t, 'label' => RecordRefs::TYPES[$t][0]], $types);
}
json_success($out);
