<?php
declare(strict_types=1);

/**
 * api/v1/equipment/units/bulk_update_status.php
 *
 * Bulk status change for up to 100 equipment units in a single request.
 * Each ID is processed independently inside its own transaction — one
 * failure never aborts the batch.
 *
 * Uses the same UNIT_STATUS_TRANSITIONS map defined in update_status.php to
 * validate every individual transition before committing. Only the three
 * operator-initiated target statuses ('available', 'maintenance', 'inactive')
 * are accepted here; transitions to 'reserved' or 'on_lease' are set
 * implicitly by their respective workflows (reservations, leases), and
 * 'decommissioned' is intentionally excluded from bulk to prevent accidental
 * terminal moves on a large set.
 *
 * Per-unit steps (inside db_transaction):
 *   1. SELECT ... FOR UPDATE — lock the row against concurrent updates (D20)
 *   2. Validate transition via UNIT_STATUS_TRANSITIONS — skip with reason if blocked
 *   3. UPDATE equipment_units SET status, updated_by, updated_at
 *   4. INSERT equipment_status_log (equipment_unit_id, old_status, new_status, reason, changed_by, changed_by_user_id)
 *   5. INSERT audit_log (action='status_change', module='equipment', entity_type='equipment_unit')
 *
 * @method   POST
 * @body     JSON { "ids": [int...], "new_status": string }
 * @required ids, new_status
 * @auth     Session required; require_permission('equipment', 'edit')
 * @returns  200 { success: true, data: { actioned: N, skipped: N, errors: [...] } }
 *           422 VALIDATION_ERROR  — ids missing/empty/over-100, new_status missing/invalid
 *
 * @depends  api/bootstrap.php
 * @spec     FLEETFORGE_SPEC_FINAL.md §11 Equipment State Machine
 * @decisions D20 (FOR UPDATE on unit row)
 * @session  S-BULK-STATUS
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('equipment', 'edit');

$body      = json_body();
$ids       = $body['ids']       ?? null;
$newStatus = clean_string($body['new_status'] ?? null);

// ── Validate new_status ────────────────────────────────────────────
// WHY explicit allowlist: 'reserved'/'on_lease' are set implicitly by their
// own workflows; 'decommissioned' is terminal and must not be applied in bulk.
const BULK_STATUS_ALLOWED_TARGETS = ['available', 'maintenance', 'inactive'];

if (!$newStatus) {
    json_error('VALIDATION_ERROR', 'new_status is required.', 422);
}
if (!in_array($newStatus, BULK_STATUS_ALLOWED_TARGETS, true)) {
    json_error(
        'VALIDATION_ERROR',
        "new_status must be one of: " . implode(', ', BULK_STATUS_ALLOWED_TARGETS) . ".",
        422
    );
}

// ── Validate ids array ─────────────────────────────────────────────
if (!is_array($ids) || count($ids) === 0) {
    json_error('VALIDATION_ERROR', 'ids must be a non-empty array.', 422);
}
if (count($ids) > 100) {
    json_error('VALIDATION_ERROR', 'ids must contain at most 100 entries.', 422);
}

// Coerce to positive ints; strip anything that resolves to 0 or below
$cleanIds = array_values(array_filter(
    array_map('intval', $ids),
    static fn(int $v): bool => $v > 0
));

if (count($cleanIds) === 0) {
    json_error('VALIDATION_ERROR', 'ids must contain at least one valid positive integer.', 422);
}

// ── State-machine map (mirrors update_status.php §11) ─────────────
// Reproduced here rather than requiring the other endpoint so this file
// is self-contained. Must stay in sync with update_status.php::UNIT_STATUS_TRANSITIONS.
const BULK_UNIT_STATUS_TRANSITIONS = [
    'available'      => ['reserved', 'maintenance', 'inactive'],
    'reserved'       => ['available'],
    'on_lease'       => ['available', 'maintenance'],
    'maintenance'    => ['available', 'inactive', 'decommissioned'],
    'inactive'       => ['available', 'decommissioned'],
    'decommissioned' => [], // TERMINAL
];

$userId    = current_user_id();
$user      = current_user();
$userName  = $user['name'] ?? 'system';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($cleanIds as $id) {
    // ── Fetch unit outside the transaction so we can skip cheaply ──
    // WHY outside: avoid holding a lock while emitting skip responses;
    // the real lock is acquired inside via FOR UPDATE.
    $unit = db_row(
        "SELECT id, unit_number, status
           FROM equipment_units
          WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$unit) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Not found or already deleted.'];
        continue;
    }

    // ── Per-unit transaction ───────────────────────────────────────
    try {
        db_transaction(function () use ($id, $newStatus, $userId, $userName, $ipAddress, &$actioned, &$skipped, &$errors): void {
            // D20: acquire row-level lock before reading current status
            $unit = db_row(
                "SELECT id, unit_number, status
                   FROM equipment_units
                  WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            // Unit may have been concurrently deleted since the pre-check
            if (!$unit) {
                $skipped++;
                $errors[] = ['id' => $id, 'reason' => 'Not found or deleted by concurrent request.'];
                return;
            }

            $oldStatus   = $unit['status'];
            $allowedNext = BULK_UNIT_STATUS_TRANSITIONS[$oldStatus] ?? [];

            // Idempotent no-op: already at target status
            if ($oldStatus === $newStatus) {
                $actioned++;
                return;
            }

            // Transition guard
            if (!in_array($newStatus, $allowedNext, true)) {
                $skipped++;
                $errors[] = [
                    'id'     => $id,
                    'reason' => "Unit {$unit['unit_number']}: cannot transition from '{$oldStatus}' to '{$newStatus}'.",
                ];
                return;
            }

            // 3. Update unit status
            db_execute(
                "UPDATE equipment_units
                    SET status = ?, updated_by = ?, updated_at = NOW()
                  WHERE id = ?",
                [$newStatus, $userId, $id]
            );

            // 4. equipment_status_log
            db_insert('equipment_status_log', [
                'equipment_unit_id'  => $id,
                'old_status'         => $oldStatus,
                'new_status'         => $newStatus,
                'reason'             => 'Bulk status update',
                'changed_by'         => $userName,
                'changed_by_user_id' => $userId,
            ]);

            // 5. audit_log
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'equipment',
                'entity_type'  => 'equipment_unit',
                'entity_id'    => $id,
                'entity_label' => $unit['unit_number'],
                'notes'        => "Bulk status change: {$oldStatus} → {$newStatus}",
                'old_values'   => json_encode(['status' => $oldStatus]),
                'new_values'   => json_encode(['status' => $newStatus, 'reason' => 'Bulk status update']),
                'ip_address'   => $ipAddress,
            ]);

            $actioned++;
        });
    } catch (\Throwable $e) {
        // DB/unexpected failure on this ID — log and continue with the rest
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Database error: ' . $e->getMessage()];
    }
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
