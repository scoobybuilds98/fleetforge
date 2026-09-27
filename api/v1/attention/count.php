<?php
declare(strict_types=1);

/**
 * api/v1/attention/count.php
 *
 * Bell badge numbers, polled by the topbar every 60s (S-ATTENTION-INBOX).
 * The number on the bell is Needs attention items only; updates just show a
 * dot. One indexed COUNT for Needs attention + one EXISTS probe for updates.
 *
 * @method  GET
 * @auth    require_auth_api
 * @returns 200 { total, urgent, mine, updates_unread }
 *          updates_unread is a 0/1 FLAG ("any unread update?"), not a count
 *          (S-PERF-3: the COUNT walked ~7k-row backlogs on prod for a dot).
 *
 * @session S-ATTENTION-INBOX, S-PERF-3
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
