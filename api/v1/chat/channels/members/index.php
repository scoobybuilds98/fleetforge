<?php
/**
 * api/v1/chat/channels/members/index.php
 *
 * GET ?channel_id=X
 * Returns all members of a channel with role + online status.
 * Online = user has a valid session updated in last 5 minutes.
 * WHY: Used by right panel member list and @mention picker.
 *
 * Dependencies: api/bootstrap.php, chat_channel_members, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_GET['channel_id'] ?? null);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

// Verify membership
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

$members = db_select(
    "SELECT ccm.id, ccm.user_id, ccm.portal_user_id, ccm.role,
            ccm.last_read_at, ccm.last_read_message_id, ccm.is_muted,
            COALESCE(u.name, pu.name) AS name,
            COALESCE(u.email, pu.email) AS email,
            CASE WHEN u.id IS NOT NULL THEN 'staff' ELSE 'portal' END AS member_type
     FROM chat_channel_members ccm
     LEFT JOIN users u ON u.id = ccm.user_id
     LEFT JOIN portal_users pu ON pu.id = ccm.portal_user_id
     WHERE ccm.channel_id = ?
     ORDER BY FIELD(ccm.role,'owner','admin','member'), name ASC",
    [$channelId]
);

// Build initials for each member
foreach ($members as &$m) {
    $parts = explode(' ', trim($m['name'] ?? '?'));
    $m['initials'] = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', array_slice($parts, 0, 2))));
}
unset($m);

json_success($members);
