-- ============================================================
-- 202605040717_S-CLEANUP-LBP-COLS-2_drop_lbp_reconciliation_scaffolding.sql
--
-- S-CLEANUP-LBP-COLS-2 — drop the sibling reconciliation pair from
-- `lease_billing_periods`. Closes KNOWN ISSUE #101.
--
-- Drops two columns:
--   • lease_billing_periods.has_mileage_reconciliation   TINYINT(1) NOT NULL DEFAULT 0
--   • lease_billing_periods.mileage_reconciliation_amount DECIMAL(12,2) NULL
--
-- Both are vestigial Model A scaffolding parallel to:
--   • leases.mileage_precharge_amount + leases.mileage_precharge_invoiced
--     (dropped in S-MILEAGE-1, commit e3df1be, 2026-05-04)
--   • lease_billing_periods.has_mileage_precharge + mileage_precharge_amount
--     (dropped in S-CLEANUP-LBP-100, commit c2cb016, 2026-05-04)
--
-- Audit (S-MILEAGE-MODEL-AUDIT, /tmp/fleetforge_mileage_model_audit.md
-- Q4): "Never written by production code." S-CLEANUP-LBP-TABLE
-- (commit cf63338, 2026-05-04) confirmed the table itself IS in
-- active use (`lib/Billing/InvoiceGenerator.php:656` writes one row
-- per invoice; `invoices.billing_period_id` carries an active FK)
-- but ruled the reconciliation columns specifically dead via
-- repo-wide D129-applied grep — same status as the precharge pair
-- before S-CLEANUP-LBP-100 dropped them.
--
-- Re-verified pre-session per D129 (audit-scope vs general-usage
-- gap): repo-wide grep with NO scope filter found 9 hits across
-- 3 files, all in SCHEMA / HISTORY categories. Zero PHP production
-- code, zero test fixtures, zero scripts.
--
-- BACKUP TABLE OMITTED — D128 trivial-data path:
--   Pre-session SELECT confirmed all 58 rows in lease_billing_periods
--   had has_mileage_reconciliation=0 AND mileage_reconciliation_amount
--   IS NULL. No meaningful data to back up. Same pattern as
--   S-CLEANUP-LBP-100. Comment retained per D128 so the omission
--   is explicit, not accidental.
--
-- This is the FINAL closure of the Model A precharge/reconciliation
-- scaffolding cleanup arc:
--   S-MILEAGE-FIX-0 → S-MILEAGE-1 → S-CLEANUP-LBP-100 →
--   S-CLEANUP-LBP-TABLE → S-CLEANUP-LBP-COLS-2.
-- After this migration, lease_billing_periods retains only
-- structural billing-period columns; Model A scaffolding is
-- fully exorcised across the schema.
--
-- Author:   S-CLEANUP-LBP-COLS-2
-- Date:     2026-05-04 (UTC)
-- Spec:     KNOWN ISSUE #101 (carry-forward from S-CLEANUP-LBP-TABLE)
-- Decisions confirmed in D-A through D-D (this session):
--   D-A  trivial-data path → no backup table
--   D-B  master file gets 2 surgical removals (no reordering)
--   D-C  parity smoke test gate before commit
--   D-D  no new D-numbers — clean reuse of D126/D127/D128/D129
--
-- Idempotency: DROPs guarded by INFORMATION_SCHEMA existence checks
-- via the ff_drop_column_if_present helper procedure (same pattern
-- as S-MILEAGE-1 + S-CLEANUP-LBP-100). Re-running this migration
-- is a no-op once columns are already dropped.
-- ============================================================

-- ── Helper: drop column only if present ─────────────────────
-- Same pattern as S-CLEANUP-LBP-100; defined locally so this file
-- is self-contained. The prior session's helper was dropped at end
-- of file per its own cleanup, so we redefine it here.
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

-- ── 1. Drop the dead Model A reconciliation scaffolding ─────
CALL ff_drop_column_if_present('lease_billing_periods', 'has_mileage_reconciliation');
CALL ff_drop_column_if_present('lease_billing_periods', 'mileage_reconciliation_amount');

-- ── 2. Cleanup helper procedure ─────────────────────────────
DROP PROCEDURE IF EXISTS ff_drop_column_if_present;
