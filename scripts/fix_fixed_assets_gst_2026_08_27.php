<?php declare(strict_types=1);

/**
 * scripts/fix_fixed_assets_gst_2026_08_27.php
 *
 * One-time correction: the S-FA-IMPORT batch (166 assets, 2026-08-25) was
 * booked on the wrong premise. The operator's spreadsheet costs were
 * GST-EXCLUSIVE — FleetForge's fixed-asset module has no GST concept at all
 * (there is no purchase_tax_gst line item anywhere in normal use; it exists
 * on acc_fixed_assets purely for the Unit Payoff Calculator's acquisition
 * breakdown, see PAYOFF-1) — but the import script assumed the opposite: it
 * treated the sheet numbers as GST-INCLUSIVE and divided by 1.05 to back
 * GST out. Net effect on every one of the 166 rows:
 *
 *   acquisition_cost  understated by exactly 1/21 of the true cost (~4.76%)
 *   purchase_tax_gst  holds a GST split that should not exist at all
 *   net_book_value    understated by the same 1/21
 *   depreciable_cost  understated by the same 1/21 (salvage_value is 0, so
 *                     depreciable_cost === acquisition_cost for every row)
 *
 * This script restores each asset to the operator's original figure by
 * moving purchase_tax_gst back into acquisition_cost and zeroing the GST
 * field — i.e. acquisition_cost_new = acquisition_cost_old + purchase_tax_gst_old.
 * It does NOT re-derive anything from a spreadsheet; the correct total is
 * already sitting in the two columns that are wrong, just split incorrectly.
 *
 * Scope: only rows tagged notes = 'S-FA-IMPORT 2026-08-25' (see
 * scripts/import_fixed_assets_2026_08_25.php). Nothing else is touched.
 *
 * SAFE-TO-RUN preconditions checked before any write, per targeted asset:
 *   - status = 'active' (a disposal/impairment would have changed this)
 *   - accumulated_depreciation = 0.00 (no depreciation run has posted yet)
 *   - depreciable_cost == acquisition_cost (confirms salvage_value is 0,
 *     which is what makes the depreciable_cost update a straight copy of
 *     the new acquisition_cost)
 *   - net_book_value == acquisition_cost (catches an impairment/disposal
 *     that adjusted NBV directly without touching accumulated_depreciation)
 *   - purchase_tax_gst > 0 (otherwise there's nothing to move)
 *   - zero rows in acc_depreciation_run_lines reference this asset — this is
 *     the precise gate for "has a JE ever touched this asset's depreciation":
 *     postRun() (FixedAssetService.php ~L697) builds its journal entry ONLY
 *     from run_lines rows for the run being posted, in one db_transaction,
 *     so a JE cannot exist without a matching run_lines row for that exact
 *     asset_id. (A blanket "any movement on accounts 1210/1220/5010" check
 *     was tried first and rejected — it false-positives on any OTHER fixed
 *     asset that happens to share those account codes.)
 * If ANY of these fail for ANY row, the script aborts before writing
 * anything — a corrected acquisition_cost after depreciation has already run
 * would desync from posted GL entries, and that repair is a different,
 * larger problem than this script is scoped to solve.
 *
 * is_opening_balance stays 1 and every other field (dates, GL accounts,
 * depreciation method, useful life) is untouched — only the cost/GST split
 * changes.
 *
 * Usage:
 *   php scripts/fix_fixed_assets_gst_2026_08_27.php            # dry-run
 *   php scripts/fix_fixed_assets_gst_2026_08_27.php --apply
 *
 * @session S-FA-IMPORT (correction)
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';

const FA_IMPORT_TAG = 'S-FA-IMPORT 2026-08-25';

$apply = in_array('--apply', array_slice($argv, 1), true);

$rows = db_select(
    "SELECT a.id, a.asset_number, a.status, eu.unit_number,
            a.acquisition_cost, a.purchase_tax_gst, a.salvage_value,
            a.depreciable_cost, a.net_book_value, a.accumulated_depreciation
       FROM acc_fixed_assets a
       LEFT JOIN equipment_units eu ON eu.id = a.equipment_unit_id
      WHERE a.notes = ?
      ORDER BY a.asset_number",
    [FA_IMPORT_TAG]
);

if (!$rows) {
    fwrite(STDERR, "No rows tagged '" . FA_IMPORT_TAG . "' found. Nothing to do.\n");
    exit(1);
}

echo "Targeted assets: " . count($rows) . " (notes = '" . FA_IMPORT_TAG . "')\n\n";

// ── Preflight — abort before writing anything if any row is unsafe ─────────
$blockers = [];
foreach ($rows as $r) {
    if (($r['status'] ?? 'active') !== 'active') {
        $blockers[] = "{$r['asset_number']}: status = {$r['status']} (expected active) — disposal/impairment may have touched this asset";
    }
    if (bccomp((string) $r['accumulated_depreciation'], '0.00', 2) !== 0) {
        $blockers[] = "{$r['asset_number']}: accumulated_depreciation = {$r['accumulated_depreciation']} (not zero)";
    }
    if (bccomp((string) $r['depreciable_cost'], (string) $r['acquisition_cost'], 2) !== 0) {
        $blockers[] = "{$r['asset_number']}: depreciable_cost ({$r['depreciable_cost']}) != acquisition_cost ({$r['acquisition_cost']}) — salvage_value may be non-zero, this script assumes 0";
    }
    if (bccomp((string) $r['net_book_value'], (string) $r['acquisition_cost'], 2) !== 0) {
        $blockers[] = "{$r['asset_number']}: net_book_value ({$r['net_book_value']}) != acquisition_cost ({$r['acquisition_cost']}) — an impairment or disposal may have adjusted NBV directly";
    }
    if (bccomp((string) $r['purchase_tax_gst'], '0.00', 2) <= 0) {
        $blockers[] = "{$r['asset_number']}: purchase_tax_gst is already 0 or negative — nothing to move, investigate before running";
    }
}

// acc_depreciation_run_lines is the precise, asset-scoped gate: postRun()
// (FixedAssetService.php ~L697) builds its journal entry ONLY from run_lines
// rows for the run being posted, inside one db_transaction — so a JE can
// never touch one of these assets' depreciation without a matching run_lines
// row existing for that exact asset_id. A blanket "any movement on accounts
// 1210/1220/5010" check was tried first and rejected: it false-positives on
// any OTHER fixed asset that ever posted to those same account codes (hit
// this immediately in dev, where unrelated seeded assets share the accounts)
// and adds no real coverage beyond what run_lines + the per-row invariants
// below already prove.
$runLines = db_row(
    "SELECT COUNT(*) n FROM acc_depreciation_run_lines l
      JOIN acc_fixed_assets a ON a.id = l.asset_id
     WHERE a.notes = ?",
    [FA_IMPORT_TAG]
);
if ((int) $runLines['n'] > 0) {
    $blockers[] = "acc_depreciation_run_lines has {$runLines['n']} row(s) referencing these assets — a depreciation run has already touched them";
}

if ($blockers) {
    fwrite(STDERR, "ABORT — " . count($blockers) . " safety check(s) failed, nothing written:\n");
    foreach ($blockers as $b) fwrite(STDERR, "  - $b\n");
    exit(1);
}
echo "Preflight OK — active, accumulated_depreciation=0, no depreciation runs, depreciable_cost==net_book_value==acquisition_cost on all " . count($rows) . " rows.\n\n";

// ── Compute + apply ──────────────────────────────────────────────────────
$totalOldCost = '0.00'; $totalOldGst = '0.00'; $totalNewCost = '0.00';
$updated = 0;

foreach ($rows as $r) {
    $oldCost = (string) $r['acquisition_cost'];
    $oldGst  = (string) $r['purchase_tax_gst'];
    $newCost = bcadd($oldCost, $oldGst, 2);

    $totalOldCost = bcadd($totalOldCost, $oldCost, 2);
    $totalOldGst  = bcadd($totalOldGst, $oldGst, 2);
    $totalNewCost = bcadd($totalNewCost, $newCost, 2);

    printf("  %-14s %-14s %-11s + gst %-9s -> %s\n",
        $r['asset_number'], $r['unit_number'] ?? '(unlinked)', $oldCost, $oldGst, $newCost);

    if ($apply) {
        db_update('acc_fixed_assets', [
            'acquisition_cost'  => $newCost,
            'purchase_tax_gst'  => '0.00',
            'depreciable_cost'  => $newCost, // salvage_value confirmed 0 in preflight
            'net_book_value'    => $newCost, // accumulated_depreciation confirmed 0 in preflight
        ], 'id = ?', [(int) $r['id']]);
        $updated++;
    }
}

echo "\n";
printf("Totals: acquisition_cost %s -> %s  (+%s, the GST that was wrongly split out)\n",
    $totalOldCost, $totalNewCost, bcsub($totalNewCost, $totalOldCost, 2));
printf("        purchase_tax_gst %s -> 0.00\n", $totalOldGst);

if (!$apply) {
    echo "\nDRY RUN — nothing written. Re-run with --apply to correct these " . count($rows) . " assets.\n";
} else {
    echo "\nUpdated: $updated\n";
}
