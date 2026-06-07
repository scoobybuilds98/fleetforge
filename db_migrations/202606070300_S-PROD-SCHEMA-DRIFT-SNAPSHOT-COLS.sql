-- ============================================================
-- 202606070300_S-PROD-SCHEMA-DRIFT-SNAPSHOT-COLS.sql
--
-- WHY: Production was provisioned from an OLDER baseline and several columns
-- that exist in FLEETFORGE_DATABASE_MASTER.sql (and dev) were only ever added
-- to the baseline file — no incremental ALTER migration was ever written. So
-- prod never received them. This surfaced as a cascade of hard fatals on every
-- lease-activation attempt, each fix revealing the next missing column:
--
--   SQLSTATE[42S22] Unknown column 'province_snapshot'          (cleared)
--   SQLSTATE[42S22] Unknown column 'gst_exempt_number_snapshot' (active)
--   ... and pst_exempt_number_snapshot / late_fee_rule_* behind it
--
-- A full column-level diff of prod vs master across all 157 tables found
-- EXACTLY 9 drifted (missing) columns across 2 tables — no missing tables and
-- no reverse drift. This migration adds all 9 in one shot so the cascade ends
-- here rather than one Sentry error at a time.
--
--   invoices (4):
--     gst_exempt_number_snapshot  — InvoiceGenerator invoice INSERT
--     pst_exempt_number_snapshot  — InvoiceGenerator invoice INSERT
--     late_fee_rule_id            — InvoiceGenerator late-fee INSERT (line ~1747)
--     late_fee_rule_snapshot      — InvoiceGenerator late-fee INSERT (line ~1748)
--
--   credit_notes (5):
--     company_name_snapshot, customer_name_snapshot, billing_address_snapshot,
--     province_snapshot, customer_email_snapshot
--       — used by credit_notes/create.php, payments/create.php (overpayment
--         path), AutoEntryBridge, InvoiceGenerator.
--
-- All columns are nullable (or JSON DEFAULT NULL) — no backfill required.
-- Existing rows read NULL, which the application already tolerates.
--
-- IDEMPOTENT: MySQL 8.0 lacks ADD COLUMN IF NOT EXISTS, so each column uses an
-- information_schema guard + PREPARE/EXECUTE. Safe to run against dev (columns
-- already present → skipped) and prod (columns missing → added).
--
-- RUN ORDER: the runner applies migrations in ascending filename order, so
-- 202606070200 (province_snapshot) runs BEFORE this file. 0200 was fixed to
-- anchor credit_notes.province_snapshot off credit_note_number so it no longer
-- depends on billing_address_snapshot (which THIS file adds). After 0200 places
-- province_snapshot, statements c5–c7 below insert company/customer_name/
-- billing_address_snapshot between credit_note_number and province_snapshot, and
-- c8's guard finds province_snapshot already present and skips it — yielding the
-- exact master column order. Both files are idempotent, so a re-run is a no-op.
--
-- Column definitions copied verbatim from FLEETFORGE_DATABASE_MASTER.sql.
--
-- Session: S-PROD-SCHEMA-DRIFT-SNAPSHOT-COLS
-- ============================================================

-- ── invoices.gst_exempt_number_snapshot ───────────────────────────────────
SET @c1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
             AND COLUMN_NAME = 'gst_exempt_number_snapshot');
SET @c1sql = IF(@c1 = 0,
  'ALTER TABLE `invoices` ADD COLUMN `gst_exempt_number_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tax_exempt_number_snapshot`',
  'SELECT ''invoices.gst_exempt_number_snapshot exists -- skip''');
PREPARE s FROM @c1sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── invoices.pst_exempt_number_snapshot ───────────────────────────────────
SET @c2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
             AND COLUMN_NAME = 'pst_exempt_number_snapshot');
SET @c2sql = IF(@c2 = 0,
  'ALTER TABLE `invoices` ADD COLUMN `pst_exempt_number_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `gst_exempt_number_snapshot`',
  'SELECT ''invoices.pst_exempt_number_snapshot exists -- skip''');
PREPARE s FROM @c2sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── invoices.late_fee_rule_id ─────────────────────────────────────────────
SET @c3 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
             AND COLUMN_NAME = 'late_fee_rule_id');
SET @c3sql = IF(@c3 = 0,
  'ALTER TABLE `invoices` ADD COLUMN `late_fee_rule_id` int unsigned DEFAULT NULL AFTER `late_fee_invoice_id`',
  'SELECT ''invoices.late_fee_rule_id exists -- skip''');
PREPARE s FROM @c3sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── invoices.late_fee_rule_snapshot ───────────────────────────────────────
SET @c4 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
             AND COLUMN_NAME = 'late_fee_rule_snapshot');
SET @c4sql = IF(@c4 = 0,
  'ALTER TABLE `invoices` ADD COLUMN `late_fee_rule_snapshot` json DEFAULT NULL AFTER `late_fee_rule_id`',
  'SELECT ''invoices.late_fee_rule_snapshot exists -- skip''');
PREPARE s FROM @c4sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── credit_notes.company_name_snapshot ────────────────────────────────────
SET @c5 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_notes'
             AND COLUMN_NAME = 'company_name_snapshot');
SET @c5sql = IF(@c5 = 0,
  'ALTER TABLE `credit_notes` ADD COLUMN `company_name_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `credit_note_number`',
  'SELECT ''credit_notes.company_name_snapshot exists -- skip''');
PREPARE s FROM @c5sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── credit_notes.customer_name_snapshot ───────────────────────────────────
SET @c6 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_notes'
             AND COLUMN_NAME = 'customer_name_snapshot');
SET @c6sql = IF(@c6 = 0,
  'ALTER TABLE `credit_notes` ADD COLUMN `customer_name_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `company_name_snapshot`',
  'SELECT ''credit_notes.customer_name_snapshot exists -- skip''');
PREPARE s FROM @c6sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── credit_notes.billing_address_snapshot ─────────────────────────────────
SET @c7 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_notes'
             AND COLUMN_NAME = 'billing_address_snapshot');
SET @c7sql = IF(@c7 = 0,
  'ALTER TABLE `credit_notes` ADD COLUMN `billing_address_snapshot` text COLLATE utf8mb4_unicode_ci AFTER `customer_name_snapshot`',
  'SELECT ''credit_notes.billing_address_snapshot exists -- skip''');
PREPARE s FROM @c7sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── credit_notes.province_snapshot ────────────────────────────────────────
SET @c8 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_notes'
             AND COLUMN_NAME = 'province_snapshot');
SET @c8sql = IF(@c8 = 0,
  'ALTER TABLE `credit_notes` ADD COLUMN `province_snapshot` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `billing_address_snapshot`',
  'SELECT ''credit_notes.province_snapshot exists -- skip''');
PREPARE s FROM @c8sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── credit_notes.customer_email_snapshot ──────────────────────────────────
SET @c9 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_notes'
             AND COLUMN_NAME = 'customer_email_snapshot');
SET @c9sql = IF(@c9 = 0,
  'ALTER TABLE `credit_notes` ADD COLUMN `customer_email_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `province_snapshot`',
  'SELECT ''credit_notes.customer_email_snapshot exists -- skip''');
PREPARE s FROM @c9sql; EXECUTE s; DEALLOCATE PREPARE s;
