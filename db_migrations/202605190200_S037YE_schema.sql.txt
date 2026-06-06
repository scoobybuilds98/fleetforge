-- S037-YE — Year-End Close schema.
--
-- 1. NEW TABLE acc_year_end_closures records the canonical ledger of
--    each fiscal-year close: when it ran, who ran it, which closing JE
--    was posted, where the package ZIP lives, its SHA-256 hash, and a
--    status flag so super-admin reversals can be recorded without
--    deleting the row.
--
-- 2. Two CHECKLIST items added to the standard 17-item year-end set
--    (audit confirms only 15 items were seeded for 2025). Items 16-17
--    cover FX revaluation (post-mark-to-market for USD-denominated
--    accounts at year-end) and lease amortization review (verify all
--    leased-asset depreciation is current). Idempotent via item_key
--    UNIQUE-style INSERT IGNORE (matching the pattern in the original
--    seed migration).
--
-- 3. Settings: retained earnings account is already configured at
--    accounting.retained_earnings_account_id=34 (Retained Earnings,
--    code 3020). No seed needed.

-- ── 1. acc_year_end_closures ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_year_end_closures` (
    `id`            int unsigned NOT NULL AUTO_INCREMENT,
    `fiscal_year`   smallint unsigned NOT NULL,
    `closed_at`     datetime NOT NULL,
    `closed_by`     int unsigned DEFAULT NULL,
    `closing_je_id` int unsigned DEFAULT NULL,
    `package_path`  varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `package_hash`  varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `status`        enum('closed','reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'closed',
    `notes`         text COLLATE utf8mb4_unicode_ci,
    `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fiscal_year` (`fiscal_year`),
    KEY `idx_status` (`status`),
    KEY `closed_by` (`closed_by`),
    KEY `closing_je_id` (`closing_je_id`),
    CONSTRAINT `acc_year_end_closures_ibfk_1` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `acc_year_end_closures_ibfk_2` FOREIGN KEY (`closing_je_id`) REFERENCES `acc_journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Top-up the 15 existing 2025 checklist items to the canonical 17 ──────
INSERT INTO `acc_year_end_checklist` (`year`, `item_key`, `item_label`, `is_complete`, `sort_order`)
    SELECT 2025, 'fx_revaluation_ye', 'Run FX revaluation for year-end USD balances', 0, 160
     WHERE NOT EXISTS (
        SELECT 1 FROM `acc_year_end_checklist`
         WHERE `year` = 2025 AND `item_key` = 'fx_revaluation_ye'
    );

INSERT INTO `acc_year_end_checklist` (`year`, `item_key`, `item_label`, `is_complete`, `sort_order`)
    SELECT 2025, 'lease_amortization_review', 'Review lease amortization schedule completeness for fiscal year', 0, 170
     WHERE NOT EXISTS (
        SELECT 1 FROM `acc_year_end_checklist`
         WHERE `year` = 2025 AND `item_key` = 'lease_amortization_review'
    );
