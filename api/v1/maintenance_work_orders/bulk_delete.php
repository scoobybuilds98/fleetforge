<?php
declare(strict_types=1);

/**
 * api/v1/maintenance_work_orders/bulk_delete.php
 *
 * Soft-delete multiple maintenance work orders in a single request.
 *
 * Business rules:
 *   - Only 'open' and 'cancelled' work orders may be deleted.
 *   - 'in_progress', 'waiting_parts', 'completed' are blocked (active or terminal).
 *   - Sets deleted_at = NOW(). Does not affect line items (same policy as single delete.php).
 *   - Each ID is processed in its own transaction; a failure on one does not roll back others.
 *   - Audit log: action='delete' written per successfully deleted work order.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { ids: [int, ...] }
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *
 * Decisions: D5 (soft delete), D7 (routing), §7 (audit log)
 * Session: S015
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

$body = json_body();

// ── Validate top-level input ──────────────────────────────────────────────────

$ids = $body['ids'] ?? null;

if (!is_array($ids) || count($ids) === 0) {
    json_validation_error(['ids' => 'ids must be a non-empty array of integers.']);
}

if (count($ids) > 100) {
    json_validation_error(['ids' => 'A maximum of 100 IDs may be submitted per request.']);
}

// Sanitise every element to a positive integer; reject the batch if any are malformed.
$clean_ids = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if (!$int) {
        json_validation_error(['ids' => 'All values in ids must be positive integers.']);
    }
    $clean_ids[] = $int;
}

// ── Per-ID processing ─────────────────────────────────────────────────────────

$actioned = 0;
$skipped  = 0;
$errors   = [];

$current_user    = current_user();
$current_user_id = current_user_id();
$ip              = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($clean_ids as $id) {
    try {
        $wo = db_row(
            "SELECT id, work_order_number, status FROM maintenance_work_orders
             WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$wo) {
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Work order not found.'];
            continue;
        }

        // Block delete on active or completed states (mirrors delete.php)
        if (!in_array($wo['status'], ['open', 'cancelled'], true)) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => "Cannot delete a work order with status '{$wo['status']}'. Only open or cancelled work orders may be deleted.",
            ];
            continue;
        }

        db_transaction(function() use ($id, $wo, $current_user_id, $current_user, $ip) {
            db_execute(
                "UPDATE maintenance_work_orders SET deleted_at = NOW() WHERE id = ?",
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $current_user_id,
                'user_name'    => $current_user['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'maintenance',
                'entity_type'  => 'work_order',
                'entity_id'    => $id,
                'entity_label' => $wo['work_order_number'],
                'old_values'   => json_encode(['status' => $wo['status']]),
                'ip_address'   => $ip,
            ]);
        });

        $actioned++;

    } catch (\Throwable $e) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Unexpected error: ' . $e->getMessage()];
    }
}

// ── Response ──────────────────────────────────────────────────────────────────
if ($actioned > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
