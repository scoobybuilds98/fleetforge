<?php
declare(strict_types=1);

/**
 * api/v1/quickbooks/tax_codes/auto_match.php
 *
 * Run TaxCodeMatcher::matchAll against the current
 * acc_qbo_tax_code_map state. User-initiated after a fresh pull.
 * Preserves manual operator overrides (confidence='manual') across
 * re-runs.
 *
 * D-QBO-9-3: matching is INFORMATIONAL only — FF computes tax
 * authoritatively; QBO accepts override via TxnTaxDetail.TotalTax +
 * TaxCodeRef='NON' in S-QBO-11 invoice push. The mapping table
 * surfaces accountant-friendly labels in QBO reports, but it does
 * NOT change how tax is computed.
 *
 * @method  POST
 * @auth    require_permission('quickbooks', 'view')
 * @returns 200 { success: true, matched, ff_only, qbo_only, manual_preserved }
 *
 * Spec ref: §7.2
 * Session:  S-QBO-9
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('quickbooks', 'view');

use FleetForge\QboPushers\TaxCodeMatcher;

try {
    // Build QBO list from pull snapshot.
    $rows = db_select(
        "SELECT qbo_tax_code_id, qbo_name, qbo_description,
                qbo_active, qbo_taxable, qbo_tax_group
           FROM acc_qbo_tax_code_map
          WHERE qbo_tax_code_id IS NOT NULL"
    );

    $qboCodes = [];
    foreach ($rows as $r) {
        $qboCodes[] = [
            'qbo_id'      => (string) $r['qbo_tax_code_id'],
            'name'        => (string) ($r['qbo_name'] ?? ''),
            'description' => (string) ($r['qbo_description'] ?? ''),
            'active'      => (bool)   ($r['qbo_active'] ?? true),
            'taxable'     => (bool)   ($r['qbo_taxable'] ?? false),
            'tax_group'   => (bool)   ($r['qbo_tax_group'] ?? false),
        ];
    }

    // Identify manual-locked FF rates (off-limits to auto-match).
    $manualLockedFfIds = [];
    $manualRows = db_select(
        "SELECT ff_tax_rate_id FROM acc_qbo_tax_code_map
          WHERE ff_tax_rate_id IS NOT NULL
            AND match_confidence = 'manual'"
    );
    foreach ($manualRows as $m) {
        $manualLockedFfIds[(int) $m['ff_tax_rate_id']] = true;
    }
    $manualPreserved = count($manualLockedFfIds);

    $decisions = TaxCodeMatcher::matchAll($qboCodes);

    $matchedCount = 0;
    $ffOnlyCount  = 0;
    $qboOnlyCount = 0;
    $userId       = current_user_id();
    $now          = date('Y-m-d H:i:s');

    foreach ($decisions as $d) {
        if ($d['ff_tax_rate_id'] !== null && isset($manualLockedFfIds[(int) $d['ff_tax_rate_id']])) {
            continue;
        }

        if ($d['mapping_status'] === 'mapped') {
            // Drop any pre-existing ff_only row for this FF tax rate.
            db_execute(
                "DELETE FROM acc_qbo_tax_code_map
                  WHERE ff_tax_rate_id = ?
                    AND qbo_tax_code_id IS NULL",
                [(int) $d['ff_tax_rate_id']]
            );

            // Capture FF rate snapshot for divergence detection
            // (D-QBO-9-4) — snapshot value at link time.
            $ffSnap = db_row(
                "SELECT gst_rate, pst_rate, hst_rate, province FROM tax_rates WHERE id = ?",
                [(int) $d['ff_tax_rate_id']]
            );
            $rateSnapshot = $ffSnap !== null
                ? ((float) $ffSnap['gst_rate'] + (float) $ffSnap['pst_rate'] + (float) $ffSnap['hst_rate'])
                : null;
            $provinceSnap = $ffSnap['province'] ?? null;

            $rowsUpdated = db_execute(
                "UPDATE acc_qbo_tax_code_map SET
                    ff_tax_rate_id   = ?,
                    mapping_status   = 'mapped',
                    match_confidence = ?,
                    ff_rate_snapshot = ?,
                    ff_province      = ?,
                    last_synced_at   = ?
                  WHERE qbo_tax_code_id = ?",
                [
                    (int) $d['ff_tax_rate_id'],
                    (string) $d['match_confidence'],
                    $rateSnapshot,
                    $provinceSnap,
                    $now,
                    (string) $d['qbo_tax_code_id'],
                ]
            );
            if ($rowsUpdated > 0) {
                $matchedCount++;
            }
            continue;
        }

        if ($d['mapping_status'] === 'ff_only') {
            db_execute(
                "INSERT INTO acc_qbo_tax_code_map
                    (ff_tax_rate_id, mapping_status, created_by_user_id)
                 VALUES (?, 'ff_only', ?)
                 ON DUPLICATE KEY UPDATE
                    mapping_status   = IF(match_confidence='manual', mapping_status, 'ff_only'),
                    match_confidence = IF(match_confidence='manual', match_confidence, NULL)",
                [(int) $d['ff_tax_rate_id'], $userId]
            );
            $ffOnlyCount++;
            continue;
        }

        if ($d['mapping_status'] === 'qbo_only') {
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
    json_error('INTERNAL_ERROR', 'Auto-match failed: ' . $e->getMessage(), 500);
}
