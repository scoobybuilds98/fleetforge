-- S-ACCT-AJE — Adjusting Entry Workflow schema.
--
-- 1. NEW COLUMN acc_journal_entries.entry_status carries the approval-workflow
--    layer ('draft' → 'submitted' → 'approved' → 'posted' | 'reversed'),
--    distinct from the existing acc_journal_entries.status column
--    ('draft' | 'posted' | 'reversed') which continues to drive GL
--    inclusion. Default 'posted' makes the migration non-destructive: all
--    existing JEs (which are status='posted' or 'reversed') correctly read
--    as entry_status='posted' without backfill.
--
-- 2. NEW COLUMNS submitted_by_id / submitted_at / approved_by_id / approved_at
--    record the two-eyes review chain. Two FKs to users(id) ON DELETE SET NULL
--    preserve audit trail even if a user is deleted.
--
-- 3. EXTEND entry_type ENUM with 'adjusting','reclassifying','closing',
--    'prior_period' per spec §23.1 (D126 append-at-end rule). The values
--    'reversing' + 'year_end' from the spec list are already on disk and
--    are not duplicated. 'adjustment' (singular) was the legacy value and
--    is preserved alongside the new 'adjusting' per pre-flight decision.
--
-- 4. SETTINGS — two new accounting keys gate the workflow:
--    accounting.aje_review_required (default '0' = single-step) and
--    accounting.aje_amount_threshold (default '0.00' = no amount threshold).

-- ── 1. entry_status column ──────────────────────────────────────────────────
ALTER TABLE `acc_journal_entries`
    ADD COLUMN `entry_status`
        ENUM('draft','submitted','approved','posted','reversed')
        COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'posted'
        AFTER `status`;

-- ── 2. workflow columns ────────────────────────────────────────────────────
ALTER TABLE `acc_journal_entries`
    ADD COLUMN `submitted_by_id` int unsigned NULL AFTER `entry_status`,
    ADD COLUMN `submitted_at`    datetime     NULL AFTER `submitted_by_id`,
    ADD COLUMN `approved_by_id`  int unsigned NULL AFTER `submitted_at`,
    ADD COLUMN `approved_at`     datetime     NULL AFTER `approved_by_id`;

-- ── 3. workflow FKs ────────────────────────────────────────────────────────
ALTER TABLE `acc_journal_entries`
    ADD CONSTRAINT `fk_je_submitted_by`
        FOREIGN KEY (`submitted_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_je_approved_by`
        FOREIGN KEY (`approved_by_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ── 4. entry_type ENUM extension (append-at-end per D126) ──────────────────
ALTER TABLE `acc_journal_entries`
    MODIFY COLUMN `entry_type`
        ENUM('manual','system','recurring','reversing','year_end','adjustment',
             'adjusting','reclassifying','closing','prior_period')
        COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'manual';

-- ── 5. settings (idempotent) ───────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
('accounting.aje_review_required',  '0',    'boolean', 'accounting', 'AJE Review Required',  'When 1, adjusting/reclassifying/prior_period JEs require a different user to approve before posting (two-eyes review)', 0),
('accounting.aje_amount_threshold', '0.00', 'decimal', 'accounting', 'AJE Amount Threshold', 'If > 0, only AJEs with debit total at or above this threshold require review. 0.00 = no threshold (all AJEs require review when aje_review_required=1)', 0);
