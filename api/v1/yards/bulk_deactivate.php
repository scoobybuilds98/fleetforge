<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Bulk Deactivate API
 *
 * @file        api/v1/yards/bulk_deactivate.php
 * @description Bulk-deactivates up to 100 yards in a single request (sets
 *              is_active = 0). Yards have no deleted_at column — deactivation
 *              is the removal mechanism; historical reservation snapshots are
 *              preserved.
 *
 *              Each ID is processed independently inside its own db_transaction;
 *              one failure never aborts the rest of the batch.
 *
 *              Skip conditions (counted in skipped, reported in errors[]):
 *                - Yard not found
 *                - Yard is already inactive (idempotent no-op)
 *                - Yard has upcoming confirmed reservations
 *                  (pickup_date >= today, status IN pending/confirmed,
 *                   deleted_at IS NULL) — cancel or move those first
 *
 * @method      POST
 * @body        JSON { "ids": [1, 2, 3] }
 * @required    ids (int[], 1–100 positive integers)
 * @auth        Session required; super_admin or manager role
 * @returns     200 {
 *                "success": true,
 *                "data": {
 *                  "actioned": N,
 *                  "skipped":  N,
 *                  "errors":   [{ "id": N, "reason": "..." }]
 *                }
 *              }
 *              400 INVALID_IDS   — ids missing / not array / empty / over-100 / non-positive
 *              403 FORBIDDEN     — caller is not super_admin or manager
 *
 * @depends     api/bootstrap.php
 */

// dirname(__DIR__, 3): api/v1/yards/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();

// WHY role check (not require_permission): deactivating a yard is a destructive
// yard-management operation scoped to super_admin and manager, matching delete.php.
if (!in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager'], true)) {
    json_error('FORBIDDEN', 'Insufficient permissions to deactivate yards.', 403);
}

$body = json_body();

// ── Validate ids array ─────────────────────────────────────────────────────

$ids = $body['ids'] ?? null;

if (!is_array($ids)) {
    json_error('INVALID_IDS', 'ids must be a non-empty array of integers.', 400);
}

if (count($ids) === 0) {
    json_error('INVALID_IDS', 'ids must contain at least one entry.', 400);
}

if (count($ids) > 100) {
    json_error('INVALID_IDS', 'ids may contain at most 100 entries per request.', 400);
}

// Coerce and validate each element — all must be positive integers
$cleanIds = [];
foreach ($ids as $raw) {
    $val = clean_int($raw);
    if (!$val || $val <= 0) {
        json_error('INVALID_IDS', 'Every entry in ids must be a positive integer.', 400);
    }
    $cleanIds[] = $val;
}

// ── Process each ID independently ─────────────────────────────────────────

$userId    = current_user_id();
$actorName = current_user()['name'] ?? 'unknown';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$today     = date('Y-m-d');

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($cleanIds as $id) {
    try {
        db_transaction(function () use (
            $id,
            $today,
            $userId,
            $actorName,
            $ipAddress,
            &$actioned,
            &$skipped,
            &$errors
        ): void {
            // Load the yard; yards have no deleted_at so we only check existence
            $yard = db_row(
                "SELECT id, name, is_active FROM yards WHERE id = ?",
                [$id]
            );

            if (!$yard) {
                $skipped++;
                $errors[] = [
                    'id'     => $id,
                    'reason' => 'Yard not found.',
                ];
                return;
            }

            // Idempotency: already inactive — skip without error
            if (!(int) $yard['is_active']) {
                $skipped++;
                return;
            }

            // Guard: upcoming reservations at this yard.
            // WHY yard_location is matched by name: reservations stores a
            // snapshot of the yard name string (no FK), matching delete.php.
            $upcomingCount = db_count(
                "SELECT COUNT(*) FROM reservations
                  WHERE yard_location = ?
                    AND pickup_date >= ?
                    AND status IN ('pending','confirmed')
                    AND deleted_at IS NULL",
                [$yard['name'], $today]
            );

            if ($upcomingCount > 0) {
                $skipped++;
                $errors[] = [
                    'id'     => $id,
                    'reason' => "Cannot deactivate '{$yard['name']}' — it has {$upcomingCount} upcoming reservation(s). " .
                                "Cancel or move those reservations first.",
                ];
                return;
            }

            // Apply the deactivation
            db_execute("UPDATE yards SET is_active = 0 WHERE id = ?", [$id]);

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $actorName,
                'action'       => 'delete',
                'module'       => 'settings',
                'entity_type'  => 'yard',
                'entity_id'    => $id,
                'entity_label' => $yard['name'],
                'notes'        => "Yard '{$yard['name']}' deactivated (bulk)",
                'ip_address'   => $ipAddress,
            ]);

            $actioned++;
        });

    } catch (\Throwable $e) {
        // Unexpected DB or application error — isolate, log, continue the batch
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Unexpected error: ' . $e->getMessage(),
        ];
    }
}

json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
