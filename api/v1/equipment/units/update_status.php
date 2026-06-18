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

// ── Delegate to the shared action service (S-AI-ACTION-1) ──────
// The state-machine guard + status-log + audit + notification all live in
// StatusActions::changeEquipmentStatus so the AI confirm→apply path runs the
// exact same logic. ActionException → json_error mirrors the old inline guards.
try {
    $result = \FleetForge\AI\Actions\StatusActions::changeEquipmentStatus(
        $id, $newStatus, $reason,
        current_user_id(), current_user()['name'] ?? 'system',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status);
}

json_success(['id' => $result['id'], 'old_status' => $result['old_status'], 'new_status' => $result['new_status']]);
