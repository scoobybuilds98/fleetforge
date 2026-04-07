<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/line_items/update.php
 *
 * Update a maintenance work order line item.
 *
 * Business rules:
 *   - Line item must exist and belong to a non-deleted WO.
 *   - WO must not be in a terminal state (completed/cancelled).
 *   - total_cost = quantity × unit_cost (bcmath D16).
 *   - After update, recalculate WO labor_cost/parts_cost/total_cost in SAME transaction (Trap 6).
 *   - maintenance_line_items has NO updated_at — no D19 optimistic lock needed here.
 *
 * @method  POST
 * @body    JSON: id (required — line item id), item_type?, description?,
 *               quantity?, unit_cost?, part_number?
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { id, total_cost (line), wo_total_cost }
 *
 * Decisions: D7, D16 (bcmath), Trap 6
 * Session: S015
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

$body   = json_body();
$fields = [];

$lineId = clean_int($body['id'] ?? null);
if (!$lineId) {
    $fields['id'] = 'Line item ID is required.';
    json_validation_error($fields);
}

// Load line item + parent WO
$line = db_row(
    "SELECT li.*, mwo.status AS wo_status, mwo.work_order_number
     FROM maintenance_line_items li
     JOIN maintenance_work_orders mwo ON mwo.id = li.work_order_id AND mwo.deleted_at IS NULL
     WHERE li.id = ?",
    [$lineId]
);
if (!$line) {
    json_error('NOT_FOUND', 'Line item not found.', 404);
}
if (in_array($line['wo_status'], ['completed', 'cancelled'], true)) {
    json_error('IMMUTABLE_RECORD',
        'Cannot edit line items on a completed or cancelled work order.', 422,
        ['fields' => ['id' => 'Cannot edit line items on a completed or cancelled work order.']]);
}

$woId = (int)$line['work_order_id'];

// Resolve updatable fields — VALID-2: accumulate every error
$validTypes = ['labor', 'part', 'sublet', 'other'];
if (array_key_exists('item_type', $body)) {
    $itemType = clean_string($body['item_type']);
    if (!$itemType || !in_array($itemType, $validTypes, true)) {
        $fields['item_type'] = 'Please select a valid item type.';
        $itemType = $line['item_type'];
    }
} else {
    $itemType = $line['item_type'];
}

if (array_key_exists('description', $body)) {
    $description = clean_string($body['description'], 500);
    if (!$description) {
        $fields['description'] = 'Description is required.';
        $description = $line['description'];
    }
} else {
    $description = $line['description'];
}

// quantity — must be > 0
$quantity = $line['quantity'];
if (array_key_exists('quantity', $body)) {
    $raw = $body['quantity'];
    if ($raw === null || $raw === '') {
        $fields['quantity'] = 'Quantity is required.';
    } else {
        $q = clean_decimal((string) $raw);
        if ($q === null || bccomp($q, '0', 6) <= 0) {
            $fields['quantity'] = 'Quantity must be greater than zero.';
        } else {
            $quantity = $q;
        }
    }
}

// unit_cost — must be >= 0
$unitCost = $line['unit_cost'];
if (array_key_exists('unit_cost', $body)) {
    $raw = $body['unit_cost'];
    if ($raw === null || $raw === '') {
        $fields['unit_cost'] = 'Unit cost is required.';
    } else {
        $uc = clean_decimal((string) $raw);
        if ($uc === null || bccomp($uc, '0', 6) < 0) {
            $fields['unit_cost'] = 'Unit cost cannot be negative.';
        } else {
            $unitCost = $uc;
        }
    }
}

$partNumber = array_key_exists('part_number', $body)
    ? clean_string($body['part_number'] ?? null, 100)
    : $line['part_number'];

if ($fields) {
    json_validation_error($fields);
}

$lineTotalCost = bcround(bcmul((string)$quantity, (string)$unitCost, 6), 2);

$woTotalCost = null;

db_transaction(function() use (
    $lineId, $itemType, $description, $quantity, $unitCost,
    $lineTotalCost, $partNumber, $woId, $line, &$woTotalCost
) {
    db_execute(
        "UPDATE maintenance_line_items SET
             item_type = ?, description = ?, quantity = ?,
             unit_cost = ?, total_cost = ?, part_number = ?
         WHERE id = ?",
        [$itemType, $description, $quantity, $unitCost, $lineTotalCost, $partNumber, $lineId]
    );

    // Recalculate WO aggregate costs
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

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'update',
        'module'       => 'maintenance',
        'entity_type'  => 'work_order',
        'entity_id'    => $woId,
        'entity_label' => $line['work_order_number'],
        'notes'        => "Line item #{$lineId} updated: {$description} ({$itemType}) — \${$lineTotalCost}",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['id' => $lineId, 'total_cost' => $lineTotalCost, 'wo_total_cost' => $woTotalCost]);
