-- ============================================================
-- 202605120907_S-MILEAGE-2B_model_c_retirement.sql
--
-- S-MILEAGE-2B C4 — Model C plumbing retirement.
--
-- Drops 7 Model-C-era columns from the `invoices` table after the
-- precondition script (scripts/model_c_retirement_2026_05_12.php)
-- has run, which:
--   (1) created the backup table invoices_model_c_backup_S_MILEAGE_2B
--   (2) snapshotted all rows with non-null Model C state
--   (3) voided INV-2026-00087 (the lone pending-review draft)
--   (4) regenerated INV-2026-00094 under post-C3 Model B Lite engine
--
-- Columns dropped:
--   excess_distance_km
--   excess_charge_amount
--   mileage_review_status
--   mileage_override_amount
--   mileage_reviewed_at
--   mileage_reviewed_by_user_id
--   mileage_review_notes
--
-- D-G locked (i) wholesale DROP + backup. D107 capture-all snapshot
-- discipline applied via the precondition script. D129 audit-scope
-- vs general-usage discipline: repo-wide grep before script run
-- confirmed production references are localized to the deleted
-- review_mileage.php endpoint (deletes in C5), the Mileage Review
-- card in show.php (converts in C6 per D-L), and InvoiceGenerator's
-- legacy Model C calc block (deleted in C3 commit a24cb49).
--
-- Idempotency: ff_drop_column_if_present helper guards each DROP via
-- INFORMATION_SCHEMA existence check. Re-running this migration is
-- a no-op once columns are gone.
--
-- Author:   S-MILEAGE-2B
-- Date:     2026-05-12 (UTC)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-2B spec block
-- Decisions: D-G (i) wholesale DROP; D-H (refined post-C4 pre-work
--           scan — periodExcess DELETED; monthlyAllowance RETAINED
--           pending portal refactor in S-PORTAL-MILEAGE-MODEL-B).
-- ============================================================

-- ── Helper: drop column only if present ─────────────────────
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

-- ── Helper: drop index only if present ─────────────────────
DROP PROCEDURE IF EXISTS ff_drop_index_if_present;
DELIMITER //
CREATE PROCEDURE ff_drop_index_if_present(
    IN tbl  VARCHAR(64),
    IN idx  VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
          AND INDEX_NAME   = idx
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' DROP INDEX ', idx);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── Drop the 7 Model C columns ──────────────────────────────
CALL ff_drop_column_if_present('invoices', 'excess_distance_km');
CALL ff_drop_column_if_present('invoices', 'excess_charge_amount');
CALL ff_drop_column_if_present('invoices', 'mileage_review_status');
CALL ff_drop_column_if_present('invoices', 'mileage_override_amount');
CALL ff_drop_column_if_present('invoices', 'mileage_reviewed_at');
CALL ff_drop_column_if_present('invoices', 'mileage_reviewed_by_user_id');
CALL ff_drop_column_if_present('invoices', 'mileage_review_notes');

-- ── Drop the residual idx_mileage_review index ──────────────
-- The original index was `idx_mileage_review (mileage_review_status, lease_id)`.
-- MySQL preserves multi-column indexes after a column DROP by removing
-- the dropped column from the index — leaving `idx_mileage_review (lease_id)`,
-- which is redundant (lease_id is already indexed via `idx_lease`).
CALL ff_drop_index_if_present('invoices', 'idx_mileage_review');

-- ── Cleanup helper procedures ───────────────────────────────
DROP PROCEDURE IF EXISTS ff_drop_column_if_present;
DROP PROCEDURE IF EXISTS ff_drop_index_if_present;
