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
$fields       = [];

$id           = clean_int($body['id'] ?? null);
$targetStatus = clean_string($body['status'] ?? null, 20);
$cancelReason = clean_string($body['cancel_reason'] ?? null, 1000);

// ── VALID-2: accumulate required field errors ─────────────────
$allowedStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];
if (!$id) {
    $fields['id'] = 'Reservation ID is required.';
}
if (!$targetStatus) {
    $fields['status'] = 'Please select a status.';
} elseif (!in_array($targetStatus, $allowedStatuses, true)) {
    $fields['status'] = 'Please select a valid status.';
}
if ($fields) {
    json_validation_error($fields);
}

// ── Delegate to the shared action service (S-AI-ACTION-4) ──────
// State machine, unit status transitions, conflict re-check, manager-reversal
// gate, audit, and notifications all live in StatusActions::changeReservationStatus
// so the AI confirm→apply path runs the exact same logic. ActionException →
// json_error preserves the old inline precondition responses.
try {
    $result = \FleetForge\AI\Actions\StatusActions::changeReservationStatus(
        $id, $targetStatus, $cancelReason,
        current_user_id(), current_user()['name'] ?? 'system',
        current_user()['role_slug'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status, ['fields' => ['status' => $e->getMessage()]]);
}

json_success($result);
