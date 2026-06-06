-- ============================================================
-- 202605170103_S-BILLING-HOLISTIC-ENGINE_leases_engine_version.sql
--
-- S-BILLING-HOLISTIC-ENGINE — Add `engine_version` column to
-- the `leases` table to dispatch InvoiceGenerator between the
-- two billing engines:
--
--   'period_independent' — OLD: ProRateCalculator (THE LAW
--                          applied per period in isolation).
--                          Locked at lease creation; never
--                          changes mid-lease.
--   'holistic'           — NEW: HolisticLeaseEngine (running
--                          reconciliation — cumulative_correct -
--                          already_billed = delta).
--
-- Default = 'holistic' so new leases automatically use the new
-- engine. The companion migration
-- 202605170104_S-BILLING-HOLISTIC-ENGINE_lock_existing_to_old.sql
-- runs immediately after this one and locks every currently
-- active/pending lease to 'period_independent' so existing leases
-- continue to bill on the engine they started with (mid-lease
-- engine switch would invalidate prior invoices' math).
--
-- NOT NULL with a DEFAULT means existing rows get 'holistic' as
-- the column lands, then the lock migration overwrites them.
--
-- ENUM ordering chosen so 'period_independent' is ordinal 1 (the
-- pre-existing path) and 'holistic' is ordinal 2 (the new path).
-- This matches the historical-then-current convention used in the
-- billing_type and rate_method_used columns.
--
-- D128 trivial-data backup-skip: pre-migration the column doesn't
-- exist. The 202605170104 lock migration immediately follows and
-- is itself observable in audit_log (records the lock action) if
-- needed for forensic reconstruction.
--
-- Stop condition #5 (spec): this column must not pre-exist.
-- Verified against live schema 2026-05-17 via
--   SHOW COLUMNS FROM leases LIKE 'engine%';
--   SHOW COLUMNS FROM leases LIKE 'pricing%';
-- Both return empty — clean to add.
--
-- Idempotency: ADD guarded by ff_add_column_if_missing helper
-- (same pattern as S-MILEAGE-1). Re-running this migration is a
-- no-op once the column exists.
--
-- Mirrored in FLEETFORGE_DATABASE_MASTER.sql per D87 + D127.
--
-- Author:    S-BILLING-HOLISTIC-ENGINE
-- Date:      2026-05-17
-- Spec:      FleetForge_Holistic_Billing_Engine_Spec.docx §31.2, §32
-- Decisions: D126 (additive schema change with safe default).
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

-- ── leases.engine_version — add ENUM column ─────────────────
-- New leases default to 'holistic'. Existing active/pending
-- leases get retro-set to 'period_independent' in the next
-- migration (202605170104) so they keep billing on the engine
-- they started with.
CALL ff_add_column_if_missing('leases', 'engine_version',
    "engine_version ENUM('period_independent', 'holistic') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'holistic' COMMENT 'S-BILLING-HOLISTIC-ENGINE: which billing engine bills this lease. Locked at lease creation; never modified mid-lease. period_independent = old ProRateCalculator (per-period THE LAW); holistic = new HolisticLeaseEngine (running reconciliation).'");

-- ── Cleanup helper procedure ────────────────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
