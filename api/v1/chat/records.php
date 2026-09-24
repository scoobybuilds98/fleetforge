<?php
/**
 * api/v1/chat/records.php
 *
 * GET ?conversation_id=N&type=invoice&q=INV-12 — records to attach.
 * Only types this user can view; in a customer thread only that customer's
 * lease / invoice / payment. Empty q = most recent.
 *
 * GET ?type=invoice&id=42 — one card (the "Send in chat" deep link from a
 * record page pre-fills the composer with it before a conversation is
 * picked; send.php re-validates it against the chosen conversation).
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

$id   = clean_int($_GET['conversation_id'] ?? null) ?? 0;
$one  = clean_int($_GET['id'] ?? null) ?? 0;
$type = clean_string($_GET['type'] ?? null, 30) ?? '';
$q    = clean_string($_GET['q'] ?? null, 100) ?? '';

if ($one > 0) {
    $card = RecordRefs::resolve([['type' => $type, 'id' => $one]], $viewer)["{$type}:{$one}"] ?? null;
    if (!$card || !$card['available']) json_error('NOT_FOUND', 'Record not found.', 404);
    json_success(['record' => $card]);
}

$cv = $id ? Conversations::find($id, $viewer) : null;
if (!$cv) json_error('NOT_FOUND', 'Conversation not found.', 404);

$scope = $cv['kind'] === 'customer' ? (int) $cv['customer_id'] : null;
json_success(['results' => RecordRefs::search($type, $q, $viewer, $scope)]);
