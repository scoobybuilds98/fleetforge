<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/items/auto_match.php
 *
 * Run ItemMatcher::matchAll against the current acc_qbo_item_map
 * state. User-initiated after a fresh pull. Preserves manual operator
 * overrides (confidence='manual') AND ItemCreator-authored Items
 * (confidence='auto_created') across re-runs.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { success, matched, ff_only, qbo_only, manual_preserved, auto_created_preserved }
 *
 * Spec ref: §7.3
 * Session:  S-QBO-10
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\ItemMatcher;

try {
    // Build QBO list from pull snapshot.
    $rows = db_select(
        "SELECT qbo_item_id, qbo_name, qbo_fully_qualified_name, qbo_description,
                qbo_type, qbo_active, qbo_income_account_id, qbo_income_account_name
           FROM acc_qbo_item_map
          WHERE qbo_item_id IS NOT NULL"
    );

    $qboItems = [];
    foreach ($rows as $r) {
        $qboItems[] = [
            'qbo_id'              => (string) $r['qbo_item_id'],
            'name'                => (string) ($r['qbo_name'] ?? ''),
            'fully_qualified_name'=> (string) ($r['qbo_fully_qualified_name'] ?? ''),
            'description'         => (string) ($r['qbo_description'] ?? ''),
            'type'                => (string) ($r['qbo_type'] ?? ''),
            'active'              => (bool)   ($r['qbo_active'] ?? true),
            'income_account_id'   => (string) ($r['qbo_income_account_id'] ?? ''),
            'income_account_name' => (string) ($r['qbo_income_account_name'] ?? ''),
        ];
    }

    // Identify protected rows (manual + auto_created) — these are
    // off-limits to auto-match overwrites.
    $protectedKeys = []; // keyed by ff_item_type . '|' . (variant ?? '')
    $protectedRows = db_select(
        "SELECT ff_item_type, ff_item_type_variant, match_confidence
           FROM acc_qbo_item_map
          WHERE ff_item_type IS NOT NULL
            AND match_confidence IN ('manual','auto_created')
            AND mapping_status = 'mapped'"
    );
    $manualPreserved      = 0;
    $autoCreatedPreserved = 0;
    foreach ($protectedRows as $p) {
        $key = $p['ff_item_type'] . '|' . ($p['ff_item_type_variant'] ?? '');
        $protectedKeys[$key] = true;
        if ($p['match_confidence'] === 'manual')       { $manualPreserved++; }
        if ($p['match_confidence'] === 'auto_created') { $autoCreatedPreserved++; }
    }

    $decisions = ItemMatcher::matchAll($qboItems);

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = date('Y-m-d H:i:s');

    foreach ($decisions as $d) {
        // qbo_only rows (FF side null) come last in the decisions array.
        if ($d['ff_item_type'] === null) {
            $qboOnlyCount++;
            continue;
        }

        $key = $d['ff_item_type'] . '|' . ($d['ff_item_type_variant'] ?? '');
        if (isset($protectedKeys[$key])) {
            // Preserve manual or auto_created decisions — already
            // counted in protected tallies above.
            continue;
        }

        if ($d['mapping_status'] === 'mapped') {
            // Snapshot the income account from the matched QBO Item.
            $snap = db_row(
                "SELECT qbo_income_account_id, qbo_income_account_name
                   FROM acc_qbo_item_map
                  WHERE qbo_item_id = ?
                    AND mapping_status = 'qbo_only'",
                [(string) $d['qbo_item_id']]
            );
            $incomeAcctId   = $snap['qbo_income_account_id']   ?? null;
            $incomeAcctName = $snap['qbo_income_account_name'] ?? null;

            // Delete any prior ff_only row for the same tuple to avoid
            // UNIQUE(ff_item_type, ff_item_type_variant) collision when
            // we update the qbo_only row to point to this FF tuple.
            db_execute(
                "DELETE FROM acc_qbo_item_map
                  WHERE ff_item_type = ?
                    AND ((ff_item_type_variant IS NULL AND ? IS NULL)
                      OR ff_item_type_variant = ?)
                    AND qbo_item_id IS NULL",
                [$d['ff_item_type'], $d['ff_item_type_variant'], $d['ff_item_type_variant']]
            );

            // Promote the qbo_only row to mapped.
            $rowsUpdated = db_execute(
                "UPDATE acc_qbo_item_map SET
                    ff_item_type           = ?,
                    ff_item_type_variant   = ?,
                    mapping_status         = 'mapped',
                    match_confidence       = ?,
                    is_credit_variant      = ?,
                    presentation_variant   = ?,
                    qbo_income_account_id  = COALESCE(qbo_income_account_id, ?),
                    qbo_income_account_name= COALESCE(qbo_income_account_name, ?),
                    last_synced_at         = ?
                  WHERE qbo_item_id = ?",
                [
                    $d['ff_item_type'],
                    $d['ff_item_type_variant'],
                    (string) $d['match_confidence'],
                    (int) ($d['is_credit_variant'] ?? 0),
                    $d['presentation_variant'],
                    $incomeAcctId,
                    $incomeAcctName,
                    $now,
                    (string) $d['qbo_item_id'],
                ]
            );
            if ($rowsUpdated > 0) {
                $matchedCount++;
            }
            continue;
        }

        if ($d['mapping_status'] === 'ff_only') {
            // Upsert ff_only row.
            db_execute(
                "INSERT INTO acc_qbo_item_map
                    (ff_item_type, ff_item_type_variant, mapping_status,
                     is_credit_variant, presentation_variant, created_by_user_id)
                 VALUES (?, ?, 'ff_only', ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    mapping_status   = IF(match_confidence IN ('manual','auto_created'), mapping_status, 'ff_only'),
                    match_confidence = IF(match_confidence IN ('manual','auto_created'), match_confidence, NULL)",
                [
                    $d['ff_item_type'],
                    $d['ff_item_type_variant'],
                    (int) ($d['is_credit_variant'] ?? 0),
                    $d['presentation_variant'],
                    $userId,
                ]
            );
            $ffOnlyCount++;
        }
    }

    json_success([
        'matched'                => $matchedCount,
        'ff_only'                => $ffOnlyCount,
        'qbo_only'               => $qboOnlyCount,
        'manual_preserved'       => $manualPreserved,
        'auto_created_preserved' => $autoCreatedPreserved,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
}
