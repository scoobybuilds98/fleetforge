<?php
declare(strict_types=1);

/**
 * app/portal/api/messenger/poll.php
 *
 * GET ?thread_id=X&after_id=Y
 *
 * Delta endpoint for the portal messenger page — called every 5s while
 * a thread is open. Returns any messages with id > after_id. Also marks
 * the thread read for the portal user.
 *
 * @session MSGR-1
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Messenger\MessengerService;

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
    portal_msgr_err('METHOD_NOT_ALLOWED', 'Use GET.', 405);
}

$threadId = isset($_GET['thread_id']) ? (int) $_GET['thread_id'] : 0;
$afterId  = isset($_GET['after_id']) ? (int) $_GET['after_id'] : 0;

if ($threadId <= 0) {
    portal_msgr_err('MISSING_REQUIRED', 'thread_id is required.', 422);
}

$thread = MessengerService::portalCanAccessThread($threadId, $portalUserId, $portalCustomerId);
if (!$thread) {
    portal_msgr_err('FORBIDDEN', 'You do not have access to that conversation.', 403);
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
        ? ($m['admin_name'] ?? 'Support')
        : ($m['portal_name'] ?? 'You');
    $m['display_text']   = (int) $m['is_archived'] === 1 ? '[message deleted]' : $m['body'];
}
unset($m);

if (!empty($messages)) {
    try {
        $maxId = (int) $messages[count($messages) - 1]['id'];
        MessengerService::markReadPortal($threadId, $portalUserId, $maxId);
    } catch (\Throwable $e) {
        error_log('[MSGR portal] poll markReadPortal failed: ' . $e->getMessage());
    }
}

portal_msgr_ok(['messages' => $messages]);
