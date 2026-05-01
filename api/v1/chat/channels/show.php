<?php
/**
 * api/v1/chat/channels/show.php
 *
 * GET ?channel_id=X
 * Returns channel details + member list.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId    = current_user_id();
$channelId = clean_int($_GET['channel_id'] ?? null);

if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

// Verify membership
if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
    json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
}

$channel = db_row(
    "SELECT cc.*, ccm.role,
            (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = cc.id) AS member_count
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     WHERE cc.id = ?",
    [$userId, $channelId]
);
if (!$channel) json_error('NOT_FOUND', 'Channel not found.', 404);

$members = db_select(
    "SELECT ccm.id, ccm.user_id, ccm.portal_user_id, ccm.role, ccm.last_read_at,
            u.name, u.email
     FROM chat_channel_members ccm
     LEFT JOIN users u ON u.id = ccm.user_id
     WHERE ccm.channel_id = ?
     ORDER BY FIELD(ccm.role,'owner','admin','member'), u.name ASC",
    [$channelId]
);

// Customer info if customer channel
$customerInfo = null;
if ($channel['type'] === 'customer' && $channel['customer_id']) {
    $customerInfo = db_row(
        "SELECT c.id, c.company_name, c.contact_name, c.email, c.phone
         FROM customers c WHERE c.id = ? AND c.deleted_at IS NULL",
        [$channel['customer_id']]
    );
}

json_success([
    'channel'       => $channel,
    'members'       => $members,
    'customer_info' => $customerInfo,
]);
