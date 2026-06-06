-- S-ACCT-DISC — Disclosure Note Builder (Phase C-9, ASPE compilation pack).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.9
-- Roadmap:  §11 row 17
--
-- 1. NEW TABLE acc_disclosure_notes — per-fiscal-year per-note row with
--    is_auto_generated guard so re-runs of generateAll() skip notes that
--    have been manually edited. Cardinality: 1 row per (fiscal_year,
--    note_number). edited_by FK SET NULL on user delete so notes survive
--    user removal.
--
-- 2. ALTER customers ADD is_related_party — tinyint(1) NOT NULL DEFAULT 0.
--    Anchor AFTER `company_name` (K-22-safe; that's the identity column).
--
-- 3. ALTER vendors ADD is_related_party — tinyint(1) NOT NULL DEFAULT 0.
--    Anchor AFTER `name` (K-22: vendors uses `name`, not `company_name`).
--
-- 4. Settings — INSERT IGNORE 7 engagement keys:
--    - entity_legal_name, entity_province, entity_fiscal_year_end
--    - engagement_type (compilation|review)
--    - cpa_firm_name, cpa_designation, cpa_city
--    - gps_net_revenue_presentation_default (mirrors per-customer toggle)
--    The accounting.aiip_proposed_reinstatement_enabled key already exists
--    from S-ACCT-CCA-2; skipped here.
--
-- 5. K-22 catches surfaced + locked alongside this session:
--    - damage_claims.status: ENUM has 'resolved'/'written_off' but NO 'settled'
--      (prompt assumed 'settled'). Note 7 commitments uses
--      status NOT IN ('resolved','written_off') instead.
--    - acc_fixed_assets.depreciation_method (not amortization_method)
--      (prompt assumed amortization_method).
--    - acc_periods.year (not fiscal_year) per K-22 lock in REFERENCE.md.

-- ── 1. acc_disclosure_notes (new) ──────────────────────────────────────────
CREATE TABLE `acc_disclosure_notes` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fiscal_year`       INT NOT NULL,
  `note_number`       INT NOT NULL,
  `note_title`        VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note_content`      TEXT COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_auto_generated` TINYINT(1) NOT NULL DEFAULT 1,
  `edited_by`         INT UNSIGNED NULL,
  `edited_at`         DATETIME NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_year_note` (`fiscal_year`, `note_number`),
  KEY `idx_year` (`fiscal_year`),
  KEY `idx_edited_by` (`edited_by`),
  CONSTRAINT `fk_dn_edited_by`
    FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. customers.is_related_party ─────────────────────────────────────────
ALTER TABLE `customers`
    ADD COLUMN `is_related_party` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `company_name`;

-- ── 3. vendors.is_related_party ───────────────────────────────────────────
ALTER TABLE `vendors`
    ADD COLUMN `is_related_party` TINYINT(1) NOT NULL DEFAULT 0
        AFTER `name`;

-- ── 4. Engagement settings (idempotent) ───────────────────────────────────
INSERT IGNORE INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
('accounting.entity_legal_name', 'Mainland Truck & Trailer Sales', 'string', 'accounting',
 'Entity Legal Name',
 'Legal entity name printed on disclosure note headers and the basis-of-accounting note.', 0),
('accounting.entity_province', 'BC', 'string', 'accounting',
 'Entity Province',
 'Province of incorporation — used by Note 1 basis of accounting boilerplate.', 0),
('accounting.entity_fiscal_year_end', '12-31', 'string', 'accounting',
 'Entity Fiscal Year End',
 'Fiscal year end in MM-DD format. Used in Notes 1, 4, 5, 6, 7, 8, 9 boilerplate.', 0),
('accounting.engagement_type', 'compilation', 'string', 'accounting',
 'Engagement Type',
 'Engagement type — compilation or review. Drives Note 1 wording per CPA Canada handbook.', 0),
('accounting.cpa_firm_name', '', 'string', 'accounting',
 'CPA Firm Name',
 'Practitioner firm name printed on disclosure note pack cover. Populate before final issue.', 0),
('accounting.cpa_designation', '', 'string', 'accounting',
 'CPA Designation',
 'Practitioner designation (e.g. CPA, CA). Printed on disclosure note pack cover.', 0),
('accounting.cpa_city', '', 'string', 'accounting',
 'CPA City',
 'Practitioner city. Printed on disclosure note pack cover.', 0),
('accounting.gps_net_revenue_presentation_default', 'net', 'string', 'accounting',
 'GPS Net Revenue Presentation Default',
 'Default presentation for new customers — net (agent, ASPE 3400) or gross. Mirrored into Note 2 revenue recognition.', 0);
