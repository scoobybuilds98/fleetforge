<?php
/**
 * api/v1/chat/search/messages.php
 *
 * GET ?q=query&channel_id=X(optional)&limit=20
 * Full-text search across chat messages.
 * Returns messages with channel context.
 * Only returns messages from channels the user is a member of.
 *
 * Dependencies: api/bootstrap.php, chat_messages, chat_channels,
 *               chat_channel_members, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$q         = clean_string($_GET['q'] ?? null, 255);
$channelId = clean_int($_GET['channel_id'] ?? null);
$limit     = min(50, max(1, clean_int($_GET['limit'] ?? 20) ?? 20));

if (!$q || mb_strlen($q) < 2) json_error('VALIDATION_ERROR', 'Query must be at least 2 characters.', 422);

$sq = '%' . $q . '%';

$params = [$userId, $sq];
$channelFilter = '';
if ($channelId) {
    $channelFilter = 'AND cm.channel_id = ?';
    $params[]      = $channelId;
}
$params[] = $limit;

$results = db_select(
    "SELECT cm.id, cm.channel_id, cm.message, cm.created_at, cm.is_deleted,
            COALESCE(u.name, cm.sender_display_name, 'Unknown') AS user_name,
            cc.name AS channel_name, cc.type AS channel_type
     FROM chat_messages cm
     JOIN chat_channels cc ON cc.id = cm.channel_id AND cc.is_archived = 0
     JOIN chat_channel_members ccm ON ccm.channel_id = cm.channel_id AND ccm.user_id = ?
     LEFT JOIN users u ON u.id = cm.user_id
     WHERE cm.is_deleted = 0
       AND cm.message LIKE ?
       {$channelFilter}
     ORDER BY cm.created_at DESC
     LIMIT ?",
    $params
);

// Highlight matching text in message snippets
foreach ($results as &$r) {
    if ($r['message']) {
        $pos = mb_stripos($r['message'], $q);
        if ($pos !== false) {
            $start   = max(0, $pos - 40);
            $snippet = ($start > 0 ? '…' : '') . mb_substr($r['message'], $start, 100);
            $snippet .= (mb_strlen($r['message']) > $start + 100 ? '…' : '');
            $r['snippet'] = $snippet;
        } else {
            $r['snippet'] = mb_substr($r['message'], 0, 100);
        }
    } else {
        $r['snippet'] = '';
    }
}
unset($r);

json_success(['results' => $results, 'query' => $q, 'count' => count($results)]);
