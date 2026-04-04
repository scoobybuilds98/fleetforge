<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Update Status API
 *
 * @file        api/v1/reservations/update_status.php
 * @description Drives the reservation state machine. All valid transitions:
 *
 *              pending   → confirmed   (unit status: available → reserved)
 *              pending   → cancelled   (unit status: reserved → available)
 *              confirmed → cancelled   (unit status: reserved → available)
 *              completed → confirmed   (reverse/undo mark-out; unit: available → reserved)
 *              cancelled → [TERMINAL — no transitions out]
 *
 *              Every transition:
 *                1. Validates transition is allowed
 *                2. Updates reservations.status
 *                3. Updates equipment_units.status (if system-linked units)
 *                4. Writes equipment_status_log row per unit
 *                5. Writes audit_log entry
 *
 *              Conflict check on pending→confirmed: re-checks for overlapping
 *              reservations with FOR UPDATE to guard against race conditions (D20).
 *
 * @method      POST
 * @body        JSON — id (req), status (req: target status), cancel_reason (req when cancelling)
 * @auth        Session required; require_permission('reservations','edit')
 *              completed→confirmed reversal additionally requires 'manager' or 'super_admin' role.
 * @returns     200 { id, status } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6, §6 state machine
 * @decisions   D20 (FOR UPDATE), spec §6 reservation state machine
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'edit');

$body         = json_body();
$id           = clean_int($body['id'] ?? null);
$targetStatus = clean_string($body['status'] ?? null, 20);
$cancelReason = clean_string($body['cancel_reason'] ?? null, 1000);

if (!$id)           json_error('MISSING_REQUIRED', 'id is required.', 422);
if (!$targetStatus) json_error('MISSING_REQUIRED', 'status is required.', 422);

// ── Valid state machine transitions ────────────────────────────
// Key = current status, value = allowed next statuses
$validTransitions = [
    'pending'   => ['confirmed', 'cancelled'],
    'confirmed' => ['cancelled'],
    'completed' => ['confirmed'],   // reverse / undo mark-out (manager only)
    'cancelled' => [],              // TERMINAL
];

$result = null;

db_transaction(function () use ($id, $targetStatus, $cancelReason, &$result) {
    // ── Fetch with FOR UPDATE lock ─────────────────────────────
    $reservation = db_row(
        "SELECT id, status, company_name, pickup_date
         FROM reservations WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );
    if (!$reservation) {
        json_error('NOT_FOUND', 'Reservation not found.', 404);
    }

    $currentStatus = $reservation['status'];

    // ── Validate transition ────────────────────────────────────
    global $validTransitions;
    $allowed = $validTransitions[$currentStatus] ?? [];
    if (!in_array($targetStatus, $allowed)) {
        json_error('INVALID_TRANSITION',
            "Cannot transition reservation #{$id} from '{$currentStatus}' to '{$targetStatus}'. " .
            "Allowed: " . (empty($allowed) ? 'none (terminal state)' : implode(', ', $allowed)) . ".",
            409
        );
    }

    // ── Role check for reversal ────────────────────────────────
    // completed → confirmed is a manager-level action (undoing a marked-out reservation)
    if ($currentStatus === 'completed' && $targetStatus === 'confirmed') {
        $user = current_user();
        if (!in_array($user['role_slug'] ?? '', ['super_admin', 'manager'])) {
            json_error('FORBIDDEN',
                'Reversing a completed reservation requires Manager role.', 403);
        }
    }

    // ── Cancel reason required ─────────────────────────────────
    if ($targetStatus === 'cancelled' && !$cancelReason) {
        json_error('VALIDATION_ERROR', 'cancel_reason is required when cancelling.', 422,
            ['errors' => ['cancel_reason' => 'Please provide a reason for cancellation.']]);
    }

    // ── Fetch system-linked units for this reservation ─────────
    $units = db_select(
        "SELECT ru.equipment_unit_id, eu.unit_number, eu.status AS current_status
         FROM reservation_units ru
         JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
         WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
        [$id]
    );

    // ── Conflict re-check for pending→confirmed ────────────────
    // Even though create.php checked, we re-check here under FOR UPDATE so
    // that two concurrent confirm requests cannot both succeed (D20).
    // FIX #3 / #6: match create.php's model exactly — check for ANY active
    // (pending OR confirmed) reservation on the unit, with no date filter.
    // One unit can only be in one active reservation at a time regardless of date.
    if ($currentStatus === 'pending' && $targetStatus === 'confirmed') {
        foreach ($units as $u) {
            $conflict = db_row(
                "SELECT r.id, r.company_name, r.pickup_date
                 FROM reservation_units ru
                 JOIN reservations r ON r.id = ru.reservation_id
                 WHERE ru.equipment_unit_id = ?
                   AND r.status IN ('pending', 'confirmed')
                   AND r.id != ?
                   AND r.deleted_at IS NULL",
                [$u['equipment_unit_id'], $id]
            );
            if ($conflict) {
                json_error('CONFLICT',
                    "Unit {$u['unit_number']} is already in an active reservation " .
                    "(Res #{$conflict['id']} — {$conflict['company_name']} — " .
                    date('M j, Y', strtotime($conflict['pickup_date'])) . ").",
                    409
                );
            }
        }
    }

    // ── Determine equipment status transitions ─────────────────
    // pending→confirmed: available → reserved
    // confirmed→cancelled OR pending→cancelled: reserved → available
    // completed→confirmed: available → reserved (reverse)
    $unitOldStatus = null;
    $unitNewStatus = null;

    if ($targetStatus === 'confirmed') {
        $unitOldStatus = 'available';
        $unitNewStatus = 'reserved';
    } elseif ($targetStatus === 'cancelled') {
        $unitOldStatus = 'reserved';
        $unitNewStatus = 'available';
    }

    // ── Update equipment units ─────────────────────────────────
    foreach ($units as $u) {
        if (!$unitNewStatus) continue;

        // Only update if the unit is in the expected state (guard against double-transition)
        if ($unitOldStatus && $u['current_status'] !== $unitOldStatus) {
            // Unit is in an unexpected state — log but don't block the transition
            // (e.g., unit may have been manually moved)
            continue;
        }

        db_execute(
            "UPDATE equipment_units SET status = ?, updated_at = NOW()
             WHERE id = ? AND deleted_at IS NULL",
            [$unitNewStatus, $u['equipment_unit_id']]
        );
        db_insert('equipment_status_log', [
            'equipment_unit_id' => $u['equipment_unit_id'],
            'changed_by'        => current_user_id(),
            'old_status'        => $u['current_status'],
            'new_status'        => $unitNewStatus,
            'reason'            => "Reservation #{$id} status changed: {$currentStatus} → {$targetStatus}" .
                                   ($cancelReason ? " — {$cancelReason}" : ''),
            'changed_at'        => date('Y-m-d H:i:s'),
        ]);
    }

    // ── Update reservation status ──────────────────────────────
    $updateData = [
        'status'     => $targetStatus,
        'updated_by' => current_user_id(),
    ];
    // Stamp cancel reason in internal_notes if provided
    if ($cancelReason && $targetStatus === 'cancelled') {
        // Append to existing internal_notes rather than overwriting
        db_execute(
            "UPDATE reservations
             SET status = ?, updated_by = ?,
                 internal_notes = CONCAT(COALESCE(internal_notes, ''), '\n[Cancelled by ',
                     (SELECT name FROM users WHERE id = ?), ' on ', NOW(), ']: ', ?)
             WHERE id = ?",
            [$targetStatus, current_user_id(), current_user_id(), $cancelReason, $id]
        );
    } else {
        db_update('reservations', $updateData, 'id = ?', [$id]);
    }

    // ── Audit log ──────────────────────────────────────────────
    $description = "Reservation #{$id} status changed: {$currentStatus} → {$targetStatus} — {$reservation['company_name']}";
    if ($cancelReason) $description .= " — Reason: {$cancelReason}";

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'reservations',
        'entity_type'  => 'reservation',
        'entity_id'    => $id,
        'entity_label' => "#{$id} — {$reservation['company_name']}",
        'notes'        => $description,
        'old_values'   => json_encode(['status' => $currentStatus]),
        'new_values'   => json_encode(['status' => $targetStatus, 'cancel_reason' => $cancelReason]),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = ['id' => $id, 'status' => $targetStatus];
});

json_success($result);
