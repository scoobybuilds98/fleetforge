<?php
/**
 * api/v1/chat/search.php
 *
 * GET ?q={query}&channel_id=X (optional)
 * Searches messages by content using LIKE. Returns up to 50 matches
 * with channel context. Only searches channels the user is a member of.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channels,
 *               chat_channel_members, users
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$query     = clean_string($_GET['q'] ?? null, 200);
$channelId = clean_int($_GET['channel_id'] ?? null);

if (!$query || mb_strlen($query) < 2) {
    json_error('VALIDATION_ERROR', 'Search query must be at least 2 characters.', 422);
}

// Build where clause — search only in channels user is a member of
$where  = ['cm.is_archived = 0', 'ccm.user_id = ?'];
$params = [$userId];

if ($channelId) {
    $where[]  = 'cm.channel_id = ?';
    $params[] = $channelId;
}

// Safe LIKE search — strip SQL wildcards from user input
$safeQuery = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $query) . '%';
$where[]  = 'cm.message LIKE ?';
$params[] = $safeQuery;

$whereSQL = implode(' AND ', $where);

$results = db_select(
    "SELECT cm.id, cm.channel_id, cm.message, cm.created_at,
            cm.user_id, u.name AS user_name,
            cc.name AS channel_name, cc.type AS channel_type
     FROM chat_messages cm
     JOIN users u ON u.id = cm.user_id
     JOIN chat_channels cc ON cc.id = cm.channel_id
     JOIN chat_channel_members ccm ON ccm.channel_id = cm.channel_id AND ccm.user_id = ?
     WHERE $whereSQL
     ORDER BY cm.created_at DESC
     LIMIT 50",
    array_merge([$userId], $params)
);

json_success(['results' => $results, 'total' => count($results), 'query' => $query]);
