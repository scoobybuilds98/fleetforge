-- ============================================================
-- db_migrations/202606060100_S-BACKUP-1.sql
--
-- S-BACKUP-1: backup_runs history table + Dropbox settings seed
--
-- 1. CREATE TABLE backup_runs — unified history for all backup
--    destinations (s3, dropbox, manual) with per-run status,
--    file key, size, duration, and error capture.
-- 2. Seed 6 dropbox.* settings keys — config-only; no OAuth
--    calls or Dropbox API usage in this session (S-BACKUP-2+).
--    Labels are NULL intentionally: the D196 `label IS NOT NULL`
--    filter keeps them off the generic settings index; the
--    dedicated Backup tab (S-BACKUP-3) renders them explicitly.
--
-- MIGRATE COUNT: 97 → 98.
--
-- @session  S-BACKUP-1
-- @date     2026-06-06
-- @decision D-BACKUP-1 (Dropbox OAuth offline refresh token,
--           stored encrypted in settings),
--           D-BACKUP-2 (Dropbox cadence: DB daily + uploads weekly),
--           D-BACKUP-3 (manual backup async via backup_runs job record)
-- ============================================================

START TRANSACTION;

-- ── 1. backup_runs history table ─────────────────────────────────
CREATE TABLE `backup_runs` (
  `id`               INT UNSIGNED           NOT NULL AUTO_INCREMENT,
  `destination`      ENUM('s3','dropbox','manual') NOT NULL,
  `backup_type`      ENUM('db','storage','full')   NOT NULL,
  `status`           ENUM('in_progress','success','failed') NOT NULL DEFAULT 'in_progress',
  `file_key`         VARCHAR(512)           DEFAULT NULL,
  `file_size_bytes`  BIGINT UNSIGNED        DEFAULT NULL,
  `started_at`       DATETIME               NOT NULL,
  `completed_at`     DATETIME               DEFAULT NULL,
  `duration_ms`      INT UNSIGNED           DEFAULT NULL,
  `error_message`    TEXT                   DEFAULT NULL,
  `initiated_by`     INT UNSIGNED           DEFAULT NULL,
  `trigger_source`   ENUM('cron','manual')  NOT NULL DEFAULT 'cron',
  `created_at`       DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dest_status` (`destination`, `status`),
  KEY `idx_created`     (`created_at`),
  KEY `idx_type`        (`backup_type`),
  CONSTRAINT `fk_backup_runs_user`
    FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Seed Dropbox config keys ───────────────────────────────────
-- label=NULL intentionally (D196 filter hides from generic index).
-- is_sensitive=1 on credentials that must be masked in UI.
INSERT INTO `settings`
  (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `label`, `description`)
VALUES
  ('dropbox.enabled',           '0',                   'boolean', 'dropbox', 0, 0, NULL, 'Master kill-switch for Dropbox backup destination. 0 = disabled until OAuth is completed.'),
  ('dropbox.app_key',           '',                    'string',  'dropbox', 0, 0, NULL, 'Dropbox API app key from the Dropbox App Console. Required to initiate OAuth flow.'),
  ('dropbox.app_secret',        '',                    'string',  'dropbox', 0, 1, NULL, 'Dropbox API app secret. Masked in UI. Required for OAuth token exchange.'),
  ('dropbox.refresh_token',     '',                    'string',  'dropbox', 0, 1, NULL, 'Offline OAuth refresh token stored after first authorization. Masked in UI. Used to obtain fresh access tokens without re-authorizing.'),
  ('dropbox.folder_path',       '/FleetForge Backups', 'string',  'dropbox', 0, 0, NULL, 'Destination folder path in the connected Dropbox account. Files are written as /FleetForge Backups/<filename>.'),
  ('dropbox.connected_account', '',                    'string',  'dropbox', 0, 0, NULL, 'Display name or email of the connected Dropbox account. Populated at OAuth callback; shown in the Backup settings tab.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

COMMIT;
