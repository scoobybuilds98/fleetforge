-- S-ACCT-LESSOR-1 — ASPE 3065 Classification Wizard + Schema (Phase D-1).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.1–§24.3
-- Roadmap:  §11 row 20
--
-- 1. ALTER `leases` — 11 new columns + FK for classification sign-off.
--    DEFAULT 'operating' means all 42 existing leases get the safe
--    default with zero backfill writes. No status enum changes.
--
-- 2. CREATE `acc_lease_classifications` — wizard archive table.
--    One row per lease (UNIQUE on lease_id) so re-running the wizard
--    overwrites via ON DUPLICATE KEY UPDATE in the service layer.
--
-- 3. INSERT 5 lessor GL accounts. Operator-chosen codes after pre-flight
--    surfaced collision: code 1040 is ALREADY "Allowance for Doubtful
--    Accounts" on disk, so the spec's reference to 1040 for NI Current
--    is repointed to 1090 (next free slot after 1080 Security Deposits).
--    NI Long-Term keeps 1600 (free between 1280 and 2000 liabilities).
--    Revenue/liability/income placements pick next-free codes in the
--    Mainland COA shape:
--      1090 Net Investment in Lease — Current        (asset, debit)
--      1600 Net Investment in Lease — Long-Term      (asset, debit)
--      4122 Sales Revenue — Lease-to-Own             (revenue, credit)
--      2230 Unearned Finance Income                  (liability, credit)
--      7060 Finance Income — Capital Leases          (other_income, credit)
--
-- 4. INSERT 6 settings keys mapping the 5 GL accounts + a module-enabled
--    gate (default 0). LESSOR-2/3/4 sessions check the gate before posting
--    capital-lease JEs.

START TRANSACTION;

-- ── 1. ALTER leases — 11 new columns + FK ───────────────────────────────
-- WHY: classification needs to live on the lease row itself (not solely
-- in acc_lease_classifications) so a single SELECT on a lease tells the
-- downstream JE engine which posting path to take. The wizard archive
-- table stores the full criteria breakdown for audit; this column is
-- the operational state.
ALTER TABLE `leases`
    ADD COLUMN `classification`
        ENUM('operating','sales_type','direct_financing')
        COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'operating'
        AFTER `status`,
    ADD COLUMN `classification_signed_off_by` INT UNSIGNED NULL
        AFTER `classification`,
    ADD COLUMN `classification_signed_off_at` DATETIME NULL
        AFTER `classification_signed_off_by`,
    ADD COLUMN `bargain_purchase_option_amount` DECIMAL(12,2) NULL
        AFTER `classification_signed_off_at`,
    ADD COLUMN `bargain_purchase_option_date` DATE NULL
        AFTER `bargain_purchase_option_amount`,
    ADD COLUMN `economic_life_months` INT NULL
        AFTER `bargain_purchase_option_date`,
    ADD COLUMN `initial_fair_value` DECIMAL(12,2) NULL
        AFTER `economic_life_months`,
    ADD COLUMN `initial_direct_costs` DECIMAL(12,2) NOT NULL DEFAULT '0.00'
        AFTER `initial_fair_value`,
    ADD COLUMN `guaranteed_residual_value` DECIMAL(12,2) NOT NULL DEFAULT '0.00'
        AFTER `initial_direct_costs`,
    ADD COLUMN `unguaranteed_residual_value` DECIMAL(12,2) NOT NULL DEFAULT '0.00'
        AFTER `guaranteed_residual_value`,
    ADD COLUMN `implicit_rate` DECIMAL(7,4) NULL
        AFTER `unguaranteed_residual_value`,
    ADD CONSTRAINT `fk_lease_classification_signoff`
        FOREIGN KEY (`classification_signed_off_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ── 2. CREATE acc_lease_classifications ─────────────────────────────────
-- WHY: archives the full ASPE 3065.06–.10 reasoning for every lease the
-- wizard touches. CPA review pulls from this table — leases.classification
-- alone is not enough audit trail. UNIQUE(lease_id) keeps it 1:1.
CREATE TABLE `acc_lease_classifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lease_id` INT UNSIGNED NOT NULL,

    -- 3065.06(a): title transfer or BPO present
    `criterion_a_met`   TINYINT(1) NOT NULL DEFAULT 0,
    `criterion_a_notes` TEXT NULL,

    -- 3065.06(b): lease term ≥ 75% of economic life
    `criterion_b_met`                  TINYINT(1) NOT NULL DEFAULT 0,
    `criterion_b_lease_term_months`    INT NULL,
    `criterion_b_economic_life_months` INT NULL,
    `criterion_b_ratio`                DECIMAL(5,4) NULL,

    -- 3065.06(c): PV of MLP ≥ 90% of fair value
    `criterion_c_met`        TINYINT(1) NOT NULL DEFAULT 0,
    `criterion_c_pv_mlp`     DECIMAL(15,2) NULL,
    `criterion_c_fair_value` DECIMAL(15,2) NULL,
    `criterion_c_ratio`      DECIMAL(5,4) NULL,

    -- 3065.07–.08 qualifying conditions
    `credit_risk_normal` TINYINT(1) NOT NULL DEFAULT 1,
    `costs_estimable`    TINYINT(1) NOT NULL DEFAULT 1,

    -- Decision
    `any_criterion_met`         TINYINT(1) NOT NULL DEFAULT 0,
    `all_conditions_met`        TINYINT(1) NOT NULL DEFAULT 0,
    `determined_classification` ENUM('operating','sales_type','direct_financing')
        COLLATE utf8mb4_unicode_ci NOT NULL,
    `classification_rationale`  TEXT NULL,

    -- Audit
    `wizard_completed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `wizard_completed_by` INT UNSIGNED NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_lease_classification` (`lease_id`),
    KEY `idx_lc_user` (`wizard_completed_by`),
    CONSTRAINT `fk_lc_lease` FOREIGN KEY (`lease_id`)
        REFERENCES `leases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lc_user` FOREIGN KEY (`wizard_completed_by`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. INSERT lessor GL accounts (INSERT IGNORE on code UNIQUE) ─────────
-- WHY: idempotent so re-running the migration is a no-op. Each row uses
-- the `code` column (not `account_number` — that column does NOT exist
-- on acc_accounts; this is a K-22 schema trap caught in pre-flight).
INSERT IGNORE INTO `acc_accounts`
    (`code`, `name`, `description`, `account_type`, `normal_balance`, `currency`, `is_active`, `is_system`, `is_bank_account`, `is_fx_monetary`, `sort_order`)
VALUES
    ('1090', 'Net Investment in Lease — Current',
     'ASPE 3065.54 — current portion (next 12 months) of net investment in lease for sales-type and direct-financing leases. Roll-forward maintained by accounting_lease_ni_reclass cron (LESSOR-3+).',
     'asset', 'debit', 'CAD', 1, 1, 0, 0, 90),
    ('1600', 'Net Investment in Lease — Long-Term',
     'ASPE 3065.54 — long-term portion (beyond 12 months) of net investment in lease for sales-type and direct-financing leases.',
     'asset', 'debit', 'CAD', 1, 1, 0, 0, 160),
    ('4122', 'Sales Revenue — Lease-to-Own',
     'ASPE 3065 sales-type lease selling revenue recognized at inception (PV of minimum lease payments). Direct financing leases do NOT credit this account.',
     'revenue', 'credit', 'CAD', 1, 1, 0, 0, 412),
    ('2230', 'Unearned Finance Income',
     'ASPE 3065 unearned finance income — gross investment minus net investment at lease inception. Amortized to Finance Income via effective-interest method each period.',
     'liability', 'credit', 'CAD', 1, 1, 0, 0, 223),
    ('7060', 'Finance Income — Capital Leases',
     'ASPE 3065 finance income recognized each period via effective-interest method (opening NI × implicit rate). Sales-type and direct-financing leases both credit this account.',
     'other_income', 'credit', 'CAD', 1, 1, 0, 0, 706);

-- ── 4. INSERT settings mapping the 5 GL accounts + module enabled gate ──
-- WHY: AutoEntryBridge methods in LESSOR-2/3/4 will look up these IDs.
-- Storing them as integer settings (not codes) decouples posting from
-- COA-code-string drift. INSERT IGNORE so re-runs are safe. The
-- `lessor_module_enabled` gate stays at 0 until the operator originates
-- the first capital lease — keeps the module dormant.
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_ni_current_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Net Investment in Lease (Current) Account',
       'GL account debited at sales-type / direct-financing lease inception for the principal expected to be received within 12 months. S-ACCT-LESSOR-1 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '1090' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_ni_longterm_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Net Investment in Lease (Long-Term) Account',
       'GL account debited at capital-lease inception for the principal expected to be received beyond 12 months. Reclassified to current by monthly cron. S-ACCT-LESSOR-1 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '1600' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_sales_revenue_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Sales Revenue (Lease-to-Own) Account',
       'GL account credited at sales-type lease inception for PV of minimum lease payments. Not used for direct-financing or operating leases. S-ACCT-LESSOR-1 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '4122' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_unearned_finance_income_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Unearned Finance Income Account',
       'GL account credited at capital-lease inception for gross-minus-net investment. Amortized to Finance Income each period via effective-interest method. S-ACCT-LESSOR-1 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '2230' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_finance_income_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Finance Income Account',
       'GL account credited each period for finance income recognized on sales-type and direct-financing leases (opening NI × implicit rate). S-ACCT-LESSOR-1 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '7060' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
    ('accounting.lessor_module_enabled', '0',
     'boolean', 'accounting',
     'Lessor Module Enabled',
     'Gate for capital-lease JE posting. Stays at 0 until the first sales-type or direct-financing lease is classified. AutoEntryBridge LESSOR-3/4 sessions check this flag before posting capital-lease JEs. S-ACCT-LESSOR-1 2026-05-19.',
     0);

COMMIT;
