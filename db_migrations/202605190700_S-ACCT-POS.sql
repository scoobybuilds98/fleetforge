-- S-ACCT-POS — Place of Supply rule engine + NS HST rate change.
--
-- 1. NEW TABLE acc_place_of_supply_rules holds per-province per-rule_type
--    mappings to tax_rates IDs (JSON array, since one province row carries
--    multiple rate fields). Spec §23.6.
--
-- 2. tax_rates.effective_from + effective_to ALREADY EXIST on disk (verified
--    via pre-flight scan — no ALTER needed). Adds the (effective_from,
--    effective_to) index for resolution-query performance per spec §23.6.
--
-- 3. K-22 catches surfaced in pre-flight + locked alongside this session:
--    - tax_rates uses WIDE-row design — one row per province with separate
--      gst_rate / pst_rate / hst_rate columns. NOT the tall-row {tax_type, rate}
--      shape the prompt assumed. POS rule rows store a single tax_rates.id
--      per province (the province's "current" row).
--    - tax_rates column is `province` (varchar(100)) — NOT `province_code`.
--    - tax_rates has NO `tax_type` column — taxes are columns on the row.
--    - leases has NO province / ordinarily_located_province column — POS
--      LONG_LEASE rule falls back to customer.province per STOP CONDITION.
--    - equipment_units has NO province_code column — POS SPECIFIED_MOTOR_VEHICLE
--      rule falls back to customer.province per STOP CONDITION.
--    - Province codes on disk use ISO-3166-2 short codes (NT not NWT).
--
-- 4. NS HST historic row: the current NS tax_rates row (#7) is already
--    effective 2025-04-01 at 14%. Pre-2025-04-01 history at 15% is MISSING
--    on disk — without it, a backdated invoice to March 2025 silently uses
--    14%. Seed file 016 INSERTs the historic 15% row with
--    effective_from='2000-01-01' + effective_to='2025-03-31' (effective_to
--    on the existing 14% row is left NULL — uncapped going forward).
--
-- 5. POS rule rows: 13 provinces × 4 rule_types = 52 INSERT IGNORE rows
--    (the specified_motor_vehicle rule_type is permitted by the ENUM but
--    is not seeded for Mainland's current operation — added on demand).
--
-- 6. Settings: 2 new keys gating the POS confirmation flow.

-- ── 1. acc_place_of_supply_rules ───────────────────────────────────────────
CREATE TABLE `acc_place_of_supply_rules` (
    `id`                      int unsigned NOT NULL AUTO_INCREMENT,
    `rule_type`               enum('goods_delivered','short_lease','long_lease','service','specified_motor_vehicle') COLLATE utf8mb4_unicode_ci NOT NULL,
    `province_code`           varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL,
    `applicable_tax_rate_ids` json NOT NULL,
    `priority`                tinyint unsigned NOT NULL DEFAULT '10',
    `notes`                   text COLLATE utf8mb4_unicode_ci,
    `is_active`               tinyint(1) NOT NULL DEFAULT '1',
    `created_at`              datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pos_rule` (`rule_type`, `province_code`),
    KEY `idx_pos_province`  (`province_code`),
    KEY `idx_pos_rule_type` (`rule_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. tax_rates index (effective_from/effective_to columns pre-exist) ─────
-- ALTER skipped per pre-flight: both columns already on disk. Adding only
-- the composite index for resolution-query performance.
ALTER TABLE `tax_rates`
    ADD INDEX `idx_tr_effective` (`effective_from`, `effective_to`);

-- ── 3. Settings (idempotent) ───────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
('accounting.pos_confirmation_threshold', '0',  'decimal', 'accounting', 'POS Confirmation Threshold', 'Dollar amount above which out-of-province invoices surface a confirmation prompt. 0 = always prompt.', 0),
('accounting.pos_default_province',       'BC', 'string',  'accounting', 'POS Default Province',       'Province assumed when no other signal is available (customer.province blank + no delivery address). ISO-3166-2 short code.', 0);
