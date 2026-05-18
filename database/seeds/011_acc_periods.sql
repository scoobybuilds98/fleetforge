-- ---------------------------------------------------------------------------
-- 011_acc_periods.sql
--
-- Canonical seed: 24 accounting periods (Jan 2025 – Dec 2026).
--
-- This is the original 24-period horizon established by S028. Additional
-- months are auto-generated at runtime by cron/accounting_generate_periods.php
-- (S037-CRONS, schedule `0 4 1 * *`) so a fresh DB only needs these baseline
-- rows; the cron will extend the horizon forward 12 months on its first run.
--
-- Idempotent via INSERT IGNORE against the UNIQUE KEY (year, month) on
-- acc_periods. is_year_end=1 is set on December periods to match the cron's
-- year-end flag convention.
--
-- Re-creation context: S037-CRUD (Phase B CRUD completion / spec §22.5).
-- Original file referenced in S028 PROGRESS.md log but never committed to
-- repo; restored 2026-05-19 by transcribing live DB values.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `acc_periods` (`year`, `month`, `name`, `start_date`, `end_date`, `status`, `is_year_end`) VALUES
(2025,  1, 'January 2025',   '2025-01-01', '2025-01-31', 'open', 0),
(2025,  2, 'February 2025',  '2025-02-01', '2025-02-28', 'open', 0),
(2025,  3, 'March 2025',     '2025-03-01', '2025-03-31', 'open', 0),
(2025,  4, 'April 2025',     '2025-04-01', '2025-04-30', 'open', 0),
(2025,  5, 'May 2025',       '2025-05-01', '2025-05-31', 'open', 0),
(2025,  6, 'June 2025',      '2025-06-01', '2025-06-30', 'open', 0),
(2025,  7, 'July 2025',      '2025-07-01', '2025-07-31', 'open', 0),
(2025,  8, 'August 2025',    '2025-08-01', '2025-08-31', 'open', 0),
(2025,  9, 'September 2025', '2025-09-01', '2025-09-30', 'open', 0),
(2025, 10, 'October 2025',   '2025-10-01', '2025-10-31', 'open', 0),
(2025, 11, 'November 2025',  '2025-11-01', '2025-11-30', 'open', 0),
(2025, 12, 'December 2025',  '2025-12-01', '2025-12-31', 'open', 1),
(2026,  1, 'January 2026',   '2026-01-01', '2026-01-31', 'open', 0),
(2026,  2, 'February 2026',  '2026-02-01', '2026-02-28', 'open', 0),
(2026,  3, 'March 2026',     '2026-03-01', '2026-03-31', 'open', 0),
(2026,  4, 'April 2026',     '2026-04-01', '2026-04-30', 'open', 0),
(2026,  5, 'May 2026',       '2026-05-01', '2026-05-31', 'open', 0),
(2026,  6, 'June 2026',      '2026-06-01', '2026-06-30', 'open', 0),
(2026,  7, 'July 2026',      '2026-07-01', '2026-07-31', 'open', 0),
(2026,  8, 'August 2026',    '2026-08-01', '2026-08-31', 'open', 0),
(2026,  9, 'September 2026', '2026-09-01', '2026-09-30', 'open', 0),
(2026, 10, 'October 2026',   '2026-10-01', '2026-10-31', 'open', 0),
(2026, 11, 'November 2026',  '2026-11-01', '2026-11-30', 'open', 0),
(2026, 12, 'December 2026',  '2026-12-01', '2026-12-31', 'open', 1);
