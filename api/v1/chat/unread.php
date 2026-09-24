<?php
/**
 * api/v1/chat/unread.php
 *
 * GET — { total, team, customers } for the topbar Chat badge.
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
session_write_close();   // polled every few seconds from every admin tab
json_success(Conversations::staffUnread($viewer));
