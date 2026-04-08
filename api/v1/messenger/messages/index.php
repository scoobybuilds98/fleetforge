<?php
/**
 * api/v1/messenger/messages/index.php
 *
 * GET ?thread_id=X[&before_id=Y&limit=50]
 *
 * Returns messages for a thread in chronological order (oldest first)
 * with author names resolved from either `users` or `portal_users`.
 *
 * Cursor pagination: pass &before_id to fetch the page before a given
 * message id (for infinite-scroll "load older" behavior).
 *
 * Side effect: touches messenger_thread_reads for the caller so the
 * thread's unread_count drops to zero the next time the sidebar polls.
 *
 * Dependencies: api/bootstrap.php, lib/Messenger/MessengerService.php,
 *               messenger_messages, messenger_threads, users, portal_users
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
$beforeId = clean_int($_GET['before_id'] ?? null);
$limit    = min(100, max(10, clean_int($_GET['limit'] ?? 50) ?? 50));

if (!$threadId) {
    json_error('MISSING_REQUIRED', 'thread_id is required.', 422);
}

// Confirm thread exists + not archived. Admin visibility is global so
// no per-user membership check is needed.
$thread = db_row(
    "SELECT id, is_archived FROM messenger_threads WHERE id = ?",
    [$threadId]
);
if (!$thread) {
    json_error('NOT_FOUND', 'Thread not found.', 404);
}

// Cursor clause for "load older" pagination
$cursorSQL = $beforeId ? 'AND mm.id < ?' : '';
$params    = $beforeId ? [$threadId, $beforeId, $limit] : [$threadId, $limit];

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

// Build display fields so the UI can just render one string per row.
foreach ($messages as &$m) {
    $m['id']             = (int) $m['id'];
    $m['thread_id']      = (int) $m['thread_id'];
    $m['admin_user_id']  = $m['admin_user_id']  !== null ? (int) $m['admin_user_id']  : null;
    $m['portal_user_id'] = $m['portal_user_id'] !== null ? (int) $m['portal_user_id'] : null;
    $m['author_name']    = $m['sender_type'] === 'admin'
        ? ($m['admin_name'] ?? '(deleted user)')
        : ($m['portal_name'] ?? '(deleted portal user)');
    if ((int) $m['is_archived'] === 1) {
        $m['body'] = null;
        $m['display_text'] = '[message deleted]';
    } else {
        $m['display_text'] = $m['body'];
    }
}
unset($m);

// If this was the "head" fetch (no cursor), mark the thread read for
// the admin so new-activity badges disappear on the next sidebar poll.
if ($beforeId === null) {
    try {
        MessengerService::markReadAdmin($threadId, $adminId);
    } catch (\Throwable $e) {
        error_log('[MESSENGER] markReadAdmin failed: ' . $e->getMessage());
    }
}

$hasMore = count($messages) >= $limit;

json_success([
    'messages' => $messages,
    'has_more' => $hasMore,
]);
