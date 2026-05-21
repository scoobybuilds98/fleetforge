<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/accounts/auto_match.php
 *
 * Run AccountMatcher::matchAll against the current acc_qbo_account_map
 * state. User-initiated after a fresh pull. Preserves manual operator
 * overrides (confidence='manual') across re-runs.
 *
 * Cascade per D-QBO-8-3: exact_code → exact_name+type → Levenshtein+type
 * → subtype+token → singleton-type → null. NEVER cross-type matches.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { success: true, matched, ff_only, qbo_only, manual_preserved }
 *
 * Spec ref: §7.1
 * Session:  S-QBO-8
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\AccountMatcher;
use FleetForge\QboPushers\AccountValidator;

try {
    // Build QBO list from pull snapshot.
    $rows = db_select(
        "SELECT qbo_account_id, qbo_name, qbo_fully_qualified_name,
                qbo_account_type, qbo_account_subtype, qbo_account_number
           FROM acc_qbo_account_map
          WHERE qbo_account_id IS NOT NULL"
    );

    $qboAccounts = [];
    foreach ($rows as $r) {
        $qboAccounts[] = [
            'qbo_id'               => (string) $r['qbo_account_id'],
            'name'                 => (string) ($r['qbo_name'] ?? ''),
            'fully_qualified_name' => (string) ($r['qbo_fully_qualified_name'] ?? ''),
            'account_type'         => (string) ($r['qbo_account_type'] ?? ''),
            'account_subtype'      => (string) ($r['qbo_account_subtype'] ?? ''),
            'account_number'       => (string) ($r['qbo_account_number'] ?? ''),
        ];
    }

    // Identify manual-locked FF accounts (off-limits to auto-match).
    $manualLockedFfIds = [];
    $manualRows = db_select(
        "SELECT ff_account_id FROM acc_qbo_account_map
          WHERE ff_account_id IS NOT NULL
            AND match_confidence = 'manual'"
    );
    foreach ($manualRows as $m) {
        $manualLockedFfIds[(int) $m['ff_account_id']] = true;
    }
    $manualPreserved = count($manualLockedFfIds);

    $decisions = AccountMatcher::matchAll($qboAccounts);

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = date('Y-m-d H:i:s');

    foreach ($decisions as $d) {
        // Skip auto-match for FF accounts the operator has locked.
        if ($d['ff_account_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_account_id']])) {
            continue;
        }

        if ($d['mapping_status'] === 'mapped') {
            // Promote qbo_only row to mapped by attaching ff_account_id.
            // First drop any pre-existing ff_only row to avoid the
            // UNIQUE(ff_account_id) collision.
            db_execute(
                "DELETE FROM acc_qbo_account_map
                  WHERE ff_account_id = ?
                    AND qbo_account_id IS NULL",
                [(int) $d['ff_account_id']]
            );
            $rowsUpdated = db_execute(
                "UPDATE acc_qbo_account_map SET
                    ff_account_id    = ?,
                    mapping_status   = 'mapped',
                    match_confidence = ?,
                    last_synced_at   = ?
                  WHERE qbo_account_id = ?",
                [
                    (int) $d['ff_account_id'],
                    (string) $d['match_confidence'],
                    $now,
                    (string) $d['qbo_account_id'],
                ]
            );
            if ($rowsUpdated > 0) {
                $matchedCount++;
            }
            continue;
        }

        if ($d['mapping_status'] === 'ff_only') {
            db_execute(
                "INSERT INTO acc_qbo_account_map
                    (ff_account_id, mapping_status, created_by_user_id)
                 VALUES (?, 'ff_only', ?)
                 ON DUPLICATE KEY UPDATE
                    mapping_status   = IF(match_confidence='manual', mapping_status, 'ff_only'),
                    match_confidence = IF(match_confidence='manual', match_confidence, NULL)",
                [(int) $d['ff_account_id'], $userId]
            );
            $ffOnlyCount++;
            continue;
        }

        if ($d['mapping_status'] === 'qbo_only') {
            $qboOnlyCount++;
        }
    }

    // Re-mark critical accounts post-auto-match (some rows may have
    // moved from ff_only → mapped, but is_critical flags are
    // INDEPENDENT of mapping_status per D-QBO-8-4 so this is just a
    // safety re-run to ensure is_critical sticks across the rewrite).
    AccountValidator::markCriticalAccounts();

    json_success([
        'matched'          => $matchedCount,
        'ff_only'          => $ffOnlyCount,
        'qbo_only'         => $qboOnlyCount,
        'manual_preserved' => $manualPreserved,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
}
