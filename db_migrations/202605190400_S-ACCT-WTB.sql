-- S-ACCT-WTB — Working Trial Balance v2 + Lead Schedules + Annotations
--
-- 1. NEW COLUMN acc_accounts.lead_schedule_code (VARCHAR(10) NULL) carries
--    the CaseWare-aligned lead schedule mapping per spec §23.2. NULL means
--    unassigned — the WTB API groups unassigned accounts under their
--    natural lead by account_type heuristic.
--
-- 2. NEW TABLE acc_workpaper_annotations stores tickmarks + notes attached
--    to workpapers (trial balance, lead schedules, reports). Annotations
--    are IMMUTABLE — there is no UPDATE or DELETE endpoint. created_by
--    uses ON DELETE RESTRICT (not SET NULL) — annotations must always
--    have an author for CRA defensibility (STOP CONDITION).
--
-- 3. Lead-schedule code seed values live in database/seeds/014_acc_lead_schedules.sql
--    (separate file, run manually post-migration via mysql redirect).
--
-- 4. Tickmark legend setting is INSERT IGNOREd here so the migration is
--    one-stop for both schema + the canonical CPA tickmark set.

-- ── 1. lead_schedule_code column ────────────────────────────────────────────
ALTER TABLE `acc_accounts`
    ADD COLUMN `lead_schedule_code` VARCHAR(10) NULL AFTER `account_type`;

-- ── 2. acc_workpaper_annotations table ─────────────────────────────────────
CREATE TABLE `acc_workpaper_annotations` (
    `id`             int unsigned NOT NULL AUTO_INCREMENT,
    `workpaper_type` enum('trial_balance','lead_schedule','report') COLLATE utf8mb4_unicode_ci NOT NULL,
    `workpaper_ref`  varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `period_id`      int unsigned NOT NULL,
    `account_id`     int unsigned DEFAULT NULL,
    `tickmark`       varchar(8)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `note`           text        COLLATE utf8mb4_unicode_ci,
    `created_by`     int unsigned NOT NULL,
    `created_at`     datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_workpaper` (`workpaper_type`, `workpaper_ref`, `period_id`),
    KEY `idx_account`   (`account_id`),
    KEY `fk_wpa_period` (`period_id`),
    KEY `fk_wpa_user`   (`created_by`),
    CONSTRAINT `fk_wpa_period`  FOREIGN KEY (`period_id`)  REFERENCES `acc_periods` (`id`)  ON DELETE RESTRICT,
    CONSTRAINT `fk_wpa_account` FOREIGN KEY (`account_id`) REFERENCES `acc_accounts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_wpa_user`    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- WHY tickmark is VARCHAR(8) not CHAR(2): the canonical legend includes the
-- ⊥ symbol (3 bytes in utf8mb4) and emoji-style ticks may appear in the
-- future. CHAR(2) under utf8mb4 still allows 2 chars × 4 bytes but VARCHAR(8)
-- is more forgiving and the storage delta is negligible.

-- ── 3. Tickmark legend setting (idempotent) ─────────────────────────────────
-- Standard CPA convention per spec §23.2. JSON map of symbol → meaning.
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
('accounting.tickmark_legend',
 '{"A":"Agreed to source document","B":"Balance confirmed","T":"Traced to GL","V":"Vouched to support","F":"Footed (math verified)","✓":"Reviewed and accepted","⊥":"Cross-referenced"}',
 'json',
 'accounting',
 'Workpaper Tickmark Legend',
 'CPA convention tickmark symbols used on working trial balance and lead schedules. JSON map of symbol -> meaning.',
 0);
