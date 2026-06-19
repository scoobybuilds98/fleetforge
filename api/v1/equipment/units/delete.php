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
 * @session  SAMSARA-3 — originally deleted the trailer from Samsara on soft-delete
 * @session  S-SAMSARA-DELETE-DECOUPLE — D-SAMSARA-DELETE-1: unit delete no longer
 *           propagates to Samsara. Use api/v1/samsara/unlink.php to intentionally
 *           decouple (nulls the link without destroying either record).
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
    "SELECT id, unit_number, status
       FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
    [$id]
);
if (!$unit) {
    json_error('NOT_FOUND', 'Equipment unit not found.', 404);
}

// ── Block if unit is active or reserved ───────────────────────
// FIX #5: also block 'reserved' — deleting a reserved unit orphans the reservation
if ($unit['status'] === 'on_lease') {
    json_error(
        'UNIT_ON_LEASE',
        "Cannot delete unit {$unit['unit_number']}: it is currently on active lease. Close the lease first.",
        422
    );
}
if ($unit['status'] === 'reserved') {
    json_error(
        'UNIT_RESERVED',
        "Cannot delete unit {$unit['unit_number']}: it is reserved for an active reservation. Cancel the reservation first.",
        422
    );
}

$userId = current_user_id();

$deleted = db_transaction(function () use ($id, $userId, $unit): bool {
    // E11: gate the soft-delete on the unit STILL being deletable. status +
    // deleted_at were read unlocked above, so a concurrent lease activation or
    // reservation could have flipped this unit to on_lease/reserved since the
    // guard — an unconditional UPDATE would soft-delete it anyway and orphan the
    // live lease/reservation. 0 rows affected = state changed → abort the delete.
    $affected = db_execute(
        "UPDATE equipment_units
            SET deleted_at = NOW(), updated_by = ?
          WHERE id = ?
            AND deleted_at IS NULL
            AND status NOT IN ('on_lease', 'reserved')",
        [$userId, $id]
    );
    if ($affected === 0) {
        return false;
    }

    // FIX #33: write a status log entry on deletion for full history
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $id,
        'old_status'         => $unit['status'],
        'new_status'         => 'deleted',
        'reason'             => "Unit soft-deleted by {$userId}",
        'changed_by'         => current_user()['name'] ?? 'system',
        'changed_by_user_id' => $userId,
    ]);

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

    return true;
});

if (!$deleted) {
    json_error('UNIT_STATE_CHANGED',
        "Cannot delete unit {$unit['unit_number']}: its status changed (it is now on lease or reserved, or was already deleted). Refresh and try again.", 409);
}

// D-SAMSARA-DELETE-1: unit delete does NOT propagate to Samsara (use samsara/unlink.php to intentionally decouple).

invalidate_dashboard_cache();

json_success(['id' => $id, 'deleted' => true]);
