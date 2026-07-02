-- S-MILEAGE-EST-DAILY: estimated daily mileage billing + running true-up.
--
-- New model (replaces the per-period "bill actual usage" cadence): a lease
-- carries an ESTIMATED MILEAGE PER DAY (e.g. 40 mi/day). Each recurring invoice
-- bills an estimate = (billable days in period x per-day x mileage_rate), and a
-- running cumulative true-up reconciles that estimate against ACTUAL distance
-- (odometer/Samsara) whenever a reading is available — posting a mileage_adjustment
-- (charge) or mileage_credit (credit) for the delta. The $/mile-$/km rate still
-- comes from the customer rate card and is snapshotted onto leases.mileage_rate_km
-- (unchanged). The per-day value is entered on the lease.
--
-- This migration:
--   1. adds 'mileage_estimate' to invoice_line_items.item_type (the recurring
--      estimate line the engine now emits — distinct from actual 'mileage_usage').
--   2. adds leases.estimated_mileage_per_day (+ _km / _miles canonical mirrors),
--      following the existing estimated_mileage dual-unit pattern (S-LEASE-UNITS).
--
-- The pre-existing estimated_mileage (total allowance) + precharge columns are
-- LEFT UNTOUCHED — the new per-day field is additive (operator decision
-- S-MILEAGE-EST-DAILY). All steps INFORMATION_SCHEMA-guarded → idempotent.

SET @schema = DATABASE();

-- ── 1. invoice_line_items.item_type ENUM += 'mileage_estimate' ──────────────
-- ENUM clamp trap (project_rate_method_enum_clamp_trap): the new line type MUST
-- be a member of the enum at every write site. Guard on the current COLUMN_TYPE
-- so the MODIFY runs once. Appended at the END so existing ordinal positions of
-- every other member are preserved.
SET @has_mileage_estimate = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'invoice_line_items'
      AND COLUMN_NAME = 'item_type'
      AND COLUMN_TYPE LIKE '%''mileage_estimate''%'
);
SET @stmt = IF(
    @has_mileage_estimate = 0,
    "ALTER TABLE invoice_line_items MODIFY COLUMN `item_type` enum('base_rental','mileage_precharge','mileage_adjustment','mileage_credit','insurance','warranty','late_fee','early_return_credit','manual_adjustment','damage','discount','account_credit_applied','other','gps','mileage_usage','mileage_drawdown_credit','base_rental_reconciliation_credit','mileage','hourly_usage','cartage','sweep','wash','fuel','mileage_estimate') COLLATE utf8mb4_unicode_ci NOT NULL",
    "SELECT 'item_type already has mileage_estimate' AS info"
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. leases.estimated_mileage_per_day (primary, in the lease's mileage_unit) ──
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='estimated_mileage_per_day');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `estimated_mileage_per_day` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'S-MILEAGE-EST-DAILY: estimated distance driven per day in the lease mileage_unit. Billed as an estimate each period (days x per-day x mileage_rate) then trued-up against actual. 0 = no estimated-mileage billing.' AFTER `estimated_mileage_miles`",
    "SELECT 'leases.estimated_mileage_per_day exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2b. canonical km mirror (billing math is km-canonical) ──────────────────
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='estimated_mileage_per_day_km');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `estimated_mileage_per_day_km` decimal(12,4) DEFAULT NULL COMMENT 'S-MILEAGE-EST-DAILY: per-day estimate in km (canonical, drives the estimate math). Matches estimated_mileage_per_day when mileage_unit=km.' AFTER `estimated_mileage_per_day`",
    "SELECT 'leases.estimated_mileage_per_day_km exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2c. miles mirror (informational / unit-toggle parity S-MILEAGE-EST-UNIT-TOGGLE) ──
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=@schema AND TABLE_NAME='leases' AND COLUMN_NAME='estimated_mileage_per_day_miles');
SET @stmt = IF(@col=0,
    "ALTER TABLE leases ADD COLUMN `estimated_mileage_per_day_miles` decimal(12,4) DEFAULT NULL COMMENT 'S-MILEAGE-EST-DAILY: per-day estimate in miles (informational when mileage_unit=km). Matches estimated_mileage_per_day when mileage_unit=miles.' AFTER `estimated_mileage_per_day_km`",
    "SELECT 'leases.estimated_mileage_per_day_miles exists' AS info");
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
