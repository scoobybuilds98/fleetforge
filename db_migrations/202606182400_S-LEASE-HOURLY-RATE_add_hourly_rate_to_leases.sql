-- S-LEASE-HOURLY-RATE: add hourly_rate to leases so reefer/hourly billing
-- rates captured from the rate card are persisted on the lease record,
-- displayed in the Rates card, and amendable via the rate-amendment modal.
-- DECIMAL(10,4): mirrors rate_card_items.hourly_rate precision.
-- NULL default: existing leases default to no hourly rate (not billed).
-- INFORMATION_SCHEMA guard: idempotent if re-run.

SET @schema = DATABASE();

SET @col_hourly_rate = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema
      AND TABLE_NAME = 'leases'
      AND COLUMN_NAME = 'hourly_rate'
);

SET @stmt = IF(
    @col_hourly_rate = 0,
    'ALTER TABLE leases ADD COLUMN `hourly_rate` decimal(10,4) DEFAULT NULL COMMENT ''S-LEASE-HOURLY-RATE: hourly reefer/etc rate captured from rate card at lease creation. NULL = no hourly billing. Amendable via amend_rate.php.'' AFTER `gps_cost`',
    'SELECT ''hourly_rate already exists on leases'' AS info'
);
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
