-- ============================================================================
-- S-CRON-FIX-NOTIFICATION-HARDENING — notification_log enum hardening
-- ============================================================================
--
-- The notification crons write enum values that notification_log's columns
-- lacked, so under STRICT_TRANS_TABLES the INSERT throws "Data truncated":
--
--   status='skipped'  (cron-audit HIGH-2 + MED-5) — ai_weekly_brief.php:126/140
--     + notification_digest.php:325/342. 'skipped' is a real, distinct state:
--     the channel was DELIBERATELY not attempted (Slack/SMS disabled or no
--     webhook), semantically NOT 'failed' (attempted-and-errored). Adding it
--     preserves accurate error counts. (D-NOTIFICATION-STATUS-SKIPPED)
--
--   channel='slack'   (found during S-CRON-FIX-NOTIFICATION PART A — beyond the
--     audit's HIGH-2 description, same bug class) — ai_weekly_brief.php:122 +
--     notification_digest.php:327 write channel='slack', but the enum only had
--     email/sms/in_app/webhook, so the SLACK path threw on channel regardless
--     of the status fix. Slack is a first-class delivery channel here (distinct
--     from generic 'webhook'), so it's added as an enum member. (D-NOTIFICATION-
--     CHANNEL-SLACK)
--
-- notification_log_archive gets the SAME members: archive_old_data.php copies
-- notification_log.status + channel into the archive, so leaving the archive
-- enums short would recreate the audit's HIGH-4 archive-corruption class
-- (INSERT IGNORE coerces an unknown enum value to '' under strict mode, then
-- DELETEs the source) once such a row ages past the 90-day retention. The two
-- tables are kept in lockstep here. (This alters the archive TABLE only — it
-- does NOT fix HIGH-4's separate audit_log_archive.action gap.)
--
-- Each MODIFY mirrors the column's existing definition exactly (charset/collate/
-- NOT NULL/DEFAULT per FLEETFORGE_DATABASE_MASTER.sql), only appending the new
-- member. Re-applying is a no-op ALTER (idempotent-safe).
-- ============================================================================

ALTER TABLE `notification_log`
  MODIFY COLUMN `status`
    enum('queued','sent','delivered','failed','bounced','skipped')
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued';

ALTER TABLE `notification_log`
  MODIFY COLUMN `channel`
    enum('email','sms','in_app','webhook','slack')
    COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE `notification_log_archive`
  MODIFY COLUMN `status`
    enum('queued','sent','delivered','failed','bounced','skipped')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued';

ALTER TABLE `notification_log_archive`
  MODIFY COLUMN `channel`
    enum('email','sms','in_app','webhook','slack')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
