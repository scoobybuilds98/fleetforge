<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Permanent Delete API
 *
 * @file        api/v1/yards/destroy.php
 * @description Soft-deletes a yard (sets deleted_at = NOW(), D5).
 *              Also sets is_active = 0 so the yard disappears from all
 *              dropdowns immediately. Historical yard_location text snapshots
 *              on reservations and equipment_units are unaffected (they are
 *              plain varchar strings, not FKs — yard history is preserved).
 *
 *              Guards:
 *              - Cannot delete a yard with ANY active or upcoming reservations
 *                (status pending/confirmed, not soft-deleted).
 *              - Cannot delete a yard that is already soft-deleted.
 *
 * @method      POST
 * @body        JSON — id (required)
 * @auth        Session required; super_admin or manager role
 * @returns     200 { id, deleted_at } | 422 | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @decisions   D5 (soft-delete)
 * @session     S-YARDS-DELETE
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
if (!in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager'])) {
    json_error('FORBIDDEN', 'Only super admins and managers can delete yards.', 403);
}

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$yard = db_row(
    "SELECT id, name, is_active, deleted_at FROM yards WHERE id = ?",
    [$id]
);
if (!$yard || $yard['deleted_at'] !== null) {
    json_error('NOT_FOUND', 'Yard not found.', 404);
}

// Guard: any active or upcoming reservations at this yard
$activeCount = db_count(
    "SELECT COUNT(*) FROM reservations
     WHERE yard_location = ?
       AND status IN ('pending','confirmed')
       AND deleted_at IS NULL",
    [$yard['name']]
);
if ($activeCount > 0) {
    json_error(
        'HAS_ACTIVE_RESERVATIONS',
        "Cannot delete '{$yard['name']}' — it has {$activeCount} active or upcoming reservation(s). " .
        "Cancel or move those reservations first.",
        422
    );
}

// MEDIUM [09]: yard membership is a denormalized name string on
// equipment_units.yard_location (no FK), so deleting a yard with units parked
// there orphans them (they keep pointing at a now-deleted yard name). Guard on
// it the same way as reservations — the operator must move the units first.
$parkedCount = db_count(
    "SELECT COUNT(*) FROM equipment_units
     WHERE yard_location = ? AND deleted_at IS NULL",
    [$yard['name']]
);
if ($parkedCount > 0) {
    json_error(
        'HAS_PARKED_UNITS',
        "Cannot delete '{$yard['name']}' — {$parkedCount} equipment unit(s) are parked here. " .
        "Move them to another yard first.",
        422
    );
}

$deletedAt = date('Y-m-d H:i:s');

db_transaction(function () use ($id, $yard, $deletedAt): void {
    db_execute(
        "UPDATE yards SET deleted_at = ?, is_active = 0, updated_at = NOW() WHERE id = ?",
        [$deletedAt, $id]
    );

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'settings',
        'entity_type'  => 'yard',
        'entity_id'    => $id,
        'entity_label' => $yard['name'],
        'old_values'   => json_encode(['deleted_at' => null, 'is_active' => (int) $yard['is_active']]),
        'new_values'   => json_encode(['deleted_at' => $deletedAt, 'is_active' => 0]),
        'notes'        => "Yard '{$yard['name']}' permanently deleted.",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $id, 'deleted_at' => $deletedAt]);
