-- ============================================================================
-- S-QBO-22 — Fixed Asset JE Sync (FF → QBO) (Phase QBO-11 / 1 of 2)
-- ============================================================================
--
-- Scope: Depreciation + asset_disposal + impairment JEs route through the
-- existing JournalEntryPusher (S-QBO-21). No new Pusher class — spec §8.13
-- mandates "Depreciation JEs push as standard JE (§8.10)" and "Disposal JEs
-- push as standard JE." Asset records themselves DO NOT sync (FF-only per
-- D-QBO-CORE-3 + spec §8.13 — CCA Schedule 8 continuity not representable
-- in QBO).
--
-- Migration motions:
--   1. EXTEND acc_journal_entries.source_type ENUM with 'impairment' so
--      FixedAssetService::impair() can use clean semantics instead of the
--      "closest enum match" asset_disposal workaround (locked D-QBO-22-2).
--   2. Seed marker setting quickbooks.sync_mode.fixed_asset='inherit_je'
--      documenting that FA-derived JEs inherit sync gating from the
--      journal_entry sync_mode — there is NO separate fixed_asset sync
--      pipeline. Operator-visible in /admin/quickbooks/settings.
--
-- Per D-QBO-22-1: JournalEntryPusher::buildQboPayload PrivateNote section
--   enriches with FA-specific attribution when source_type IN
--   ('depreciation','asset_disposal','impairment'). Source row lookup is
--   best-effort (try/catch) — never blocks push if FA row was deleted.
--   Format examples:
--     depreciation:    "FA: run='2026-04 Monthly' assets=12 total=$4250.00"
--     asset_disposal:  "FA: asset=FA-0042 type=sale proceeds=$5000.00"
--     impairment:      "FA: asset=FA-0042 reason='market_crash' loss=$2500.00"
--
-- Per D-QBO-22-2: ENUM extension preserves all prior values + appends
--   'impairment' (MODIFY COLUMN, not recreate). FixedAssetService::impair()
--   updated to use the clean value in the same commit. Existing
--   acc_journal_entries rows with source_type='asset_disposal' from prior
--   impairments are NOT backfilled (audit-trail preservation) — only NEW
--   impairment JEs will use 'impairment'. Document the conflation in
--   spec §8.13.
--
-- Per D-QBO-22-3: Admin UI for FA JE visibility is a filter chip on
--   /admin/quickbooks/journal_entries.php — NOT a separate /fixed_assets
--   page. Filter chip toggles "Fixed Asset only" view (source_type IN
--   ('depreciation','asset_disposal','impairment')). Plus one new KPI tile
--   "FA JEs synced this period". No migration motion for this — pure UI.
--
-- Per D-QBO-22-4 (defense-in-depth re-confirmation): JournalEntryEnqueuer
--   BRIDGE_DERIVED_SOURCE_TYPES does NOT include depreciation/asset_disposal/
--   impairment (confirmed via grep at pre-flight). FA JEs flow through
--   gate-0b without rejection. Pusher BRIDGE_DERIVED_SOURCE_TYPES also
--   confirmed identical. No change to the constants — but smoke test C8
--   asserts the regression guard explicitly.
--
-- @session  S-QBO-22
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.13 (Fixed Asset) + §8.10 (JE)
-- @decision D-QBO-22-1 (PrivateNote FA-enrichment best-effort lookup),
--           D-QBO-22-2 (acc_journal_entries.source_type ENUM +impairment;
--                       prior asset_disposal impairments NOT backfilled),
--           D-QBO-22-3 (admin UI = filter chip + KPI tile on
--                       /admin/quickbooks/journal_entries.php; NO separate
--                       /fixed_assets sync page),
--           D-QBO-22-4 (bridge-derived skip list NEVER includes FA source
--                       types — defense-in-depth regression guard via C8)

-- ─────────────────────────────────────────────────────────────────────
-- B1: ALTER acc_journal_entries.source_type ENUM — add 'impairment'
-- ─────────────────────────────────────────────────────────────────────
-- K-22 ALERT: existing ENUM (pre-S-QBO-22) is 21 values:
--   ('invoice','payment','credit_note','ap_bill','ap_payment',
--    'bank_transaction','depreciation','asset_disposal','tax_remittance',
--    'fx_revaluation','manual','year_end','recurring','damage_recovery',
--    'damage_repair','damage_writeoff','lease_inception','lease_period',
--    'lease_termination','lease_ni_reclass','lease_residual_impairment')
-- POST-MIGRATION: 22 values, with 'impairment' appended after
--   'asset_disposal' for taxonomic locality (both FA-typed).
-- Default stays NULL. No backfill — existing 'asset_disposal' rows that
--   were actually impairments stay as-is (audit-trail preservation per
--   D-QBO-22-2 lock).

ALTER TABLE `acc_journal_entries`
    MODIFY COLUMN `source_type` ENUM(
        'invoice',
        'payment',
        'credit_note',
        'ap_bill',
        'ap_payment',
        'bank_transaction',
        'depreciation',
        'asset_disposal',
        'impairment',
        'tax_remittance',
        'fx_revaluation',
        'manual',
        'year_end',
        'recurring',
        'damage_recovery',
        'damage_repair',
        'damage_writeoff',
        'lease_inception',
        'lease_period',
        'lease_termination',
        'lease_ni_reclass',
        'lease_residual_impairment'
    ) COLLATE utf8mb4_unicode_ci DEFAULT NULL
    COMMENT 'S-QBO-22: extended with impairment per D-QBO-22-2. NOT bridge-derived per spec §8.10 — FA-typed values flow through to QBO via standard JE push.';

-- ─────────────────────────────────────────────────────────────────────
-- B2: Seed marker setting quickbooks.sync_mode.fixed_asset
-- ─────────────────────────────────────────────────────────────────────
-- Documents that FA JEs inherit the journal_entry sync mode — there is
-- no independent FA sync pipeline. Value 'inherit_je' is a MARKER (not
-- consumed by Enqueuer/Pusher); operator-facing in /admin/quickbooks/settings
-- so the FA-JE flow is discoverable without reading spec §8.13. Per D-QBO-22-3.

INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`)
VALUES
    ('quickbooks.sync_mode.fixed_asset', 'inherit_je', 'string', 'quickbooks', 0, 0,
     'S-QBO-22 / D-QBO-22-3 marker: FA-derived JEs (depreciation + asset_disposal + impairment) inherit sync mode from quickbooks.sync_mode.journal_entry. No independent FA sync pipeline per spec §8.13. Operator-visible documentation only.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
