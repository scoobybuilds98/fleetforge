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
use FleetForge\QboPushers\PartyAutoMatch;

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

    // Identify manual-locked mappings (off-limits to auto-match) by BOTH the FF
    // account id AND the claimed QBO account id. MEDIUM [01e]: the skip below
    // only checked the decision's ff_account_id, but a 'mapped' decision UPDATEs
    // `WHERE qbo_account_id = ?` — so a DIFFERENT (unlocked) FF row matched to a
    // manually-locked row's qbo_account_id would overwrite (steal) that lock.
    // Track the locked qbo_ids too and skip any decision claiming one.
    $manualLockedFfIds  = [];
    $manualLockedQboIds = [];
    $manualRows = db_select(
        "SELECT ff_account_id, qbo_account_id FROM acc_qbo_account_map
          WHERE ff_account_id IS NOT NULL
            AND match_confidence = 'manual'"
    );
    foreach ($manualRows as $m) {
        $manualLockedFfIds[(int) $m['ff_account_id']] = true;
        if ($m['qbo_account_id'] !== null && $m['qbo_account_id'] !== '') {
            $manualLockedQboIds[(string) $m['qbo_account_id']] = true;
        }
    }
    $manualPreserved = count($manualLockedFfIds);

    $decisions = AccountMatcher::matchAll($qboAccounts);

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = ff_now_utc(); // S-UTC-STAMPS: QBO map stamps (last_synced_at/pushed_at/…) are UTC

    foreach ($decisions as $d) {
        // Skip auto-match for FF accounts the operator has locked.
        if ($d['ff_account_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_account_id']])) {
            continue;
        }
        // MEDIUM [01e]: and never re-route a QBO account already claimed by a
        // manual lock to a different FF row (the 'mapped' UPDATE keys on
        // qbo_account_id, which would otherwise overwrite the locked mapping).
        if ($d['qbo_account_id'] !== null && isset($manualLockedQboIds[(string) $d['qbo_account_id']])) {
            continue;
        }

        // S-QBO-GOLIVE-AUDIT: only an exact code / exact name match links
        // on its own. The weaker tiers guessed from type + a shared word —
        // on the rehearsal company they mapped PST Payable → Note Payable,
        // Bad Debt Expense → "BC Ministry of Finance Expense", Income Tax
        // Payable → an A/P account: real money into the accountant's wrong
        // accounts. Those become suggestions a person confirms.
        if ($d['mapping_status'] === 'mapped' && !in_array((string) $d['match_confidence'], ['exact_code', 'exact_name'], true)) {
            $current = db_row("SELECT qbo_account_id FROM acc_qbo_account_map WHERE ff_account_id = ?", [(int) $d['ff_account_id']]);
            if ($current !== null && $current['qbo_account_id'] !== null) {
                continue; // already linked — a suggestion never overrides a link
            }
            $qboRow = db_row("SELECT qbo_name FROM acc_qbo_account_map WHERE qbo_account_id = ?", [(string) $d['qbo_account_id']]);
            $note = PartyAutoMatch::SUGGEST_PREFIX . json_encode([
                'qbo_id'     => (string) $d['qbo_account_id'],
                'name'       => (string) ($qboRow['qbo_name'] ?? ''),
                'confidence' => (string) $d['match_confidence'],
                'why'        => PartyAutoMatch::ACCOUNT_WHY[(string) $d['match_confidence']] ?? (string) $d['match_confidence'],
            ], JSON_UNESCAPED_UNICODE);
            db_execute(
                "INSERT INTO acc_qbo_account_map (ff_account_id, mapping_status, match_notes, created_by_user_id)
                 VALUES (?, 'ff_only', ?, ?)
                 ON DUPLICATE KEY UPDATE
                    match_notes = IF(match_confidence = 'manual' OR qbo_account_id IS NOT NULL, match_notes, VALUES(match_notes))",
                [(int) $d['ff_account_id'], $note, $userId]
            );
            $suggestedCount = ($suggestedCount ?? 0) + 1;
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
        'suggested'        => $suggestedCount ?? 0,
        'ff_only'          => $ffOnlyCount,
        'qbo_only'         => $qboOnlyCount,
        'manual_preserved' => $manualPreserved,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
}
