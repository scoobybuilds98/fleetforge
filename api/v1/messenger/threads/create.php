<?php
/**
 * api/v1/messenger/threads/create.php
 *
 * POST — Open (or resurface) a customer messenger thread from the admin side.
 *
 * Payload (either/or):
 *   customer_id    required when scope=customer
 *   portal_user_id required when scope=portal_user
 *   scope          'customer' | 'portal_user'   (defaults based on which id is set)
 *   subject        optional, max 255
 *   body           optional — if present, an initial message is posted in the
 *                  same transaction so the thread is never empty in the list.
 *
 * Idempotency: if an open thread already exists with the same
 * (customer_id, scope, portal_user_id) the admin is handed back THAT thread's
 * id rather than creating a duplicate. This prevents accidental double-clicks
 * from fragmenting a customer's inbox.
 *
 * Dependencies: api/bootstrap.php, lib/Messenger/MessengerService.php,
 *               lib/Notifications/NotificationService.php,
 *               messenger_threads, messenger_messages, customers, portal_users
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/Messenger/MessengerService.php';
require_method('POST');
require_auth_api();
require_permission('customers', 'view');

use FleetForge\Messenger\MessengerService;

$adminId = current_user_id();
// JSON-body: every messenger endpoint uses FF_Api.post() which sends
// application/json, so we read via json_body() rather than $_POST.
$input         = json_body();
$customerId    = clean_int($input['customer_id'] ?? null);
$portalUserId  = clean_int($input['portal_user_id'] ?? null);
$scope         = clean_string($input['scope'] ?? null, 20);
$subject       = clean_string($input['subject'] ?? null, 255) ?? '';
$body          = clean_string($input['body'] ?? null, 5000);

// Infer scope from which id was passed if the caller didn't set it explicitly.
if ($scope === null) {
    $scope = $portalUserId ? 'portal_user' : 'customer';
}
if (!in_array($scope, ['customer', 'portal_user'], true)) {
    json_error('VALIDATION_ERROR', 'scope must be customer or portal_user.', 422);
}

// Resolve the target customer — every thread is customer-rooted.
if ($scope === 'portal_user') {
    if (!$portalUserId) {
        json_error('MISSING_REQUIRED', 'portal_user_id is required when scope=portal_user.', 422);
    }
    $pu = db_row(
        "SELECT pu.id, pu.customer_id, pu.status
           FROM portal_users pu
          WHERE pu.id = ?",
        [$portalUserId]
    );
    if (!$pu) {
        json_error('NOT_FOUND', 'Portal user not found.', 404);
    }
    if ($pu['status'] !== 'active') {
        json_error('VALIDATION_ERROR', 'That portal user is not active and cannot receive messages.', 422);
    }
    $customerId = (int) $pu['customer_id'];
} else {
    if (!$customerId) {
        json_error('MISSING_REQUIRED', 'customer_id is required when scope=customer.', 422);
    }
    $portalUserId = null;
}

// Customer must exist and not be deleted
$cust = db_row(
    "SELECT id, company_name FROM customers WHERE id = ? AND deleted_at IS NULL",
    [$customerId]
);
if (!$cust) {
    json_error('NOT_FOUND', 'Customer not found.', 404);
}

// Idempotency: look for an existing open thread matching this scope.
if ($scope === 'portal_user') {
    $existing = db_row(
        "SELECT id FROM messenger_threads
          WHERE customer_id = ? AND scope = 'portal_user' AND portal_user_id = ?
            AND is_archived = 0
          ORDER BY id DESC LIMIT 1",
        [$customerId, $portalUserId]
    );
} else {
    $existing = db_row(
        "SELECT id FROM messenger_threads
          WHERE customer_id = ? AND scope = 'customer' AND portal_user_id IS NULL
            AND is_archived = 0
          ORDER BY id DESC LIMIT 1",
        [$customerId]
    );
}

$threadId  = null;
$messageId = null;

db_transaction(function () use (
    $existing, $customerId, $portalUserId, $scope, $subject, $body,
    $adminId, &$threadId, &$messageId
) {
    if ($existing) {
        $threadId = (int) $existing['id'];
    } else {
        $threadId = db_insert('messenger_threads', [
            'customer_id'        => $customerId,
            'scope'              => $scope,
            'portal_user_id'     => $portalUserId,
            'subject'            => $subject,
            'created_by_user_id' => $adminId,
        ]);
    }

    // Optionally post the first message in the same transaction so the
    // thread is never empty in the inbox view.
    if ($body !== null && trim($body) !== '') {
        $messageId = db_insert('messenger_messages', [
            'thread_id'      => $threadId,
            'sender_type'    => 'admin',
            'admin_user_id'  => $adminId,
            'portal_user_id' => null,
            'body'           => $body,
        ]);

        MessengerService::touchLastMessage($threadId, 'admin', $body);
        // Mark the new message read for the sender so it doesn't badge their own inbox.
        MessengerService::markReadAdmin($threadId, $adminId, $messageId);
    }
});

// Reload the thread joined with customer + portal user for the UI.
$thread = db_row(
    "SELECT mt.id, mt.customer_id, c.company_name AS customer_name,
            mt.scope, mt.portal_user_id, pu.name AS portal_user_name,
            mt.subject, mt.last_message_at, mt.last_message_preview,
            mt.last_message_by, mt.created_at
       FROM messenger_threads mt
       JOIN customers c ON c.id = mt.customer_id
       LEFT JOIN portal_users pu ON pu.id = mt.portal_user_id
      WHERE mt.id = ?",
    [$threadId]
);
$thread['id']             = (int) $thread['id'];
$thread['customer_id']    = (int) $thread['customer_id'];
$thread['portal_user_id'] = $thread['portal_user_id'] !== null ? (int) $thread['portal_user_id'] : null;
$thread['unread_count']   = 0;

// Fire a portal-side in-app notification if an initial message was posted.
if ($messageId !== null) {
    try {
        require_once dirname(__DIR__, 4) . '/lib/Notifications/NotificationService.php';
        $senderName = current_user()['name'] ?? 'Support';
        \FleetForge\Notifications\NotificationService::notifyPortal(
            'messenger.new_message',
            $customerId,
            'New message from ' . $senderName,
            mb_substr($body, 0, 255),
            'messenger_thread',
            $threadId,
            '/portal/messages?thread=' . $threadId
        );
    } catch (Throwable $e) {
        error_log('[MESSENGER] portal notification failed: ' . $e->getMessage());
    }
}

json_success(['thread' => $thread, 'message_id' => $messageId], 201);
