-- Migration 101 (S-FIX-BACKUP-PROGRESS-MIGRATION): idempotent corrective add of
-- backup_runs.progress_pct + progress_stage.
--
-- ROOT CAUSE: 202606060102_S-BACKUP-3c-PROGRESS.sql added these columns and was
-- applied on dev (schema_migrations id=199), but prod — which is migrated
-- incrementally — had not deployed/applied it, so the status endpoint the UI
-- polls fataled with "Unknown column 'progress_pct'" and the manual-backup bar
-- hung even though the worker succeeded. 202606060102 is a plain non-idempotent
-- ALTER and is already checksummed on dev, so it can't be edited (checksum
-- drift). This corrective migration is IDEMPOTENT (MySQL 8 has no
-- ADD COLUMN IF NOT EXISTS — guard via INFORMATION_SCHEMA): a clean no-op where
-- the columns already exist (dev), adds them where they don't (prod).

-- progress_pct
SET @has_pct := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'backup_runs'
      AND COLUMN_NAME  = 'progress_pct'
);
SET @sql := IF(@has_pct = 0,
    'ALTER TABLE `backup_runs` ADD COLUMN `progress_pct` TINYINT UNSIGNED NULL AFTER `status`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- progress_stage (AFTER progress_pct — exists by now whether pre-existing or
-- just added by the guard above)
SET @has_stage := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'backup_runs'
      AND COLUMN_NAME  = 'progress_stage'
);
SET @sql := IF(@has_stage = 0,
    'ALTER TABLE `backup_runs` ADD COLUMN `progress_stage` VARCHAR(40) NULL AFTER `progress_pct`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
