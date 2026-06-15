-- ============================================================
-- S-LEASE-RENTAL-DAY-TIME — Add pickup/return time columns to
-- leases + return grace-period setting.
--
-- Three TIME NULL columns (DATE columns stay DATE — no DATETIME
-- migration):
--   leases.start_time           — pickup time of day
--   leases.end_time             — expected return time (optional)
--   leases.actual_return_time   — actual return time captured at close
--
-- One settings row:
--   lease.return_grace_minutes  — default 0; minutes after pickup
--     time during which a return is still "on-time" (no extra day).
--
-- Engine rule (computed at invoice time, not stored):
--   if (actual_return_time > start_time + grace) → extent_end = actual_return_date
--   else                                          → extent_end = actual_return_date − 1 day
--   min: start_date (at least 1 billed day).
--
-- Legacy/NULL: when start_time OR actual_return_time is NULL the
-- engine falls back to the existing inclusive date-only extent.
--
-- Idempotent: INFORMATION_SCHEMA guard on each column + ON DUPLICATE
-- KEY UPDATE for the settings row.
--
-- Date:    2026-06-16
-- Session: S-LEASE-RENTAL-DAY-TIME
-- ============================================================

-- 1. leases.start_time — pickup time of day
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leases'
      AND COLUMN_NAME  = 'start_time'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE leases
       ADD COLUMN `start_time` TIME NULL
       COMMENT 'Pickup time of day (S-LEASE-RENTAL-DAY-TIME). NULL = legacy lease, no time captured.'
       AFTER `start_date`",
    "SELECT 'leases.start_time already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 2. leases.end_time — expected return time
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leases'
      AND COLUMN_NAME  = 'end_time'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE leases
       ADD COLUMN `end_time` TIME NULL
       COMMENT 'Expected return time of day (S-LEASE-RENTAL-DAY-TIME). Optional; informs the operator when the unit should be back.'
       AFTER `end_date`",
    "SELECT 'leases.end_time already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 3. leases.actual_return_time — actual return time captured at close
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leases'
      AND COLUMN_NAME  = 'actual_return_time'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE leases
       ADD COLUMN `actual_return_time` TIME NULL
       COMMENT 'Actual return time of day at lease close (S-LEASE-RENTAL-DAY-TIME). Compared with start_time + return_grace_minutes to compute effective_billable_end_date.'
       AFTER `actual_return_date`",
    "SELECT 'leases.actual_return_time already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- 4. settings: lease.return_grace_minutes
INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`)
VALUES
  ('lease.return_grace_minutes', '0', 'string', 'lease',
   'Return grace period (minutes)',
   'Minutes after the pickup time during which a return is treated as on-time and the final day is NOT billed. 0 = no grace (exact pickup time is the cut-off). Example: 60 means a noon pickup + 1-hour grace → returns by 1:00 pm on the final day are not charged for that day.')
ON DUPLICATE KEY UPDATE
  `group_name`  = VALUES(`group_name`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `value_type`  = VALUES(`value_type`);

COMMIT;
