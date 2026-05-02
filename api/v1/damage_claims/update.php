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
$body   = json_body();
$fields = [];

$claimId = clean_int($body['id'] ?? null);
if (!$claimId) {
    $fields['id'] = 'Damage claim ID is required.';
}

$submittedUpdatedAt = clean_string($body['updated_at'] ?? null);
if (!$submittedUpdatedAt) {
    $fields['updated_at'] = 'Optimistic lock token is required.';
}

if ($fields) {
    json_validation_error($fields);
}

$claim = db_row(
    "SELECT * FROM damage_claims WHERE id = ? AND deleted_at IS NULL",
    [$claimId]
);
if (!$claim) {
    json_error('NOT_FOUND', 'Damage claim not found.', 404);
}

// D19: optimistic lock
if (!optimistic_lock_matches($submittedUpdatedAt, $claim['updated_at'])) {
    json_error('STALE_DATA',
        'This damage claim was modified by another user. Refresh and try again.', 409,
        ['fields' => ['updated_at' => 'This damage claim was modified by another user. Refresh and try again.']]);
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
        json_error('INVALID_TRANSITION',
            "Cannot move a damage claim from '{$claim['status']}' to '{$newStatus}'.", 409,
            ['fields' => ['status' => "Cannot move a damage claim from '{$claim['status']}' to '{$newStatus}'."]]);
    }
}

// -----------------------------------------------------------------------
// 3. Collect updatable fields (only apply if present in body)
//    VALID-2: accumulate every field error before responding
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
        $fields['description'] = 'Description is required.';
    } else {
        $updates['description'] = $desc;
    }
}

if (array_key_exists('severity', $body)) {
    $sev = clean_string($body['severity'] ?? null);
    $validSeverities = ['minor', 'moderate', 'major', 'total_loss'];
    if (!$sev) {
        $fields['severity'] = 'Please select a severity.';
    } elseif (!in_array($sev, $validSeverities, true)) {
        $fields['severity'] = 'Please select a valid severity.';
    } else {
        $updates['severity'] = $sev;
    }
}

if (array_key_exists('damage_location', $body)) {
    $updates['damage_location'] = clean_string($body['damage_location'] ?? null);
}

// Customer: either FK or free-text name — mutually exclusive
if (array_key_exists('customer_id', $body)) {
    $cId = clean_int($body['customer_id'] ?? null);
    if ($cId) {
        $cCheck = db_row("SELECT id FROM customers WHERE id = ? AND deleted_at IS NULL", [$cId]);
        if (!$cCheck) {
            $fields['customer_id'] = 'Customer not found.';
        } else {
            $updates['customer_id']   = $cId;
            $updates['customer_name'] = null; // clear free-text when FK is set
        }
    } else {
        $updates['customer_id'] = null;
    }
}
if (array_key_exists('customer_name', $body) && !array_key_exists('customer_id', $body)) {
    // Only apply free-text name when customer_id is not being set in the same request
    $cName = clean_string($body['customer_name'] ?? null, 255);
    $updates['customer_name'] = $cName ?: null;
    if ($cName) {
        $updates['customer_id'] = null; // clear FK when free-text is set
    }
}

if (array_key_exists('notes', $body)) {
    $updates['notes'] = clean_string($body['notes'] ?? null, 5000);
}

if (array_key_exists('resolution_notes', $body)) {
    $updates['resolution_notes'] = clean_string($body['resolution_notes'] ?? null, 5000);
}

// D16: monetary amounts — non-negative per field with friendly label
$moneyLabels = [
    'estimated_repair_cost'  => 'Estimated repair cost',
    'actual_repair_cost'     => 'Actual repair cost',
    'customer_liable_amount' => 'Customer liable amount',
    'insurance_claim_amount' => 'Insurance claim amount',
];
foreach ($moneyLabels as $field => $label) {
    if (!array_key_exists($field, $body)) {
        continue;
    }
    $raw = $body[$field];
    if ($raw === null || $raw === '') {
        $updates[$field] = null;
        continue;
    }
    $val = clean_decimal((string)$raw);
    if ($val === null || bccomp($val, '0', 6) < 0) {
        $fields[$field] = "{$label} cannot be negative.";
    } else {
        $updates[$field] = bcround($val, 2);
    }
}

// Optional FK links
if (array_key_exists('work_order_id', $body)) {
    $woId = clean_int($body['work_order_id'] ?? null);
    if ($woId) {
        $woCheck = db_row("SELECT id FROM maintenance_work_orders WHERE id = ?", [$woId]);
        if (!$woCheck) {
            $fields['work_order_id'] = 'Work order not found.';
        } else {
            $updates['work_order_id'] = $woId;
        }
    } else {
        $updates['work_order_id'] = null;
    }
}

if (array_key_exists('invoice_id', $body)) {
    $invId = clean_int($body['invoice_id'] ?? null);
    if ($invId) {
        $invCheck = db_row("SELECT id FROM invoices WHERE id = ? AND deleted_at IS NULL", [$invId]);
        if (!$invCheck) {
            $fields['invoice_id'] = 'Invoice not found.';
        } else {
            $updates['invoice_id'] = $invId;
        }
    } else {
        $updates['invoice_id'] = null;
    }
}

if (array_key_exists('vendor_id', $body)) {
    $vId = clean_int($body['vendor_id'] ?? null);
    if ($vId) {
        $vCheck = db_row("SELECT id FROM vendors WHERE id = ? AND deleted_at IS NULL", [$vId]);
        if (!$vCheck) {
            $fields['vendor_id'] = 'Vendor not found.';
        } else {
            $updates['vendor_id'] = $vId;
        }
    } else {
        $updates['vendor_id'] = null;
    }
}

if ($fields) {
    json_validation_error($fields);
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

    // ── In-app notification (NOTIF-1) ──────────────────────────
    try {
        \FleetForge\Notifications\NotificationService::notify(
            type:       'damage.updated',
            title:      "Damage claim {$claim['claim_number']} updated",
            message:    "Damage claim {$claim['claim_number']} updated",
            entityType: 'damage_claim',
            entityId:   $claimId,
            url:        '/fleetforge/damage_claims/show?id=' . $claimId
        );
    } catch (\Throwable $e) {
        error_log('[NOTIF damage.updated] ' . $e->getMessage());
    }
});

json_success($resultRow);
