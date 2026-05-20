<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/vendors/auto_match.php
 *
 * Run the auto-matching cascade (VendorMatcher::matchAll) against
 * the current acc_qbo_vendor_map state. User-initiated via "Auto-
 * Match" button on the Vendors Sync page — typically clicked AFTER
 * a fresh pull.
 *
 * Decision policy (mirrors customers auto_match S-QBO-5):
 *   - Reads QBO snapshot data from acc_qbo_vendor_map (no live HTTP
 *     to QBO — auto_match operates on what pull.php last fetched).
 *   - Calls VendorMatcher::matchAll($qboVendors) which compares
 *     every active FF vendor against the QBO list.
 *   - Writes decisions back to acc_qbo_vendor_map:
 *       * For 'mapped' decisions: UPDATE existing ff_only row (or
 *         existing qbo_only row) to link both sides.
 *       * For 'ff_only' decisions: ensure an ff_only row exists for
 *         the FF vendor (INSERT if missing).
 *       * For 'qbo_only' decisions: no-op (pull.php already created
 *         the qbo_only row).
 *   - PRESERVES rows where match_confidence='manual' — operator
 *     overrides win over auto-match every time.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view') — writes only to mapping table
 * @returns 200 { success: true, matched: int, ff_only: int, qbo_only: int, manual_preserved: int }
 *
 * Spec ref: §7.5
 * Session:  S-QBO-7
 * Decision: D-QBO-5-2 (auto-match cascade preserves manual overrides)
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\VendorMatcher;

try {
    // ── Build the QBO vendor list from existing snapshot data ──
    // Pull from acc_qbo_vendor_map (the pull.php side-effect) rather
    // than calling QBO live — saves an HTTP round-trip and ensures
    // the matcher sees exactly what the operator's UI sees.
    $rows = db_select(
        "SELECT qbo_vendor_id, qbo_display_name, qbo_company_name, qbo_email, qbo_phone
           FROM acc_qbo_vendor_map
          WHERE qbo_vendor_id IS NOT NULL"
    );

    $qboVendors = [];
    foreach ($rows as $r) {
        $qboVendors[] = [
            'qbo_id'       => (string) $r['qbo_vendor_id'],
            'display_name' => (string) ($r['qbo_display_name'] ?? ''),
            'company_name' => (string) ($r['qbo_company_name'] ?? ''),
            'email'        => (string) ($r['qbo_email'] ?? ''),
            'phone'        => (string) ($r['qbo_phone'] ?? ''),
        ];
    }

    // ── Identify FF vendors that already have manual mappings ──
    // These are off-limits to auto-match.
    $manualLockedFfIds = [];
    $manualRows = db_select(
        "SELECT ff_vendor_id FROM acc_qbo_vendor_map
          WHERE ff_vendor_id IS NOT NULL
            AND match_confidence = 'manual'"
    );
    foreach ($manualRows as $m) {
        $manualLockedFfIds[(int) $m['ff_vendor_id']] = true;
    }
    $manualPreserved = count($manualLockedFfIds);

    // ── Run the cascade ──
    // matchAll() pulls FF vendors internally; we filter its decisions
    // to skip ones for FF vendors under manual lock.
    $decisions = VendorMatcher::matchAll($qboVendors);

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = date('Y-m-d H:i:s');

    foreach ($decisions as $d) {
        // Skip auto-match for FF vendors operator already locked.
        if ($d['ff_vendor_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_vendor_id']])) {
            continue;
        }

        if ($d['mapping_status'] === 'mapped') {
            // The qbo_only row for this qbo_vendor_id likely already
            // exists (from pull.php). UPDATE it to link the FF side
            // + set confidence. UNIQUE(ff_vendor_id) guarantees one
            // FF row per mapping. Drop any pre-existing ff_only row
            // for this FF vendor first to avoid the collision.
            db_execute(
                "DELETE FROM acc_qbo_vendor_map
                  WHERE ff_vendor_id = ?
                    AND qbo_vendor_id IS NULL",
                [(int) $d['ff_vendor_id']]
            );

            $rowsUpdated = db_execute(
                "UPDATE acc_qbo_vendor_map SET
                    ff_vendor_id     = ?,
                    mapping_status   = 'mapped',
                    match_confidence = ?,
                    last_synced_at   = ?
                  WHERE qbo_vendor_id = ?",
                [
                    (int) $d['ff_vendor_id'],
                    (string) $d['match_confidence'],
                    $now,
                    (string) $d['qbo_vendor_id'],
                ]
            );

            if ($rowsUpdated > 0) {
                $matchedCount++;
            }
            continue;
        }

        if ($d['mapping_status'] === 'ff_only') {
            // Ensure an ff_only row exists. INSERT … ON DUPLICATE KEY
            // UPDATE handles the case where the row already exists
            // (e.g., set by a prior auto_match run). Preserves any
            // manual-confidence row from being demoted.
            db_execute(
                "INSERT INTO acc_qbo_vendor_map
                    (ff_vendor_id, mapping_status, created_by_user_id)
                 VALUES (?, 'ff_only', ?)
                 ON DUPLICATE KEY UPDATE
                    mapping_status   = IF(match_confidence='manual', mapping_status, 'ff_only'),
                    match_confidence = IF(match_confidence='manual', match_confidence, NULL)",
                [(int) $d['ff_vendor_id'], $userId]
            );
            $ffOnlyCount++;
            continue;
        }

        if ($d['mapping_status'] === 'qbo_only') {
            // pull.php already inserted the qbo_only row. No-op.
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
