-- ============================================================================
-- S-CRON-FIX-REMAINING — audit_log_archive.action enum lockstep with audit_log
-- ============================================================================
--
-- audit_log.action includes 'manual_trigger' (added in S-QBO-11-POSTVERIFY-FIXES
-- migration 202605260000_S-SETTINGS-AUDIT-3 per D-SETTINGS-AUDIT-3, for cron
-- "Run Now" buttons) but audit_log_archive.action did NOT.
--
-- archive_old_data.php moves rows via:
--   INSERT IGNORE INTO audit_log_archive ... SELECT ... FROM audit_log WHERE id IN (...)
--   DELETE FROM audit_log WHERE id IN (...)
--
-- Under MySQL strict mode, INSERT IGNORE with an unknown ENUM value coerces it
-- to '' (empty string) and the INSERT succeeds — then the DELETE fires, removing
-- the source row. Any 'manual_trigger' audit_log row that ages past the 365-day
-- retention threshold would be silently corrupted: stored as '' in the archive,
-- deleted from source. Latent today (0 manual_trigger rows are 365+ days old)
-- but will bite without this fix once they age out.
--
-- Fix: mirror the full audit_log.action enum onto audit_log_archive so the two
-- tables stay in lockstep. COLLATE and NOT NULL constraint match exactly.
-- (D-AUDIT-ARCHIVE-ACTION-LOCKSTEP, locked S-CRON-FIX-REMAINING 2026-06-03)
--
-- Standing rule: any future audit_log.action ENUM addition must also ALTER
-- audit_log_archive in the same migration (see FLEETFORGE_CLAUDE_CODE_REFERENCE.md §7).

ALTER TABLE `audit_log_archive`
    MODIFY COLUMN `action`
        enum('create','update','delete','restore','login','logout','export',
             'status_change','view','bulk_action','payment_recorded',
             'invoice_sent','invoice_voided','lease_closed','cron','manual_trigger')
        COLLATE utf8mb4_unicode_ci NOT NULL;
