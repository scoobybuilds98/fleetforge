<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Bulk Delete API
 *
 * @file        api/v1/leases/bulk_delete.php
 * @description Soft-deletes up to 100 leases in a single request.
 *              Each ID is processed independently with its own transaction so a
 *              failure on one record never rolls back the others (partial success).
 *              Blocked if any individual lease is currently 'active' — those are
 *              skipped and reported in the errors array.
 *              Pending leases with an assigned equipment unit have that unit
 *              released back to 'available' (same logic as single delete.php).
 *
 * @method      POST
 * @body        JSON — ids (required, array of int, max 100)
 * @auth        Session required; require_permission('leases','delete')
 * @returns     200 with { success, data: { deleted, skipped, errors[] } }
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D5 (soft-delete), D20 (state machine)
 * @session     S-BULK-DELETE-1
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'delete');

$body = json_body();
$ids  = $body['ids'] ?? null;

// ── Input validation ───────────────────────────────────────────
if (!is_array($ids) || count($ids) === 0) {
    json_error('MISSING_REQUIRED', 'ids must be a non-empty array.', 422);
}

if (count($ids) > 100) {
    json_error('TOO_MANY_IDS', 'A maximum of 100 IDs may be submitted per request.', 422);
}

// Sanitise: coerce every element to a positive integer and drop invalid ones
$clean_ids = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if ($int && $int > 0) {
        $clean_ids[] = $int;
    }
}

if (count($clean_ids) === 0) {
    json_error('MISSING_REQUIRED', 'ids contained no valid integer values.', 422);
}

// ── Per-ID processing ─────────────────────────────────────────
$deleted = 0;
$skipped = 0;
$errors  = [];

foreach ($clean_ids as $id) {
    // Fetch the lease — must exist and not already be soft-deleted
    $lease = db_row(
        "SELECT id, status, contract_number, equipment_unit_id
         FROM leases WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$lease) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Lease not found or already deleted.',
        ];
        continue;
    }

    // Active leases cannot be deleted — must be closed first
    if ($lease['status'] === 'active') {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => "Cannot delete active lease {$lease['contract_number']} — close it first.",
        ];
        continue;
    }

    // Wrap each deletion in its own transaction so failures are isolated
    try {
        db_transaction(function () use ($id, $lease) {
            $uid = current_user_id();

            // Soft-delete the lease
            db_execute(
                "UPDATE leases SET deleted_at = NOW(), updated_by = ? WHERE id = ?",
                [$uid, $id]
            );

            // Release a reserved unit back to 'available' when a pending lease
            // is deleted — prevents the unit from being permanently locked.
            if ($lease['status'] === 'pending' && $lease['equipment_unit_id']) {
                $unit = db_row(
                    "SELECT id, status FROM equipment_units WHERE id = ? AND deleted_at IS NULL",
                    [$lease['equipment_unit_id']]
                );
                if ($unit && $unit['status'] === 'reserved') {
                    db_execute(
                        "UPDATE equipment_units
                            SET status = 'available', updated_by = ?, updated_at = NOW()
                          WHERE id = ?",
                        [$uid, $lease['equipment_unit_id']]
                    );
                    db_insert('equipment_status_log', [
                        'equipment_unit_id'  => $lease['equipment_unit_id'],
                        'old_status'         => 'reserved',
                        'new_status'         => 'available',
                        'reason'             => "Lease {$lease['contract_number']} bulk-deleted — unit released",
                        'changed_by'         => current_user()['name'] ?? 'system',
                        'changed_by_user_id' => $uid,
                    ]);
                }
            }

            db_insert('audit_log', [
                'user_id'      => current_user_id(),
                'user_name'    => current_user()['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'leases',
                'entity_type'  => 'lease',
                'entity_id'    => $id,
                'entity_label' => $lease['contract_number'],
                'notes'        => "Lease {$lease['contract_number']} soft-deleted via bulk delete",
                'old_values'   => json_encode(['status' => $lease['status']]),
                'new_values'   => null,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        });

        $deleted++;
    } catch (\Throwable $e) {
        // Transaction rolled back; record the failure and continue with remaining IDs
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Internal error — lease could not be deleted.',
        ];
    }
}

// ── Response ──────────────────────────────────────────────────
json_success([
    'deleted' => $deleted,
    'skipped' => $skipped,
    'errors'  => $errors,
]);
