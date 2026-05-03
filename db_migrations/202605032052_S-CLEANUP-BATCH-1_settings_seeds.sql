-- ============================================================
-- 202605032052_S-CLEANUP-BATCH-1_settings_seeds.sql
--
-- Resolves S-COMPLETENESS-CHECK Finding E (settings side only).
--
-- Five settings keys were referenced from cron files with hardcoded
-- code defaults (settings_get($key, $default) second-arg) but had
-- no rows in the settings table. Adds explicit rows so:
--   1. Admins can see and override each value via Settings UI.
--   2. Future ops audits don't flag the keys as "code defaults
--      only — no DB authority".
--   3. The first-touch from cron stops hitting the fallback path.
--
-- Values match the existing code defaults verbatim — behavior
-- before/after this migration is identical UNTIL an admin edits
-- the row.
--
-- Author:   S-CLEANUP-BATCH-1
-- Date:     2026-05-03 (UTC)
-- Spec:     audit Finding E
-- Decisions confirmed in D-A through D-B:
--   D-A  delivered as a runner-applied migration (not raw mysql)
--        so the schema_migrations audit trail captures the apply.
--   D-B  app.url seeded from running .env's APP_URL value to
--        match dev environment; production seed will use
--        production APP_URL at deploy time. Other 4 use code
--        defaults verbatim.
--
-- Idempotent: INSERT IGNORE on the UNIQUE `key` constraint —
-- re-running this migration is a no-op once rows exist.
-- ============================================================

-- ── 1. app.url ──────────────────────────────────────────────
-- Used by cron/notification_digest.php and cron/compliance_alerts.php
-- to build absolute URLs in email bodies and notification links.
-- Seeded with the running dev .env APP_URL value (D-B).
INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'app.url',
    'http://fleetforge.test',
    'string',
    'app',
    'Application URL',
    'Absolute URL prefix used by cron jobs when building email and notification links. Override per-environment.',
    0
);

-- ── 2. archive.audit_log_retention_days ─────────────────────
-- Used by cron/archive_old_data.php — older audit_log rows are
-- moved to audit_log_archive after this many days. Code default 365.
INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'archive.audit_log_retention_days',
    '365',
    'integer',
    'archive',
    'Audit log retention (days)',
    'Audit log rows older than this are moved to audit_log_archive by the monthly archive cron. Minimum 30.',
    0
);

-- ── 3. archive.notification_log_retention_days ──────────────
-- Same archive cron, parallel logic for notification_log.
-- Code default 90.
INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'archive.notification_log_retention_days',
    '90',
    'integer',
    'archive',
    'Notification log retention (days)',
    'Notification log rows older than this are moved to notification_log_archive by the monthly archive cron. Minimum 7.',
    0
);

-- ── 4. notifications.digest_hour ────────────────────────────
-- Used by cron/notification_digest.php — the local-time hour
-- (0-23) at which the daily digest fires. Cron runs hourly and
-- self-checks against this value. Code default 7.
INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'notifications.digest_hour',
    '7',
    'integer',
    'notifications',
    'Digest delivery hour (0-23 local)',
    'The hour of day in company.timezone when the daily notification digest is delivered.',
    0
);

-- ── 5. reservations.stale_after_days ────────────────────────
-- Used by cron/stale_reservations.php — reservations in 'pending'
-- past this many days get auto-cancelled. Code default 14.
INSERT IGNORE INTO settings (`key`, value, value_type, group_name, label, description, is_public)
VALUES (
    'reservations.stale_after_days',
    '14',
    'integer',
    'reservations',
    'Stale reservation cutoff (days)',
    'Pending reservations older than this many days are auto-cancelled by the daily stale-reservation cron. Minimum 1.',
    0
);
