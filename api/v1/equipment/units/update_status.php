<?php
declare(strict_types=1);

/**
 * FleetForge — Equipment Unit Status Change API
 *
 * @file        api/v1/equipment/units/update_status.php
 * @description Changes equipment unit status with state machine validation.
 *              Implicit status changes (via lease activate/close) bypass this
 *              endpoint. This handles all operator-initiated changes.
 *
 *              Valid transitions (spec §11):
 *                available   → reserved, on_lease, maintenance, inactive
 *                reserved    → on_lease, available
 *                on_lease    → available, maintenance
 *                maintenance → available, inactive
 *                inactive    → available
 *                decommissioned → TERMINAL (no transitions out)
 *
 *              All transitions: validate, FOR UPDATE, write equipment_status_log + audit_log.
 *
 * @method      POST
 * @body        JSON — id (required), new_status (required), reason (optional)
 * @auth        Session required; require_permission('equipment','edit')
 * @returns     200 { id, old_status, new_status } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §11 Equipment State Machine
 * @decisions   D20 (FOR UPDATE on unit row)
 * @session     S008.5
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body      = json_body();
$id        = clean_int($body['id'] ?? null);
$newStatus = clean_string($body['new_status'] ?? null);
$reason    = clean_string($body['reason'] ?? null, 1000);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}
if (!$newStatus) {
    json_error('MISSING_REQUIRED', 'new_status is required.', 422);
}

// ── Valid transitions (spec §11) ───────────────────────────────
// Keyed by current status → array of allowed target statuses.
// FIX #11: 'decommissioned' was unreachable — add it as a target from
//          maintenance and inactive (the two states where a unit is out of service).
// FIX #17: removed 'on_lease' from the 'available' transition — on_lease should only
//          be set implicitly via lease/activate.php, not via manual status change.
//          Manual assignment to on_lease without a lease record creates data inconsistency.
const UNIT_STATUS_TRANSITIONS = [
    'available'      => ['reserved', 'maintenance', 'inactive'],
    'reserved'       => ['available'],
    'on_lease'       => ['available', 'maintenance'],
    'maintenance'    => ['available', 'inactive', 'decommissioned'],
    'inactive'       => ['available', 'decommissioned'],
    'decommissioned' => [], // TERMINAL
];

$result = null;

db_transaction(function () use ($id, $newStatus, $reason, &$result) {
    // ── D20: FOR UPDATE — lock unit row ────────────────────────
    $unit = db_row(
        "SELECT id, unit_number, status FROM equipment_units
         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    $oldStatus   = $unit['status'];
    $allowedNext = UNIT_STATUS_TRANSITIONS[$oldStatus] ?? [];

    // No-op check: treat same→same as valid (idempotent)
    if ($oldStatus === $newStatus) {
        $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus];
        return;
    }

    if (!in_array($newStatus, $allowedNext, true)) {
        json_error('INVALID_TRANSITION',
            "Cannot change unit {$unit['unit_number']} status from '{$oldStatus}' to '{$newStatus}'.", 409);
    }

    $changedBy = current_user()['name'] ?? 'system';

    // ── Update unit status ─────────────────────────────────────
    db_execute(
        "UPDATE equipment_units SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
        [$newStatus, current_user_id(), $id]
    );

    // ── equipment_status_log ───────────────────────────────────
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $id,
        'old_status'         => $oldStatus,
        'new_status'         => $newStatus,
        'reason'             => $reason ?? "Manual status change: {$oldStatus} → {$newStatus}",
        'changed_by'         => $changedBy,
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── audit_log ──────────────────────────────────────────────
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => $changedBy,
        'action'       => 'status_change',
        'module'       => 'equipment',
        'entity_type'  => 'equipment_unit',
        'entity_id'    => $id,
        'entity_label' => $unit['unit_number'],
        'notes'        => "Unit {$unit['unit_number']} status changed: {$oldStatus} → {$newStatus}",
        'old_values'   => json_encode(['status' => $oldStatus]),
        'new_values'   => json_encode(['status' => $newStatus, 'reason' => $reason]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus];
});

json_success($result);
