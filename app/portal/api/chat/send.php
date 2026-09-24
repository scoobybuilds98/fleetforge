<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/send.php
 *
 * POST { body, records: [{type, id}] } — text the team. Creates the
 * customer's thread on first use. Records must be this customer's own
 * lease / invoice / payment (validated server-side).
 *
 * @session S-CHAT-REBUILD
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Chat\Conversations;

if ($method !== 'POST') portal_chat_err('METHOD_NOT_ALLOWED', 'POST only.', 405);

$in   = portal_chat_input();
$body = is_string($in['body'] ?? null) ? $in['body'] : '';
$refs = is_array($in['records'] ?? null) ? $in['records'] : [];

$cv = Conversations::portalThread($portalViewer)
    ?? Conversations::find(Conversations::openCustomer((int) $portalCustomerId, null), $portalViewer);

try {
    $msgId = Conversations::send($cv, $portalViewer, $body, $refs);
} catch (\InvalidArgumentException $e) {
    portal_chat_err('VALIDATION_ERROR', $e->getMessage(), 422);
}

$page = Conversations::messages($cv, $portalViewer, $msgId - 1);
portal_chat_ok(['conversation_id' => (int) $cv['id'], 'message' => $page['messages'][0] ?? null], 201);
