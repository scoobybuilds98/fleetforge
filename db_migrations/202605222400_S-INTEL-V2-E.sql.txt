-- ============================================================
-- S-INTEL-V2 Phase E — Weekly digest.
--
-- Adds:
--   users.weekly_brief_opt_in TINYINT(1) NOT NULL DEFAULT 0
--   settings.ai.weekly_brief_enabled   (boolean, default '1')
--   settings.ai.weekly_brief_day       (integer 0..6; 1=Monday default)
--   settings.ai.weekly_brief_hour      (integer 0..23; 9 default)
--
-- Weekly briefing is a SEPARATE opt-in from morning briefing. Users
-- can be subscribed to one, both, or neither.
--
-- @session  S-INTEL-V2 Phase E
-- @decision D-INTEL-V2-7 (weekly digest independent of morning)
-- ============================================================

START TRANSACTION;

ALTER TABLE `users`
  ADD COLUMN `weekly_brief_opt_in` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'D-INTEL-V2-7: per-user weekly digest opt-in. Independent of morning_briefing_opt_in. Default 0 — opt-in.';

INSERT IGNORE INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
  ('ai.weekly_brief_enabled', '1',  'boolean', 'ai', 'Weekly Brief Enabled',  'Master toggle for the weekly digest cron. When 0, no weekly emails sent.', 0),
  ('ai.weekly_brief_day',     '1',  'integer', 'ai', 'Weekly Brief Day',      'Day of week the weekly digest fires (0=Sun, 1=Mon, ..., 6=Sat). Default 1 (Monday).', 0),
  ('ai.weekly_brief_hour',    '9',  'integer', 'ai', 'Weekly Brief Hour',     'Hour of day the weekly digest fires (0..23, company.timezone). Default 9.', 0);

COMMIT;
