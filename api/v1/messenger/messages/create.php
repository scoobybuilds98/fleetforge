<?php
/**
 * api/v1/messenger/messages/create.php
 *
 * POST — Admin posts a reply in an existing customer thread.
 *
 * Payload:
 *   thread_id  required
 *   body       required (1..5000 chars)
 *
 * Side effects:
 *   - Inserts messenger_messages row (sender_type=admin)
 *   - Updates thread.last_message_* fields
 *   - Marks the thread read for this admin (so their own send doesn't badge them)
 *   - Fires a portal-side in-app notification via NotificationService::notifyPortal()
 *     (scope='customer' → every portal user; scope='portal_user' → just that user)
 *
 * Dependencies: api/bootstrap.php, lib/Messenger/MessengerService.php,
 *               lib/Notifications/NotificationService.php,
 *               messenger_threads, messenger_messages
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/Messenger/MessengerService.php';
require_method('POST');
require_auth_api();
require_permission('customers', 'view');

use FleetForge\Messenger\MessengerService;

$adminId  = current_user_id();
// JSON-body: FF_Api.post() ships application/json, so $_POST is empty.
$input    = json_body();
$threadId = clean_int($input['thread_id'] ?? null);
$body     = clean_string($input['body'] ?? null, 5000);

if (!$threadId) {
    json_error('MISSING_REQUIRED', 'thread_id is required.', 422);
}
if ($body === null || trim($body) === '') {
    json_error('VALIDATION_ERROR', 'Message body is required.', 422);
}

$thread = db_row(
    "SELECT id, customer_id, scope, portal_user_id, is_archived
       FROM messenger_threads
      WHERE id = ?",
    [$threadId]
);
if (!$thread) {
    json_error('NOT_FOUND', 'Thread not found.', 404);
}
if ((int) $thread['is_archived'] === 1) {
    json_error('VALIDATION_ERROR', 'This thread is archived.', 422);
}

$messageId = null;

db_transaction(function () use ($threadId, $adminId, $body, &$messageId) {
    $messageId = db_insert('messenger_messages', [
        'thread_id'      => $threadId,
        'sender_type'    => 'admin',
        'admin_user_id'  => $adminId,
        'portal_user_id' => null,
        'body'           => $body,
    ]);
    MessengerService::touchLastMessage($threadId, 'admin', $body);
    MessengerService::markReadAdmin($threadId, $adminId, $messageId);
});

// Return the full message row so the UI can append without a re-fetch.
$created = db_row(
    "SELECT mm.id, mm.thread_id, mm.sender_type, mm.admin_user_id,
            mm.portal_user_id, mm.body, mm.is_archived, mm.created_at, mm.updated_at,
            u.name AS admin_name
       FROM messenger_messages mm
       LEFT JOIN users u ON u.id = mm.admin_user_id
      WHERE mm.id = ?",
    [$messageId]
);
$created['id']             = (int) $created['id'];
$created['thread_id']      = (int) $created['thread_id'];
$created['admin_user_id']  = $created['admin_user_id'] !== null ? (int) $created['admin_user_id'] : null;
$created['portal_user_id'] = null;
$created['author_name']    = $created['admin_name'] ?? '(deleted user)';
$created['display_text']   = $created['body'];

// Fire portal-side in-app notification
try {
    require_once dirname(__DIR__, 4) . '/lib/Notifications/NotificationService.php';
    $senderName = current_user()['name'] ?? 'Support';

    if ($thread['scope'] === 'portal_user') {
        // Target only the pinned portal user.
        $pu = db_row(
            "SELECT id, customer_id FROM portal_users WHERE id = ?",
            [$thread['portal_user_id']]
        );
        if ($pu) {
            // notifyPortal() fans out to all portal users of the customer,
            // so for portal_user scope we need a direct insert instead.
            db_insert('notifications', [
                'user_id'        => null,
                'portal_user_id' => (int) $pu['id'],
                'title'          => 'New message from ' . $senderName,
                'message'        => mb_substr($body, 0, 255),
                'type'           => 'messenger.new_message',
                'category'       => 'system',
                'url'            => '/fleetforge/portal/messages?thread=' . $threadId,
                'entity_type'    => 'messenger_thread',
                'entity_id'      => $threadId,
                'severity'       => 'info',
            ]);
        }
    } else {
        // Customer-wide → notify every active portal user of this customer.
        \FleetForge\Notifications\NotificationService::notifyPortal(
            'messenger.new_message',
            (int) $thread['customer_id'],
            'New message from ' . $senderName,
            mb_substr($body, 0, 255),
            'messenger_thread',
            $threadId,
            '/fleetforge/portal/messages?thread=' . $threadId
        );
    }
} catch (\Throwable $e) {
    error_log('[MESSENGER] portal notification failed: ' . $e->getMessage());
}

json_success($created, 201);
