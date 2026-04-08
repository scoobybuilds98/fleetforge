<?php
/**
 * api/v1/messenger/messages/poll.php
 *
 * GET ?thread_id=X&after_id=Y
 *
 * Polled every 5 seconds by the admin messenger page to fetch messages
 * that have arrived since the last known message id. Returns an empty
 * array when there is no new activity — the client just waits for the
 * next tick.
 *
 * This endpoint DOES mark the thread as read because the admin is
 * actively viewing it.
 *
 * Dependencies: api/bootstrap.php, lib/Messenger/MessengerService.php,
 *               messenger_messages, messenger_threads
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/Messenger/MessengerService.php';
require_method('GET');
require_auth_api();
require_permission('customers', 'view');

use FleetForge\Messenger\MessengerService;

$adminId  = current_user_id();
$threadId = clean_int($_GET['thread_id'] ?? null);
$afterId  = clean_int($_GET['after_id'] ?? null) ?? 0;

if (!$threadId) {
    json_error('MISSING_REQUIRED', 'thread_id is required.', 422);
}

if (!db_exists('messenger_threads', 'id = ?', [$threadId])) {
    json_error('NOT_FOUND', 'Thread not found.', 404);
}

$messages = db_select(
    "SELECT mm.id, mm.thread_id, mm.sender_type,
            mm.admin_user_id, mm.portal_user_id,
            mm.body, mm.is_archived, mm.created_at, mm.updated_at,
            u.name  AS admin_name,
            pu.name AS portal_name
       FROM messenger_messages mm
       LEFT JOIN users u         ON u.id  = mm.admin_user_id
       LEFT JOIN portal_users pu ON pu.id = mm.portal_user_id
      WHERE mm.thread_id = ? AND mm.id > ?
      ORDER BY mm.id ASC
      LIMIT 100",
    [$threadId, $afterId]
);

foreach ($messages as &$m) {
    $m['id']             = (int) $m['id'];
    $m['thread_id']      = (int) $m['thread_id'];
    $m['admin_user_id']  = $m['admin_user_id']  !== null ? (int) $m['admin_user_id']  : null;
    $m['portal_user_id'] = $m['portal_user_id'] !== null ? (int) $m['portal_user_id'] : null;
    $m['author_name']    = $m['sender_type'] === 'admin'
        ? ($m['admin_name'] ?? '(deleted user)')
        : ($m['portal_name'] ?? '(deleted portal user)');
    $m['display_text']   = (int) $m['is_archived'] === 1 ? '[message deleted]' : $m['body'];
}
unset($m);

// If we pulled anything, advance the admin read cursor to the newest id.
if (!empty($messages)) {
    try {
        $maxId = (int) $messages[count($messages) - 1]['id'];
        MessengerService::markReadAdmin($threadId, $adminId, $maxId);
    } catch (\Throwable $e) {
        error_log('[MESSENGER] poll markReadAdmin failed: ' . $e->getMessage());
    }
}

json_success(['messages' => $messages]);
