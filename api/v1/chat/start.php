<?php
/**
 * api/v1/chat/start.php
 *
 * POST — open (or reuse) a conversation, returns { id }.
 *   { kind: 'direct',   user_id }              one DM per pair, reused
 *   { kind: 'group',    title, user_ids: [] }  2+ other staff
 *   { kind: 'customer', customer_id }          one thread per customer, reused (customers.view)
 *
 * Dependencies: api/bootstrap.php, lib/Chat/Conversations.php
 * @session S-CHAT-REBUILD
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_method('POST');
require_auth_api();

use FleetForge\Chat\Conversations;

$viewer = Conversations::staffViewer();
$me     = $viewer['user_id'];
$kind   = clean_string($_POST['kind'] ?? null, 20) ?? '';

$activeStaff = function (array $ids) use ($me): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0 && $i !== $me)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_map('intval', array_column(db_select(
        "SELECT id FROM users WHERE id IN ({$in}) AND deleted_at IS NULL AND status = 'active'",
        $ids
    ), 'id'));
};

switch ($kind) {
    case 'direct':
        $other = $activeStaff([$_POST['user_id'] ?? 0]);
        if (!$other) json_error('VALIDATION_ERROR', 'Pick a teammate to message.', 422);
        json_success(['id' => Conversations::openDirect($me, $other[0])], 201);

    case 'group':
        $title   = clean_string($_POST['title'] ?? null, 120) ?? '';
        $members = $activeStaff(is_array($_POST['user_ids'] ?? null) ? $_POST['user_ids'] : []);
        if ($title === '') json_error('VALIDATION_ERROR', 'Give the group a name.', 422);
        if (count($members) < 2) json_error('VALIDATION_ERROR', 'A group needs at least two other people. For one person, start a direct message.', 422);
        json_success(['id' => Conversations::createGroup($me, $title, $members)], 201);

    case 'customer':
        if (!$viewer['customers']) json_error('FORBIDDEN', 'You do not have permission to message customers.', 403);
        $cid = clean_int($_POST['customer_id'] ?? null) ?? 0;
        if (!$cid || !db_count('SELECT COUNT(*) FROM customers WHERE id = ? AND deleted_at IS NULL', [$cid])) {
            json_error('NOT_FOUND', 'Customer not found.', 404);
        }
        json_success(['id' => Conversations::openCustomer($cid, $me)], 201);

    default:
        json_error('VALIDATION_ERROR', 'kind must be direct, group or customer.', 422);
}
