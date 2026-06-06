-- ============================================================
-- S-INTEL-V2 Phase A — Token Economics + Visibility.
--
-- Adds settings keys driving:
--   - Token budget threshold alerts (F12) — operator-defined thresholds
--     at which the system emails super_admins when daily token usage
--     crosses them. Defaults to [0.5, 0.8, 1.0] (50%, 80%, 100%).
--   - Per-threshold dedup state — JSON map of threshold → last_sent_date
--     so a 60%-of-budget alert doesn't fire repeatedly within one day.
--
-- @session   S-INTEL-V2
-- @phase     A
-- @decision  D-INTEL-V2-1 (token budget threshold semantics)
-- ============================================================

START TRANSACTION;

INSERT IGNORE INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
  ('ai.budget_alert_thresholds', '[0.5,0.8,1.0]',
    'json', 'ai', 'Token Budget Alert Thresholds',
    'JSON array of fractions (0..1) at which the system emails super_admins when daily token usage crosses each threshold. Default [0.5, 0.8, 1.0] = 50%, 80%, 100% of ai.daily_token_limit. Set to [] to disable.', 0),
  ('ai.budget_alert_last_sent', '{}',
    'json', 'ai', 'Token Budget Alert Last-Sent State',
    'INTERNAL: JSON map of threshold → ISO date string of last send. Used by the budget-check cron for daily dedup so a 60%-crossing alert does not re-fire each hour. Do NOT edit manually unless clearing state.', 0),
  ('ai.budget_alert_recipients', '["super_admin"]',
    'json', 'ai', 'Token Budget Alert Recipient Roles',
    'JSON array of user_roles.slug values whose users receive the token budget alert email. Default: super_admin only. Add manager or accountant if you want them in the loop.', 0);

COMMIT;
