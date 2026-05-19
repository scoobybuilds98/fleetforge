-- S-ACCT-LESSOR-5 — NI Current/Long-Term Reclass Cron + Annual Residual
-- Review (Phase D-5).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.6 + §24.7
-- Roadmap:  §11 row 24
--
-- 1. ALTER acc_journal_entries.source_type ENUM — append 2 new values:
--    'lease_ni_reclass'             (monthly cron output)
--    'lease_residual_impairment'    (annual residual review write-down)
--    Append-at-end per D126 — never reorder existing values.
--
-- 2. CREATE acc_lease_residual_reviews — UNIQUE(lease_id, fiscal_year)
--    so a lease can have at most one review row per fiscal year (matches
--    spec §24.7's annual-review cadence). delta is GENERATED to enforce
--    the constraint that revised − prior is always the recorded delta.
--    impairment_je_id is NULL until the JE posts (set on the same call).
--    schedule_regenerated flag flips to 1 after LeaseAmortizationService::
--    regeneratePartial completes for the downward case.
--
-- 3. INSERT IGNORE 1 new GL account:
--      6220  Impairment Loss — Residual   (operating_expense/debit)
--    K-22 catch surfaced in pre-flight: prompt named 6170 but disk has
--    #71 6170 Bank Charges (operating_expense). Operator chose 6220 via
--    AskUserQuestion — next to #74 6200 Amortization (conceptually
--    adjacent — both are non-cash deferred-asset writedowns), leaving
--    6230+ free for a future LESSOR-6 fleet impairment account
--    (ASPE 3063, §24.8 scope).
--
-- 4. INSERT IGNORE 1 setting mapping the new account id:
--      accounting.lessor_residual_impairment_account_id → #6220's row id

START TRANSACTION;

-- ── 1. ALTER source_type ENUM ──────────────────────────────────────────
-- Idempotent: MySQL treats the MODIFY as a no-op when ENUM already matches.
ALTER TABLE `acc_journal_entries`
    MODIFY COLUMN `source_type` ENUM(
        'invoice','payment','credit_note','ap_bill','ap_payment',
        'bank_transaction','depreciation','asset_disposal','tax_remittance',
        'fx_revaluation','manual','year_end','recurring',
        'damage_recovery','damage_repair','damage_writeoff',
        'lease_inception','lease_period','lease_termination',
        'lease_ni_reclass','lease_residual_impairment'
    ) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

-- ── 2. CREATE acc_lease_residual_reviews ───────────────────────────────
CREATE TABLE `acc_lease_residual_reviews` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lease_id` INT UNSIGNED NOT NULL,
    `fiscal_year` INT NOT NULL,
    `prior_residual_value`   DECIMAL(12,2) NOT NULL,
    `revised_residual_value` DECIMAL(12,2) NOT NULL,
    `delta` DECIMAL(12,2) GENERATED ALWAYS AS
        (`revised_residual_value` - `prior_residual_value`) STORED,
    `impairment_je_id`       INT UNSIGNED NULL,
    `schedule_regenerated`   TINYINT(1) NOT NULL DEFAULT 0,
    `notes`                  TEXT NULL,
    `reviewed_by`            INT UNSIGNED NOT NULL,
    `reviewed_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_year_lease` (`lease_id`, `fiscal_year`),
    KEY `idx_lrr_je` (`impairment_je_id`),
    KEY `idx_lrr_user` (`reviewed_by`),
    CONSTRAINT `fk_lrr_lease` FOREIGN KEY (`lease_id`)
        REFERENCES `leases` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lrr_je` FOREIGN KEY (`impairment_je_id`)
        REFERENCES `acc_journal_entries` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_lrr_user` FOREIGN KEY (`reviewed_by`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. INSERT GL account 6220 Impairment Loss — Residual ───────────────
INSERT IGNORE INTO `acc_accounts`
    (`code`, `name`, `description`, `account_type`, `normal_balance`,
     `currency`, `is_active`, `is_system`, `is_bank_account`,
     `is_fx_monetary`, `sort_order`, `lead_schedule_code`)
VALUES
    ('6220', 'Impairment Loss — Residual',
     'ASPE 3065 §24.7 annual residual review write-down expense. Debited when an unguaranteed residual value is revised downward (upward revisions prohibited per spec). Sits next to #74 6200 Amortization — Leasehold Improvements; future ASPE 3063 fleet impairment account (LESSOR-6 scope) will land in 6230+. S-ACCT-LESSOR-5 2026-05-19.',
     'operating_expense', 'debit', 'CAD', 1, 1, 0, 0, 622, '300-Lead');

-- ── 4. INSERT setting ──────────────────────────────────────────────────
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_residual_impairment_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Residual Impairment Loss Account',
       'GL account debited when an unguaranteed residual value on a capital lease is revised downward via the annual review per ASPE 3065 §24.7. Resolved via requireAccountId() in LeaseResidualService::reviewResidual. S-ACCT-LESSOR-5 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '6220' LIMIT 1;

COMMIT;
