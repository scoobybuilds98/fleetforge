<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/line_items/add.php
 *
 * Add a line item to a maintenance work order.
 *
 * Business rules:
 *   - WO must exist, not deleted, and not in a terminal state (completed/cancelled).
 *   - total_cost = quantity × unit_cost (bcmath D16).
 *   - After insert, recalculate WO labor_cost/parts_cost/total_cost from all line items
 *     in the SAME transaction (Trap 6).
 *     labor_cost  = SUM where item_type = 'labor'
 *     parts_cost  = SUM where item_type IN ('part','sublet','other')
 *     total_cost  = labor_cost + parts_cost
 *   - No soft delete on maintenance_line_items (no deleted_at column).
 *   - Audit log: action='update' on the parent work order (line item change).
 *
 * @method  POST
 * @body    JSON: work_order_id (required), item_type (required), description (required),
 *               quantity (required), unit_cost (required), part_number?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 201 { id, work_order_id, total_cost (line), wo_total_cost }
 *
 * Decisions: D5, D7, D16 (bcmath), Trap 6 (denorm counter in transaction)
 * Session: S015
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

$body = json_body();

// -----------------------------------------------------------------------
// 1. Validate required fields
// -----------------------------------------------------------------------
$woId = clean_int($body['work_order_id'] ?? null);
if (!$woId) {
    json_error('MISSING_REQUIRED', 'work_order_id is required.', 422);
}

$itemType = clean_string($body['item_type'] ?? null);
$validTypes = ['labor', 'part', 'sublet', 'other'];
if (!$itemType || !in_array($itemType, $validTypes, true)) {
    json_error('VALIDATION_ERROR', 'item_type is required and must be one of: ' . implode(', ', $validTypes) . '.', 422);
}

$description = clean_string($body['description'] ?? null, 500);
if (!$description) {
    json_error('MISSING_REQUIRED', 'description is required.', 422);
}

// quantity and unit_cost: D16 bcmath
$quantityRaw = $body['quantity'] ?? null;
$quantity    = clean_decimal((string)($quantityRaw ?? ''));
if ($quantity === null || bccomp($quantity, '0', 6) <= 0) {
    json_error('VALIDATION_ERROR', 'quantity must be a positive number.', 422);
}

$unitCostRaw = $body['unit_cost'] ?? null;
$unitCost    = clean_decimal((string)($unitCostRaw ?? ''));
if ($unitCost === null || bccomp($unitCost, '0', 6) < 0) {
    json_error('VALIDATION_ERROR', 'unit_cost must be a non-negative number.', 422);
}

$partNumber = clean_string($body['part_number'] ?? null, 100);

// -----------------------------------------------------------------------
// 2. Verify WO exists and is editable
// -----------------------------------------------------------------------
$wo = db_row(
    "SELECT id, work_order_number, status FROM maintenance_work_orders
     WHERE id = ? AND deleted_at IS NULL",
    [$woId]
);
if (!$wo) {
    json_error('NOT_FOUND', 'Work order not found.', 404);
}
if (in_array($wo['status'], ['completed', 'cancelled'], true)) {
    json_error('IMMUTABLE_RECORD', 'Cannot add line items to a completed or cancelled work order.', 422);
}

// -----------------------------------------------------------------------
// 3. Calculate line item total_cost (D16)
// -----------------------------------------------------------------------
$lineTotalCost = bcround(bcmul($quantity, $unitCost, 6), 2);

// -----------------------------------------------------------------------
// 4. Insert + recalculate WO costs in one transaction (Trap 6)
// -----------------------------------------------------------------------
$newId     = null;
$woTotalCost = null;

db_transaction(function() use (
    $woId, $itemType, $description, $quantity, $unitCost, $lineTotalCost,
    $partNumber, $wo, &$newId, &$woTotalCost
) {
    $newId = db_insert('maintenance_line_items', [
        'work_order_id' => $woId,
        'item_type'     => $itemType,
        'description'   => $description,
        'quantity'      => $quantity,
        'unit_cost'     => $unitCost,
        'total_cost'    => $lineTotalCost,
        'part_number'   => $partNumber,
    ]);

    // Recalculate WO aggregate costs from all line items
    $laborCost = db_row(
        "SELECT COALESCE(SUM(total_cost), 0) AS s FROM maintenance_line_items
         WHERE work_order_id = ? AND item_type = 'labor'",
        [$woId]
    )['s'] ?? '0';

    $partsCost = db_row(
        "SELECT COALESCE(SUM(total_cost), 0) AS s FROM maintenance_line_items
         WHERE work_order_id = ? AND item_type IN ('part','sublet','other')",
        [$woId]
    )['s'] ?? '0';

    $woTotalCost = bcround(bcadd((string)$laborCost, (string)$partsCost, 6), 2);

    db_execute(
        "UPDATE maintenance_work_orders SET labor_cost = ?, parts_cost = ?, total_cost = ?
         WHERE id = ?",
        [
            bcround((string)$laborCost, 2),
            bcround((string)$partsCost, 2),
            $woTotalCost,
            $woId,
        ]
    );

    // Audit the parent WO for the line item change
    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $woId,
        'entity_label' => $wo['work_order_number'],
        'notes'        => "Line item added: {$description} ({$itemType}) — \${$lineTotalCost}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success([
    'id'             => $newId,
    'work_order_id'  => $woId,
    'total_cost'     => $lineTotalCost,
    'wo_total_cost'  => $woTotalCost,
], 201);
