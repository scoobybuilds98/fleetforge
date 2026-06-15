-- =============================================================
-- S-LINEITEM-ENUM-PROD-PARITY: widen invoice_line_items.item_type
-- =============================================================
--
-- Root cause of Sentry FLEETFORGE-H (MySQL 1265 "Data truncated for
-- column 'item_type'", lease close transaction rolled back):
--   api/v1/leases/close.php line 836 (legacy pre-odometer mileage-
--   overage path) emits item_type='mileage'; the line 535 fallback
--   also defaults to 'mileage'. Neither value was in the ENUM on
--   any of the three sources (dev DB / prod DB / DATABASE_MASTER.sql),
--   so any pre-odometer lease that closes with a mileage overage
--   fatals and rolls back the entire close transaction.
--
-- Three-way ENUM diff (pre-fix):
--   Master SQL  : missing 'mileage'
--   Dev DB      : missing 'mileage'
--   Prod DB     : missing 'mileage'
--   Code emits  : 'mileage' (close.php:836 + :535 fallback)
--   ALL other values emitted by InvoiceGenerator.php / close.php /
--   HolisticLeaseEngine.php are already present in all three sources.
--
-- ENUM widening is non-destructive: existing rows are unchanged,
-- no data rewrite occurs, no backup table is needed. Adding at the
-- end of the ENUM list qualifies as an instant DDL operation in
-- MySQL 8.0+ (metadata-only, no table rebuild).
--
-- Idempotent: the PROCEDURE checks INFORMATION_SCHEMA before
-- running the ALTER — safe to re-run if applied twice.
-- =============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS ff_enum_add_mileage $$

CREATE PROCEDURE ff_enum_add_mileage()
BEGIN
    -- 'mileage' as a standalone ENUM value appears in COLUMN_TYPE as
    -- the exact substring 'mileage' (with single quotes). None of the
    -- existing prefixed values (mileage_precharge, mileage_usage, etc.)
    -- contain that exact substring, so the LIKE check is unambiguous.
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'invoice_line_items'
           AND COLUMN_NAME  = 'item_type'
           AND COLUMN_TYPE  LIKE "%'mileage'%"
    ) THEN
        ALTER TABLE invoice_line_items
            MODIFY COLUMN item_type ENUM(
                'base_rental',
                'mileage_precharge',
                'mileage_adjustment',
                'mileage_credit',
                'insurance',
                'warranty',
                'late_fee',
                'early_return_credit',
                'manual_adjustment',
                'damage',
                'discount',
                'account_credit_applied',
                'other',
                'gps',
                'mileage_usage',
                'mileage_drawdown_credit',
                'base_rental_reconciliation_credit',
                'mileage'
            ) COLLATE utf8mb4_unicode_ci NOT NULL;
    END IF;
END $$

CALL ff_enum_add_mileage() $$

DROP PROCEDURE IF EXISTS ff_enum_add_mileage $$

DELIMITER ;
