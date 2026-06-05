<?php
declare(strict_types=1);

/**
 * api/v1/mileage_logs/bulk_delete.php
 *
 * Hard-deletes multiple mileage log entries in a single request.
 *
 * Business rules:
 *   - mileage_logs has no deleted_at column — hard delete is correct.
 *   - BLOCKED for log_type IN ('lease_start', 'lease_end'): these entries are
 *     billing-critical (tied to invoice generation at lease activation and close).
 *     Deletion would corrupt the mileage reconciliation audit trail.
 *   - Each ID is processed independently; a failure on one does not affect others.
 *   - Audit log: action='delete' written per successfully deleted log.
 *   - Maximum 100 IDs per request.
 *
 * @method  POST
 * @body    JSON: { ids: [int, ...] }
 * @auth    Session required; require_permission('maintenance','delete')
 * @returns 200 { success:true, data:{ actioned:N, skipped:N, errors:[{id,reason}] } }
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

// Billing-critical log types that must never be hard-deleted (mirrors delete.php).
$protected_types = ['lease_start', 'lease_end'];

$current_user    = current_user();
$current_user_id = current_user_id();
$ip              = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($clean_ids as $id) {
    try {
        $log = db_row(
            "SELECT id, log_type, equipment_unit_id, odometer_reading, mileage_unit, log_date
             FROM mileage_logs WHERE id = ?",
            [$id]
        );

        if (!$log) {
            $skipped++;
            $errors[] = ['id' => $id, 'reason' => 'Mileage log not found.'];
            continue;
        }

        // Block deletion of billing-critical system types (mirrors delete.php).
        if (in_array($log['log_type'], $protected_types, true)) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => "Log type '{$log['log_type']}' is billing-critical and cannot be deleted. " .
                            "These entries are created automatically at lease activation and close.",
            ];
            continue;
        }

        // Hard delete — no transaction needed for a single-row delete with no FK cascades.
        db_execute("DELETE FROM mileage_logs WHERE id = ?", [$id]);

        db_insert('audit_log', [
            'user_id'      => $current_user_id,
            'user_name'    => $current_user['name'] ?? 'system',
            'action'       => 'delete',
            'module'       => 'maintenance',
            'entity_type'  => 'mileage_log',
            'entity_id'    => $id,
            'entity_label' => "Mileage log #{$id} — {$log['log_type']} {$log['odometer_reading']} {$log['mileage_unit']} on {$log['log_date']}",
            'old_values'   => json_encode($log),
            'ip_address'   => $ip,
        ]);

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
