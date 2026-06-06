-- ============================================================
-- S-INTEL-V2 Phase B — Brief Control.
--
-- users.briefing_snoozed_until — DATETIME NULL. When set in the
-- future, the morning digest cron skips that user even if their
-- role + opt_in say yes. Per-user "vacation" without permanently
-- unsubscribing. NULL means no snooze (default state).
--
-- Snooze is FF-clock — compared against NOW() at cron time. The
-- cron treats expired snooze (snoozed_until < NOW()) as identical
-- to NULL so users auto-resume after vacation.
--
-- @session  S-INTEL-V2 Phase B
-- @decision D-INTEL-V2-5 (snooze semantics — DATETIME UTC, expired
--                         snooze auto-resumes)
-- ============================================================

START TRANSACTION;

ALTER TABLE `users`
  ADD COLUMN `briefing_snoozed_until` DATETIME NULL DEFAULT NULL
    COMMENT 'D-INTEL-V2-5: vacation-style temporary unsubscribe from morning briefing. NULL = no snooze. snoozed_until > NOW() means cron skips this user. Once snoozed_until <= NOW(), cron treats as NULL (auto-resume).'
  AFTER `morning_briefing_opt_in`;

COMMIT;
