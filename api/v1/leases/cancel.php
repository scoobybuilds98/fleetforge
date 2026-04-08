<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Cancel API
 *
 * @file        api/v1/leases/cancel.php
 * @description Cancels a lease. Valid from pending or active status.
 *
 *              State machine (spec §6):
 *                pending → cancelled (any role with leases.edit)
 *                active  → cancelled (Manager role only — requires reason)
 *
 *              On cancel from pending:
 *                1. Lease status → cancelled
 *                2. Unit status: reserved → available
 *                3. Write equipment_status_log, lease_status_log, audit_log
 *
 *              On cancel from active:
 *                1. Require Manager role + cancel_reason
 *                2. Lease status → cancelled
 *                3. Unit status: on_lease → available
 *                4. Write equipment_status_log, lease_status_log, audit_log
 *
 * @method      POST
 * @body        JSON — id (required), cancel_reason (required when cancelling active lease)
 * @auth        Session required; require_permission('leases','edit')
 *              Active lease cancel additionally requires Manager or super_admin role.
 * @returns     200 { id, status } | 409 INVALID_TRANSITION | 403 FORBIDDEN | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §6 state machine
 * @decisions   D20 (FOR UPDATE on unit), spec §6 state machine
 * @session     S008.5
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body         = json_body();
$id           = clean_int($body['id'] ?? null);
$cancelReason = clean_string($body['cancel_reason'] ?? null, 1000);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$result = null;

db_transaction(function () use ($id, $cancelReason, &$result) {
    // ── Fetch lease ────────────────────────────────────────────
    $lease = db_row(
        "SELECT id, status, contract_number, company_name_snapshot, equipment_unit_id, unit_number_snapshot
         FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$lease) {
        json_error('NOT_FOUND', 'Lease not found.', 404);
    }

    // ── State machine validation ───────────────────────────────
    if (!in_array($lease['status'], ['pending', 'active'])) {
        json_error('INVALID_TRANSITION',
            "Cannot cancel lease {$lease['contract_number']}: status is '{$lease['status']}'. Only pending or active leases can be cancelled.", 409);
    }

    // Active leases require Manager role + reason
    if ($lease['status'] === 'active') {
        $user = current_user();
        $role = $user['role'] ?? '';
        if (!in_array($role, ['super_admin', 'manager'])) {
            json_error('FORBIDDEN', 'Cancelling an active lease requires Manager role.', 403);
        }
        if (!$cancelReason) {
            json_error('VALIDATION_ERROR', 'cancel_reason is required when cancelling an active lease.', 422);
        }
    }

    // ── D20: FOR UPDATE lock on unit ───────────────────────────
    $unit = db_row(
        "SELECT id, unit_number, status FROM equipment_units
         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$lease['equipment_unit_id']]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    $oldUnitStatus = $unit['status'];
    $user          = current_user();
    $changedBy     = $user['name'] ?? 'system';

    // ── Update lease → cancelled ───────────────────────────────
    db_execute(
        "UPDATE leases SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ?",
        [current_user_id(), $id]
    );

    // ── Return unit to available ───────────────────────────────
    db_execute(
        "UPDATE equipment_units SET status = 'available', updated_by = ?, updated_at = NOW() WHERE id = ?",
        [current_user_id(), $lease['equipment_unit_id']]
    );

    // ── equipment_status_log ───────────────────────────────────
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $lease['equipment_unit_id'],
        'old_status'         => $oldUnitStatus,
        'new_status'         => 'available',
        'reason'             => "Lease {$lease['contract_number']} cancelled" . ($cancelReason ? ": {$cancelReason}" : ''),
        'changed_by'         => $changedBy,
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log ───────────────────────────────────────
    db_insert('lease_status_log', [
        'lease_id'   => $id,
        'old_status' => $lease['status'],
        'new_status' => 'cancelled',
        'notes'      => $cancelReason ?? 'Lease cancelled',
        'changed_by' => $changedBy,
        'user_id'    => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'status_change',
        'module'       => 'leases',
        'entity_type'  => 'lease',
        'entity_id'    => $id,
        'entity_label' => $lease['contract_number'],
        'notes'        => "Lease {$lease['contract_number']} cancelled ({$lease['status']} → cancelled)",
        'old_values'   => json_encode(['status' => $lease['status']]),
        'new_values'   => json_encode(['status' => 'cancelled', 'cancel_reason' => $cancelReason]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = ['id' => $id, 'status' => 'cancelled'];

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'lease.cancelled',
            title:      "Lease {$lease['contract_number']} cancelled",
            message:    "Lease {$lease['contract_number']} cancelled" . ($cancelReason ? ": {$cancelReason}" : ''),
            entityType: 'lease',
            entityId:   $id,
            url:        '/fleetforge/leases/show?id=' . $id,
            severity:   'warning'
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF lease.cancelled] ' . $e->getMessage());
    }
});

json_success($result);
