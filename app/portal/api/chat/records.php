<?php
declare(strict_types=1);

/**
 * app/portal/api/chat/records.php
 *
 * GET ?type=invoice&q=… — this customer's own leases / invoices / payments
 * to attach (portal visibility rules: no void or internal-draft invoices).
 * GET ?type=invoice&id=42 — one card ("Message us about this invoice" link).
 *
 * @session S-CHAT-REBUILD
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Chat\RecordRefs;

if ($method !== 'GET') portal_chat_err('METHOD_NOT_ALLOWED', 'GET only.', 405);
session_write_close();

$type = substr(trim((string) ($_GET['type'] ?? '')), 0, 30);
$q    = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$one  = max(0, (int) ($_GET['id'] ?? 0));

if ($one > 0) {
    $card = in_array($type, RecordRefs::attachableTypes($portalViewer, null), true)
        ? (RecordRefs::resolve([['type' => $type, 'id' => $one]], $portalViewer)["{$type}:{$one}"] ?? null)
        : null;
    if (!$card || !$card['available']) portal_chat_err('NOT_FOUND', 'Record not found.', 404);
    portal_chat_ok(['record' => $card]);
}
portal_chat_ok(['results' => RecordRefs::search($type, $q, $portalViewer, null)]);
