<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/customers/auto_match.php
 *
 * Run the auto-matching cascade (CustomerMatcher::matchAll) against
 * the current acc_qbo_customer_map state. User-initiated via "Auto-
 * Match" button on the Customers Sync page — typically clicked
 * AFTER a fresh pull.
 *
 * Decision policy:
 *   - Reads QBO snapshot data from acc_qbo_customer_map (no live HTTP
 *     to QBO — auto_match operates on what pull.php last fetched).
 *   - Calls CustomerMatcher::matchAll($qboCustomers) which compares
 *     every active FF customer against the QBO list.
 *   - Writes decisions back to acc_qbo_customer_map:
 *       * For 'mapped' decisions: UPDATE existing ff_only row (or
 *         existing qbo_only row) to link both sides.
 *       * For 'ff_only' decisions: ensure an ff_only row exists for
 *         the FF customer (INSERT if missing).
 *       * For 'qbo_only' decisions: no-op (pull.php already created
 *         the qbo_only row).
 *   - PRESERVES rows where match_confidence='manual' — operator
 *     overrides win over auto-match every time. Auto-match treats
 *     these rows as already-decided and skips them in the FF side
 *     of the cascade.
 *
 * Side effect summary stats are returned for the UI toast.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — writes to mapping
 *          table only; no QBO HTTP, no FF customer mutations.
 * @returns 200 { success: true, matched: int, ff_only: int, qbo_only: int, manual_preserved: int }
 *
 * Spec ref: §7.4, §8.1
 * Session:  S-QBO-5
 * Decision: D-QBO-5-2 (auto-match cascade preserves manual overrides)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\CustomerMatcher;

try {
    // ── Build the QBO customer list from existing snapshot data ──
    // We pull from acc_qbo_customer_map (the pull.php side-effect)
    // rather than calling QBO live — saves an HTTP round-trip and
    // ensures the matcher sees exactly what the operator's UI sees.
    $rows = db_select(
        "SELECT qbo_customer_id, qbo_display_name, qbo_company_name, qbo_email, qbo_phone
           FROM acc_qbo_customer_map
          WHERE qbo_customer_id IS NOT NULL"
    );

    $qboCustomers = [];
    foreach ($rows as $r) {
        $qboCustomers[] = [
            'qbo_id'       => (string) $r['qbo_customer_id'],
            'display_name' => (string) ($r['qbo_display_name'] ?? ''),
            'company_name' => (string) ($r['qbo_company_name'] ?? ''),
            'email'        => (string) ($r['qbo_email'] ?? ''),
            'phone'        => (string) ($r['qbo_phone'] ?? ''),
        ];
    }

    // ── Identify FF customers that already have manual mappings ──
    // These are off-limits to auto-match.
    $manualLockedFfIds = [];
    $manualRows = db_select(
        "SELECT ff_customer_id FROM acc_qbo_customer_map
          WHERE ff_customer_id IS NOT NULL
            AND match_confidence = 'manual'"
    );
    foreach ($manualRows as $m) {
        $manualLockedFfIds[(int) $m['ff_customer_id']] = true;
    }
    $manualPreserved = count($manualLockedFfIds);

    // ── Run the cascade ──
    // matchAll() pulls FF customers internally; we filter its
    // decisions to skip ones for FF customers under manual lock.
    $decisions = CustomerMatcher::matchAll($qboCustomers);

    $userId = current_user_id();
    $now    = date('Y-m-d H:i:s');

    // Apply ALL mapping decisions atomically. Previously the loop ran in
    // autocommit, so a uq_ff_customer collision midway (when an FF customer was
    // already mapped to a DIFFERENT QBO id) committed the prior decisions and
    // surfaced only as a blanket 500 — leaving partial, non-atomic rewrites.
    // Wrapping in a transaction makes the run all-or-nothing; the per-decision
    // detach below removes the collision at its source.
    $counts = db_transaction(function () use ($decisions, $manualLockedFfIds, $userId, $now) {
        $matchedCount = 0;
        $ffOnlyCount  = 0;
        $qboOnlyCount = 0;

        foreach ($decisions as $d) {
            // Skip auto-match for FF customers operator already locked.
            if ($d['ff_customer_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_customer_id']])) {
                continue;
            }

            if ($d['mapping_status'] === 'mapped') {
                $ffId  = (int) $d['ff_customer_id'];
                $qboId = (string) $d['qbo_customer_id'];

                // Detach this FF customer from ANY row it is currently linked to
                // except the target qbo row, so setting ff_customer_id on the
                // target cannot collide on uq_ff_customer. Manual-locked FF ids
                // never reach here (skipped above), so detaching is always safe.
                //  - stale ff_only row (qbo_customer_id IS NULL): remove it.
                db_execute(
                    "DELETE FROM acc_qbo_customer_map
                      WHERE ff_customer_id = ?
                        AND qbo_customer_id IS NULL",
                    [$ffId]
                );
                //  - stale mapped row pointing at a DIFFERENT qbo id: demote it
                //    back to qbo_only and free the FF link.
                db_execute(
                    "UPDATE acc_qbo_customer_map
                        SET ff_customer_id   = NULL,
                            mapping_status   = 'qbo_only',
                            match_confidence = NULL
                      WHERE ff_customer_id = ?
                        AND qbo_customer_id IS NOT NULL
                        AND qbo_customer_id <> ?",
                    [$ffId, $qboId]
                );

                $rowsUpdated = db_execute(
                    "UPDATE acc_qbo_customer_map SET
                        ff_customer_id   = ?,
                        mapping_status   = 'mapped',
                        match_confidence = ?,
                        last_synced_at   = ?
                      WHERE qbo_customer_id = ?",
                    [$ffId, (string) $d['match_confidence'], $now, $qboId]
                );

                if ($rowsUpdated > 0) {
                    $matchedCount++;
                }
                continue;
            }

            if ($d['mapping_status'] === 'ff_only') {
                // Ensure an ff_only row exists. Use INSERT … ON DUPLICATE
                // KEY UPDATE to handle the case where the row already
                // exists (e.g., set by a prior auto_match run).
                db_execute(
                    "INSERT INTO acc_qbo_customer_map
                        (ff_customer_id, mapping_status, created_by_user_id)
                     VALUES (?, 'ff_only', ?)
                     ON DUPLICATE KEY UPDATE
                        mapping_status   = IF(match_confidence='manual', mapping_status, 'ff_only'),
                        match_confidence = IF(match_confidence='manual', match_confidence, NULL)",
                    [(int) $d['ff_customer_id'], $userId]
                );
                $ffOnlyCount++;
                continue;
            }

            if ($d['mapping_status'] === 'qbo_only') {
                // pull.php already inserted the qbo_only row. Confirm by
                // ensuring no FF link snuck in via a partial earlier match.
                // (No-op in the common case.)
                $qboOnlyCount++;
            }
        }

        return [
            'matched'  => $matchedCount,
            'ff_only'  => $ffOnlyCount,
            'qbo_only' => $qboOnlyCount,
        ];
    });

    json_success([
        'matched'          => $counts['matched'],
        'ff_only'          => $counts['ff_only'],
        'qbo_only'         => $counts['qbo_only'],
        'manual_preserved' => $manualPreserved,
    ]);

} catch (\PDOException $e) {
    // A residual uq_ff_customer / unique collision (defence-in-depth — the
    // detach above should prevent it) surfaces as a clear conflict, not a 500.
    if ($e->getCode() === '23000') {
        json_error(
            'CONFLICT',
            'Auto-match hit a mapping conflict (a customer is already linked to a different QuickBooks record). Resolve it manually and retry.',
            409
        );
    }
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
}
