-- ============================================================
-- S-INTEL-V2 Phase D — Multi-channel delivery (Slack + SMS).
--
-- D-INTEL-V2-6: Channel-per-user JSON column with default ['email'].
-- Cron dispatch fans out across each user's enabled channels at send
-- time. Each channel has its own settings prerequisites:
--   - email: company.* + SMTP creds (already wired via Mailer)
--   - slack: settings.slack.enabled='1' + slack.webhook_url set; user
--            has u.slack_user_id (optional — falls back to webhook
--            channel)
--   - sms:   settings.twilio.enabled='1' + twilio.account_sid +
--            twilio.auth_token + twilio.from_phone set; user has
--            u.phone_e164 OR u.phone formatted in E.164
--
-- Settings keys (INSERT IGNORE so re-runnable):
--   slack.enabled, slack.webhook_url
--   twilio.enabled, twilio.account_sid, twilio.auth_token,
--   twilio.from_phone
--
-- users.briefing_channels  JSON DEFAULT '["email"]' — array of channel
--   names the user accepts the briefing on. Default email-only to
--   preserve current behavior (no surprise Slack DMs / texts).
-- users.slack_user_id      VARCHAR(50) NULL — Slack user ID for DM
--   delivery (optional; without this, Slack channel posts to the
--   webhook channel instead of DM).
-- users.phone_e164         VARCHAR(32) NULL — E.164-formatted phone for
--   SMS. The existing users.phone column is free-form and unreliable
--   for SMS dispatch; new column stores explicit E.164 for clarity.
--
-- @session  S-INTEL-V2 Phase D
-- @decision D-INTEL-V2-6 (multi-channel delivery — per-user JSON)
-- ============================================================

START TRANSACTION;

ALTER TABLE `users`
  ADD COLUMN `briefing_channels` JSON NOT NULL DEFAULT (JSON_ARRAY('email'))
    COMMENT 'D-INTEL-V2-6: array of channels [email, slack, sms] this user accepts the briefing on. Default email-only preserves current behavior.'
  AFTER `briefing_sections`,
  ADD COLUMN `slack_user_id` VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'Slack user ID (UXXXXXXXX) for DM delivery. Without this, Slack channel posts to webhook channel.'
  AFTER `briefing_channels`,
  ADD COLUMN `phone_e164` VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'E.164-formatted phone number for SMS dispatch (e.g. +14155551212). Distinct from users.phone (free-form display).'
  AFTER `slack_user_id`;

INSERT IGNORE INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`, `is_sensitive`)
VALUES
  ('slack.enabled',           '0',  'boolean', 'slack',  'Slack Enabled',           'Master toggle for Slack delivery channel. When 0, all Slack dispatches are no-ops.', 0, 0),
  ('slack.webhook_url',       '',   'string',  'slack',  'Slack Webhook URL',       'Incoming webhook URL for the workspace. Without this, Slack channel falls back to log-only.', 0, 1),
  ('twilio.enabled',          '0',  'boolean', 'twilio', 'Twilio SMS Enabled',      'Master toggle for SMS delivery. When 0, SMS dispatches are no-ops.', 0, 0),
  ('twilio.account_sid',      '',   'string',  'twilio', 'Twilio Account SID',      'Twilio API account SID.', 0, 1),
  ('twilio.auth_token',       '',   'string',  'twilio', 'Twilio Auth Token',       'Twilio API auth token. Stored sensitive.', 0, 1),
  ('twilio.from_phone',       '',   'string',  'twilio', 'Twilio From Phone',       'E.164 sender phone (e.g. +14155551212). Required for SMS.', 0, 0);

COMMIT;
