<?php
declare(strict_types=1);

/**
 * lib/QboPushers/FixedAssetMapSync.php
 *
 * Populate / refresh engine for acc_qbo_fixed_asset_map (operator-directed
 * S-QBO-FA-MAP, 2026-06-01). Reverses the S-QBO-22 D-QBO-22-3 "no FA table"
 * sub-decision.
 *
 * WHAT IT DOES: there is NO QBO FixedAsset entity (spec §8.13 — assets stay
 * FF-canonical; QBO only ever sees the depreciation/disposal/impairment JEs).
 * So this maintains a per-FF-asset REFERENCE row that answers the operationally
 * useful question "is this asset's QBO account plumbing ready for its JEs to
 * push?" by resolving the asset's three FF GL accounts (asset cost / accumulated
 * depreciation / depreciation expense) to their QBO Account.Ids via
 * acc_qbo_account_map, snapshotting cost/accum-depr/NBV/status, and setting a
 * sync_status:
 *   - 'synced'  — all three GL accounts are mapped (JEs CAN reach QBO).
 *   - 'pending' — at least one GL account is unmapped (a depreciation/disposal
 *                 JE for this asset would fail JournalEntryPusher preflight at
 *                 the per-line AccountRef gate, D-QBO-21-3).
 * ('drift' + 'not_applicable' are reserved for future use.)
 *
 * Idempotent: upserts on the uq_ff_fixed_asset UNIQUE key, so re-running just
 * refreshes snapshots. Per-asset try/catch so one bad row never aborts the run.
 *
 * @session  S-QBO-FA-MAP (operator-directed)
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.13 (Fixed Asset)
 */

namespace FleetForge\QboPushers;

class FixedAssetMapSync
{
    /**
     * Resolve a mapped QBO Account.Id for an FF acc_accounts.id, or null.
     */
    public static function resolveQboAccount(?int $ffAccountId): ?string
    {
        if (!$ffAccountId) {
            return null;
        }
        $row = db_row(
            "SELECT qbo_account_id FROM acc_qbo_account_map
              WHERE ff_account_id = ? AND mapping_status = 'mapped' AND qbo_account_id IS NOT NULL
              ORDER BY id ASC LIMIT 1",
            [$ffAccountId]
        );
        return $row['qbo_account_id'] ?? null;
    }

    /**
     * Compute the reference fields for one acc_fixed_assets row.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>  upsert payload (excludes ff_fixed_asset_id)
     */
    public static function buildReference(array $asset): array
    {
        $qboAsset  = self::resolveQboAccount(isset($asset['asset_account_id']) ? (int) $asset['asset_account_id'] : null);
        $qboAccum  = self::resolveQboAccount(isset($asset['accum_depr_account_id']) ? (int) $asset['accum_depr_account_id'] : null);
        $qboExpense = self::resolveQboAccount(isset($asset['depr_expense_account_id']) ? (int) $asset['depr_expense_account_id'] : null);

        $allMapped = ($qboAsset !== null && $qboAccum !== null && $qboExpense !== null);

        return [
            'qbo_asset_account_id'                 => $qboAsset,
            'qbo_accum_depr_account_id'            => $qboAccum,
            'qbo_depr_expense_account_id'          => $qboExpense,
            'ff_acquisition_cost_snapshot'         => $asset['acquisition_cost'] ?? null,
            'ff_accumulated_depreciation_snapshot' => $asset['accumulated_depreciation'] ?? null,
            'ff_net_book_value_snapshot'           => $asset['net_book_value'] ?? null,
            'ff_status_snapshot'                   => $asset['status'] ?? null,
            'sync_status'                          => $allMapped ? 'synced' : 'pending',
            // updated_at (ON UPDATE CURRENT_TIMESTAMP) captures "last refreshed"
            // automatically; last_je_synced_at is a separate JE-timing concern
            // not set by this populate pass.
        ];
    }

    /**
     * Upsert one asset's reference row. Returns its resulting sync_status, or
     * null if the asset doesn't exist.
     */
    public static function syncOne(int $ffFixedAssetId): ?string
    {
        $asset = db_row("SELECT * FROM acc_fixed_assets WHERE id = ?", [$ffFixedAssetId]);
        if (!$asset) {
            return null;
        }
        $ref = self::buildReference($asset);

        $existing = db_row("SELECT id FROM acc_qbo_fixed_asset_map WHERE ff_fixed_asset_id = ?", [$ffFixedAssetId]);
        if ($existing) {
            db_update('acc_qbo_fixed_asset_map', $ref, 'ff_fixed_asset_id = ?', [$ffFixedAssetId]);
        } else {
            db_insert('acc_qbo_fixed_asset_map', ['ff_fixed_asset_id' => $ffFixedAssetId] + $ref);
        }
        return (string) $ref['sync_status'];
    }

    /**
     * Populate / refresh the reference map for every FF fixed asset.
     *
     * @return array{total:int, synced:int, pending:int, errors:int}
     */
    public static function sync(): array
    {
        $stats = ['total' => 0, 'synced' => 0, 'pending' => 0, 'errors' => 0];
        $assets = db_select("SELECT id FROM acc_fixed_assets");
        foreach ($assets as $a) {
            $stats['total']++;
            try {
                $status = self::syncOne((int) $a['id']);
                if ($status === 'synced') {
                    $stats['synced']++;
                } elseif ($status === 'pending') {
                    $stats['pending']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log("[FixedAssetMapSync] syncOne failed for asset {$a['id']}: " . $e->getMessage());
            }
        }
        return $stats;
    }
}
