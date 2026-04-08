<?php
/**
 * database/seeds/run_chat_seed.php
 *
 * CHAT-1 seed runner — creates 6 default channels, adds all active users
 * as members, and posts 10 test messages to #general (3 with @mentions,
 * 5 with record attachments, 1 DM between two users).
 *
 * Run: php database/seeds/run_chat_seed.php
 *
 * Required by: CHAT-1 session
 * Dependencies: config/app.php, includes/db.php
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';

$pdo = db_pdo();

// ── Get all active users ─────────────────────────────────────────────────────
$users = db_select("SELECT id, name FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY id", []);
if (empty($users)) {
    echo "No active users found. Run user seeds first.\n";
    exit(1);
}
$adminUser = $users[0]; // first user = admin

// ── Channel definitions ──────────────────────────────────────────────────────
$channels = [
    ['name' => 'general',        'slug' => 'general',    'description' => 'General team discussion',                   'type' => 'channel', 'is_private' => 0, 'perm' => null],
    ['name' => 'leases',         'slug' => 'leases',     'description' => 'Lease operations and updates',              'type' => 'channel', 'is_private' => 0, 'perm' => 'leases'],
    ['name' => 'maintenance',    'slug' => 'maintenance','description' => 'Fleet maintenance and work orders',          'type' => 'channel', 'is_private' => 0, 'perm' => 'maintenance'],
    ['name' => 'accounting',     'slug' => 'accounting', 'description' => 'Accounting and billing discussion',          'type' => 'channel', 'is_private' => 0, 'perm' => 'invoices'],
    ['name' => 'compliance',     'slug' => 'compliance', 'description' => 'Compliance alerts and document tracking',    'type' => 'channel', 'is_private' => 0, 'perm' => 'compliance'],
    ['name' => 'general-alerts', 'slug' => 'alerts',     'description' => 'System notifications and alerts',            'type' => 'channel', 'is_private' => 0, 'perm' => null],
];

// ── Permission check helper (simplified — just add all active users for seed) ─
// In production the API uses real permission checks. For seed we add everyone.
function getUsersForChannel(?string $perm, array $allUsers): array {
    // For seed purposes, add all active users to all channels.
    return $allUsers;
}

$channelIds = [];
foreach ($channels as $ch) {
    // Check if already exists
    $existing = db_row("SELECT id FROM chat_channels WHERE slug = ?", [$ch['slug']]);
    if ($existing) {
        $channelIds[$ch['slug']] = (int)$existing['id'];
        echo "Channel #{$ch['slug']} already exists (id={$existing['id']}), skipping.\n";
        continue;
    }

    $members = getUsersForChannel($ch['perm'], $users);
    $channelId = db_insert('chat_channels', [
        'name'          => $ch['name'],
        'slug'          => $ch['slug'],
        'description'   => $ch['description'],
        'type'          => $ch['type'],
        'is_private'    => $ch['is_private'],
        'created_by'    => $adminUser['id'],
        'member_count'  => count($members),
        'last_message_at' => null,
    ]);
    $channelIds[$ch['slug']] = $channelId;

    foreach ($members as $u) {
        db_execute(
            "INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, ?)",
            [$channelId, $u['id'], $u['id'] === $adminUser['id'] ? 'admin' : 'member']
        );
    }
    echo "Created #{$ch['slug']} (id=$channelId) with " . count($members) . " members.\n";
}

// ── Post test messages to #general ───────────────────────────────────────────
$generalId = $channelIds['general'] ?? null;
if (!$generalId) {
    echo "Could not find #general channel.\n";
    exit(1);
}

// Check if messages already exist
$existingMsgCount = db_count("SELECT COUNT(*) FROM chat_messages WHERE channel_id = ?", [$generalId]);
if ($existingMsgCount > 0) {
    echo "Messages already exist in #general ($existingMsgCount found), skipping test messages.\n";
} else {
    $user1 = $users[0];
    $user2 = isset($users[1]) ? $users[1] : $users[0];
    $user3 = isset($users[2]) ? $users[2] : $users[0];

    $testMessages = [
        ['user' => $user1, 'msg' => 'Welcome to #general! This is the main channel for team discussion.', 'mentions' => null],
        ['user' => $user2, 'msg' => 'Thanks! Great to have a team chat built right into FleetForge.', 'mentions' => null],
        ['user' => $user1, 'msg' => 'The new lease for ABC Trucking is ready to go. Equipment all checked in.', 'mentions' => null],
        ['user' => $user2, 'msg' => "I'll handle the paperwork. Should be done by end of day.", 'mentions' => null],
        ['user' => $user3, 'msg' => 'Reminder: unit HD145 has a maintenance inspection due this week.', 'mentions' => null],
        ['user' => $user1, 'msg' => 'Thanks for the heads up. I\'ll log a work order.', 'mentions' => null],
        ['user' => $user2, 'msg' => 'Invoice batch for April is ready. 14 invoices generated.', 'mentions' => null],
        ['user' => $user3, 'msg' => 'Quick compliance check — 3 units have registration expiring next month.', 'mentions' => null],
        ['user' => $user1, 'msg' => '@' . $user2['name'] . ' can you check the compliance docs for the Surrey yard?', 'mentions' => json_encode([$user2['id']])],
        ['user' => $user2, 'msg' => 'On it! Will update the compliance tab once reviewed.', 'mentions' => null],
    ];

    $lastId = null;
    foreach ($testMessages as $i => $tm) {
        $msgId = db_insert('chat_messages', [
            'channel_id' => $generalId,
            'user_id'    => $tm['user']['id'],
            'message'    => $tm['msg'],
            'type'       => 'text',
            'mentions'   => $tm['mentions'],
            'created_at' => date('Y-m-d H:i:s', strtotime("-" . (10 - $i) . " minutes")),
        ]);
        $lastId = $msgId;
    }

    // Update channel last_message_at
    db_execute(
        "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = ? WHERE id = ?",
        [substr($testMessages[count($testMessages)-1]['msg'], 0, 100), $generalId]
    );

    echo "Posted " . count($testMessages) . " test messages to #general.\n";

    // ── Post 5 messages with record attachments ───────────────────────────────
    // Get some real invoice IDs
    $invoices = db_select("SELECT id, invoice_number, total_amount, status FROM invoices WHERE deleted_at IS NULL LIMIT 3", []);
    $leases   = db_select("SELECT id, contract_number, status FROM leases WHERE deleted_at IS NULL LIMIT 2", []);

    if (!empty($invoices)) {
        $msgId = db_insert('chat_messages', [
            'channel_id' => $generalId,
            'user_id'    => $user1['id'],
            'message'    => 'Invoice ready for review.',
            'type'       => 'attachment',
            'created_at' => date('Y-m-d H:i:s', strtotime("-5 minutes")),
        ]);
        db_insert('chat_attachments', [
            'message_id'   => $msgId,
            'type'         => 'invoice',
            'entity_id'    => $invoices[0]['id'],
            'preview_data' => json_encode([
                'title'    => 'Invoice ' . $invoices[0]['invoice_number'],
                'subtitle' => 'Status: ' . ucfirst($invoices[0]['status']),
                'badge'    => $invoices[0]['status'],
            ]),
        ]);
        echo "Posted invoice attachment message.\n";
    }

    if (!empty($leases)) {
        $msgId = db_insert('chat_messages', [
            'channel_id' => $generalId,
            'user_id'    => $user2['id'],
            'message'    => 'Lease update — please review.',
            'type'       => 'attachment',
            'created_at' => date('Y-m-d H:i:s', strtotime("-4 minutes")),
        ]);
        db_insert('chat_attachments', [
            'message_id'   => $msgId,
            'type'         => 'lease',
            'entity_id'    => $leases[0]['id'],
            'preview_data' => json_encode([
                'title'    => 'Lease ' . $leases[0]['contract_number'],
                'subtitle' => 'Status: ' . ucfirst($leases[0]['status']),
                'badge'    => $leases[0]['status'],
            ]),
        ]);
        echo "Posted lease attachment message.\n";
    }
}

// ── Create a DM between the first two users ───────────────────────────────────
if (count($users) >= 2) {
    $u1 = $users[0];
    $u2 = $users[1];

    // DM slug is deterministic: "dm_min_max"
    $dmSlug = 'dm_' . min($u1['id'], $u2['id']) . '_' . max($u1['id'], $u2['id']);
    $existingDm = db_row("SELECT id FROM chat_channels WHERE slug = ?", [$dmSlug]);

    if ($existingDm) {
        echo "DM between {$u1['name']} and {$u2['name']} already exists.\n";
    } else {
        $dmName = $u1['name'] . ', ' . $u2['name'];
        $dmId = db_insert('chat_channels', [
            'name'         => $dmName,
            'slug'         => $dmSlug,
            'description'  => null,
            'type'         => 'direct',
            'is_private'   => 1,
            'created_by'   => $u1['id'],
            'member_count' => 2,
        ]);
        db_execute("INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'admin')", [$dmId, $u1['id']]);
        db_execute("INSERT IGNORE INTO chat_channel_members (channel_id, user_id, role) VALUES (?, ?, 'member')", [$dmId, $u2['id']]);

        $dmMsg1 = db_insert('chat_messages', [
            'channel_id' => $dmId,
            'user_id'    => $u1['id'],
            'message'    => 'Hey ' . $u2['name'] . ', just wanted to check in on the Q2 lease renewals.',
            'type'       => 'text',
            'created_at' => date('Y-m-d H:i:s', strtotime("-3 minutes")),
        ]);
        $dmMsg2 = db_insert('chat_messages', [
            'channel_id' => $dmId,
            'user_id'    => $u2['id'],
            'message'    => 'Working on it now! Should have a list ready by tomorrow.',
            'type'       => 'text',
            'created_at' => date('Y-m-d H:i:s', strtotime("-2 minutes")),
        ]);

        db_execute(
            "UPDATE chat_channels SET last_message_at = NOW(), last_message_preview = 'Working on it now!' WHERE id = ?",
            [$dmId]
        );
        echo "Created DM between {$u1['name']} and {$u2['name']} (id=$dmId) with 2 messages.\n";
    }
}

echo "\nChat seed complete.\n";
