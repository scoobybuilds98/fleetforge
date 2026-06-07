-- ============================================================
-- 202606070200_add-province-snapshot.sql
--
-- WHY: province_snapshot was added to the invoices and credit_notes
-- tables in the baseline schema but no ALTER TABLE migration was ever
-- written for databases that predate the column. Production hit a hard
-- fatal on every lease-activation attempt:
--
--   PDOException: SQLSTATE[42S22]: Column not found: 1054
--   Unknown column 'province_snapshot' in 'field list'
--
-- The column stores the customer's province at the time the invoice /
-- credit note was created, so GST/PST rates applied to the document
-- can be re-derived later even if the customer record changes.
--
-- Both columns are nullable (DEFAULT NULL) — no backfill required.
-- Existing rows will correctly read as NULL (province unknown at time
-- of creation), which the application already handles with ?? 'BC'
-- fallback logic.
--
-- MySQL 8.0 does not support ADD COLUMN IF NOT EXISTS (MariaDB only).
-- We use the INFORMATION_SCHEMA + PREPARE idiom for idempotency so the
-- migration is safe to run against both dev (column already exists) and
-- production (column missing).
--
-- ORDER-SAFETY (S-PROD-SCHEMA-DRIFT-SNAPSHOT-COLS fix): the credit_notes
-- statement below MUST anchor province_snapshot off `credit_note_number`,
-- NOT off `billing_address_snapshot`. On a prod DB provisioned from an old
-- baseline, credit_notes lacks ALL the snapshot columns — billing_address_snapshot
-- does not exist yet (it is added later by 202606070300, which the runner
-- applies AFTER this file because it sorts filenames ascending). Anchoring off
-- a column this migration cannot guarantee exists made the ALTER fail with
-- error 1054, aborting the whole migration batch and perpetually blocking
-- 202606070300. credit_note_number is always present (it is the table's first
-- business column), and once 202606070300 inserts company/customer_name/
-- billing_address_snapshot between credit_note_number and province_snapshot,
-- the final column order still matches FLEETFORGE_DATABASE_MASTER.sql exactly.
--
-- Session: S-PROD-PROVINCE-SNAPSHOT (order-safety fix: S-PROD-SCHEMA-DRIFT-SNAPSHOT-COLS)
-- ============================================================

-- ── invoices.province_snapshot ────────────────────────────────────────────
SET @col_exists_invoices = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'invoices'
      AND COLUMN_NAME  = 'province_snapshot'
);
SET @sql_invoices = IF(
    @col_exists_invoices = 0,
    'ALTER TABLE `invoices` ADD COLUMN `province_snapshot` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `billing_address_snapshot`',
    'SELECT ''invoices.province_snapshot already exists -- skipping'' AS migration_note'
);
PREPARE _stmt FROM @sql_invoices;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- ── credit_notes.province_snapshot ───────────────────────────────────────
SET @col_exists_credit = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'credit_notes'
      AND COLUMN_NAME  = 'province_snapshot'
);
SET @sql_credit = IF(
    @col_exists_credit = 0,
    -- Anchor off credit_note_number (always present) — NOT billing_address_snapshot,
    -- which does not exist on an old-baseline prod credit_notes. See ORDER-SAFETY note.
    'ALTER TABLE `credit_notes` ADD COLUMN `province_snapshot` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `credit_note_number`',
    'SELECT ''credit_notes.province_snapshot already exists -- skipping'' AS migration_note'
);
PREPARE _stmt FROM @sql_credit;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;
