-- ============================================================
-- 202605121925_S-MILEAGE-3_close_adjustments_drop.sql
--
-- S-MILEAGE-3 C5 — lease_close_adjustments table retirement
-- (D-G locked wholesale DROP + backup).
--
-- The S-LEASE-MILEAGE Model C close-adjustment surface (manager
-- review of excess/underage at lease close with credit_note /
-- final_invoice_adjustment / waived / no_adjustment decisions)
-- retired as part of the Model B refactor. Model B's drawdown
-- lifecycle handles all per-invoice mileage events and the new
-- precharge refund picker at close (D-A through D-N) handles
-- residual-balance disposition.
--
-- K-15 pre-retirement scan (verified 2026-05-13):
--   SELECT COUNT(*) FROM lease_close_adjustments — 0 rows
--   Repo-wide grep — only retirement-marker comments + close.php
--   (deletion in same commit) + scripts/archive/ (archived).
--   Backup table snapshot will capture 0 rows; the empty backup
--   is the discipline marker per D107 capture-all + K-15 scan.
--
-- Migration steps:
--   1. CREATE backup table lease_close_adjustments_backup_S_MILEAGE_3
--      with the same shape as the source (forensic-only snapshot).
--   2. INSERT all rows from source into backup (idempotent — re-run
--      against an empty source is a no-op; the existence check on
--      the backup table prevents duplicate inserts on second-run).
--   3. DROP TABLE lease_close_adjustments via ff_drop_table_if_present
--      (naturally idempotent).
--   4. Mirror: master file lease_close_adjustments CREATE TABLE
--      block deletion in same commit per D87 + D127.
--
-- Companion code retirements (same commit):
--   - api/v1/leases/close.php: priorExcessKm SELECT + subtraction
--     blocks + close_adjustment processing block + audit_log
--     workaround paths + response payload prior_excess_km field
--   - app/admin/leases/show.php: closeReconciliation Alpine getter
--     + Mileage Reconciliation panel + adjustment_* closeForm
--     state + closeLease() close_adjustment payload assembly
--
-- Author:   S-MILEAGE-3
-- Date:     2026-05-13 (UTC: 2026-05-12 19:25)
-- Spec:     FLEETFORGE_CURRENT_SESSIONS.md S-MILEAGE-3 spec block
--           D-G (locked via SPEC-WRITE caac041 + SPEC-LOCK 3102e39).
-- Decisions: D-G (wholesale DROP + backup + close.php deletion);
--           see also D-F (priorExcessKm retirement) and the K-15
--           data-path coverage discipline.
-- ============================================================

-- ── Helper: drop table only if present ──────────────────────
DROP PROCEDURE IF EXISTS ff_drop_table_if_present;
DELIMITER //
CREATE PROCEDURE ff_drop_table_if_present(
    IN tbl VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = tbl
    ) THEN
        SET @sql = CONCAT('DROP TABLE ', tbl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── Backup table (D107 capture-all snapshot) ────────────────
-- Same shape as the source CREATE TABLE block from master file.
-- IF NOT EXISTS guard keeps the migration idempotent.
CREATE TABLE IF NOT EXISTS `lease_close_adjustments_backup_S_MILEAGE_3` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `lease_id` int unsigned NOT NULL,
  `adjustment_type` enum('excess_charge','underage_credit','no_adjustment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `calculated_distance_km` decimal(10,2) NOT NULL,
  `calculated_amount` decimal(12,2) NOT NULL,
  `final_amount` decimal(12,2) NOT NULL,
  `decision` enum('credit_note','final_invoice_adjustment','waived','no_adjustment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `related_invoice_id` int unsigned DEFAULT NULL,
  `related_credit_note_id` int unsigned DEFAULT NULL,
  `approved_by_user_id` int unsigned NOT NULL,
  `approved_at` datetime NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `snapshot_taken_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='S-MILEAGE-3 D-G: forensic snapshot of lease_close_adjustments pre-DROP. K-15 scan confirmed 0 rows.';

-- ── Snapshot existing rows (idempotent: only insert if source still exists) ──
-- Conditional INSERT — runs the SELECT only when source table exists.
-- Guard against double-insert on re-run via empty backup check.
DROP PROCEDURE IF EXISTS ff_snapshot_close_adjustments;
DELIMITER //
CREATE PROCEDURE ff_snapshot_close_adjustments()
BEGIN
    DECLARE source_exists INT DEFAULT 0;
    DECLARE backup_empty  INT DEFAULT 0;

    SELECT COUNT(*) INTO source_exists
      FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'lease_close_adjustments';

    SELECT COUNT(*) INTO backup_empty
      FROM lease_close_adjustments_backup_S_MILEAGE_3;

    IF source_exists = 1 AND backup_empty = 0 THEN
        INSERT INTO lease_close_adjustments_backup_S_MILEAGE_3
            (id, lease_id, adjustment_type, calculated_distance_km, calculated_amount,
             final_amount, decision, related_invoice_id, related_credit_note_id,
             approved_by_user_id, approved_at, notes, created_at)
        SELECT id, lease_id, adjustment_type, calculated_distance_km, calculated_amount,
               final_amount, decision, related_invoice_id, related_credit_note_id,
               approved_by_user_id, approved_at, notes, created_at
          FROM lease_close_adjustments;
    END IF;
END //
DELIMITER ;
CALL ff_snapshot_close_adjustments();

-- ── DROP the source table ────────────────────────────────────
CALL ff_drop_table_if_present('lease_close_adjustments');

-- ── Cleanup helper procedures ────────────────────────────────
DROP PROCEDURE IF EXISTS ff_drop_table_if_present;
DROP PROCEDURE IF EXISTS ff_snapshot_close_adjustments;
