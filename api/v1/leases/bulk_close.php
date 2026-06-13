<?php
declare(strict_types=1);

/**
 * FleetForge — Lease Bulk Close API
 *
 * @file        api/v1/leases/bulk_close.php
 * @description Simplified bulk close of up to 100 active leases in a single request.
 *              Uses today's date as the actual_return_date for every lease.
 *              Each ID is processed independently with its own transaction so a failure
 *              on one record never rolls back the others (partial success).
 *
 *              Intentionally simplified vs. close.php:
 *                - No final invoice generation (invoices are handled per-lease close or
 *                  by the monthly billing cron).
 *                - No precharge refund dispatch — leases with a positive precharge_balance
 *                  are skipped and must be closed individually via close.php so the
 *                  operator can select a refund method.
 *                - No advance billing reconciliation.
 *                - No mileage/odometer inputs.
 *
 *              State changes per valid lease:
 *                1. FOR UPDATE lock on equipment_unit (D20: prevents race with billing cron)
 *                2. leases: status→'completed', actual_return_date=today, closed_at, closed_by
 *                3. equipment_units: status→'available' (when equipment_unit_id is set)
 *                4. equipment_status_log entry ('on_lease' → 'available')
 *                5. lease_status_log entry ('active' → 'completed', when table exists)
 *                6. audit_log entry (action='status_change', module='leases')
 *
 *              Skip conditions (recorded in errors[]):
 *                - Lease not found or soft-deleted
 *                - status != 'active'  → "Not an active lease"
 *                - precharge_balance > 0 → "Has outstanding precharge balance — close individually"
 *
 * @method      POST
 * @body        JSON { "ids": [1, 2, 3] }   // array of int, max 100, all positive ints
 * @auth        Session required; require_permission('leases', 'edit')
 * @returns     200 { success: true, data: { actioned: N, skipped: N, errors: [{id, reason}] } }
 *              422 VALIDATION_ERROR if ids is missing, empty, not all positive ints, or > 100
 *
 * @depends     api/bootstrap.php
 * @spec        FLEETFORGE_SPEC_FINAL.md §7.5 Leases
 * @decisions   D20 (FOR UPDATE on unit), D5 (soft-delete awareness)
 * @session     S-BULK-CLOSE-LEASES
 */

require_once dirname(__DIR__, 3) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('leases', 'edit');

$body = json_body();
$ids  = $body['ids'] ?? null;

// ── Input validation ───────────────────────────────────────────
if (!is_array($ids) || count($ids) === 0) {
    json_error('VALIDATION_ERROR', 'ids must be a non-empty array.', 422);
}

if (count($ids) > 100) {
    json_error('VALIDATION_ERROR', 'ids must contain at most 100 entries.', 422);
}

// Coerce to clean positive ints; drop any invalid (zero/null/non-numeric) elements
$cleanIds = [];
foreach ($ids as $raw) {
    $int = clean_int($raw);
    if ($int && $int > 0) {
        $cleanIds[] = $int;
    }
}

if (count($cleanIds) === 0) {
    json_error('VALIDATION_ERROR', 'ids must contain at least one valid positive integer.', 422);
}

// ── Shared context for the entire batch ───────────────────────
$userId    = current_user_id();
$user      = current_user();
$userName  = $user['name'] ?? 'system';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$today     = date('Y-m-d');

// ── lease_status_log existence check (once per request) ───────
// WHY: lease_status_log is a relatively new table; guard rather than
// crash on environments that haven't run the migration yet.
$leaseStatusLogExists = (bool) db_row("SHOW TABLES LIKE 'lease_status_log'", []);

// ── Counters ───────────────────────────────────────────────────
$actioned = 0;
$skipped  = 0;
$errors   = [];

foreach ($cleanIds as $id) {
    // ── Fetch lease: must exist and not be soft-deleted ────────
    $lease = db_row(
        "SELECT id, status, contract_number, equipment_unit_id,
                start_date, last_billed_date,
                precharge_balance, precharge_enabled
           FROM leases
          WHERE id = ? AND deleted_at IS NULL",
        [$id]
    );

    if (!$lease) {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Not found or already deleted.'];
        continue;
    }

    // ── Skip non-active leases ─────────────────────────────────
    if ($lease['status'] !== 'active') {
        $skipped++;
        $errors[] = ['id' => $id, 'reason' => 'Not an active lease.'];
        continue;
    }

    // ── Skip leases with an outstanding precharge balance ──────
    // WHY: bulk close intentionally skips the precharge refund flow.
    // Operator must use close.php individually to choose cash or credit.
    $prechargeBalance = $lease['precharge_balance'] !== null
        ? (string) $lease['precharge_balance']
        : '0.00';
    if (bccomp($prechargeBalance, '0', 2) > 0) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Has outstanding precharge balance — close individually.',
        ];
        continue;
    }

    // ── Skip leases with an unbilled final period (MEDIUM [03]) ───────────────
    // bulk_close does NOT generate the partial_end final invoice that close.php
    // produces, and the monthly cron only bills 'active' leases — so days between
    // the last billed period and the close date would silently go unbilled
    // (revenue loss). Mirror close.php's coverage check: coverageEnd =
    // MAX(billing_period_end) over non-void invoices; if the next period would
    // start on or before today, the lease has a billable tail and MUST be closed
    // individually (where the final invoice + advance/mileage reconciliation run).
    $coverageEnd = db_row(
        "SELECT MAX(billing_period_end) AS max_end
           FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL
            AND status <> 'void' AND billing_period_end IS NOT NULL",
        [$id]
    )['max_end'] ?? ($lease['last_billed_date'] ?: null);
    $tailStart = $coverageEnd
        ? date('Y-m-d', strtotime($coverageEnd . ' +1 day'))
        : ($lease['start_date'] ?: null);
    if ($tailStart !== null && $tailStart <= $today) {
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Has an unbilled final period — close individually so the final invoice is generated.',
        ];
        continue;
    }

    // ── Per-lease transaction ──────────────────────────────────
    // WHY isolated: one DB failure must never abort the rest of the batch.
    try {
        db_transaction(function () use (
            $id, $lease, $userId, $userName, $ipAddress,
            $today, $leaseStatusLogExists
        ): void {
            // ── 1. D20: FOR UPDATE lock on equipment unit ──────
            // Prevents race with monthly billing cron which also writes
            // to the lease row. Only lock when a unit is assigned.
            if ($lease['equipment_unit_id']) {
                db_row(
                    "SELECT id FROM equipment_units
                      WHERE id = ? AND deleted_at IS NULL
                      FOR UPDATE",
                    [$lease['equipment_unit_id']]
                );
            }

            // ── 2. Update lease → completed ────────────────────
            db_execute(
                "UPDATE leases
                    SET status             = 'completed',
                        actual_return_date = ?,
                        closed_at          = NOW(),
                        closed_by_user_id  = ?,
                        updated_by         = ?
                  WHERE id = ?",
                [$today, $userId, $userId, $id]
            );

            // ── 3. Update equipment_unit → available ───────────
            // Only update when a unit is actually assigned to this lease.
            if ($lease['equipment_unit_id']) {
                db_execute(
                    "UPDATE equipment_units
                        SET status     = 'available',
                            updated_by = ?,
                            updated_at = NOW()
                      WHERE id = ?",
                    [$userId, $lease['equipment_unit_id']]
                );

                // ── 4. equipment_status_log ────────────────────
                db_insert('equipment_status_log', [
                    'equipment_unit_id'  => $lease['equipment_unit_id'],
                    'old_status'         => 'on_lease',
                    'new_status'         => 'available',
                    'reason'             => 'Bulk lease close',
                    'changed_by'         => $userName,
                    'changed_by_user_id' => $userId,
                ]);
            }

            // ── 5. lease_status_log (when table exists) ────────
            if ($leaseStatusLogExists) {
                db_insert('lease_status_log', [
                    'lease_id'   => $id,
                    'old_status' => 'active',
                    'new_status' => 'completed',
                    'notes'      => 'Bulk lease close',
                    'changed_by' => $userName,
                    'user_id'    => $userId,
                ]);
            }

            // ── 6. audit_log ───────────────────────────────────
            db_insert('audit_log', [
                'user_id'      => $userId,
                'user_name'    => $userName,
                'action'       => 'status_change',
                'module'       => 'leases',
                'entity_type'  => 'lease',
                'entity_id'    => $id,
                'entity_label' => $lease['contract_number'],
                'notes'        => 'Bulk close: lease completed',
                'old_values'   => json_encode([
                    'status' => 'active',
                ]),
                'new_values'   => json_encode([
                    'status'             => 'completed',
                    'actual_return_date' => $today,
                    'closed_by_user_id'  => $userId,
                ]),
                'ip_address'   => $ipAddress,
            ]);
        });

        $actioned++;
    } catch (\Throwable $e) {
        // Transaction rolled back — record failure and continue with remaining IDs
        $skipped++;
        $errors[] = [
            'id'     => $id,
            'reason' => 'Database error: ' . $e->getMessage(),
        ];
    }
}

// ── Response ───────────────────────────────────────────────────
json_success([
    'actioned' => $actioned,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
