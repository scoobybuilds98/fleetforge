-- S-LEASE-HOURLY-BILLING: activate engine/reefer-hours billing (manual entry).
-- leases.hourly_rate was captured everywhere but consumed by zero billing code.
-- This migration adds the manual hours-capture columns + the invoice snapshot
-- columns, and extends invoice_line_items.item_type with 'hourly_usage' so the
-- engine can emit hours × hourly_rate as a line item. Source is manual only
-- (no Samsara hours endpoint exists); hourly_rate>0 is the activation gate.
-- All steps INFORMATION_SCHEMA-guarded → idempotent on re-run.

SET @schema = DATABASE();

-- ── 1. invoice_line_items.item_type ENUM += 'hourly_usage' ──────────────
-- ENUM clamp trap (project_rate_method_enum_clamp_trap): the new line type
-- MUST be a member of the enum at every write site. Guard on the current
-- COLUMN_TYPE so the MODIFY only runs once.
SET @has_hourly_usage = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'item_type'
      AND COLUMN_TYPE LIKE '%''hourly_usage''%'
);
SET @stmt = IF(
    @has_hourly_usage = 0,
    "ALTER TABLE invoice_line_items MODIFY COLUMN `item_type` enum('base_rental','mileage_precharge','mileage_adjustment','mileage_credit','insurance','warranty','late_fee','early_return_credit','manual_adjustment','damage','discount','account_credit_applied','other','gps','mileage_usage','mileage_drawdown_credit','base_rental_reconciliation_credit','mileage','hourly_usage') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'item_type already has hourly_usage' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. leases: manual engine-hours capture (start at lease, end at close) ──
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='engine_hours_at_start');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `engine_hours_at_start` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-HOURLY-BILLING: engine/reefer hours reading at lease start (manual). Billing baseline when hourly_rate>0.' AFTER `hourly_rate`",
    "SELECT 'leases.engine_hours_at_start exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='engine_hours_at_end');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `engine_hours_at_end` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-HOURLY-BILLING: engine/reefer hours reading at lease close (manual). Final hours = end - start.' AFTER `engine_hours_at_start`",
    "SELECT 'leases.engine_hours_at_end exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. invoices: per-period engine-hours snapshot ──────────────────────
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='invoices' AND COLUMN_NAME='engine_hours_at_period_start');
SET @stmt = IF(@col=0,
    "ALTER TABLE invoices ADD COLUMN `engine_hours_at_period_start` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-HOURLY-BILLING: engine hours at start of this invoice period (manual)' AFTER `odometer_fetched_at`",
    "SELECT 'invoices.engine_hours_at_period_start exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='invoices' AND COLUMN_NAME='engine_hours_at_period_end');
SET @stmt = IF(@col=0,
    "ALTER TABLE invoices ADD COLUMN `engine_hours_at_period_end` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-HOURLY-BILLING: engine hours at end of this invoice period (manual)' AFTER `engine_hours_at_period_start`",
    "SELECT 'invoices.engine_hours_at_period_end exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='invoices' AND COLUMN_NAME='period_engine_hours');
SET @stmt = IF(@col=0,
    "ALTER TABLE invoices ADD COLUMN `period_engine_hours` decimal(10,2) DEFAULT NULL COMMENT 'S-LEASE-HOURLY-BILLING: engine hours billed in this period (end - start, clamped >=0)' AFTER `engine_hours_at_period_end`",
    "SELECT 'invoices.period_engine_hours exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
