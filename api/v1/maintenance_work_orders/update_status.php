<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/update_status.php
 *
 * Transition a work order through its state machine.
 *
 * State machine (spec §11):
 *   open         → in_progress, cancelled
 *   in_progress  → waiting_parts, completed
 *   waiting_parts → in_progress
 *   completed    → [TERMINAL]
 *   cancelled    → [TERMINAL]
 *
 * On completion:
 *   - completed_date set to today.
 *   - completed_by set to current user.
 *   - resolution_notes saved if provided.
 *   - If vendor_id is set: UPDATE vendors SET total_spent = total_spent + total_cost
 *     in the SAME transaction (Trap 6 — denormalized counter).
 *
 * No dedicated work_order_status_log table exists in schema.
 * All status history is written to audit_log (action='status_change').
 *
 * @method  POST
 * @body    JSON: id (required), new_status (required), reason?, resolution_notes?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, old_status, new_status } | 409 INVALID_TRANSITION | 404 NOT_FOUND
 *
 * Decisions: D5 (soft delete), D7 (routing), §6 (state machine), Trap 6 (denorm counter)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

$body      = json_body();
$fields    = [];

$id        = clean_int($body['id'] ?? null);
$newStatus = clean_string($body['new_status'] ?? null);
$reason    = clean_string($body['reason'] ?? null, 1000);
$resNotes  = clean_string($body['resolution_notes'] ?? null, 5000);

if (!$id) {
    $fields['id'] = 'Work order ID is required.';
}

$validStatuses = ['open', 'in_progress', 'waiting_parts', 'completed', 'cancelled'];
if (!$newStatus) {
    $fields['new_status'] = 'Please select a status.';
} elseif (!in_array($newStatus, $validStatuses, true)) {
    $fields['new_status'] = 'Please select a valid status.';
}

if ($fields) {
    json_validation_error($fields);
}

// ── Delegate to the shared action service (S-AI-ACTION-5) ──────
// State machine, completion stamping, Trap 6 counter bumps (vendor total_spent
// + equipment total_maintenance_cost), audit, and notifications all live in
// StatusActions::changeWorkOrderStatus so the AI confirm→apply path runs the
// exact same logic. ActionException → json_error keeps the field-map shape.
try {
    $result = \FleetForge\AI\Actions\StatusActions::changeWorkOrderStatus(
        $id, $newStatus, $reason, $resNotes,
        current_user_id(), current_user()['name'] ?? 'system',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );
} catch (\FleetForge\AI\Actions\ActionException $e) {
    json_error($e->errorCode, $e->getMessage(), $e->status, ['fields' => ['new_status' => $e->getMessage()]]);
}

json_success(['id' => $result['id'], 'old_status' => $result['old_status'], 'new_status' => $result['new_status']]);
