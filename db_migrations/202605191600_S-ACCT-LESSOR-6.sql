-- S-ACCT-LESSOR-6 — ASPE 3063 Fleet Impairment Two-Step Test (Phase D-6,
-- Phase D CLOSURE).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.8
-- Roadmap:  §11 row 25
--
-- 1. CREATE acc_impairment_tests — ASPE 3063 two-step test record per
--    asset per fiscal year per triggering event. UNIQUE(asset_id,
--    fiscal_year, triggering_event) allows one row per (asset, year,
--    event-type) combination — so 'annual' and 'damage' can coexist
--    in the same year for the same asset but a re-run of the same
--    triggering_event in the same year UPSERTs the existing row.
--
--    Decision references (locked in this session):
--      D-LESSOR-6-CGU-UNIT-LEVEL    (each fleet unit = its own CGU)
--      D-LESSOR-6-NO-REVERSAL       (impairments never reversed per ASPE)
--      D-LESSOR-6-CF-ESTIMATOR      (default UDF CF computation)
--      D-LESSOR-6-FAIR-VALUE-OPERATOR  (step 2 FV is operator input)
--      D-LESSOR-6-JE-VIA-FIXED-ASSET-SERVICE  (delegate JE post to
--                                              FixedAssetService::impair)
--
--    The JE itself + the on-asset accounting (accum_dep + NBV update)
--    are handled by FixedAssetService::impair() (S034). This table is
--    the workflow record — it captures the ASPE 3063 test details (the
--    step 1 inputs + verdict + step 2 FV) and LINKs to the actual JE
--    via impairment_je_id (which FixedAssetService::impair returns).
--
--    NULL columns:
--      step_2_* fields are NULL when step 1 passed (no impairment).
--      impairment_je_id is NULL until step 2 completes + JE posts (so
--        rows with step_1_passed=0 can sit "pending operator FV" with
--        step_2 NULL + impairment_je_id NULL).
--
--    No FOREIGN KEY on impairment_je_id → acc_journal_entries with ON
--    DELETE SET NULL pattern (same as acc_asset_impairments.journal_
--    entry_id) so JE reversals/voids don't cascade-delete the test
--    record; the test stays as historical reference even if the JE is
--    later voided.
--
-- 2. Settings — INSERT IGNORE:
--      accounting.impairment_cf_lookback_months = '12'
--        (months of revenue history to average for the CF estimator)
--      accounting.impairment_default_disposal_basis = 'salvage_value'
--        (basis for estimated disposal value; 'salvage_value' uses
--         acc_fixed_assets.salvage_value column. 'manual' = operator
--         provides per-test. 'market_lookup' reserved for future use.)
--
-- 3. Account note: 7020 "Loss on Disposal of Assets" (account #78,
--    type=other_expense) is the impairment-loss account used by
--    FixedAssetService::impair() per S034. Code 7020 lives in
--    accountIdByCode('7020') hardcoded in FixedAssetService.php
--    line 1070. The ImpairmentTestService delegates to that method,
--    so no new GL account or settings key needed.

START TRANSACTION;

-- ── 1. CREATE acc_impairment_tests ─────────────────────────────────────
CREATE TABLE `acc_impairment_tests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asset_id` INT UNSIGNED NOT NULL,
    `fiscal_year` INT NOT NULL,
    `triggering_event` ENUM('annual','idle','damage','market_decline',
                            'adverse_legal','other')
        COLLATE utf8mb4_unicode_ci NOT NULL,
    `triggering_event_notes` TEXT NULL,

    -- Step 1: Recoverability test (ASPE 3063.20)
    `step_1_carrying_amount` DECIMAL(15,2) NOT NULL,
    `step_1_undiscounted_cf` DECIMAL(15,2) NOT NULL,
    `step_1_cf_source` ENUM('estimator','operator_override')
        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'estimator',
    `step_1_cf_breakdown_json` JSON NULL,
    `step_1_passed` TINYINT(1) NOT NULL,

    -- Step 2: Measurement (ASPE 3063.22) — only populated when step 1 failed
    `step_2_fair_value` DECIMAL(15,2) NULL,
    `step_2_impairment_loss` DECIMAL(15,2) NULL,
    `step_2_fair_value_basis` TEXT NULL,

    `impairment_je_id` INT UNSIGNED NULL,
    `tested_by` INT UNSIGNED NOT NULL,
    `tested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` TEXT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_year_asset_event` (`asset_id`, `fiscal_year`, `triggering_event`),
    KEY `idx_year` (`fiscal_year`),
    KEY `idx_asset` (`asset_id`),
    KEY `idx_it_je` (`impairment_je_id`),
    KEY `idx_it_user` (`tested_by`),
    CONSTRAINT `fk_it_asset` FOREIGN KEY (`asset_id`)
        REFERENCES `acc_fixed_assets` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_it_je` FOREIGN KEY (`impairment_je_id`)
        REFERENCES `acc_journal_entries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_it_user` FOREIGN KEY (`tested_by`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1b. Backfill year-end checklist for existing year rows ────────────
-- Seed 013 captures the canonical 18 checklist items but isn't re-run
-- when migrations land. This migration adds the new ASPE 3063
-- impairment-test row for every fiscal year that already has checklist
-- data on disk. Idempotent via uq_year_item(year, item_key).
INSERT IGNORE INTO `acc_year_end_checklist`
    (`year`, `item_key`, `item_label`, `is_complete`, `sort_order`)
SELECT DISTINCT `year`,
       'fleet_impairment_tests',
       'Run ASPE 3063 fleet impairment two-step tests (per active asset)',
       0,
       175
  FROM `acc_year_end_checklist`;

-- ── 2. INSERT IGNORE settings ──────────────────────────────────────────
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
    ('accounting.impairment_cf_lookback_months', '12',
     'integer', 'accounting',
     'Impairment CF Estimator Lookback (Months)',
     'Months of revenue history to average when computing the default undiscounted future cash flow for ASPE 3063 step 1 recoverability tests. Default 12. Operator may override per-test via the CF override field. S-ACCT-LESSOR-6 2026-05-19.',
     0),
    ('accounting.impairment_default_disposal_basis', 'salvage_value',
     'string', 'accounting',
     'Impairment Disposal Value Basis',
     'Source for estimated disposal value in step 1 CF estimator: ''salvage_value'' uses acc_fixed_assets.salvage_value (default); ''manual'' requires operator to provide per-test; ''market_lookup'' reserved for future external valuation integration. S-ACCT-LESSOR-6 2026-05-19.',
     0);

COMMIT;
