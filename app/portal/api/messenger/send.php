<?php
declare(strict_types=1);

/**
 * app/portal/api/messenger/send.php
 *
 * POST — Portal user sends a reply in a thread they can access.
 *
 * Payload (form-encoded):
 *   thread_id   required
 *   body        required (1..5000 chars)
 *
 * Side effects:
 *   - Inserts messenger_messages (sender_type=portal)
 *   - Updates thread.last_message_* fields
 *   - Marks the thread read for the sender
 *   - Fires an in-app notification targeting all active admin users with
 *     customers.view permission (shared inbox). Uses NotificationService
 *     with an explicit recipient list because portal messages are always
 *     targeted at the admin support group, not a broadcast.
 *
 * @session MSGR-1
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Messenger\MessengerService;

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    portal_msgr_err('METHOD_NOT_ALLOWED', 'Use POST.', 405);
}

// JSON-body: FF_PortalMessenger uses FF_Api.post() which serialises to
// application/json — $_POST will be empty.
$input    = portal_msgr_input();
$threadId = isset($input['thread_id']) ? (int) $input['thread_id'] : 0;
$body     = isset($input['body']) ? trim((string) $input['body']) : '';

if ($threadId <= 0) {
    portal_msgr_err('MISSING_REQUIRED', 'thread_id is required.', 422);
}
if ($body === '') {
    portal_msgr_err('VALIDATION_ERROR', 'Message body is required.', 422);
}
if (mb_strlen($body) > 5000) {
    $body = mb_substr($body, 0, 5000);
}

// Gate access — portal user must be allowed on this thread.
$thread = MessengerService::portalCanAccessThread($threadId, $portalUserId, $portalCustomerId);
if (!$thread) {
    portal_msgr_err('FORBIDDEN', 'You do not have access to that conversation.', 403);
}

$messageId = null;
db_transaction(function () use ($threadId, $portalUserId, $body, &$messageId) {
    $messageId = db_insert('messenger_messages', [
        'thread_id'      => $threadId,
        'sender_type'    => 'portal',
        'admin_user_id'  => null,
        'portal_user_id' => $portalUserId,
        'body'           => $body,
    ]);
    MessengerService::touchLastMessage($threadId, 'portal', $body);
    MessengerService::markReadPortal($threadId, $portalUserId, $messageId);
});

// Return the full message row for optimistic append
$created = db_row(
    "SELECT mm.id, mm.thread_id, mm.sender_type,
            mm.admin_user_id, mm.portal_user_id,
            mm.body, mm.is_archived, mm.created_at, mm.updated_at,
            pu.name AS portal_name
       FROM messenger_messages mm
       LEFT JOIN portal_users pu ON pu.id = mm.portal_user_id
      WHERE mm.id = ?",
    [$messageId]
);
$created['id']             = (int) $created['id'];
$created['thread_id']      = (int) $created['thread_id'];
$created['admin_user_id']  = null;
$created['portal_user_id'] = (int) $created['portal_user_id'];
$created['author_name']    = $created['portal_name'] ?? 'You';
$created['display_text']   = $created['body'];

// In-app notification → every admin with customers.view will see it in
// their bell AND their messenger badge will tick.
try {
    require_once FF_ROOT . '/lib/Notifications/NotificationService.php';

    $sender     = portal_user();
    $senderName = $sender['name'] ?? 'Customer';
    $company    = $sender['company_name'] ?? '';

    // Resolve admin recipients — active users whose role grants customers.view.
    $admins = db_select(
        "SELECT DISTINCT u.id
           FROM users u
           LEFT JOIN user_roles r ON r.id = u.role_id
           LEFT JOIN user_permissions p
                  ON p.role_id = u.role_id
                 AND p.module = 'customers'
                 AND p.can_view = 1
          WHERE u.deleted_at IS NULL
            AND u.status = 'active'
            AND (p.id IS NOT NULL OR r.slug = 'super_admin')"
    );
    $adminIds = array_map(static fn($r) => (int) $r['id'], $admins);

    if (!empty($adminIds)) {
        \FleetForge\Notifications\NotificationService::notify(
            'messenger.portal_reply',
            'New message from ' . $senderName . ($company !== '' ? ' (' . $company . ')' : ''),
            mb_substr($body, 0, 255),
            'messenger_thread',
            $threadId,
            '/messenger?thread=' . $threadId,
            $adminIds,
            'info'
        );
    }
} catch (\Throwable $e) {
    error_log('[MSGR portal] admin notification failed: ' . $e->getMessage());
}

portal_msgr_ok($created, 201);
