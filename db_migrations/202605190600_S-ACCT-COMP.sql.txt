-- S-ACCT-COMP — PP&E Componentization + Betterment/Repair workflow schema.
--
-- 1. ALTER acc_fixed_assets: add parent_asset_id (FK self-reference) +
--    is_component flag per spec §23.5 (ASPE 3061.18). Components depreciate
--    independently — the parent's display NBV is the sum of itself + all
--    components. FK uses ON DELETE CASCADE because components are not
--    meaningful without their parent (spec §23.5).
--
-- 2. ALTER acc_bill_lines: add betterment_note (TEXT NULL) for CPA-defensibility
--    classification justification (ASPE 3061.14 reasoning). The capitalize
--    TINYINT(1) column ALREADY EXISTS on disk (from S028 schema — confirmed
--    via pre-flight scan); only betterment_note needs adding.
--
-- 3. K-22 traps applied:
--    - acc_fixed_assets has NO `deleted_at` (hard-delete only) — confirmed
--    - acc_fixed_assets uses `acquisition_cost` not `original_cost`
--    - acc_fixed_assets uses `useful_life_years` not `useful_life_months`
--      (spec text said months — code adapts to years internally)

-- ── 1. acc_fixed_assets parent + component columns ─────────────────────────
ALTER TABLE `acc_fixed_assets`
    ADD COLUMN `parent_asset_id` int unsigned NULL AFTER `id`,
    ADD COLUMN `is_component`    tinyint(1)   NOT NULL DEFAULT '0' AFTER `parent_asset_id`;

ALTER TABLE `acc_fixed_assets`
    ADD CONSTRAINT `fk_fa_parent`
        FOREIGN KEY (`parent_asset_id`) REFERENCES `acc_fixed_assets` (`id`) ON DELETE CASCADE;

-- ── 2. acc_bill_lines.betterment_note ──────────────────────────────────────
-- capitalize TINYINT(1) already exists (S028 schema); only the note is new.
ALTER TABLE `acc_bill_lines`
    ADD COLUMN `betterment_note` text COLLATE utf8mb4_unicode_ci NULL AFTER `capitalize`;
