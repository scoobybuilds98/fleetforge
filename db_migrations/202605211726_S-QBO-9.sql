-- ============================================================
-- S-QBO-9 — acc_qbo_tax_code_map.
--
-- Tax Code mapping table. Pulls QBO TaxCode entities via /query and
-- links them to FF tax_rates rows. PULLER-ONLY per D-QBO-9-1 —
-- accountant owns the tax code structure in QBO, FF mirrors via the
-- mapping table. No corresponding TaxCodePusher class.
--
-- The CRITICAL deliverable of this session: identify the 'NON'
-- override target (D-QBO-9-2). QBO's 'NON' tax code is the
-- no-tax-computed code used with TxnTaxDetail.TotalTax to push
-- FF-computed tax without QBO recalculation (D-QBO-CORE-6 +
-- D-QBO-9-3). The QBO Id of the 'NON' code gets auto-written to
-- settings.quickbooks.tax_override_code_id after Pull — this is the
-- load-bearing setting for S-QBO-11 invoice push.
--
-- K-22 catches surfaced during pre-flight (silently applied):
--   - FF table is `tax_rates` (verified — matches prompt assumption)
--   - FF columns: `name`, `province` (NOT `jurisdiction`), `country`,
--     `gst_rate`, `pst_rate`, `hst_rate` (3 SEPARATE rate columns,
--     NOT a single `rate_percent` — composite-rate setups like BC
--     "GST + PST" carry non-zero values in BOTH gst_rate AND
--     pst_rate; HST provinces carry hst_rate only; QC stores QST in
--     pst_rate per the seed note. Matcher logic accommodates).
--   - FF columns: `is_active`, `is_default`, `effective_from`,
--     `effective_to`, `notes`, `created_at` (no updated_at, no
--     created_by, no deleted_at — operator-managed CRA-context
--     reference data, immutable once seeded).
--
-- UNIQUE-on-TINYINT verified at pre-flight: NOT NULL TINYINT(1) UNIQUE
-- rejects duplicate 0s (MySQL InnoDB standard behavior). So the
-- override-target slot is implemented as NULLABLE column with
-- semantics: NULL = not-the-override-target / 1 = is-the-override-target
-- (never 0). UNIQUE then allows many NULLs and enforces exactly-one '1'.
--
-- Schema mirrors customer/vendor/account mapping state-machine pattern:
--   - mapping_status ENUM 4-state (mapped/ff_only/qbo_only/ignored)
--   - UNIQUE on nullable ff_tax_rate_id + qbo_tax_code_id (InnoDB
--     multi-NULL semantics — many single-sided rows allowed)
--   - match_confidence ENUM with tax-specific values
--   - Snapshot fields (qbo_name, qbo_description, qbo_taxable,
--     qbo_hidden, qbo_active, qbo_tax_group)
--
-- Tax-specific additions:
--   - is_override_target NULLABLE — D-QBO-9-2 single-row enforcement
--   - ff_rate_snapshot — captured at mapping time (which of the 3
--     FF rate columns is non-zero, summed). FF rate is the ground
--     truth for tax computation; QBO rate is reporting parity only.
--   - ff_province (snapshot at mapping time — FF tax_rates is small
--     enough that we don't need a full FK + join; snapshot is
--     fine for display)
--
-- @session   S-QBO-9
-- @date      2026-05-21
-- @decisions D-QBO-9-1 (Puller-only — no TaxCodePusher/Enqueuer;
--                       accountant owns tax codes in QBO),
--            D-QBO-9-2 (NON override target identification +
--                       auto-wire to settings; UNIQUE-on-NULLABLE
--                       enforces single override slot),
--            D-QBO-9-3 (FF→QBO mapping is INFORMATIONAL — FF computes
--                       tax authoritatively; QBO accepts override via
--                       TxnTaxDetail.TotalTax with TaxCodeRef=NON; the
--                       mapping is used for invoice PrivateNote audit
--                       trail in S-QBO-11),
--            D-QBO-9-4 (effective-date awareness — snapshot CURRENT
--                       active FF rate at mapping time; historical
--                       drift between FF and QBO labels acceptable),
--            D-QBO-9-5 (active-only filter — auto-match + UI default
--                       to FF tax_rates with is_active=1 AND
--                       effective_from <= NOW() AND (effective_to IS
--                       NULL OR effective_to > NOW()); historical
--                       rows accessible via "Show historical" toggle)
-- ============================================================

START TRANSACTION;

CREATE TABLE `acc_qbo_tax_code_map` (
  `id`                  int unsigned                  NOT NULL AUTO_INCREMENT,
  `ff_tax_rate_id`      int unsigned                           DEFAULT NULL COMMENT 'NULL = qbo_only state (QBO tax code with no FF link)',
  `qbo_tax_code_id`     varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit TaxCode.Id; NULL = ff_only state',
  `qbo_sync_token`      varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token; refreshed on every pull',
  `qbo_name`            varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO TaxCode.Name snapshot at last sync',
  `qbo_description`     varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO TaxCode.Description snapshot',
  `qbo_taxable`         tinyint(1)                             DEFAULT NULL COMMENT 'QBO TaxCode.Taxable flag',
  `qbo_hidden`          tinyint(1)                             DEFAULT NULL COMMENT 'QBO TaxCode.Hidden (Intuit seeded codes are sometimes hidden)',
  `qbo_active`          tinyint(1)                             DEFAULT NULL COMMENT 'QBO TaxCode.Active flag',
  `qbo_tax_group`       tinyint(1)                             DEFAULT NULL COMMENT 'QBO TaxCode.TaxGroup (1 if composite rate like ON HST combining federal+provincial)',
  `qbo_sales_rate_refs` json                                   DEFAULT NULL COMMENT 'QBO TaxCode.SalesTaxRateList.TaxRateDetail snapshot (rate refs; values not resolved without TaxRatePuller — future)',
  `ff_rate_snapshot`    decimal(8,6)                           DEFAULT NULL COMMENT 'Sum of FF gst_rate + pst_rate + hst_rate at mapping time — single-number proxy for divergence detection',
  `ff_province`         varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'FF tax_rates.province snapshot (BC/AB/ON/NS/etc.)',
  `mapping_status`      enum('mapped','ff_only','qbo_only','ignored') NOT NULL DEFAULT 'qbo_only',
  `match_confidence`    enum('exact_name','exact_rate','high','medium','manual') DEFAULT NULL COMMENT 'D-QBO-9: exact_name=case-insensitive name match; exact_rate=jurisdiction+rate within ±0.01%; high=jurisdiction+name-substring; manual=operator override',
  `is_override_target`  tinyint(1)                             DEFAULT NULL COMMENT 'D-QBO-9-2: NULL = not-the-override-target; 1 = is-the-override-target. NEVER 0. UNIQUE allows many NULLs + enforces exactly-one 1. The single row with is_override_target=1 corresponds to QBO TaxCode Name=NON used in S-QBO-11 invoice push tax-override pattern.',
  `match_notes`         text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at`      datetime                               DEFAULT NULL,
  `last_pull_at`        datetime                               DEFAULT NULL,
  `created_at`          datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime                      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by_user_id`  int unsigned                           DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_tax_rate`     (`ff_tax_rate_id`),
  UNIQUE KEY `uq_qbo_tax_code`    (`qbo_tax_code_id`),
  UNIQUE KEY `uq_override_target` (`is_override_target`) COMMENT 'D-QBO-9-2: NULL allows many; 1 allows only one. Enforces single override slot.',
  KEY `idx_status`                (`mapping_status`),
  KEY `idx_last_synced`           (`last_synced_at`),
  CONSTRAINT `fk_qbo_tax_map_ff`
    FOREIGN KEY (`ff_tax_rate_id`) REFERENCES `tax_rates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qbo_tax_map_user`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
