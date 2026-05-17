-- ============================================================
-- 202605170100_S-BILLING-HOLISTIC-ENGINE_invoice_audit_columns.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Add three AUDIT-ONLY columns to the
-- `invoices` table that record the holistic engine's reasoning for
-- each invoice it generates. These columns DO NOT participate in
-- financial totals — subtotal / total_amount continue to be the
-- source of truth for what the customer owes. The new columns are
-- a forensic trail so a future auditor (or future-us) can replay
-- the math without rebuilding it from line items.
--
--   total_days_at_period_end    INT UNSIGNED NULL
--     The lease's inclusive day count from start_date through this
--     invoice's billing_period_end. Determines which tier the
--     engine applied (1-5 daily, 6-7 weekly_flat, 8-29 weekly_math,
--     30+ monthly_math).
--
--   cumulative_correct_amount   DECIMAL(12,2) NULL
--     What the lease's total base_rental SHOULD be at this period
--     end, per the holistic engine. This is the "running correct"
--     total — independent of how many invoices got there.
--
--   already_billed_before_this  DECIMAL(12,2) NULL
--     Sum of base_rental from all prior non-void invoices on this
--     lease at the moment this invoice was generated. The engine's
--     delta = cumulative_correct_amount - already_billed_before_this.
--
-- NULLability: all three are NULL because (a) period_independent
-- engine leases never populate them, (b) historical invoices
-- predate the engine entirely. The holistic engine fills all three
-- on every invoice it touches.
--
-- D128 trivial-data backup-skip: pre-migration NO data in any of
-- these new columns by definition (they don't exist yet). No
-- backup table needed.
--
-- Idempotency: ADDs guarded by ff_add_column_if_missing helper
-- (same pattern as S-MILEAGE-1). Re-running this migration is a
-- no-op once columns exist.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql per D87 + D127.
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §29.1
-- Decisions: D126 (additive schema change, append columns at end)
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

-- ── invoices — add three audit columns ──────────────────────
CALL ff_add_column_if_missing('invoices', 'total_days_at_period_end',
    "total_days_at_period_end INT UNSIGNED NULL COMMENT 'S-BILLING-HOLISTIC-ENGINE: lease total days from start_date through this invoice billing_period_end (inclusive). NULL for period_independent engine leases and pre-engine invoices.'");

CALL ff_add_column_if_missing('invoices', 'cumulative_correct_amount',
    "cumulative_correct_amount DECIMAL(12,2) NULL COMMENT 'S-BILLING-HOLISTIC-ENGINE: what the lease total base_rental SHOULD be at this period_end per the holistic engine. NULL for period_independent.'");

CALL ff_add_column_if_missing('invoices', 'already_billed_before_this',
    "already_billed_before_this DECIMAL(12,2) NULL COMMENT 'S-BILLING-HOLISTIC-ENGINE: sum of base_rental from prior non-void invoices for this lease at the moment of generation. delta = cumulative_correct_amount - already_billed_before_this.'");

-- ── Cleanup helper procedure ────────────────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
