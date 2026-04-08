<?php
/**
 * api/v1/chat/channels/members.php
 *
 * GET ?channel_id=X  — List members of a channel with user info.
 * POST               — Add a user to a channel (admin only).
 * DELETE             — Remove a user from a channel (admin only).
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members, users
 * Spec: CHAT-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_auth_api();

$userId = current_user_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $channelId = clean_int($_GET['channel_id'] ?? null);
    if (!$channelId) json_error('MISSING_REQUIRED', 'channel_id is required.', 422);

    // Verify requester is a member
    if (!db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $userId])) {
        json_error('FORBIDDEN', 'You are not a member of this channel.', 403);
    }

    $members = db_select(
        "SELECT u.id, u.name, u.email, ccm.role, ccm.joined_at, ccm.is_muted
         FROM chat_channel_members ccm
         JOIN users u ON u.id = ccm.user_id
         WHERE ccm.channel_id = ? AND u.deleted_at IS NULL
         ORDER BY ccm.role DESC, u.name ASC",
        [$channelId]
    );

    json_success($members);

} elseif ($method === 'POST') {
    $channelId    = clean_int($_POST['channel_id'] ?? null);
    $targetUserId = clean_int($_POST['user_id'] ?? null);
    if (!$channelId || !$targetUserId) json_error('MISSING_REQUIRED', 'channel_id and user_id are required.', 422);

    // Must be channel admin
    $myMembership = db_row("SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $userId]);
    if (!$myMembership || $myMembership['role'] !== 'admin') {
        json_error('FORBIDDEN', 'Only channel admins can add members.', 403);
    }

    db_transaction(function() use ($channelId, $targetUserId) {
        $alreadyMember = db_exists('chat_channel_members', 'channel_id = ? AND user_id = ?', [$channelId, $targetUserId]);
        if (!$alreadyMember) {
            db_execute(
                "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')",
                [$channelId, $targetUserId]
            );
            db_execute("UPDATE chat_channels SET member_count = member_count + 1 WHERE id = ?", [$channelId]);
        }
    });

    json_success(['message' => 'Member added.']);

} elseif ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $channelId    = clean_int($input['channel_id'] ?? null);
    $targetUserId = clean_int($input['user_id'] ?? null);
    if (!$channelId || !$targetUserId) json_error('MISSING_REQUIRED', 'channel_id and user_id are required.', 422);

    $myMembership = db_row("SELECT role FROM chat_channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $userId]);
    if (!$myMembership || $myMembership['role'] !== 'admin') {
        json_error('FORBIDDEN', 'Only channel admins can remove members.', 403);
    }

    db_transaction(function() use ($channelId, $targetUserId) {
        $affected = db_execute("DELETE FROM chat_channel_members WHERE channel_id = ? AND user_id = ?", [$channelId, $targetUserId]);
        if ($affected > 0) {
            db_execute("UPDATE chat_channels SET member_count = GREATEST(0, member_count - 1) WHERE id = ?", [$channelId]);
        }
    });

    json_success(['message' => 'Member removed.']);

} else {
    json_error('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
}
