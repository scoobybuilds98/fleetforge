<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/bulk_delete.php
 *
 * Bulk soft-deletes up to 100 equipment units in a single request.
 * Each ID is processed independently — one failure never aborts the batch.
 * Mirrors all side effects of delete.php per unit:
 *   - Soft-delete (deleted_at + updated_by)
 *   - equipment_status_log entry ('deleted')
 *   - audit_log entry (action='delete', module='equipment')
 *   - Samsara trailer removal (non-blocking, after DB commit)
 *
 * Blocked statuses (per FIX #5):
 *   - 'on_lease'  — unit is on an active lease; close the lease first
 *   - 'reserved'  — unit is reserved; cancel the reservation first
 *
 * @method   POST
 * @body     JSON { "ids": [1, 2, 3] }   // array of int, max 100
 * @required ids
 * @auth     Session required; require_permission('equipment','delete')
 * @returns  200 { success: true, data: { deleted: N, skipped: N, errors: [...] } }
 *           422 VALIDATION_ERROR if ids is missing, empty, or exceeds 100
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §7.4, §5 SOFT_DELETE_TABLES
 * @session  S-BULK-DELETE
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';
require_once dirname(__DIR__, 4) . '/lib/GPS/SamsaraClient.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'delete');

$body = json_body();
$ids  = $body['ids'] ?? null;

// ── Validate the ids array ─────────────────────────────────────
if (!is_array($ids) || count($ids) === 0) {
    json_error('VALIDATION_ERROR', 'ids must be a non-empty array.', 422);
}
if (count($ids) > 100) {
    json_error('VALIDATION_ERROR', 'ids must contain at most 100 entries.', 422);
}

// Coerce to clean ints and strip any invalid (0/null) entries
$cleanIds = array_values(array_filter(array_map('intval', $ids)));
if (count($cleanIds) === 0) {
    json_error('VALIDATION_ERROR', 'ids must contain at least one valid integer.', 422);
}

$userId      = current_user_id();
$currentUser = current_user();
$userName    = $currentUser['name'] ?? 'system';
$ipAddress   = $_SERVER['REMOTE_ADDR'] ?? null;

$deleted = 0;
$skipped = 0;
$errors  = [];

// ── Collect Samsara trailers to remove after all DB work ───────
// WHY deferred: commit every DB transaction first so FleetForge state is
// consistent even if Samsara calls fail. Non-blocking, same policy as delete.php.
$samsaraTrailersToDelete = [];

foreach ($cleanIds as $id) {
    // ── Fetch unit — skip unknown / already-deleted IDs ────────
    $unit = db_row(
        "SELECT id, unit_number, status, samsara_vehicle_id, samsara_entity_type
           FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$unit) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Not found or already deleted.'];
        continue;
    }

    // ── Block on_lease — matches delete.php behaviour ──────────
    if ($unit['status'] === 'on_lease') {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Unit {$unit['unit_number']} is on active lease. Close the lease first.",
        ];
        continue;
    }

    // ── FIX #5: block reserved — deleting orphans the reservation
    if ($unit['status'] === 'reserved') {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Unit {$unit['unit_number']} is reserved. Cancel the reservation first.",
        ];
        continue;
    }

    // ── Per-unit transaction: soft-delete + status log + audit ─
    try {
        db_transaction(function () use ($id, $userId, $userName, $ipAddress, $unit): void {
            // FIX #32: record who deleted the unit
            db_execute(
                "UPDATE equipment_units SET deleted_at = NOW(), updated_by = ? WHERE id = ?",
                [$userId, $id]
            );

            // FIX #33: status log entry for full deletion history
            db_insert('equipment_status_log', [
                'equipment_unit_id'  => $id,
                'old_status'         => $unit['status'],
                'new_status'         => 'deleted',
                'reason'             => "Unit soft-deleted (bulk) by {$userId}",
                'changed_by'         => $userName,
                'changed_by_user_id' => $userId,
            ]);

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'equipment',
                'entity_type'  => 'equipment_unit',
                'entity_id'    => $id,
                'entity_label' => $unit['unit_number'],
                'old_values'   => json_encode([
                    'unit_number' => $unit['unit_number'],
                    'status'      => $unit['status'],
                ]),
                'new_values'   => null,
                'ip_address'   => $ipAddress,
            ]);
        });

        $deleted++;

        // Queue Samsara trailer deletion (executed after all DB work)
        if (!empty($unit['samsara_vehicle_id'])
            && ($unit['samsara_entity_type'] ?? 'vehicle') === 'trailer'
        ) {
            $samsaraTrailersToDelete[] = (string) $unit['samsara_vehicle_id'];
        }
    } catch (\Throwable $e) {
        // DB failure on this ID — log it and continue with the rest
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Database error: ' . $e->getMessage()];
    }
}

// ── SAMSARA-3: Remove trailers from Samsara (non-blocking) ─────
// WHY after the loop: all DB commits are done; Samsara failures cannot
// roll back already-committed soft-deletes. Matches delete.php policy.
if (!empty($samsaraTrailersToDelete)) {
    try {
        $samsara = new \FleetForge\GPS\SamsaraClient();
        foreach ($samsaraTrailersToDelete as $vehicleId) {
            try {
                $samsara->deleteTrailer($vehicleId);
            } catch (\Throwable) {
                // Per-trailer Samsara failure is non-blocking — logged by SamsaraClient
            }
        }
    } catch (\Throwable) {
        // SamsaraClient construction failure — non-blocking
    }
}

if ($deleted > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'deleted' => $deleted,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
