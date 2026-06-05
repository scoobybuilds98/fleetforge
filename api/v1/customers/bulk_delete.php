<?php
declare(strict_types=1);

/**
 * FleetForge — Customer Bulk Soft-Delete API
 *
 * @file        api/v1/customers/bulk_delete.php
 * @description Soft-deletes up to 100 customers in a single request.
 *              Each ID is processed independently; one failure does not abort
 *              the rest. Blocks deletion per-record if the customer has active
 *              leases (active_lease_count > 0 OR live COUNT) or active
 *              reservations (pending/confirmed). Soft-deletes by setting
 *              deleted_at to NOW(). Audit-logs each successful deletion.
 *
 * @method      POST
 * @body        JSON { "ids": [1, 2, 3] }   // array of int, max 100
 * @required    ids (int[])
 * @auth        Session required; require_permission('customers','delete')
 * @returns     200 {
 *                "success": true,
 *                "data": {
 *                  "deleted": N,
 *                  "skipped": N,
 *                  "errors":  [{ "id": N, "reason": "..." }]
 *                }
 *              }
 *              400 INVALID_IDS if ids is missing, not an array, exceeds 100,
 *                  or contains non-positive integers
 *
 * @depends     api/bootstrap.php, includes/auth.php, includes/functions.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.2 Customers Module
 */

// dirname(__DIR__, 3): api/v1/customers/ → api/v1/ → api/ → project root
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('customers', 'delete');

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
$deletedAt = date('Y-m-d H:i:s');

$deleted = 0;
$skipped = 0;
$errors  = [];

foreach ($cleanIds as $id) {
    try {
        // Load the customer; skip quietly if already deleted or never existed
        $customer = db_row(
            "SELECT id, company_name
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
            continue;
        }

        // active_lease_count is never maintained by lease endpoints — rely solely
        // on the live COUNT, which is the authoritative source.
        $activeLeasesActual = db_count(
            "SELECT COUNT(*)
               FROM leases
              WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL",
            [$id]
        );

        if ($activeLeasesActual > 0) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => 'Customer has active leases and cannot be deleted.',
            ];
            continue;
        }

        // Block if customer has pending or confirmed reservations
        $activeReservations = db_count(
            "SELECT COUNT(*)
               FROM reservations
              WHERE customer_id = ? AND status IN ('pending','confirmed') AND deleted_at IS NULL",
            [$id]
        );

        if ($activeReservations > 0) {
            $skipped++;
            $errors[] = [
                'id'     => $id,
                'reason' => 'Customer has active reservations and cannot be deleted.',
            ];
            continue;
        }

        // Each deletion gets its own transaction so one DB failure is isolated
        db_transaction(function () use ($id, $userId, $actorName, $deletedAt, $customer): void {
            db_update(
                'customers',
                ['deleted_at' => $deletedAt, 'updated_by' => $userId],
                'id = ?',
                [$id]
            );

            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $actorName,
                'action'       => 'delete',
                'module'       => 'customers',
                'entity_type'  => 'customer',
                'entity_id'    => $id,
                'entity_label' => $customer['company_name'],
                'old_values'   => json_encode(['deleted_at' => null]),
                'new_values'   => json_encode(['deleted_at' => $deletedAt]),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        });

        $deleted++;

    } catch (Throwable $e) {
        // Unexpected error — count as skipped, surface reason in errors array
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Unexpected error: ' . $e->getMessage(),
        ];
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
