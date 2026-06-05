<?php
declare(strict_types=1);

/**
 * FleetForge — Reservation Bulk Delete API
 *
 * @file        api/v1/reservations/bulk_delete.php
 * @description Soft-deletes up to 100 reservations in a single request.
 *              Each ID is processed independently inside its own transaction;
 *              a failure on one ID does not abort the remaining IDs.
 *
 *              Business rules mirror reservations/delete.php exactly:
 *                - Only 'pending' or 'cancelled' reservations can be deleted.
 *                  Confirmed/completed reservations must be cancelled first via
 *                  update_status.php before deletion is permitted.
 *                - On delete: soft-delete (deleted_at = NOW()), then release any
 *                  system-linked units in 'reserved' status back to 'available',
 *                  writing an equipment_status_log row for each released unit.
 *
 * @method      POST
 * @body        JSON — { "ids": [int...] }   (max 100 IDs)
 * @auth        Session required; require_permission('reservations','delete')
 * @returns     200 { "success": true, "data": { "actioned": N, "skipped": N, "errors": [...] } }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.6 Reservations
 * @decisions   D5 (soft-delete), D20 (FOR UPDATE on unit revert)
 * @session     S018
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('reservations', 'delete');

$body = json_body();

// ── Validate ids array ────────────────────────────────────────────────────────

$rawIds = $body['ids'] ?? null;
if (!is_array($rawIds) || count($rawIds) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}
if (count($rawIds) > 100) {
    json_error('VALIDATION_ERROR', 'Maximum 100 ids per request.', 422);
}

// Coerce to clean integers; reject any non-positive values early.
$ids = [];
foreach ($rawIds as $raw) {
    $int = clean_int($raw);
    if (!$int || $int <= 0) {
        json_error('VALIDATION_ERROR', 'All ids must be positive integers.', 422);
    }
    $ids[] = $int;
}
$ids = array_values(array_unique($ids));

// ── Process each ID independently ────────────────────────────────────────────

$actioned  = 0;
$skipped   = 0;
$errors    = [];

$now       = date('Y-m-d H:i:s');
$userId    = current_user_id();
$userName  = current_user()['name'] ?? 'System';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

foreach ($ids as $id) {
    // Fetch outside the transaction so we can gate before opening one.
    $reservation = db_row(
        "SELECT id, status, company_name, pickup_date
         FROM reservations WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$reservation) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Reservation not found or already deleted.'];
        continue;
    }

    // Guard: only pending or cancelled can be deleted — mirrors delete.php (D5).
    if (!in_array($reservation['status'], ['pending', 'cancelled'], true)) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Reservation #{$id} is '{$reservation['status']}'. Cancel it first before deleting.",
        ];
        continue;
    }

    // Each delete is its own transaction; failures are isolated from sibling IDs.
    try {
        db_transaction(function () use ($id, $reservation, $now, $userId, $userName, $ipAddress) {
            // Re-fetch with a row lock now that we are inside the transaction,
            // preventing a concurrent status change between our pre-check and the write.
            $locked = db_row(
                "SELECT id, status, company_name, pickup_date
                 FROM reservations WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );
            if (!$locked) {
                // Deleted by a concurrent request between our pre-check and the lock.
                throw new \RuntimeException('Reservation not found under lock.');
            }
            if (!in_array($locked['status'], ['pending', 'cancelled'], true)) {
                // Status changed between our pre-check and acquiring the lock.
                throw new \RuntimeException(
                    "Reservation #{$id} status changed to '{$locked['status']}' — cannot delete."
                );
            }

            // ── Release any 'reserved' units back to 'available' ─────────────
            // Mirrors the unit-revert logic in reservations/delete.php (D20).
            $units = db_select(
                "SELECT ru.equipment_unit_id, eu.status, eu.unit_number
                 FROM reservation_units ru
                 JOIN equipment_units eu ON eu.id = ru.equipment_unit_id AND eu.deleted_at IS NULL
                 WHERE ru.reservation_id = ? AND ru.equipment_unit_id IS NOT NULL",
                [$id]
            );
            foreach ($units as $u) {
                if ($u['status'] === 'reserved') {
                    db_execute(
                        "UPDATE equipment_units SET status = 'available', updated_at = NOW()
                         WHERE id = ? AND deleted_at IS NULL",
                        [$u['equipment_unit_id']]
                    );
                    db_insert('equipment_status_log', [
                        'equipment_unit_id' => $u['equipment_unit_id'],
                        'changed_by'        => $userId,
                        'old_status'        => 'reserved',
                        'new_status'        => 'available',
                        'reason'            => "Reservation #{$id} deleted (bulk) — unit released",
                        'changed_at'        => $now,
                    ]);
                }
            }

            // ── Soft-delete the reservation row ──────────────────────────────
            db_execute(
                "UPDATE reservations SET deleted_at = ?, updated_by = ?
                 WHERE id = ? AND deleted_at IS NULL",
                [$now, $userId, $id]
            );

            // ── Audit log ─────────────────────────────────────────────────────
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'delete',
                'module'       => 'reservations',
                'entity_type'  => 'reservation',
                'entity_id'    => $id,
                'entity_label' => "#{$id} — {$locked['company_name']}",
                'notes'        => "Reservation #{$id} deleted via bulk_delete — {$locked['company_name']} (pickup: {$locked['pickup_date']})",
                'old_values'   => json_encode($locked),
                'new_values'   => null,
                'ip_address'   => $ipAddress,
            ]);
        });

        $actioned++;
    } catch (\Throwable $e) {
        // Isolate the failure; log server-side but return a safe message to the caller.
        error_log("bulk_delete reservations: id={$id} failed: " . $e->getMessage());
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Delete failed due to a server error.'];
    }
}

if ($actioned > 0) {
    invalidate_dashboard_cache();
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
