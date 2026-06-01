-- ============================================================================
-- S-QBO-FA-MAP — Fixed Asset reference map (acc_qbo_fixed_asset_map)
-- ============================================================================
--
-- OPERATOR-DIRECTED 2026-06-01. Creates acc_qbo_fixed_asset_map.
--
-- HISTORY / DECISION REVERSAL: S-QBO-22 (D-QBO-22-3) deliberately did NOT
-- create a dedicated fixed-asset map table — FA-derived JEs (depreciation /
-- asset_disposal / impairment) route through acc_qbo_journal_entry_map, and
-- the asset RECORD itself stays FF-only (spec §8.13 — QBO has no fixed-asset
-- module; CCA Schedule 8 continuity is not representable in QBO). This
-- migration reverses that "no table" choice at operator request.
--
-- WHAT THIS TABLE IS (and is NOT): there is NO QBO FixedAsset entity to map
-- to, so this is a per-FF-asset REFERENCE / tracking row, NOT a push-mapping
-- to a QBO entity id. It records, for each acc_fixed_assets row:
--   - the QBO GL Account.Ids the asset's JEs post to (asset cost / accumulated
--     depreciation / depreciation expense), resolved via acc_qbo_account_map
--     from the asset's three FF account_id columns — so an operator can see at
--     a glance whether an asset's accounts are mapped before its
--     depreciation/disposal JEs can push;
--   - cost / accumulated-depreciation / NBV snapshots + status for
--     reference and FA-level drift comparison;
--   - a sync_status + last_je_synced_at tracking marker.
--
-- Schema mirrors the established acc_qbo_*_map conventions (PK, ff FK with
-- UNIQUE + CASCADE, snapshot columns, status ENUM, timestamps, indexes).
-- NO spec defines these columns (the table was superseded), so this is a
-- sensible default shape open to revision.
--
-- @session  S-QBO-FA-MAP (operator-directed)
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.13 (Fixed Asset)
-- @note     Reverses D-QBO-22-3's "no FA map table" sub-decision per operator
--           request 2026-06-01.

CREATE TABLE IF NOT EXISTS `acc_qbo_fixed_asset_map` (
    `id`                                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_fixed_asset_id`                   INT UNSIGNED NOT NULL COMMENT 'acc_fixed_assets.id — FF asset this reference row tracks. FF-origin only (spec §8.13: assets stay FF-canonical).',
    `qbo_asset_account_id`                VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Account.Id for the asset-cost account, resolved via acc_qbo_account_map from acc_fixed_assets.asset_account_id. NULL until that GL account is mapped.',
    `qbo_accum_depr_account_id`           VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Account.Id for the accumulated-depreciation account (from accum_depr_account_id).',
    `qbo_depr_expense_account_id`         VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO Account.Id for the depreciation-expense account (from depr_expense_account_id).',
    `ff_acquisition_cost_snapshot`        DECIMAL(15,2) DEFAULT NULL COMMENT 'acc_fixed_assets.acquisition_cost snapshot at last sync — FA-level drift baseline.',
    `ff_accumulated_depreciation_snapshot` DECIMAL(15,2) DEFAULT NULL COMMENT 'acc_fixed_assets.accumulated_depreciation snapshot at last sync.',
    `ff_net_book_value_snapshot`          DECIMAL(15,2) DEFAULT NULL COMMENT 'acc_fixed_assets.net_book_value snapshot at last sync.',
    `ff_status_snapshot`                  ENUM('active','fully_depreciated','disposed','impaired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'acc_fixed_assets.status snapshot at last sync.',
    `last_je_synced_at`                   DATETIME DEFAULT NULL COMMENT 'Timestamp of the most recent depreciation/disposal/impairment JE that synced to QBO for this asset (via acc_qbo_journal_entry_map).',
    `sync_status`                         ENUM('pending','synced','drift','not_applicable') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending=accounts not yet mapped / no JE synced; synced=accounts mapped + JEs flowing; drift=snapshot mismatch flagged; not_applicable=asset has no QBO-relevant activity.',
    `notes`                               TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Operator/forensic notes.',
    `created_at`                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_fixed_asset` (`ff_fixed_asset_id`) COMMENT 'One reference row per FF fixed asset.',
    KEY `idx_sync_status` (`sync_status`),
    CONSTRAINT `fk_qbo_fa_map_ff` FOREIGN KEY (`ff_fixed_asset_id`) REFERENCES `acc_fixed_assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Operator-directed S-QBO-FA-MAP 2026-06-01: per-FF-asset reference/tracking row (NOT a QBO-entity push-map — QBO has no FixedAsset entity per spec §8.13). Tracks the asset''s QBO GL-account refs + cost/depr snapshots + sync_status. Reverses D-QBO-22-3 no-table sub-decision.';
