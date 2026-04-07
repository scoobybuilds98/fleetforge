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

// Valid state machine transitions (spec §11)
const WO_STATUS_TRANSITIONS = [
    'open'          => ['in_progress', 'cancelled'],
    'in_progress'   => ['waiting_parts', 'completed'],
    'waiting_parts' => ['in_progress'],
    'completed'     => [],  // TERMINAL
    'cancelled'     => [],  // TERMINAL
];

$result = null;

db_transaction(function() use ($id, $newStatus, $reason, $resNotes, &$result) {
    // Lock the work order row for update
    $wo = db_row(
        "SELECT id, work_order_number, status, vendor_id, total_cost
         FROM maintenance_work_orders
         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
        [$id]
    );

    if (!$wo) {
        json_error('NOT_FOUND', 'Work order not found.', 404);
    }

    $oldStatus   = $wo['status'];
    $allowedNext = WO_STATUS_TRANSITIONS[$oldStatus] ?? [];

    // Idempotent: same→same is a no-op
    if ($oldStatus === $newStatus) {
        $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus];
        return;
    }

    if (!in_array($newStatus, $allowedNext, true)) {
        json_error('INVALID_TRANSITION',
            "Cannot transition work order {$wo['work_order_number']} from '{$oldStatus}' to '{$newStatus}'.", 409,
            ['fields' => ['new_status' => "Cannot move a work order from '{$oldStatus}' to '{$newStatus}'."]]);
    }

    // Build the UPDATE fields
    $setClause  = 'status = ?';
    $setParams  = [$newStatus];

    if ($newStatus === 'completed') {
        $setClause .= ', completed_date = CURDATE(), completed_by = ?';
        $setParams[] = current_user_id();

        if ($resNotes !== null) {
            $setClause .= ', resolution_notes = ?';
            $setParams[] = $resNotes;
        }
    }

    $setParams[] = $id;
    db_execute("UPDATE maintenance_work_orders SET $setClause WHERE id = ?", $setParams);

    // Trap 6 — update vendors.total_spent in the SAME transaction on completion
    if ($newStatus === 'completed' && $wo['vendor_id'] !== null) {
        $totalCost = $wo['total_cost'] ?? '0.00';
        db_execute(
            "UPDATE vendors SET total_spent = total_spent + ? WHERE id = ?",
            [$totalCost, $wo['vendor_id']]
        );
    }

    // Audit log — status_change action
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'status_change',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $id,
        'entity_label' => $wo['work_order_number'],
        'old_values'   => json_encode(['status' => $oldStatus]),
        'new_values'   => json_encode([
            'status' => $newStatus,
            'reason' => $reason,
        ]),
        'notes'        => "Work order {$wo['work_order_number']}: {$oldStatus} → {$newStatus}"
                         . ($reason ? " — {$reason}" : ''),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $result = ['id' => $id, 'old_status' => $oldStatus, 'new_status' => $newStatus];
});

json_success($result);
