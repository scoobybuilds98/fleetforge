<?php
declare(strict_types=1);

/**
 * api/v1/attention/show.php
 *
 * One Needs attention item with its history and the people it can be given
 * to (S-ATTENTION-INBOX). Used when an item is expanded on the full page.
 *
 * @method  GET
 * @auth    require_auth_api
 * @query   id  int (required)
 * @returns 200 { item, events[{action,note,who,at,at_label}], people[{id,name}] }
 *          404 when the item doesn't exist or the user can't see it
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
$role = (string) (current_user()['role_slug'] ?? '');

$id = clean_positive_int($_GET['id'] ?? null);
if ($id === null) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}

$row = AttentionService::findFor($id, $userId, $role);
if ($row === null) {
    json_error('NOT_FOUND', 'This item no longer exists or you can\'t see it.', 404);
}

// People who could take this item: active staff who can see it. Small team,
// so checking each is cheap and keeps "Give to" honest (no giving an item to
// someone who can't open it).
$people = [];
foreach (db_select(
    "SELECT u.id, u.name, r.slug FROM users u JOIN user_roles r ON r.id = u.role_id
      WHERE u.status = 'active' AND u.deleted_at IS NULL ORDER BY u.name"
) as $u) {
    if (AttentionService::findFor($id, (int) $u['id'], (string) $u['slug']) !== null) {
        $people[] = ['id' => (int) $u['id'], 'name' => (string) $u['name']];
    }
}

json_success([
    'item'   => AttentionService::present($row, $userId, can_view_financials()),
    'events' => AttentionService::events($id),
    'people' => $people,
]);
