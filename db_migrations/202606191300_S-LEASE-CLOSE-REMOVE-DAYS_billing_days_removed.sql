-- ============================================================
-- S-LEASE-CLOSE-REMOVE-DAYS: remove N billable days at lease close
-- ============================================================
-- At close, an operator may enter "Remove ___ days". The value subtracts N
-- days off the END of the lease's BILLABLE period (the day is NOT counted in
-- the billing math) WITHOUT changing actual_return_date or any displayed date.
-- The existing S-LEASE-MIN-DAYS floor still wins — it is evaluated on the
-- REDUCED total, so the removal can never shave below the minimum.
--
-- This column stores the operator's input. The N-day subtraction SEMANTICS live
-- in three lockstep billing sites (InvoiceGenerator extent re-derivation,
-- _close_reconciliation lease_billable_extent(), close.php period_end); this
-- file is schema only.
--
-- Idempotent:
--   • The ADD COLUMN step is INFORMATION_SCHEMA-guarded (skip if present),
--     matching the repo's established PREPARE/EXECUTE pattern (S-LEASE-MIN-DAYS).
--
-- Session: S-LEASE-CLOSE-REMOVE-DAYS
-- Date:    2026-06-19
-- ============================================================

-- Step 1: ADD leases.billing_days_removed (operator input at close; default 0 = none)
-- S-LEASE-CLOSE-REMOVE-DAYS: TINYINT UNSIGNED NOT NULL DEFAULT 0 — the count of
-- billable days to shave off the END of the billing period at close. Clamped at
-- the billing sites so the extent never goes before start_date (min 1 billed day),
-- and the S-LEASE-MIN-DAYS floor is evaluated AFTER the removal. Placed AFTER
-- minimum_billing_days.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leases'
      AND COLUMN_NAME  = 'billing_days_removed'
);
SET @ddl = IF(@col_exists = 0,
    "ALTER TABLE leases
       ADD COLUMN `billing_days_removed` tinyint unsigned NOT NULL DEFAULT 0
           COMMENT 'S-LEASE-CLOSE-REMOVE-DAYS: operator-entered count of billable days to remove off the END of the billing period at close, without changing actual_return_date or any displayed date. Subtracted identically at the three billing sites (InvoiceGenerator extent, _close_reconciliation lease_billable_extent, close.php period_end), clamped so the extent never precedes start_date (min 1 billed day). The S-LEASE-MIN-DAYS floor is evaluated on the REDUCED total, so removal can never shave below the minimum. Removal note is internal-only (admin calc-breakdown + audit_log). 0 = none.'
       AFTER `minimum_billing_days`",
    "SELECT 'leases.billing_days_removed already exists — skip'"
);
PREPARE _stmt FROM @ddl; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
