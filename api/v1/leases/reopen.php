<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Reopen API
 *
 * @file        api/v1/leases/reopen.php
 * @description Reopens a completed lease back to active. Manager role required.
 *
 *              State machine (spec §6):
 *                completed → active (Manager/super_admin + reason required)
 *
 *              On reopen:
 *                1. Require Manager role + reopen_reason
 *                2. FOR UPDATE on unit — unit must be available
 *                3. Lease status → active
 *                4. Unit status → on_lease
 *                5. Write equipment_status_log, lease_status_log, audit_log
 *
 * @method      POST
 * @body        JSON — id (required), reopen_reason (required)
 * @auth        Session required; Manager or super_admin role required
 * @returns     200 { id, status } | 409 INVALID_TRANSITION | 409 UNIT_UNAVAILABLE | 403 FORBIDDEN
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases, §6 state machine
 * @decisions   D20 (FOR UPDATE on unit)
 * @session     S008.5
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body         = json_body();
$id           = clean_int($body['id'] ?? null);
$reopenReason = clean_string($body['reopen_reason'] ?? null, 1000);

if (!$id) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

if (!$reopenReason) {
    json_error('VALIDATION_ERROR', 'reopen_reason is required.', 422);
}

// Manager role required — reopening is a high-stakes override
// FIX: was checking $user['role'] which doesn't exist; correct key is 'role_slug'
$user = current_user();
$role = $user['role_slug'] ?? '';
if (!in_array($role, ['super_admin', 'manager'])) {
    json_error('FORBIDDEN', 'Reopening a completed lease requires Manager role.', 403);
}

$result = null;

db_transaction(function () use ($id, $reopenReason, &$result) {
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
    if ($lease['status'] !== 'completed') {
        json_error('INVALID_TRANSITION',
            "Cannot reopen lease {$lease['contract_number']}: status is '{$lease['status']}'. Only completed leases can be reopened.", 409);
    }

    // ── D20: FOR UPDATE lock on unit — must be available ──────
    $unit = db_row(
        "SELECT id, unit_number, status FROM equipment_units
         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$lease['equipment_unit_id']]
    );

    if (!$unit) {
        json_error('NOT_FOUND', 'Equipment unit not found.', 404);
    }

    // Unit must be available to reopen — can't reopen if unit is on another lease
    if ($unit['status'] !== 'available') {
        json_error('UNIT_UNAVAILABLE',
            "Cannot reopen: unit {$unit['unit_number']} is not available (status: {$unit['status']}).", 409);
    }

    $changedBy = current_user()['name'] ?? 'system';

    // ── Update lease → active ──────────────────────────────────
    // FIX #30: reset next_billing_date to first day of next month so the billing
    //           cron doesn't immediately invoice a stale past date.
    // FIX #31: clear mileage_at_end and actual_mileage — stale from previous close;
    //           these will be re-captured at the next close.
    $nextBillingDate = date('Y-m-d', strtotime('first day of next month'));
    db_execute(
        "UPDATE leases
         SET status = 'active',
             actual_return_date = NULL,
             closed_at = NULL,
             closed_by_user_id = NULL,
             mileage_at_end = NULL,
             actual_mileage = 0,
             next_billing_date = ?,
             updated_by = ?,
             updated_at = NOW()
         WHERE id = ?",
        [$nextBillingDate, current_user_id(), $id]
    );

    // ── Update unit → on_lease ─────────────────────────────────
    db_execute(
        "UPDATE equipment_units SET status = 'on_lease', updated_by = ?, updated_at = NOW() WHERE id = ?",
        [current_user_id(), $lease['equipment_unit_id']]
    );

    // ── equipment_status_log ───────────────────────────────────
    db_insert('equipment_status_log', [
        'equipment_unit_id'  => $lease['equipment_unit_id'],
        'old_status'         => 'available',
        'new_status'         => 'on_lease',
        'reason'             => "Lease {$lease['contract_number']} reopened: {$reopenReason}",
        'changed_by'         => $changedBy,
        'changed_by_user_id' => current_user_id(),
    ]);

    // ── lease_status_log ───────────────────────────────────────
    db_insert('lease_status_log', [
        'lease_id'   => $id,
        'old_status' => 'completed',
        'new_status' => 'active',
        'notes'      => "Reopened by manager: {$reopenReason}",
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
        'notes'        => "Lease {$lease['contract_number']} reopened by manager (completed → active): {$reopenReason}",
        'old_values'   => json_encode(['status' => 'completed']),
        'new_values'   => json_encode(['status' => 'active', 'reopen_reason' => $reopenReason]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = ['id' => $id, 'status' => 'active'];

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'lease.reopened',
            title:      "Lease {$lease['contract_number']} reopened",
            message:    "Lease {$lease['contract_number']} reopened by {$changedBy}: {$reopenReason}",
            entityType: 'lease',
            entityId:   $id,
            url:        '/fleetforge/leases/show?id=' . $id,
            severity:   'warning'
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF lease.reopened] ' . $e->getMessage());
    }
});

json_success($result);
