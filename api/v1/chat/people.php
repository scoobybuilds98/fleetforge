<?php
/**
 * api/v1/chat/people.php
 *
 * GET ?q=… — who I can start a conversation with:
 *   { staff: [{id, name, role}], customers: [{id, name, portal_users, conversation_id}] }
 * Customers only with customers.view. Empty q = first page alphabetically.
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('GET');
require_auth_api();

use FleetForge\Chat\Conversations;

$viewer = Conversations::staffViewer();
session_write_close();

$q    = clean_string($_GET['q'] ?? null, 100) ?? '';
$like = '%' . addcslashes($q, '%_\\') . '%';

$staff = db_select(
    "SELECT u.id, u.name, r.name AS role
       FROM users u
       LEFT JOIN user_roles r ON r.id = u.role_id
      WHERE u.deleted_at IS NULL AND u.status = 'active' AND u.id <> ?
        AND (u.name LIKE ? OR u.email LIKE ?)
      ORDER BY u.name LIMIT 30",
    [$viewer['user_id'], $like, $like]
);

$customers = [];
if ($viewer['customers']) {
    $customers = db_select(
        "SELECT c.id, c.company_name AS name, cv.id AS conversation_id,
                (SELECT COUNT(*) FROM portal_users pu WHERE pu.customer_id = c.id AND pu.status = 'active') AS portal_users
           FROM customers c
           LEFT JOIN conversations cv ON cv.kind = 'customer' AND cv.customer_id = c.id
          WHERE c.deleted_at IS NULL AND (c.company_name LIKE ? OR c.contact_name LIKE ?)
          ORDER BY c.company_name LIMIT 30",
        [$like, $like]
    );
}

json_success([
    'staff'     => array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role'] ?? ''], $staff),
    'customers' => array_map(fn($c) => [
        'id'              => (int) $c['id'],
        'name'            => $c['name'],
        'portal_users'    => (int) $c['portal_users'],
        'conversation_id' => $c['conversation_id'] !== null ? (int) $c['conversation_id'] : null,
    ], $customers),
]);
