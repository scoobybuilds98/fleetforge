<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/line_items/delete.php
 *
 * Hard-delete a maintenance work order line item.
 *
 * Business rules:
 *   - maintenance_line_items has NO deleted_at — this is a hard (physical) DELETE.
 *   - WO must not be in a terminal state (completed/cancelled).
 *   - After delete, recalculate WO labor_cost/parts_cost/total_cost in SAME transaction (Trap 6).
 *
 * @method  POST
 * @body    JSON: id (required — line item id)
 * @auth    Session required; require_permission('maintenance','edit')
 * @returns 200 { deleted: true, wo_total_cost }
 *
 * Decisions: D7, D16 (bcmath), Trap 6
 * Session: S015
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'edit');

$body   = json_body();
$lineId = clean_int($body['id'] ?? null);
if (!$lineId) {
    json_error('MISSING_REQUIRED', 'id is required.', 422);
}

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
    json_error('IMMUTABLE_RECORD', 'Cannot delete line items from a completed or cancelled work order.', 422);
}

$woId = (int)$line['work_order_id'];
$woTotalCost = null;

db_transaction(function() use ($lineId, $woId, $line, &$woTotalCost) {
    // Hard delete — no deleted_at on this table
    db_execute("DELETE FROM maintenance_line_items WHERE id = ?", [$lineId]);

    // Recalculate WO aggregate costs after deletion
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
        'notes'        => "Line item #{$lineId} deleted: {$line['description']} ({$line['item_type']})",
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ]);
});

json_success(['deleted' => true, 'wo_total_cost' => $woTotalCost]);
