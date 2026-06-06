-- ============================================================
-- S-INTEL-V2 Phase C — Per-user briefing customization.
--
-- TWO new columns:
--   users.briefing_hour       TINYINT(0..23) NULL — per-user preferred
--     send hour (local timezone). NULL = use settings.notifications.
--     digest_hour (global default).
--   users.briefing_sections   JSON NULL — array of section keys to
--     INCLUDE in the email body. NULL/missing = include all sections.
--     Available section keys:
--       'overdue'      Overdue invoices summary
--       'compliance'   Compliance expiring this week
--       'damage'       Open damage claims
--       'risk_high'    Customer risk transitions
--       'health_drops' Equipment health drops
--       'brief'        AI-generated narrative paragraph
--
-- Cron behavior change (cron/notification_digest.php):
--   Old: ran at single fixed hour (notifications.digest_hour) and sent
--        all recipients in one pass.
--   New: runs every hour; for each active hour H, selects users whose
--        briefing_hour = H OR (briefing_hour IS NULL AND digest_hour = H).
--        This makes per-user time work without re-architecting the cron.
--
-- @session  S-INTEL-V2 Phase C
-- @decision D-INTEL-V2-2 (per-user briefing_hour default-via-NULL)
-- @decision D-INTEL-V2-4 (briefing_sections — JSON inclusion list;
--                         NULL means all sections; empty array means no
--                         body sections [edge case: AI brief only])
-- ============================================================

START TRANSACTION;

ALTER TABLE `users`
  ADD COLUMN `briefing_hour` TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'D-INTEL-V2-2: per-user preferred briefing send hour (0..23, local timezone). NULL = use notifications.digest_hour global default.'
  AFTER `briefing_snoozed_until`,
  ADD COLUMN `briefing_sections` JSON NULL DEFAULT NULL
    COMMENT 'D-INTEL-V2-4: JSON array of section keys to INCLUDE in the briefing body. NULL = all sections. Section keys: overdue, compliance, damage, risk_high, health_drops, brief.';

COMMIT;
