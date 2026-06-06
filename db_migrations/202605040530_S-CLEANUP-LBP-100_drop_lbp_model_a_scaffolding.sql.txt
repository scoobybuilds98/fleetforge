-- ============================================================
-- 202605040530_S-CLEANUP-LBP-100_drop_lbp_model_a_scaffolding.sql
--
-- S-CLEANUP-LBP-100 — drop dead Model A scaffolding from
-- `lease_billing_periods`. Closes KNOWN ISSUE #100.
--
-- Drops two columns:
--   • lease_billing_periods.has_mileage_precharge   TINYINT(1) NOT NULL DEFAULT 0
--   • lease_billing_periods.mileage_precharge_amount DECIMAL(12,2) NULL
--
-- Both are vestigial Model A scaffolding parallel to the
-- leases.mileage_precharge_amount + leases.mileage_precharge_invoiced
-- columns dropped in S-MILEAGE-1 (commit e3df1be, 2026-05-04).
--
-- Audit (S-MILEAGE-MODEL-AUDIT, /tmp/fleetforge_mileage_model_audit.md
-- Q4): "Never written by production code." Re-verified pre-session
-- with grep — zero PHP/SQL production references; only documentation
-- + historical migration files mention them.
--
-- BACKUP TABLE OMITTED — D-A trivial-data path:
--   Pre-session SELECT confirmed all 58 rows in lease_billing_periods
--   had has_mileage_precharge=0 AND mileage_precharge_amount IS NULL.
--   No meaningful data to back up. Comment retained per S-MILEAGE-1
--   precedent so the omission is explicit, not accidental.
--
--   If a future cleanup ever needs to roll this back, regenerate the
--   columns with the same shape (TINYINT(1) NOT NULL DEFAULT 0 +
--   DECIMAL(12,2) NULL) — the data being preserved is structural,
--   not value-bearing.
--
-- The sibling reconciliation pair (has_mileage_reconciliation +
-- mileage_reconciliation_amount) STAYS — out of scope for this
-- session. Same audit Q4 noted both pairs are dead, but the brief
-- explicitly scopes only the precharge pair.
--
-- Author:   S-CLEANUP-LBP-100
-- Date:     2026-05-04 (UTC)
-- Spec:     KNOWN ISSUE #100 (carry-forward from S-MILEAGE-1 D-B.2)
-- Decisions confirmed in D-A through D-E (this session):
--   D-A  trivial-data path → no backup table
--   D-B  master file gets 2 surgical removals (no reordering)
--   D-C  parity smoke test gate before commit
--   D-E  zero constraints/indexes on dropped cols → no precursor DROPs
--
-- Idempotency: DROPs guarded by INFORMATION_SCHEMA existence checks
-- via the ff_drop_column_if_present helper procedure (same pattern
-- as S-MILEAGE-1's migration). Re-running this migration is a no-op
-- once columns are already dropped.
-- ============================================================

-- ── Helper: drop column only if present ─────────────────────
-- Same pattern as the S-MILEAGE-1 migration; defined locally so
-- this file is self-contained (and the prior session's helper
-- was dropped at end-of-file per its own cleanup).
DROP PROCEDURE IF EXISTS ff_drop_column_if_present;
DELIMITER //
CREATE PROCEDURE ff_drop_column_if_present(
    IN tbl  VARCHAR(64),
    IN col  VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND COLUMN_NAME  = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' DROP COLUMN ', col);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── 1. Drop the dead Model A precharge scaffolding ──────────
CALL ff_drop_column_if_present('lease_billing_periods', 'has_mileage_precharge');
CALL ff_drop_column_if_present('lease_billing_periods', 'mileage_precharge_amount');

-- ── 2. Cleanup helper procedure ─────────────────────────────
DROP PROCEDURE IF EXISTS ff_drop_column_if_present;
