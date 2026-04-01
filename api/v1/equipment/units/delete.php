<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/delete.php
 *
 * Soft-deletes an equipment unit. BLOCKED if the unit is currently on_lease
 * (status = 'on_lease'). Units in any other status can be soft-deleted.
 * The unit_number and all history remain in the DB for audit purposes.
 *
 * @method   POST
 * @body     JSON { id }
 * @required id
 * @auth     Session required; require_permission('equipment','delete')
 * @returns  200 on success
 *           422 LEASE_NOT_ACTIVE if unit status is 'on_lease'
 *           404 NOT_FOUND
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.4, §5 SOFT_DELETE_TABLES
 * @session  S006
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);
if (!$id) {
    json_error('VALIDATION_ERROR', 'id is required.', 422);
}

$unit = db_row(
    "SELECT id, unit_number, status FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// ── Block if unit is currently on lease ────────────────────────
// WHY: soft-deleting a unit on active lease would orphan the lease mid-term
if ($unit['status'] === 'on_lease') {
    // FIX #38: UNIT_ON_LEASE is semantically correct (unit IS on_lease, blocking delete)
    json_error(
        'UNIT_ON_LEASE',
        "Cannot delete unit {$unit['unit_number']}: it is currently on active lease. Close the lease first.",
        422
    );
}

$userId = current_user_id();

db_transaction(function () use ($id, $userId, $unit): void {
    db_execute(
        "UPDATE equipment_units SET deleted_at = NOW() WHERE id = ?",
        [$id]
    );

    db_insert('audit_log', [
        'user_id'      => $userId,
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $id,
        'entity_label' => $unit['unit_number'],
        'old_values'   => json_encode(['unit_number' => $unit['unit_number'], 'status' => $unit['status']]),
        'new_values'   => null,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
});

json_success(['id' => $id, 'deleted' => true]);
