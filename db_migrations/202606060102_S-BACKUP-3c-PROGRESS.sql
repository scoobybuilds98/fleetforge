-- Migration 100 (S-BACKUP-3c-PROGRESS): stage-based progress for manual backups.
-- Adds progress_pct (0-100) + progress_stage label to backup_runs so the manual
-- "download everything" UI can render a stage-based progress bar while the
-- worker (cron/backup_manual_worker.php) builds the bundle.
ALTER TABLE `backup_runs`
  ADD COLUMN `progress_pct`   TINYINT UNSIGNED NULL AFTER `status`,
  ADD COLUMN `progress_stage` VARCHAR(40)      NULL AFTER `progress_pct`;
