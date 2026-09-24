<?php
/**
 * api/v1/chat/conversations.php
 *
 * GET — the staff inbox: { team: [...], customers: [...], unread: {team, customers, total} }.
 * Team = direct messages + groups I'm in. Customers = every customer thread
 * with messages (customers.view only). Newest activity first.
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
// Polled — release the session lock so other tabs' requests aren't queued behind it.
session_write_close();

$lists = Conversations::listForStaff($viewer);
$sum   = fn(array $items) => array_sum(array_column($items, 'unread'));
json_success($lists + [
    'unread' => [
        'team'      => $sum($lists['team']),
        'customers' => $sum($lists['customers']),
        'total'     => $sum($lists['team']) + $sum($lists['customers']),
    ],
    'can_customers' => $viewer['customers'],
]);
