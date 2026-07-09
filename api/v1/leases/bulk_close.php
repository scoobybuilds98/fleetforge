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
// S-CLOSE-OVERSHOOT: shared reconciliation helpers (same definitions close.php uses).
require_once __DIR__ . '/_close_reconciliation.php';

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
        "SELECT l.id, l.status, l.contract_number, l.customer_id, l.equipment_unit_id,
                l.company_name_snapshot, l.customer_name_snapshot,
                l.start_date, l.last_billed_date,
                l.precharge_balance, l.precharge_enabled,
                c.province AS province
           FROM leases l
           LEFT JOIN customers c ON c.id = l.customer_id AND c.deleted_at IS NULL
          WHERE l.id = ? AND l.deleted_at IS NULL",
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
    // S-CLOSE-OVERSHOOT: restrict the anchor to invoices STARTING on/before today
    // so a future-dated invoice (billing_period_start > today) cannot mask a real
    // unbilled tail — without this, such a row pushes coverageEnd past today and
    // the lease is wrongly NOT skipped, leaving the real gap unbilled.
    // S-AUDIT-LIFECYCLE-1 #19a: NO last_billed_date fallback — close.php
    // explicitly forbids trusting that anchor for coverage (S-CLOSE-ZEROBILL:
    // it is GREATEST-monotonic and survives voids, so after a reopen/void
    // cycle a stale month-end value masked a REAL unbilled tail and the lease
    // bulk-closed with revenue silently unbilled). Live invoices are the only
    // coverage truth; no live coverage ⇒ tail starts at start_date ⇒ skip.
    $coverageEnd = db_row(
        "SELECT MAX(billing_period_end) AS max_end
           FROM invoices
          WHERE lease_id = ? AND deleted_at IS NULL
            AND status <> 'void' AND billing_period_end IS NOT NULL
            AND billing_period_start <= ?",
        [$id, $today]
    )['max_end'] ?? null;
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
            // ── 1. D20 + L01 parity (S-AUDIT-LIFECYCLE-1 #6): lease-row
            // FOR UPDATE FIRST (lease-before-unit, matching close.php:533 and
            // activate.php), then re-check status UNDER the lock — the gates
            // above ran on an unlocked pre-txn read, so a racing individual
            // close could complete this lease first and this bulk pass would
            // overwrite its actual_return_date/closed_at and duplicate logs.
            // Also re-check the precharge balance (#19/11): a drawdown that
            // landed mid-flight must divert the lease to the individual close
            // (bulk deliberately skips the refund flow — a completed lease
            // with a positive balance and no method has NO settle path).
            $locked = db_row(
                "SELECT status, precharge_balance FROM leases
                  WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );
            if (!$locked || $locked['status'] !== 'active') {
                throw new \RuntimeException('Lease is no longer active (closed concurrently).');
            }
            if ($locked['precharge_balance'] !== null
                && bccomp((string) $locked['precharge_balance'], '0', 2) > 0
            ) {
                throw new \RuntimeException('Precharge balance changed mid-flight — close individually.');
            }
            if ($lease['equipment_unit_id']) {
                db_row(
                    "SELECT id FROM equipment_units
                      WHERE id = ? AND deleted_at IS NULL
                      FOR UPDATE",
                    [$lease['equipment_unit_id']]
                );
            }

            // ── 2. Update lease → completed ────────────────────
            // Belt-and-suspenders AND status='active' (I05 pattern) on top of
            // the locked re-check above.
            $affected = db_execute(
                "UPDATE leases
                    SET status             = 'completed',
                        actual_return_date = ?,
                        closed_at          = NOW(),
                        closed_by_user_id  = ?,
                        updated_by         = ?
                  WHERE id = ? AND status = 'active'",
                [$today, $userId, $userId, $id]
            );
            if ($affected === 0) {
                throw new \RuntimeException('Lease status changed concurrently — not closed by bulk.');
            }

            // ── 2b. S-CLOSE-OVERSHOOT: clamp any non-advance rental invoice
            // billed past today (the bulk close-out date). bulk_close does not
            // capture a return time, so the extent is the date-only $today.
            // Without this, a lease started mid-month (partial_start invoice
            // billed to month-end) and bulk-closed mid-month would keep the
            // over-billed month-end period — the same bug close.php now fixes.
            reconcile_overshoot_invoices(
                $id, $lease, $today,
                "Bulk close {$today} — billing period shortened to lease extent {$today}.",
                true // bulk_close has no legacy_handle + bills no mileage → clamp full_month straddles here too
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
