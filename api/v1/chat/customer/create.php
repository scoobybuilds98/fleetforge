<?php
/**
 * api/v1/chat/customer/create.php
 *
 * POST {customer_id, subject, message(optional)}
 * Create new conversation thread with a customer.
 * Adds all staff with customer view permission.
 * Posts system message. Optionally sends initial message.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               chat_messages, customers, users
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('POST');
require_auth_api();

$userId     = current_user_id();
$user       = current_user();
$customerId = clean_int($_POST['customer_id'] ?? null);
$subject    = clean_string($_POST['subject'] ?? null, 255);
$initMsg    = clean_string($_POST['message'] ?? null, 5000);

if (!$customerId) json_error('MISSING_REQUIRED', 'customer_id is required.', 422);
if (!$subject)    json_error('MISSING_REQUIRED', 'subject is required.', 422);

$customer = db_row("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL", [$customerId]);
if (!$customer) json_error('NOT_FOUND', 'Customer not found.', 404);

// Generate unique slug for this conversation
$baseSlug = 'customer_' . $customerId . '_' . date('ymd');
$slug     = $baseSlug;
$suf      = 1;
while (db_exists('chat_channels', 'slug = ?', [$slug])) {
    $slug = $baseSlug . '_' . $suf++;
}

$channelId = null;
db_transaction(function() use ($userId, $user, $customerId, $customer, $subject, $initMsg, $slug, &$channelId) {
    $channelId = db_insert('chat_channels', [
        'name'        => $customer['company_name'],
        'slug'        => $slug,
        'description' => $subject,
        'type'        => 'customer',
        'created_by'  => $userId,
        'customer_id' => $customerId,
    ]);

    // Add current user as owner
    db_insert('chat_channel_members', [
        'channel_id' => $channelId,
        'user_id'    => $userId,
        'role'       => 'owner',
    ]);

    // Add all other active staff members as members
    $staffUsers = db_select(
        "SELECT id FROM users WHERE deleted_at IS NULL AND status = 'active' AND id != ?",
        [$userId]
    );
    foreach ($staffUsers as $su) {
        db_execute(
            "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')",
            [$channelId, $su['id']]
        );
    }

    // System message
    db_insert('chat_messages', [
        'channel_id'          => $channelId,
        'user_id'             => $userId,
        'sender_display_name' => $user['name'] ?? 'System',
        'message'             => "Conversation started: {$subject}",
        'type'                => 'system',
    ]);

    // Initial message if provided
    if ($initMsg) {
        $msgId = db_insert('chat_messages', [
            'channel_id'          => $channelId,
            'user_id'             => $userId,
            'sender_display_name' => 'Mainland Truck & Trailer',
            'message'             => $initMsg,
            'type'                => 'text',
        ]);
        db_execute(
            "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
            [mb_substr($initMsg, 0, 100), $channelId]
        );
    } else {
        db_execute(
            "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
            ["Conversation started: {$subject}", $channelId]
        );
    }
});

$channel = db_row(
    "SELECT cc.*, ccm.role, 0 AS unread_count,
            c.company_name AS customer_company_name
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     LEFT JOIN customers c ON c.id = cc.customer_id
     WHERE cc.id = ?",
    [$userId, $channelId]
);

// Audit-log the new conversation. action='create' is the valid enum value
// ('created' is NOT in audit_log.action) and the free-text column is `notes`,
// NOT `description` (no such column). Wrapped in try/catch so an audit-log
// failure can never 500 a request whose primary work (the channel, members and
// messages) already committed in the transaction above.
try {
    db_insert('audit_log', [
        'user_id'     => $userId,
        'user_name'   => $user['name'] ?? 'system',
        'action'      => 'create',
        'module'      => 'chat',
        'entity_type' => 'chat_channel',
        'entity_id'   => $channelId,
        'notes'       => 'Started customer conversation: ' . $subject . ' with ' . $customer['company_name'],
        'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
} catch (\Throwable $e) {
    error_log('chat/customer/create.php audit_log insert failed: ' . $e->getMessage());
}

json_success($channel, 201);
