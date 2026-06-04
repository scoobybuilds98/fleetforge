<?php
declare(strict_types=1);

/**
 * FleetForge — Yard Bulk Activate API
 *
 * @file        api/v1/yards/bulk_activate.php
 * @description Bulk-activates up to 100 yards in a single request (sets
 *              is_active = 1). No guard is needed for activation — a yard can
 *              always be re-enabled regardless of reservation state.
 *
 *              Each ID is processed independently inside its own db_transaction;
 *              one failure never aborts the rest of the batch.
 *
 *              Skip conditions (counted in skipped, reported in errors[]):
 *                - Yard not found
 *                - Yard is already active (idempotent no-op)
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

// WHY role check (not require_permission): activation is a yard-management
// operation scoped to super_admin and manager, matching deactivate and delete.
if (!in_array(current_user()['role_slug'] ?? '', ['super_admin', 'manager'], true)) {
    json_error('FORBIDDEN', 'Insufficient permissions to activate yards.', 403);
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

$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($cleanIds as $id) {
    try {
        db_transaction(function () use (
            $id,
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

            // Idempotency: already active — skip without error
            if ((int) $yard['is_active']) {
                $skipped++;
                return;
            }

            // Apply the activation — no guard needed for re-enabling a yard
            db_execute("UPDATE yards SET is_active = 1 WHERE id = ?", [$id]);

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $actorName,
                'action'       => 'update',
                'module'       => 'settings',
                'entity_type'  => 'yard',
                'entity_id'    => $id,
                'entity_label' => $yard['name'],
                'notes'        => "Yard '{$yard['name']}' activated (bulk)",
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
