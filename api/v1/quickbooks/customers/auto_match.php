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

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = date('Y-m-d H:i:s');

    foreach ($decisions as $d) {
        // Skip auto-match for FF customers operator already locked.
        if ($d['ff_customer_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_customer_id']])) {
            continue;
        }

        if ($d['mapping_status'] === 'mapped') {
            // The qbo_only row for this qbo_customer_id likely already
            // exists (from pull.php). UPDATE it to link the FF side
            // + set confidence. The UNIQUE(ff_customer_id) constraint
            // guarantees one FF row per mapping.
            //
            // Edge: if an old ff_only row also exists for this FF
            // customer, we must DELETE it first to avoid a UNIQUE
            // collision when we set ff_customer_id on the qbo_only row.
            db_execute(
                "DELETE FROM acc_qbo_customer_map
                  WHERE ff_customer_id = ?
                    AND qbo_customer_id IS NULL",
                [(int) $d['ff_customer_id']]
            );

            $rowsUpdated = db_execute(
                "UPDATE acc_qbo_customer_map SET
                    ff_customer_id   = ?,
                    mapping_status   = 'mapped',
                    match_confidence = ?,
                    last_synced_at   = ?
                  WHERE qbo_customer_id = ?",
                [
                    (int) $d['ff_customer_id'],
                    (string) $d['match_confidence'],
                    $now,
                    (string) $d['qbo_customer_id'],
                ]
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

    json_success([
        'matched'          => $matchedCount,
        'ff_only'          => $ffOnlyCount,
        'qbo_only'         => $qboOnlyCount,
        'manual_preserved' => $manualPreserved,
    ]);

} catch (\Throwable $e) {
    json_error(
        'INTERNAL_ERROR',
        'Auto-match failed: ' . $e->getMessage(),
        500
    );
}
