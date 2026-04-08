<?php
declare(strict_types=1);

/**
 * app/portal/api/messenger/messages.php
 *
 * GET ?thread_id=X[&before_id=Y&limit=50]
 *
 * Returns messages for a thread the portal user can access. Also marks
 * the thread read for the portal user (unless this is a paginated
 * "load older" call).
 *
 * Access is validated via MessengerService::portalCanAccessThread() —
 * the portal user must either share the thread's customer_id (scope=
 * customer) or BE the pinned portal_user (scope=portal_user).
 *
 * @session MSGR-1
 */

require_once __DIR__ . '/_bootstrap.php';

use FleetForge\Messenger\MessengerService;

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
    portal_msgr_err('METHOD_NOT_ALLOWED', 'Use GET.', 405);
}

$threadId = isset($_GET['thread_id']) ? (int) $_GET['thread_id'] : 0;
$beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : 0;
$limit    = isset($_GET['limit'])     ? (int) $_GET['limit']     : 50;
$limit    = max(10, min(100, $limit));

if ($threadId <= 0) {
    portal_msgr_err('MISSING_REQUIRED', 'thread_id is required.', 422);
}

$thread = MessengerService::portalCanAccessThread($threadId, $portalUserId, $portalCustomerId);
if (!$thread) {
    portal_msgr_err('FORBIDDEN', 'You do not have access to that conversation.', 403);
}

$cursorSQL = $beforeId > 0 ? 'AND mm.id < ?' : '';
$params    = $beforeId > 0 ? [$threadId, $beforeId, $limit] : [$threadId, $limit];

$messages = db_select(
    "SELECT mm.id, mm.thread_id, mm.sender_type,
            mm.admin_user_id, mm.portal_user_id,
            mm.body, mm.is_archived, mm.created_at, mm.updated_at,
            u.name  AS admin_name,
            pu.name AS portal_name
       FROM messenger_messages mm
       LEFT JOIN users u         ON u.id  = mm.admin_user_id
       LEFT JOIN portal_users pu ON pu.id = mm.portal_user_id
      WHERE mm.thread_id = ? $cursorSQL
      ORDER BY mm.id DESC
      LIMIT ?",
    $params
);
$messages = array_reverse($messages);

foreach ($messages as &$m) {
    $m['id']             = (int) $m['id'];
    $m['thread_id']      = (int) $m['thread_id'];
    $m['admin_user_id']  = $m['admin_user_id']  !== null ? (int) $m['admin_user_id']  : null;
    $m['portal_user_id'] = $m['portal_user_id'] !== null ? (int) $m['portal_user_id'] : null;
    $m['author_name']    = $m['sender_type'] === 'admin'
        ? ($m['admin_name'] ?? 'Support')
        : ($m['portal_name'] ?? 'You');
    if ((int) $m['is_archived'] === 1) {
        $m['body'] = null;
        $m['display_text'] = '[message deleted]';
    } else {
        $m['display_text'] = $m['body'];
    }
}
unset($m);

// Head fetch → mark read for this portal user.
if ($beforeId === 0) {
    try {
        MessengerService::markReadPortal($threadId, $portalUserId);
    } catch (\Throwable $e) {
        error_log('[MSGR portal] markReadPortal failed: ' . $e->getMessage());
    }
}

$hasMore = count($messages) >= $limit;

portal_msgr_ok([
    'messages' => $messages,
    'has_more' => $hasMore,
]);
