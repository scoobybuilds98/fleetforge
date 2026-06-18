-- ============================================================
-- S-LEASE-MIN-DAYS: minimum N-day charge for short leases
-- ============================================================
-- When a lease's TOTAL billable duration is shorter than N days, the billing
-- engines bill a FLAT N x daily_rate (overriding the normal daily/weekly/monthly
-- tier ladder) instead of the actual short duration. Default N = 3 (mainly chassis).
--
-- THREE CONFIG LAYERS (highest priority first):
--   1. rate_card_items.minimum_days        per equipment category/template; NULL = none
--   2. leases.minimum_billing_days          frozen on the lease at creation; overridable
--   3. settings 'lease.minimum_billing_days' global default '3'
--
-- This migration adds the two schema columns + seeds the global default setting +
-- seeds a minimum_days=3 floor on every chassis rate-card item. The floor SEMANTICS
-- live in the two billing engines (period-independent + holistic); this file is
-- schema + config only.
--
-- Idempotent:
--   • Both ADD COLUMN steps are INFORMATION_SCHEMA-guarded (skip if present),
--     matching the repo's established PREPARE/EXECUTE pattern (S-GPS-RATE-ITEM).
--   • The settings seed uses INSERT ... ON DUPLICATE KEY UPDATE that touches only
--     label/description (NEVER `value`), so re-applying never clobbers an
--     operator-tuned floor.
--   • The chassis seed only writes rows whose minimum_days is still NULL, so it is
--     a no-op on re-apply and never overwrites an operator-set per-item value.
--
-- NOTE: rate_card_items has NO `deleted_at` column (verified against
-- FLEETFORGE_DATABASE_MASTER.sql), so the chassis seed does NOT reference one.
--
-- Session: S-LEASE-MIN-DAYS
-- Date:    2026-06-19
-- ============================================================

-- Step 1: ADD rate_card_items.minimum_days (per-equipment floor; NULL = no minimum)
-- S-LEASE-MIN-DAYS: TINYINT UNSIGNED so the floor (typ. 3) costs one byte; NULL is
-- the "no minimum for this equipment type" sentinel — config layer 1, highest priority.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'rate_card_items'
      AND COLUMN_NAME  = 'minimum_days'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE rate_card_items
       ADD COLUMN `minimum_days` tinyint unsigned DEFAULT NULL
           COMMENT 'S-LEASE-MIN-DAYS: short-lease floor for this equipment row. When a lease bills fewer than this many days, charge a flat minimum_days x daily_rate. NULL = no minimum. Config layer 1 (overrides lease + global default).'
       AFTER `gps_price`",
    "SELECT 'rate_card_items.minimum_days already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Step 2: ADD leases.minimum_billing_days (per-lease frozen floor; NULL = no minimum)
-- S-LEASE-MIN-DAYS: frozen onto the lease at creation from the rate card / global
-- default; operator-overridable on the lease form. Config layer 2.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leases'
      AND COLUMN_NAME  = 'minimum_billing_days'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE leases
       ADD COLUMN `minimum_billing_days` tinyint unsigned DEFAULT NULL
           COMMENT 'S-LEASE-MIN-DAYS: short-lease floor frozen on this lease at creation (from rate card minimum_days or the lease.minimum_billing_days global default), operator-overridable on the lease form. When total billable days < this, bill a flat minimum_billing_days x daily_rate. NULL/0/1 = no minimum. Config layer 2.'
       AFTER `mileage_tracking_mode`",
    "SELECT 'leases.minimum_billing_days already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

-- Step 3: Seed the global default setting (config layer 3).
-- value_type='integer' / group_name='billing' (NOT type/group — Trap 71). is_public=0.
-- ON DUPLICATE KEY UPDATE touches only label/description so a re-apply NEVER clobbers
-- an operator-tuned `value`.
INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
VALUES
  ('lease.minimum_billing_days', '3', 'integer', 'billing', 0, 0,
   'Minimum Billing Days (short-lease floor)',
   'When a lease is returned in fewer than this many days, bill a flat N x daily rate; overridable per equipment via rate cards and per lease on the lease form; 0 or 1 disables.')
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);

-- Step 4: Chassis seed — apply a 3-day floor to every chassis rate-card item.
-- S-LEASE-MIN-DAYS: chassis are the primary short-lease use case. Only fills NULL
-- rows so the seed is idempotent and never overwrites an operator-set per-item value.
-- rate_card_items has no deleted_at column, so there is no soft-delete predicate here.
UPDATE rate_card_items
   SET minimum_days = 3
 WHERE equipment_type = 'chassis'
   AND minimum_days IS NULL;
