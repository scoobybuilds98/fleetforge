-- ============================================================
-- S-INTEL-TAB — Intelligence settings tab foundations.
--
-- Schema + seed changes to support a dedicated Intelligence tab in
-- settings (relocating ai.* keys out of Integrations + adding new
-- per-feature toggles + per-user opt-in).
--
-- THREE locked decisions:
--   D-INTEL-1 (per-user opt-in defaults): existing super_admin,
--     manager, accountant users get morning_briefing_opt_in=1
--     (preserves current behavior — no one stops receiving a brief
--     they already get). All other existing users (dispatcher,
--     read_only) get opt_in=0. New users default to 0 (opt-in is
--     privacy-friendlier than opt-out for a daily email).
--
--   D-INTEL-2 (cron filter precedence): notification_digest.php
--     applies three gates in order:
--       (1) ai.briefing_enabled (master kill switch — independent
--           from ai.enabled so the briefing can be paused without
--           taking down AI chat or anomaly scan)
--       (2) ai.briefing_recipient_roles JSON array (role-level
--           allow list — operator can drop accountant role for
--           example without affecting other AI features)
--       (3) users.morning_briefing_opt_in = 1 (per-user check;
--           each user can unsubscribe even if their role is in
--           the allow list)
--     All three must pass for a user to receive the briefing.
--
--   D-INTEL-3 (test-send semantics): the "Send me a test briefing
--     now" button in the Intelligence tab dispatches the CACHED
--     brief from report_cache (report_type='ai_fleet_brief',
--     expires_at > NOW()). If no cache exists, the endpoint
--     returns 422 "No cached briefing — runs at digest_hour
--     daily". This avoids the settings page accidentally burning
--     AI tokens (a regenerate-from-Claude option would require an
--     explicit "Regenerate now" button with separate gating; left
--     for a future session if needed).
--
-- @session   S-INTEL-TAB
-- @date      2026-05-21
-- ============================================================

START TRANSACTION;

-- ── Column: users.morning_briefing_opt_in ──────────────────
-- TINYINT(1) NOT NULL DEFAULT 0 — opt-in default for new users
-- per D-INTEL-1. Existing users are backfilled below by role.
ALTER TABLE `users`
  ADD COLUMN `morning_briefing_opt_in` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'D-INTEL-1: user-level subscription to the AI morning briefing email (cron/notification_digest.php). Cron gates on this column AFTER the role-level allow list. Default 0 means new users opt-in explicitly; existing super_admin/manager/accountant users backfilled to 1 in the same migration to preserve current behavior.'
  AFTER `permissions_updated_at`;

-- ── Backfill: existing super_admin / manager / accountant ──
-- Preserves current behavior — users in the cron's role allow list
-- continue receiving the briefing after the column is added.
-- Other roles (dispatcher, read_only) stay at the column default (0).
UPDATE `users` u
   JOIN `user_roles` ur ON ur.id = u.role_id
   SET u.morning_briefing_opt_in = 1
 WHERE ur.slug IN ('super_admin', 'manager', 'accountant')
   AND u.deleted_at IS NULL;

-- ── New settings keys ──────────────────────────────────────
-- INSERT IGNORE so re-running the migration is idempotent. Keys
-- carry value_type for the settings UI's render-by-type loop +
-- group_name='ai' so they show in the Intelligence tab + label
-- for the form rendering + description for the field hint.
INSERT IGNORE INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
  ('ai.briefing_enabled',          '1',
    'boolean', 'ai', 'Morning Briefing Enabled',
    'Master toggle for the AI-generated morning briefing email. When 0, the cron skips briefing generation AND email dispatch entirely. Independent of ai.enabled so the briefing can be paused without affecting AI chat or anomaly scan.', 0),
  ('ai.anomaly_scan_enabled',      '1',
    'boolean', 'ai', 'Anomaly Scan Enabled',
    'Master toggle for the nightly AI anomaly scan cron (cron/ai_anomaly_scan.php). Independent of ai.enabled.', 0),
  ('ai.briefing_recipient_roles',  '["super_admin","manager","accountant"]',
    'json', 'ai', 'Morning Briefing Recipient Roles',
    'JSON array of user_roles.slug values whose users receive the morning briefing (subject to per-user opt-in). Default matches the original cron hardcode. Edit this to add/remove role-level eligibility.', 0);

COMMIT;
