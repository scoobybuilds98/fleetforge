<?php
declare(strict_types=1);

/**
 * api/v1/vendors/bulk_delete.php
 *
 * Bulk soft-delete vendors.
 *
 * Business rules:
 *   - Block delete for any vendor that has active work orders
 *     (status IN open, in_progress, waiting_parts); those IDs are skipped
 *     and reported in errors[].
 *   - Soft delete: sets deleted_at = NOW(), does NOT hard-delete the row.
 *   - Skips IDs that are not found or already soft-deleted.
 *   - Each ID is processed in its own transaction so one failure does not
 *     roll back successful deletes.
 *   - Audit log: action='delete' per successfully actioned row.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { ids: [int, ...] }
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
 *          422 MISSING_REQUIRED | INVALID_PAYLOAD | TOO_MANY_IDS
 *
 * Decisions: D5 (soft delete), §7 (audit log)
 * Session: S014
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('maintenance', 'delete');

// -----------------------------------------------------------------------
// 1. Parse + validate request body
// -----------------------------------------------------------------------
$body = json_body();

if (!isset($body['ids']) || !is_array($body['ids'])) {
    json_error('MISSING_REQUIRED', 'ids (array) is required.', 422);
}

$rawIds = $body['ids'];

if (count($rawIds) === 0) {
    json_error('INVALID_PAYLOAD', 'ids array must not be empty.', 422);
}

if (count($rawIds) > 100) {
    json_error('TOO_MANY_IDS', 'Maximum 100 IDs per request.', 422);
}

// Coerce to clean ints, reject any non-positive values immediately.
$ids = [];
foreach ($rawIds as $raw) {
    $clean = clean_int($raw);
    if (!$clean) {
        json_error('INVALID_PAYLOAD', "All ids must be positive integers; received: " . json_encode($raw), 422);
    }
    $ids[] = $clean;
}

// Remove duplicates so we don't double-count actioned.
$ids = array_values(array_unique($ids));

// -----------------------------------------------------------------------
// 2. Process each ID independently
// -----------------------------------------------------------------------
$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($ids as $id) {
    try {
        // -- 2a. Resolve vendor (skip if not found or already deleted) ----
        $vendor = db_row(
            "SELECT id, name FROM vendors WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
        if (!$vendor) {
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Vendor not found or already deleted.'];
            continue;
        }

        // -- 2b. Block if active work orders exist ------------------------
        $activeWoCount = db_count(
            "SELECT COUNT(*) FROM maintenance_work_orders
             WHERE vendor_id = ? AND status IN ('open','in_progress','waiting_parts')
               AND deleted_at IS NULL",
            [$id]
        );
        if ($activeWoCount > 0) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => "Cannot delete vendor with {$activeWoCount} active work order(s). " .
                            "Complete or cancel all work orders before deleting this vendor.",
            ];
            continue;
        }

        // -- 2c. Soft delete + audit log inside a per-ID transaction ------
        db_transaction(function() use ($id, $vendor) {
            db_execute(
                "UPDATE vendors SET deleted_at = NOW() WHERE id = ?",
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => current_user_id(),
                'user_name'    => current_user()['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'maintenance',
                'entity_type'  => 'vendor',
                'entity_id'    => $id,
                'entity_label' => $vendor['name'],
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        });

        $actioned++;

    } catch (Throwable $e) {
        // Unexpected DB or runtime error — count as skipped, surface reason.
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Unexpected error: ' . $e->getMessage()];
    }
}

// -----------------------------------------------------------------------
// 3. Return aggregate result
// -----------------------------------------------------------------------
if ($actioned > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
