-- S-ACCT-CCA-1 — Capital Cost Allowance Schedule 8 engine schema.
--
-- 1. NEW TABLE acc_cca_classes holds the 9 CRA capital cost allowance
--    classes relevant to a fleet operation. Seeded via
--    database/seeds/015_acc_cca_classes.sql.
--
-- 2. NEW TABLE acc_cca_continuity holds per-fiscal-year per-class T2
--    Schedule 8 continuity rows. UNIQUE(fiscal_year, cca_class_id) +
--    is_locked flag prevent accidental recompute after sign-off.
--
-- 3. ALTER acc_fixed_assets adds three CCA-specific columns:
--    cca_class_id (FK to acc_cca_classes — separate from the legacy
--    cra_class varchar / cra_cca_rate decimal which we KEEP for
--    backward compatibility); available_for_use_date (CRA "available
--    for use" anchor for AIIP + first-year half-year application);
--    is_aiip_eligible (flag for CCA-2 AIIP engine, default true).
--    K-22 column-name catches surfaced in pre-flight:
--      - acc_fixed_assets uses `asset_class` (not `asset_category`)
--      - acc_fixed_assets uses `acquisition_cost` (not `original_cost`)
--      - no `deleted_at` on acc_fixed_assets (hard-delete only)
--    AFTER clauses use the actual on-disk column names.
--
-- 4. 20 existing live assets have cca_class_id=NULL after this migration.
--    Operator must assign classes via the asset edit form before the
--    first CCA compute on production. PREDEPLOY items H-CCA-1 + H-CCA-2
--    track this.

-- ── 1. acc_cca_classes ─────────────────────────────────────────────────────
CREATE TABLE `acc_cca_classes` (
    `id`                    int unsigned NOT NULL AUTO_INCREMENT,
    `class_number`          varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
    `description`           varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `rate`                  decimal(5,4) NOT NULL,
    `method`                enum('declining_balance','straight_line') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'declining_balance',
    `half_year_rule`        tinyint(1) NOT NULL DEFAULT '1',
    `aiip_eligible`         tinyint(1) NOT NULL DEFAULT '1',
    `recapture_applies`     tinyint(1) NOT NULL DEFAULT '1',
    `terminal_loss_applies` tinyint(1) NOT NULL DEFAULT '1',
    `one_asset_per_class`   tinyint(1) NOT NULL DEFAULT '0',
    `is_active`             tinyint(1) NOT NULL DEFAULT '1',
    `notes`                 text COLLATE utf8mb4_unicode_ci,
    `created_at`            datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_class_number` (`class_number`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. acc_cca_continuity ──────────────────────────────────────────────────
CREATE TABLE `acc_cca_continuity` (
    `id`                              int unsigned NOT NULL AUTO_INCREMENT,
    `fiscal_year`                     int NOT NULL,
    `cca_class_id`                    int unsigned NOT NULL,
    `opening_ucc`                     decimal(15,2) NOT NULL DEFAULT '0.00',
    `cost_of_additions`               decimal(15,2) NOT NULL DEFAULT '0.00',
    `adjustments_transfers`           decimal(15,2) NOT NULL DEFAULT '0.00',
    `proceeds_of_disposition`         decimal(15,2) NOT NULL DEFAULT '0.00',
    `ucc_after_additions_dispositions` decimal(15,2) NOT NULL DEFAULT '0.00',
    `aiip_adjustment`                 decimal(15,2) NOT NULL DEFAULT '0.00',
    `base_amount_for_cca`             decimal(15,2) NOT NULL DEFAULT '0.00',
    `half_year_adjustment`            decimal(15,2) NOT NULL DEFAULT '0.00',
    `cca_claimed`                     decimal(15,2) NOT NULL DEFAULT '0.00',
    `recapture`                       decimal(15,2) NOT NULL DEFAULT '0.00',
    `terminal_loss`                   decimal(15,2) NOT NULL DEFAULT '0.00',
    `closing_ucc`                     decimal(15,2) NOT NULL DEFAULT '0.00',
    `is_locked`                       tinyint(1) NOT NULL DEFAULT '0',
    `computed_at`                     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `computed_by`                     int unsigned DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_year_class` (`fiscal_year`, `cca_class_id`),
    KEY `fk_cca_cont_class` (`cca_class_id`),
    KEY `fk_cca_cont_user` (`computed_by`),
    CONSTRAINT `fk_cca_cont_class` FOREIGN KEY (`cca_class_id`) REFERENCES `acc_cca_classes` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cca_cont_user`  FOREIGN KEY (`computed_by`)  REFERENCES `users` (`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. acc_fixed_assets — 3 new columns + FK ───────────────────────────────
-- Order: cca_class_id after asset_class (the actual on-disk column —
-- prompt mistakenly called it asset_category). available_for_use_date
-- after acquisition_date. is_aiip_eligible at end for tidiness.
ALTER TABLE `acc_fixed_assets`
    ADD COLUMN `cca_class_id`           int unsigned NULL AFTER `asset_class`,
    ADD COLUMN `available_for_use_date` date         NULL AFTER `acquisition_date`,
    ADD COLUMN `is_aiip_eligible`       tinyint(1)   NOT NULL DEFAULT '1' AFTER `available_for_use_date`;

ALTER TABLE `acc_fixed_assets`
    ADD CONSTRAINT `fk_fa_cca_class`
        FOREIGN KEY (`cca_class_id`) REFERENCES `acc_cca_classes` (`id`) ON DELETE SET NULL;
