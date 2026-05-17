-- ============================================================
-- 032_integration_settings.sql
--
-- INT-1 — Integration settings: ensure every third-party
-- credential the app reads has a row in the settings table.
--
-- Before this migration, lib/GPS/SamsaraClient.php and the
-- AWS SES / S3 helpers read credentials from .env constants
-- only. The Settings → Integrations UI saved values into the
-- settings table but those values were ignored at runtime.
--
-- After this migration + the matching code changes:
--   1. settings table FIRST (settings_get('group.key'))
--   2. .env file SECOND   (env('ENV_KEY'))
-- never the other way around.
--
-- Group naming follows the existing convention used by
-- app/admin/settings/index.php:
--   gps.*      Samsara / Geotab credentials
--   ai.*       Anthropic / Claude config
--   email.*    SMTP / SES sending credentials
--   storage.*  Driver selector (local | s3)
--   aws.*      Shared AWS credentials (S3 + SES)
--
-- All values default to '' so the lookup falls back to .env
-- on a fresh install.
-- ============================================================

-- ── Anthropic API key (was previously read from .env only) ──
INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public)
VALUES
  ('ai.anthropic_api_key', '', 'string', 'ai', 'Anthropic API Key',
   'Claude API key. Leave blank to fall back to AI_ANTHROPIC_API_KEY in .env.', 0)
ON DUPLICATE KEY UPDATE
  label       = VALUES(label),
  description = VALUES(description),
  group_name  = VALUES(group_name);

-- ── SMTP / outbound email credentials ───────────────────────
INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES
  ('email.smtp_host', '',  'string', 'email', 'SMTP Host',
   'SES or generic SMTP host. Falls back to AWS_SES_SMTP_HOST in .env.', 0),
  ('email.smtp_port', '',  'integer', 'email', 'SMTP Port',
   'TCP port. Defaults to 587 if blank.', 0),
  ('email.smtp_user', '',  'string', 'email', 'SMTP Username',
   'SES SMTP username. Falls back to AWS_SES_SMTP_USER in .env.', 0),
  ('email.smtp_pass', '',  'string', 'email', 'SMTP Password',
   'SES SMTP password. Falls back to AWS_SES_SMTP_PASS in .env.', 0),
  ('email.from_email', '', 'string', 'email', 'From Email',
   'Default sender email. Falls back to SMTP_FROM_EMAIL in .env.', 0),
  ('email.from_name',  '', 'string', 'email', 'From Name',
   'Default sender display name. Falls back to SMTP_FROM_NAME in .env.', 0)
ON DUPLICATE KEY UPDATE
  label       = VALUES(label),
  description = VALUES(description),
  group_name  = VALUES(group_name);

-- ── Storage driver + AWS credentials ────────────────────────
INSERT INTO settings (`key`, `value`, value_type, group_name, label, description, is_public) VALUES
  ('storage.driver', '', 'string', 'storage', 'Storage Driver',
   'local or s3. Falls back to STORAGE_DRIVER in .env. Default: local.', 0),
  ('aws.access_key_id', '', 'string', 'aws', 'AWS Access Key ID',
   'Used by S3 and SES. Falls back to AWS_ACCESS_KEY_ID in .env.', 0),
  ('aws.secret_access_key', '', 'string', 'aws', 'AWS Secret Access Key',
   'Used by S3 and SES. Falls back to AWS_SECRET_ACCESS_KEY in .env.', 0),
  ('aws.region', '', 'string', 'aws', 'AWS Region',
   'AWS region for SES + S3. Falls back to AWS_REGION in .env. Default: us-west-2.', 0),
  ('aws.s3_bucket', '', 'string', 'aws', 'S3 Bucket',
   'Target S3 bucket for uploads. Falls back to AWS_S3_BUCKET in .env.', 0)
ON DUPLICATE KEY UPDATE
  label       = VALUES(label),
  description = VALUES(description),
  group_name  = VALUES(group_name);
