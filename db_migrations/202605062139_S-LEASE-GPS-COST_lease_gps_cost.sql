-- ============================================================
-- 202605062139_S-LEASE-GPS-COST_lease_gps_cost.sql
--
-- S-LEASE-GPS-COST — adds per-lease GPS tracking add-on, parallel
-- to the insurance + warranty pattern but billed PER DAY rather
-- than flat-per-period.
--
-- Two columns on leases:
--   gps_opt_in tinyint(1) NOT NULL DEFAULT 1   (toggle ON by default)
--   gps_cost   decimal(10,2) NOT NULL DEFAULT 1.00  ($1/day default)
--
-- Plus one ENUM extension on invoice_line_items so the engine can
-- emit gps lines:
--   item_type ENUM(... existing values ..., 'gps')
--
-- Engine semantic (operator-confirmed Option (i)):
--   GPS line emitted when gps_opt_in=1 AND gps_cost > 0.
--   Existing leases backfill to opt_in=1 / cost=1.00 by schema
--   default — they auto-start billing $1/day GPS on their next
--   invoice cycle. Operator can edit a lease to zero it out.
--
-- Append discipline (D126): both new columns land at the END of
-- the leases CREATE TABLE block (after precharge_refund_settled_at)
-- in FLEETFORGE_DATABASE_MASTER.sql.
--
-- Author:   S-LEASE-GPS-COST
-- Date:     2026-05-07 (UTC)
-- Decisions confirmed in D-A through D-G this session:
--   D-A  schema columns + DEFAULT 1 / 1.00
--   D-D  per-day billing (amount = cost × days)
--   D-F  invoice_line_items.item_type ENUM extension
--   D-G  Option (i) — auto-bill existing leases at $1/day
--
-- Idempotency: ADDs guarded by ff_add_column_if_missing helper.
-- ENUM MODIFY is naturally idempotent (same target value-set).
-- ============================================================

-- ── Helper: add column only if missing ──────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE ff_add_column_if_missing(
    IN tbl  VARCHAR(64),
    IN col  VARCHAR(64),
    IN ddl  TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND COLUMN_NAME  = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── 1. leases.gps_opt_in ────────────────────────────────────
-- Default 1: GPS service is opted-in for every new lease unless
-- the operator explicitly toggles it off in the create form.
-- Differs from insurance_opt_in / warranty_opt_in (default 0)
-- because GPS is the standard service offering for this fleet.
CALL ff_add_column_if_missing('leases', 'gps_opt_in',
    "gps_opt_in TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'S-LEASE-GPS-COST: per-lease GPS tracking add-on toggle. Default 1 = ON for new leases (differs from insurance/warranty default 0).'");

-- ── 2. leases.gps_cost ──────────────────────────────────────
-- Per-day rate. Default 1.00 = $1/day. Engine multiplies by the
-- billing-window day count, so a 30-day month bills $30.00.
-- Existing rows backfill to 1.00 by schema default — Option (i)
-- per D-G: existing leases auto-start GPS billing on next cycle.
CALL ff_add_column_if_missing('leases', 'gps_cost',
    "gps_cost DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'S-LEASE-GPS-COST: GPS service rate per billing day. Default \$1.00. Engine multiplies by billing-window day count when gps_opt_in=1 AND gps_cost>0.'");

-- ── 2b. Backfill comments on existing columns ──────────────
-- ff_add_column_if_missing skips when the column already exists,
-- so a re-run on a DB that already has gps_opt_in / gps_cost
-- (added in an earlier dev iteration without comments) would
-- otherwise leave the COMMENT metadata blank and break parity
-- against FLEETFORGE_DATABASE_MASTER.sql (D127). MODIFY COLUMN
-- is naturally idempotent — re-running produces the same final
-- column definition.
ALTER TABLE leases
  MODIFY COLUMN gps_opt_in TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'S-LEASE-GPS-COST: per-lease GPS tracking add-on toggle. Default 1 = ON for new leases (differs from insurance/warranty default 0).';

ALTER TABLE leases
  MODIFY COLUMN gps_cost DECIMAL(10,2) NOT NULL DEFAULT 1.00
    COMMENT 'S-LEASE-GPS-COST: GPS service rate per billing day. Default $1.00. Engine multiplies by billing-window day count when gps_opt_in=1 AND gps_cost>0.';

-- ── 3. invoice_line_items.item_type — append 'gps' ──────────
-- ENUM extension is append-at-end so existing ENUM ordinals are
-- preserved (no row rewrite for legacy data). Naturally idempotent
-- because the target ENUM definition is the same on re-run.
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
    'gps'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;

-- ── Cleanup helper procedures ───────────────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
