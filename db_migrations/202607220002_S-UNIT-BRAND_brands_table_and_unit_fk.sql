-- ============================================================================
-- 202607220002_S-UNIT-BRAND_brands_table_and_unit_fk.sql
--
-- S-UNIT-BRAND — move `brand` from the equipment TEMPLATE onto the UNIT.
--
-- Operator request: "the brand is added when we create a unit template, the
-- problem is we can have similar unit type from multiple different brands.
-- For example we can have a 53 feet dry van from 5-6 different brands." A
-- template describes a TYPE ("53' Dry Van"); the manufacturer is a property of
-- the individual asset, not of the type. Modelling it on the template forced
-- one duplicate template per brand and made the type taxonomy meaningless.
--
-- Brand becomes an operator-managed lookup (`equipment_brands`) referenced by
-- `equipment_units.brand_id`, selected on unit create/edit. It is deliberately
-- a TABLE, not an ENUM or a free-text varchar:
--   * ENUM would 1265-abort in STRICT mode the first time an operator adds a
--     brand that isn't in the list (the rate_method trap, D-RATE-ENUM).
--   * free text would fragment ("Great Dane" / "great dane" / "GreatDane")
--     and make the dropdown useless within a month.
-- It mirrors `equipment_categories` exactly (slug/label/is_active/sort_order)
-- so the CRUD, the picker and the soft-delete semantics are already familiar.
--
-- ORDER MATTERS. The backfill MUST run before the DROP, in this one migration:
-- `equipment_templates.brand` is the ONLY source for a unit's existing brand,
-- so splitting the two across migrations would lose the data if the second
-- never ran. Everything below is idempotent and re-runnable.
--
-- SEED — the operator's 23 brands (their list had "Fontaine" twice; deduped),
-- plus the 4 brands already in use on existing templates (Freightliner,
-- Kenworth, Volvo, Doonan). Seeding those 4 is what lets the backfill below
-- match every existing template instead of silently dropping tractor brands on
-- the floor. Any of them can be deactivated from the admin UI.
--
-- NOT DONE HERE — the operator's production re-assignment ("make everything
-- other than the dry vans a Max Atlas unit") is a separate, deployment-specific
-- data script they asked to write later. This migration only PRESERVES what
-- each deployment already has.
--
-- charset/collation utf8mb4_unicode_ci to match the rest of the schema.
-- ============================================================================

-- 1. The operator-managed brand list (mirrors equipment_categories).
CREATE TABLE IF NOT EXISTS `equipment_brands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stable identifier. Never edited after create.',
  `label` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Operator-editable display name shown in the unit brand dropdown.',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Inactive brands stay on existing units but drop out of the picker.',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eqbrand_slug` (`slug`),
  KEY `idx_eqbrand_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed. ON DUPLICATE KEY refreshes only the display label + ordering, never
--    `is_active` or `deleted_at` — re-running must not resurrect a brand the
--    operator has deliberately retired.
INSERT INTO `equipment_brands` (`slug`, `label`, `sort_order`) VALUES
  ('max-atlas',    'Max Atlas',    10),
  ('cimc',         'CIMC',         20),
  ('jindo',        'Jindo',        30),
  ('thaco',        'Thaco',        40),
  ('sigimas',      'Sigimas',      50),
  ('fruehauf',     'Fruehauf',     60),
  ('stoughton',    'Stoughton',    70),
  ('utility',      'Utility',      80),
  ('great-dane',   'Great Dane',   90),
  ('wabash',       'Wabash',      100),
  ('vanguard',     'Vanguard',    110),
  ('raja',         'Raja',        120),
  ('hyundai',      'Hyundai',     130),
  ('fontaine',     'Fontaine',    140),
  ('cheetah',      'Cheetah',     150),
  ('manac',        'Manac',       160),
  ('doepker',      'Doepker',     170),
  ('wilson',       'Wilson',      180),
  ('mac',          'Mac',         190),
  ('trail-king',   'Trail King',  200),
  ('reitnouer',    'Reitnouer',   210),
  ('lode-king',    'Lode King',   220),
  ('aspen',        'Aspen',       230),
  -- Already in use on existing templates; seeded so the backfill can match them.
  ('freightliner', 'Freightliner', 240),
  ('kenworth',     'Kenworth',     250),
  ('volvo',        'Volvo',        260),
  ('doonan',       'Doonan',       270)
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `sort_order` = VALUES(`sort_order`);

-- 3. The unit's brand. NULL = not yet set (the operator expects to fill these
--    in per unit), so no default and ON DELETE SET NULL: retiring a brand row
--    must never cascade-delete fleet assets.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_units' AND COLUMN_NAME = 'brand_id'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `equipment_units`
     ADD COLUMN `brand_id` INT UNSIGNED DEFAULT NULL
       COMMENT ''S-UNIT-BRAND: manufacturer of THIS unit. Was equipment_templates.brand — a template is a TYPE, and one type comes from many brands.''
       AFTER `template_id`,
     ADD KEY `idx_equnit_brand` (`brand_id`),
     ADD CONSTRAINT `fk_equnit_brand` FOREIGN KEY (`brand_id`)
       REFERENCES `equipment_brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT ''equipment_units.brand_id already present''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Backfill: carry each template's brand down onto its units.
--    Matched on the LABEL (that is what templates store), case-insensitively.
--    Only fills NULLs, so a re-run never overwrites an operator's later edit.
--    Any template brand with no matching row simply leaves brand_id NULL —
--    which is why step 2 seeds the four in-use extras.
SET @brand_col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment_templates' AND COLUMN_NAME = 'brand'
);
SET @sql := IF(@brand_col_exists = 1,
  'UPDATE `equipment_units` u
      JOIN `equipment_templates` t ON t.id = u.template_id
      JOIN `equipment_brands` b    ON LOWER(TRIM(b.label)) = LOWER(TRIM(t.brand))
       SET u.brand_id = b.id
     WHERE u.brand_id IS NULL
       AND t.brand IS NOT NULL AND t.brand <> ''''',
  'SELECT ''equipment_templates.brand already dropped — backfill skipped''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Drop the template column. Runs only after step 4 has moved the data.
--    Guarded so a re-run is a no-op rather than a 1091 error.
SET @sql := IF(@brand_col_exists = 1,
  'ALTER TABLE `equipment_templates` DROP COLUMN `brand`',
  'SELECT ''equipment_templates.brand already dropped''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
