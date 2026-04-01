<?php
declare(strict_types=1);

/**
 * api/v1/damage_claims/update.php
 *
 * Update a damage claim — metadata fields and/or status transition.
 *
 * Two operations in one endpoint:
 *   A) Metadata update: description, damage_location, severity, notes,
 *      resolution_notes, estimated_repair_cost, actual_repair_cost,
 *      customer_liable_amount, insurance_claim_amount, work_order_id,
 *      invoice_id.
 *   B) Status transition: status field triggers the state machine.
 *
 * State machine (inferred from ENUM):
 *   reported      → assessed, written_off
 *   assessed      → repair_ordered, written_off
 *   repair_ordered→ invoiced, resolved, written_off
 *   invoiced      → resolved, written_off
 *   resolved      → [terminal]
 *   written_off   → [terminal]
 *
 * D19: optimistic lock — caller must supply updated_at matching DB value.
 * D16: monetary amounts via bcmath.
 *
 * @method  POST
 * @body    JSON: id (required), updated_at (required for D19),
 *               status?, description?, severity?, damage_location?,
 *               notes?, resolution_notes?,
 *               estimated_repair_cost?, actual_repair_cost?,
 *               customer_liable_amount?, insurance_claim_amount?,
 *               work_order_id?, invoice_id?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, claim_number, status, updated_at }
 *
 * Decisions: D5 (soft delete), D16 (bcmath), D19 (optimistic lock), §6 (state machine)
 * Session: S012
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

// -----------------------------------------------------------------------
// 1. Load and validate claim
// -----------------------------------------------------------------------
$body = json_body();

$claimId = clean_int($body['id'] ?? null);
if (!$claimId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    json_error('MISSING_REQUIRED', 'updated_at is required for optimistic lock (D19).', 422);
}

$claim = db_row(
    "SELECT * FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
    [$claimId]
);
if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// D19: optimistic lock
if ($claim['updated_at'] !== $submittedUpdatedAt) {
    json_error('STALE_DATA', 'Record modified by another user. Refresh and try again.', 409);
}

// -----------------------------------------------------------------------
// 2. State machine validation
// -----------------------------------------------------------------------
$allowedTransitions = [
    'reported'       => ['assessed', 'written_off'],
    'assessed'       => ['repair_ordered', 'written_off'],
    'repair_ordered' => ['invoiced', 'resolved', 'written_off'],
    'invoiced'       => ['resolved', 'written_off'],
    'resolved'       => [],    // terminal
    'written_off'    => [],    // terminal
];

$newStatus = clean_string($body['status'] ?? null);
if ($newStatus !== null && $newStatus !== $claim['status']) {
    $allowed = $allowedTransitions[$claim['status']] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        json_error(
            'INVALID_TRANSITION',
            "Cannot transition from '{$claim['status']}' to '{$newStatus}'.",
            409
        );
    }
}

// -----------------------------------------------------------------------
// 3. Collect updatable fields (only apply if present in body)
// -----------------------------------------------------------------------
$updates = [];

// Status
if ($newStatus !== null) {
    $updates['status'] = $newStatus;
}

// Text fields
if (array_key_exists('description', $body)) {
    $desc = clean_string($body['description'] ?? null, 5000);
    if (!$desc) {
        json_error('VALIDATION_ERROR', 'description cannot be empty.', 422);
    }
    $updates['description'] = $desc;
}

if (array_key_exists('severity', $body)) {
    $sev = clean_string($body['severity'] ?? null);
    $validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
    if (!$sev || !in_array($sev, $validSeverities, true)) {
        json_error('VALIDATION_ERROR', 'severity must be one of: ' . implode(', ', $validSeverities) . '.', 422);
    }
    $updates['severity'] = $sev;
}

if (array_key_exists('damage_location', $body)) {
    $updates['damage_location'] = clean_string($body['damage_location'] ?? null);
}

if (array_key_exists('notes', $body)) {
    $updates['notes'] = clean_string($body['notes'] ?? null, 5000);
}

if (array_key_exists('resolution_notes', $body)) {
    $updates['resolution_notes'] = clean_string($body['resolution_notes'] ?? null, 5000);
}

// D16: monetary amounts
foreach (['estimated_repair_cost', 'actual_repair_cost', 'customer_liable_amount', 'insurance_claim_amount'] as $field) {
    if (array_key_exists($field, $body)) {
        $val = clean_decimal($body[$field] ?? null);
        if ($val !== null) {
            if (bccomp($val, '0', 6) < 0) {
                json_error('VALIDATION_ERROR', "{$field} must be a non-negative number.", 422);
            }
            $updates[$field] = bcround($val, 2);
        } else {
            $updates[$field] = null;
        }
    }
}

// Optional FK links
if (array_key_exists('work_order_id', $body)) {
    $woId = clean_int($body['work_order_id'] ?? null);
    if ($woId) {
        $woCheck = db_row("SELECT id FROM maintenance_work_orders WHERE id = ?", [$woId]);
        if (!$woCheck) {
            json_error('NOT_FOUND', 'Work order not found.', 404);
        }
    }
    $updates['work_order_id'] = $woId;
}

if (array_key_exists('invoice_id', $body)) {
    $invId = clean_int($body['invoice_id'] ?? null);
    if ($invId) {
        $invCheck = db_row("SELECT id FROM invoices WHERE id = ? AND deleted_at IS NULL", [$invId]);
        if (!$invCheck) {
            json_error('NOT_FOUND', 'Invoice not found.', 404);
        }
    }
    $updates['invoice_id'] = $invId;
}

if (empty($updates)) {
    json_error('VALIDATION_ERROR', 'No fields provided to update.', 422);
}

// -----------------------------------------------------------------------
// 4. Transaction: update + audit
// -----------------------------------------------------------------------
$resultRow = null;

db_transaction(function () use ($claimId, $claim, $updates, $newStatus, &$resultRow) {
    db_update('damage_claims', $updates, 'id = ?', [$claimId]);

    // Reload to get DB-stamped updated_at
    $fresh = db_row(
        "SELECT id, claim_number, status, updated_at FROM damage_claims WHERE id = ?",
        [$claimId]
    );

    $action = ($newStatus && $newStatus !== $claim['status']) ? 'status_change' : 'update';
    $notes  = $action === 'status_change'
        ? "Status changed from '{$claim['status']}' to '{$newStatus}' on claim {$claim['claim_number']}."
        : "Damage claim {$claim['claim_number']} updated. Fields: " . implode(', ', array_keys($updates)) . '.';

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'System',
        'action'       => $action,
        'module'       => 'maintenance',
        'entity_type'  => 'damage_claim',
        'entity_id'    => $claimId,
        'entity_label' => $claim['claim_number'],
        'old_values'   => json_encode(['status' => $claim['status']]),
        'new_values'   => json_encode($updates),
        'notes'        => $notes,
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);

    $resultRow = $fresh;
});

json_success($resultRow);
