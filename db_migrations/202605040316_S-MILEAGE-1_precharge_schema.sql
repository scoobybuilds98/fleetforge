-- ============================================================
-- 202605040316_S-MILEAGE-1_precharge_schema.sql
--
-- S-MILEAGE-1 — Phase 1 of the Model B mileage refactor.
--
-- Adds the lease-level precharge schema (6 new columns + CHECK
-- constraint) and drops the two dead Model A scaffolding columns
-- (mileage_precharge_amount, mileage_precharge_invoiced).
--
-- The dead columns had a single seed-script writer
-- (scripts/seed_dataset.php) and two read sites that selected
-- but never used them (api/v1/leases/show.php, close.php) —
-- confirmed as zero-impact in S-MILEAGE-MODEL-AUDIT (2026-05-04).
-- This migration captures every row's prior state in
-- leases_precharge_backup_S_MILEAGE_1 BEFORE the DROP, as cheap
-- insurance against an unreviewed seed/fixture writer.
--
-- Author:   S-MILEAGE-1
-- Date:     2026-05-04 (UTC)
-- Spec:     S-MILEAGE-MODEL-AUDIT (/tmp/fleetforge_mileage_model_audit.md)
--           FLEETFORGE_PROGRESS.md S-MILEAGE-FIX-0 entry
-- Decisions confirmed in D-A through D-J (this session):
--   D-A  6 new columns; precharge_balance NULL until activation
--   D-B  drop the 2 Model A columns + capture full backup table
--   D-C  no new indexes (low cardinality / per-lease lookups)
--   D-D  CHECK constraint + app-level validation
--   D-G  add columns first, drop dead columns last
--   D-H  precharge_enabled + precharge_amount only in API whitelist
--
-- Idempotency: ADDs guarded by ff_add_column_if_missing helper.
-- DROPs guarded by INFORMATION_SCHEMA existence checks. CHECK
-- constraint ADD guarded by INFORMATION_SCHEMA.TABLE_CONSTRAINTS.
-- Re-running this migration is a no-op once columns/constraint exist.
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

-- ── Helper: add CHECK constraint only if missing ────────────
DROP PROCEDURE IF EXISTS ff_add_check_if_missing;
DELIMITER //
CREATE PROCEDURE ff_add_check_if_missing(
    IN tbl   VARCHAR(64),
    IN cname VARCHAR(64),
    IN expr  TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA    = DATABASE()
          AND TABLE_NAME      = tbl
          AND CONSTRAINT_NAME = cname
          AND CONSTRAINT_TYPE = 'CHECK'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE ', tbl, ' ADD CONSTRAINT ', cname, ' CHECK (', expr, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ── 1. Backup table for the dead Model A columns (D-B) ──────
-- Captures every existing lease row's prior precharge state so a
-- forensic recovery is possible if any unreviewed seed/fixture
-- writer turns out to have populated these columns. Snapshot is
-- unconditional (all rows, not just non-zero) — cheaper than
-- debating thresholds.
CREATE TABLE IF NOT EXISTS leases_precharge_backup_S_MILEAGE_1 (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lease_id                    INT UNSIGNED NOT NULL,
    mileage_precharge_amount    DECIMAL(12,2) NULL
        COMMENT 'Pre-S-MILEAGE-1 value of leases.mileage_precharge_amount',
    mileage_precharge_invoiced  TINYINT(1) NULL
        COMMENT 'Pre-S-MILEAGE-1 value of leases.mileage_precharge_invoiced',
    snapshot_taken_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lease (lease_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='S-MILEAGE-1 (D-B): pre-drop snapshot of dead Model A columns on leases';

-- ── 2. Populate backup snapshot ─────────────────────────────
-- Only insert if backup table is empty AND source columns still exist.
-- The two NOT EXISTS checks around the SELECT make this idempotent on
-- re-run: once the columns are dropped (or backup is already populated),
-- the second/third run is a no-op.
SET @do_backup := (
    SELECT (
        (SELECT COUNT(*) FROM leases_precharge_backup_S_MILEAGE_1) = 0
    ) AND (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'leases'
          AND COLUMN_NAME  = 'mileage_precharge_amount'
    ) > 0
);

SET @backup_sql := IF(
    @do_backup,
    'INSERT INTO leases_precharge_backup_S_MILEAGE_1
        (lease_id, mileage_precharge_amount, mileage_precharge_invoiced)
     SELECT id, mileage_precharge_amount, mileage_precharge_invoiced
     FROM leases',
    'SELECT 1'
);

PREPARE backup_stmt FROM @backup_sql;
EXECUTE backup_stmt;
DEALLOCATE PREPARE backup_stmt;

-- ── 3. Add the 6 new precharge columns on `leases` (D-A) ────
-- All NULL-default except precharge_enabled which defaults to 0
-- so existing leases stay opted-out of Model B.
CALL ff_add_column_if_missing('leases', 'precharge_enabled',
    "precharge_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'S-MILEAGE-1 Model B: per-lease precharge toggle. 0 = straight per-km billing every invoice. 1 = upfront precharge on Invoice 1 + drawdown on subsequent invoices.'");

CALL ff_add_column_if_missing('leases', 'precharge_amount',
    "precharge_amount DECIMAL(12,2) NULL COMMENT 'S-MILEAGE-1 Model B: original precharge amount in dollars. NOT NULL when precharge_enabled=1 (enforced by chk_leases_precharge_amount). User-set; not derived from estimated_mileage.'");

CALL ff_add_column_if_missing('leases', 'precharge_balance',
    "precharge_balance DECIMAL(12,2) NULL COMMENT 'S-MILEAGE-1 Model B: running drawdown balance. Initialized = precharge_amount on lease activation (S-MILEAGE-2). Decremented per invoice. NULL when precharge_enabled=0.'");

CALL ff_add_column_if_missing('leases', 'precharge_invoiced_at',
    "precharge_invoiced_at DATETIME NULL COMMENT 'S-MILEAGE-1 Model B: when the precharge line was billed on Invoice 1. NULL until billed (S-MILEAGE-2 sets). Lock signal: amount/enabled become immutable once non-NULL.'");

CALL ff_add_column_if_missing('leases', 'precharge_refund_method',
    "precharge_refund_method ENUM('cash','credit') NULL COMMENT 'S-MILEAGE-1 Model B: refund mechanism picked at lease close when precharge_balance > 0. NULL until close (S-MILEAGE-3 sets).'");

CALL ff_add_column_if_missing('leases', 'precharge_refund_settled_at',
    "precharge_refund_settled_at DATETIME NULL COMMENT 'S-MILEAGE-1 Model B: when the refund (cash or credit) actually posted. Audit trail for S-MILEAGE-3.'");

-- ── 4. CHECK constraint: amount required when enabled (D-D) ─
-- MySQL 8.0 enforces CHECK constraints (5.7 silently ignored).
-- App-level validation in the API endpoints provides the
-- user-friendly error message; this constraint is the safety net
-- against direct-SQL writes that bypass the API.
CALL ff_add_check_if_missing('leases',
    'chk_leases_precharge_amount',
    '(precharge_enabled = 0) OR (precharge_amount IS NOT NULL AND precharge_amount > 0)');

-- ── 5. Drop the dead Model A columns (D-B) ──────────────────
-- Per S-MILEAGE-MODEL-AUDIT Q1/Q4: zero production code paths
-- read or write these columns. Backup captured above. Safe to drop.
CALL ff_drop_column_if_present('leases', 'mileage_precharge_amount');
CALL ff_drop_column_if_present('leases', 'mileage_precharge_invoiced');

-- ── 6. Cleanup helper procedures ────────────────────────────
DROP PROCEDURE IF EXISTS ff_add_column_if_missing;
DROP PROCEDURE IF EXISTS ff_drop_column_if_present;
DROP PROCEDURE IF EXISTS ff_add_check_if_missing;
