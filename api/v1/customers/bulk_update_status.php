<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Bulk Status Update API
 *
 * @file        api/v1/customers/bulk_update_status.php
 * @description Bulk-changes the status of up to 100 customers in a single
 *              request. Only 'active' and 'inactive' are supported as targets
 *              (the two simplest, most-useful bulk operations). Each ID is
 *              processed independently inside its own db_transaction; one
 *              failure never aborts the rest.
 *
 *              Skip conditions (counted in skipped, reported in errors[]):
 *                - Customer not found or soft-deleted
 *                - Customer already at target status (silent idempotency)
 *                - Moving to 'inactive' while active_lease_count > 0
 *
 * @method      POST
 * @body        JSON { "ids": [1, 2, 3], "status": "active"|"inactive" }
 * @required    ids (int[], 1-100 positive integers), status (string)
 * @auth        Session required; require_permission('customers','edit')
 * @returns     200 {
 *                "success": true,
 *                "data": {
 *                  "actioned": N,
 *                  "skipped":  N,
 *                  "errors":   [{ "id": N, "reason": "..." }]
 *                }
 *              }
 *              400 INVALID_IDS   — ids missing / not array / out of range / non-positive
 *              400 INVALID_STATUS — status missing or not 'active'/'inactive'
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'edit');

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

// ── Validate target status ─────────────────────────────────────────────────
// WHY bulk only supports 'active'/'inactive': these are the only two operations
// that make sense to apply to large sets without operator review. Transitions
// to suspended/credit_hold/pending carry individual business context.

$BULK_ALLOWED_TARGETS = ['active', 'inactive'];

$targetStatus = isset($body['status']) ? (string) $body['status'] : null;

if ($targetStatus === null || $targetStatus === '') {
    json_error('INVALID_STATUS', 'status is required.', 400);
}

if (!in_array($targetStatus, $BULK_ALLOWED_TARGETS, true)) {
    json_error(
        'INVALID_STATUS',
        "status must be 'active' or 'inactive'. Received: " . htmlspecialchars($targetStatus, ENT_QUOTES, 'UTF-8'),
        400
    );
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
            $targetStatus,
            $userId,
            $actorName,
            $ipAddress,
            &$actioned,
            &$skipped,
            &$errors
        ): void {
            // Load the customer; skip if not found or already soft-deleted
            $customer = db_row(
                "SELECT id, company_name, status
                   FROM customers
                  WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );

            if (!$customer) {
                $skipped++;
                $errors[] = [
                    'id'     => $id,
                    'reason' => 'Customer not found or already deleted.',
                ];
                return;
            }

            $oldStatus = $customer['status'];

            // Idempotency: already at target status — skip without error
            if ($oldStatus === $targetStatus) {
                $skipped++;
                return;
            }

            // Block deactivation when the customer has active leases.
            // active_lease_count is never maintained — use the live COUNT.
            if ($targetStatus === 'inactive' && db_count(
                "SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
                [$id]
            ) > 0) {
                $skipped++;
                $errors[] = [
                    'id'     => $id,
                    'reason' => 'Has active leases',
                ];
                return;
            }

            // Apply the status change
            db_update(
                'customers',
                [
                    'status'     => $targetStatus,
                    'updated_by' => $userId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                'id = ?',
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $actorName,
                'action'       => 'status_change',
                'module'       => 'customers',
                'entity_type'  => 'customer',
                'entity_id'    => $id,
                'entity_label' => $customer['company_name'],
                'notes'        => "Customer status changed (bulk): {$oldStatus} → {$targetStatus}",
                'old_values'   => json_encode(['status' => $oldStatus]),
                'new_values'   => json_encode(['status' => $targetStatus]),
                'ip_address'   => $ipAddress,
            ]);

            $actioned++;
        });

    } catch (Throwable $e) {
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
