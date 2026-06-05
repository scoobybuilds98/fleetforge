<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Delete API
 *
 * @file        api/v1/leases/delete.php
 * @description Soft-deletes a lease by setting deleted_at to NOW().
 *              Blocked if the lease is currently 'active' — must be closed first.
 *              Active leases have an associated unit on_lease; deleting without
 *              closing would leave the unit status inconsistent.
 *
 * @method      POST
 * @body        JSON — id (required)
 * @auth        Session required; require_permission('leases','delete')
 * @returns     200 on success | 409 LEASE_NOT_ACTIVE | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D5 (soft-delete), D20 (state machine)
 * @session     S007
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'delete');

$body = json_body();
$id   = clean_int($body['id'] ?? null);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$lease = db_row(
    "SELECT id, status, contract_number, company_name_snapshot, customer_id, equipment_unit_id
     FROM leases WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$lease) {
    json_error('NOT_FOUND', 'Lease not found.', 404);
}

// Active leases cannot be deleted — unit is on_lease; must close first
if ($lease['status'] === 'active') {
    json_error('LEASE_IS_ACTIVE',
        "Cannot delete lease {$lease['contract_number']}: it is currently active. Close the lease first.", 409);
}

// ── Soft-delete + audit + unit release ────────────────────────
db_transaction(function () use ($id, $lease) {
    db_execute(
        "UPDATE leases SET deleted_at = NOW(), updated_by = ? WHERE id = ?",
        [current_user_id(), $id]
    );

    // Decrement denormalized lease_count (tracks total leases regardless of status)
    if ($lease['customer_id']) {
        db_execute("UPDATE customers SET lease_count = GREATEST(0, lease_count - 1), updated_at = NOW() WHERE id = ?", [$lease['customer_id']]);
    }
    if ($lease['equipment_unit_id']) {
        db_execute("UPDATE equipment_units SET lease_count = GREATEST(0, lease_count - 1), updated_at = NOW() WHERE id = ?", [$lease['equipment_unit_id']]);
    }

    // FIX #25: when a pending lease is deleted, the equipment unit was set to
    // 'reserved' at lease creation. Release it back to 'available' so it can
    // be leased or reserved again — otherwise it gets permanently locked.
    if ($lease['status'] === 'pending' && $lease['equipment_unit_id']) {
        $unit = db_row(
            "SELECT id, status FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
            [$lease['equipment_unit_id']]
        );
        if ($unit && $unit['status'] === 'reserved') {
            db_execute(
                "UPDATE equipment_units SET status = 'available', updated_by = ?, updated_at = NOW()
                 WHERE id = ?",
                [current_user_id(), $lease['equipment_unit_id']]
            );
            db_insert('equipment_status_log', [
                'equipment_unit_id'  => $lease['equipment_unit_id'],
                'old_status'         => 'reserved',
                'new_status'         => 'available',
                'reason'             => "Lease {$lease['contract_number']} deleted — unit released",
                'changed_by'         => current_user()['name'] ?? 'system',
                'changed_by_user_id' => current_user_id(),
            ]);
        }
    }

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'delete',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => "Lease {$lease['contract_number']} soft-deleted",
        'old_values'   => json_encode(['status' => $lease['status']]),
        'new_values'   => null,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

invalidate_dashboard_cache();

json_success(['id' => $id]);
