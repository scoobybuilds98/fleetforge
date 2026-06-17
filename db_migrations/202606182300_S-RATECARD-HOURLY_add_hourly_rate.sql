-- ============================================================
-- S-RATECARD-HOURLY — Add hourly_rate as a first-class per-item
-- rate across the rate catalog.
--
-- Use case: reefer engine-hours billed at $/hr (generic column;
-- category-semantics applied at the application layer).
--
-- Four columns added:
--   rate_card_items.hourly_rate          DECIMAL(10,4) NULL
--   customer_equipment_rates.hourly_rate DECIMAL(10,4) NULL
--   customer_rate_history.hourly_rate    DECIMAL(10,4) NULL
--   equipment_templates.default_hourly_rate DECIMAL(10,4) NULL
--
-- Precision DECIMAL(10,4) matches equipment_templates.default_mileage_rate.
-- All columns NULLABLE; no backfill; non-reefer rows stay NULL.
--
-- Idempotent: INFORMATION_SCHEMA guard on each column.
--
-- Date:    2026-06-18
-- Session: S-RATECARD-HOURLY
-- ============================================================

-- 1. rate_card_items.hourly_rate
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_card_items'
      AND COLUMN_NAME  = 'hourly_rate'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE rate_card_items
       ADD COLUMN `hourly_rate` decimal(10,4) DEFAULT NULL
           COMMENT 'S-RATECARD-HOURLY: per-item hourly rate (e.g. reefer engine-hours $/hr). NULL = not applicable.'
       AFTER `mileage_unit`",
    "SELECT 'rate_card_items.hourly_rate already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 2. customer_equipment_rates.hourly_rate
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'customer_equipment_rates'
      AND COLUMN_NAME  = 'hourly_rate'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE customer_equipment_rates
       ADD COLUMN `hourly_rate` decimal(10,4) DEFAULT NULL
           COMMENT 'S-RATECARD-HOURLY: per-item hourly rate override for this customer.'
       AFTER `mileage_unit`",
    "SELECT 'customer_equipment_rates.hourly_rate already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 3. customer_rate_history.hourly_rate
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'customer_rate_history'
      AND COLUMN_NAME  = 'hourly_rate'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE customer_rate_history
       ADD COLUMN `hourly_rate` decimal(10,4) DEFAULT NULL
           COMMENT 'S-RATECARD-HOURLY: hourly rate snapshot at the time this history row was written.'
       AFTER `mileage_unit`",
    "SELECT 'customer_rate_history.hourly_rate already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 4. equipment_templates.default_hourly_rate
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'equipment_templates'
      AND COLUMN_NAME  = 'default_hourly_rate'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE equipment_templates
       ADD COLUMN `default_hourly_rate` decimal(10,4) DEFAULT NULL
           COMMENT 'S-RATECARD-HOURLY: default hourly rate for new leases on this template. NULL = not applicable.'
       AFTER `default_mileage_rate`",
    "SELECT 'equipment_templates.default_hourly_rate already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

COMMIT;
