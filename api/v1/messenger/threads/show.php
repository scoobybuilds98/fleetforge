<?php
/**
 * api/v1/messenger/threads/show.php
 *
 * GET ?id=123  — Return a single thread with rich detail for the header.
 *
 * Used when the admin opens a thread directly via the URL (?thread=123)
 * so the page can render the header + customer context without waiting
 * for the full list to load.
 *
 * Dependencies: api/bootstrap.php, messenger_threads, customers, portal_users
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();
require_permission('customers', 'view');

$threadId = clean_int($_GET['id'] ?? null);
if (!$threadId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$thread = db_row(
    "SELECT mt.id,
            mt.customer_id,
            c.company_name AS customer_name,
            c.email AS customer_email,
            c.contact_name AS customer_contact_name,
            c.phone AS customer_phone,
            mt.scope,
            mt.portal_user_id,
            pu.name AS portal_user_name,
            pu.email AS portal_user_email,
            mt.subject,
            mt.last_message_at,
            mt.last_message_preview,
            mt.last_message_by,
            mt.is_archived,
            mt.created_at
       FROM messenger_threads mt
       JOIN customers c ON c.id = mt.customer_id
       LEFT JOIN portal_users pu ON pu.id = mt.portal_user_id
      WHERE mt.id = ? AND c.deleted_at IS NULL",
    [$threadId]
);

if (!$thread) {
    json_error('NOT_FOUND', 'Thread not found.', 404);
}

$thread['id']             = (int) $thread['id'];
$thread['customer_id']    = (int) $thread['customer_id'];
$thread['portal_user_id'] = $thread['portal_user_id'] !== null ? (int) $thread['portal_user_id'] : null;
$thread['is_archived']    = (int) $thread['is_archived'];

// Participants list for the info panel:
// - all active portal users of this customer (so admin knows who can
//   reply on a scope='customer' thread), OR
// - just the one pinned portal user for scope='portal_user'.
if ($thread['scope'] === 'portal_user') {
    $participants = db_select(
        "SELECT id, name, email, is_primary
           FROM portal_users
          WHERE id = ?",
        [$thread['portal_user_id']]
    );
} else {
    $participants = db_select(
        "SELECT id, name, email, is_primary
           FROM portal_users
          WHERE customer_id = ? AND status = 'active'
          ORDER BY is_primary DESC, name ASC",
        [$thread['customer_id']]
    );
}

json_success([
    'thread'       => $thread,
    'participants' => $participants,
]);
