<?php
declare(strict_types=1);

/**
 * api/v1/attention/count.php
 *
 * Bell badge numbers, polled by the topbar every 60s (S-ATTENTION-INBOX).
 * The number on the bell is Needs attention items only; updates just show a
 * dot. One indexed COUNT each.
 *
 * @method  GET
 * @auth    require_auth_api
 * @returns 200 { total, urgent, mine, updates_unread }
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Attention\AttentionService;

require_method('GET');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}

json_success(AttentionService::badge($userId, (string) (current_user()['role_slug'] ?? '')));
