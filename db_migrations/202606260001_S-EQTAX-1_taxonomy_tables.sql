-- ============================================================================
-- 202606260001_S-EQTAX-1_taxonomy_tables.sql
--
-- S-EQTAX (equipment taxonomy) — turn the flat single-level
-- equipment_templates.category enum into a TWO-LEVEL, operator-managed
-- taxonomy: Category (top level) -> Sub-category (specific type).
--
-- Operator request: "Combo" was sitting at the SAME level as "Chassis" when it
-- is really a TYPE of chassis, so the chassis-only short-lease minimum skipped
-- it. Fix the model: Combo becomes a sub-category UNDER Chassis, and billing
-- rules attach at the Category level so every sub-type inherits them.
--
-- This file is SCHEMA + the deterministic top-level category SEED only. The
-- data-dependent backfill (derive one sub-category per existing template name,
-- populate the new FKs, map combo templates -> Chassis/Combo, reconcile the
-- enforce-minimum flag from the live setting) lives in the idempotent
-- companion script scripts/backfill_equipment_taxonomy.php (repo convention:
-- schema in db_migrations/, data backfills in scripts/).
--
-- DESIGN — the legacy `category` column is RETAINED as a denormalized "type
-- slug" mirror so the ~40 existing readers (reports, analytics, rate matching,
-- portal/admin display) keep working UNCHANGED. The new category_id /
-- subcategory_id FKs are the authoritative STRUCTURE used by rules (the billing
-- minimum reads equipment_categories.enforce_minimum_billing_days via
-- category_id). The backfill deliberately does NOT rewrite existing mirror
-- values, so Combo stays its own line in reports until the operator chooses to
-- move it (their decision: "keep Combo as its own for now, I'll change on
-- screen"). On template save the mirror is written as the sub-category slug
-- when one is chosen, else the category slug.
--
-- Step 0 is REQUIRED before any operator-defined slug can ever be stored in the
-- mirror: `category` is a fixed ENUM today and would 1265-abort (STRICT mode)
-- on a new slug. Relax it to VARCHAR.
--
-- charset/collation utf8mb4_unicode_ci to match the rest of the schema.
-- ============================================================================

-- 0. Relax the legacy mirror so it can carry any operator-managed slug.
ALTER TABLE `equipment_templates`
  MODIFY `category` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL;

-- 1. Top-level categories (operator-managed CRUD; rules attach here).
CREATE TABLE `equipment_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stable identifier; mirrored into equipment_templates.category. Never edited after create.',
  `label` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Operator-editable display name.',
  `enforce_minimum_billing_days` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'S-LEASE-MIN-DAYS-CATEGORY: when 1, leases on this category honour the short-lease minimum-billing-days floor. Sub-types inherit.',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eqcat_slug` (`slug`),
  KEY `idx_eqcat_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sub-categories (specific types within a category; operator-managed CRUD).
CREATE TABLE `equipment_subcategories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `slug` VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stable identifier; mirrored into equipment_templates.category when chosen. Unique within its category.',
  `label` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eqsubcat_cat_slug` (`category_id`, `slug`),
  KEY `idx_eqsubcat_cat` (`category_id`),
  CONSTRAINT `eqsubcat_ibfk_cat` FOREIGN KEY (`category_id`) REFERENCES `equipment_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Structured FKs on templates. Mirror column `category` is RETAINED.
--    Nullable so this ALTER is safe before the backfill populates them.
ALTER TABLE `equipment_templates`
  ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL AFTER `category`,
  ADD COLUMN `subcategory_id` INT UNSIGNED DEFAULT NULL AFTER `category_id`,
  ADD KEY `idx_et_category_id` (`category_id`),
  ADD KEY `idx_et_subcategory_id` (`subcategory_id`),
  ADD CONSTRAINT `et_ibfk_cat` FOREIGN KEY (`category_id`) REFERENCES `equipment_categories` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `et_ibfk_subcat` FOREIGN KEY (`subcategory_id`) REFERENCES `equipment_subcategories` (`id`) ON DELETE RESTRICT;

-- 4. Seed the deterministic top-level categories (the historical enum values,
--    EXCEPT combo, which becomes a Chassis sub-category in the backfill). slug =
--    the old enum value verbatim so the mirror contract holds for the 40 SAFE
--    readers. enforce flag defaults: chassis=1 (matches the shipped
--    'lease.minimum_billing_days_categories' default of 'chassis'); the backfill
--    reconciles this against the live setting. ON DUPLICATE only refreshes
--    label/sort_order so a re-apply NEVER clobbers operator-tuned flags.
INSERT INTO `equipment_categories` (`slug`, `label`, `enforce_minimum_billing_days`, `sort_order`) VALUES
  ('chassis',   'Chassis',    1, 10),
  ('dry_van',   'Dry Van',    0, 20),
  ('reefer',    'Reefer',     0, 30),
  ('container', 'Container',  0, 40),
  ('flatbed',   'Flatbed',    0, 50),
  ('step_deck', 'Step Deck',  0, 60),
  ('lowboy',    'Lowboy',     0, 70),
  ('tanker',    'Tanker',     0, 80),
  ('dump',      'Dump',       0, 90),
  ('other',     'Other',      0, 100)
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `sort_order` = VALUES(`sort_order`);
