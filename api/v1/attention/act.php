<?php
declare(strict_types=1);

/**
 * api/v1/attention/act.php
 *
 * Take / release / give / snooze / wake / done / reopen / note on a Needs
 * attention item (S-ATTENTION-INBOX). Items are shared: the change is what
 * everyone who can see the item now sees, and it's written to the item's
 * history with the person's name.
 *
 * @method  POST
 * @auth    require_auth_api + CSRF (api/bootstrap.php)
 * @body    { item_id: int, action: take|release|assign|snooze|wake|done|reopen|note,
 *            until?: tomorrow|monday|week|YYYY-MM-DD   (snooze)
 *            note?: string ≤ 500                        (done — required while the
 *                                                        problem is still there — / note / reopen)
 *            user_id?: int                              (assign) }
 * @returns 200 { item, counts }
 *          404 not found / not visible · 409 wrong state · 422 bad input
 *
 * @session S-ATTENTION-INBOX
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

use FleetForge\Attention\AttentionService;

require_method('POST');
require_auth_api();

$userId = current_user_id();
if (!$userId) {
    json_error('UNAUTHORIZED', 'No authenticated user.', 401);
}
$role = (string) (current_user()['role_slug'] ?? '');
$body = json_body();

$itemId = clean_positive_int($body['item_id'] ?? null);
$action = (string) ($body['action'] ?? '');
if ($itemId === null || !in_array($action, AttentionService::ACTIONS, true)) {
    json_error('VALIDATION_ERROR', 'item_id and a valid action are required.', 422);
}

try {
    $row = AttentionService::act($itemId, $userId, $role, $action, [
        'until'   => (string) ($body['until'] ?? ''),
        'note'    => (string) ($body['note'] ?? ''),
        'user_id' => clean_positive_int($body['user_id'] ?? null) ?? 0,
    ]);
} catch (\InvalidArgumentException $e) {
    json_error('VALIDATION_ERROR', $e->getMessage(), 422);
} catch (\DomainException $e) {
    // Not visible / gone → 404; otherwise it's a state conflict.
    $gone = str_contains($e->getMessage(), 'no longer exists');
    json_error($gone ? 'NOT_FOUND' : 'CONFLICT', $e->getMessage(), $gone ? 404 : 409);
}

json_success([
    'item'   => AttentionService::present($row, $userId, can_view_financials()),
    'counts' => AttentionService::badge($userId, $role),
]);
