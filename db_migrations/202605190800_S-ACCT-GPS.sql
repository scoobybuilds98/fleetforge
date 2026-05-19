-- S-ACCT-GPS — GPS Principal/Agent revenue presentation toggle (ASPE 3400).
--
-- 1. ALTER customers — ADD COLUMN gps_revenue_presentation ENUM('net','gross')
--    NOT NULL DEFAULT 'net'. K-22: prompt assumed AFTER `gps_exempt` but
--    customers has no `gps_exempt` column on disk (GPS is lease-level via
--    leases.gps_opt_in / gps_cost). Placed AFTER `billing_cycle` instead —
--    that's the presentation-policy cluster on this table.
--
-- 2. Three NEW GL accounts:
--    4120 GPS Recharge Revenue — Net (revenue/credit, 100-Lead)
--    4121 GPS Recharge Revenue — Gross (revenue/credit, 100-Lead)
--    1055 Samsara GPS Recoverable (asset/debit, G-100)
--    Pre-flight scan confirmed no GPS revenue accounts existed (only
--    #53/5040 GPS & Telematics Costs cost_of_revenue is on disk).
--    1055 fills the gap between existing 1050 (GST/HST Receivable) and
--    1060 (PST Receivable). 4120/4121 follow the existing 4xxx revenue
--    series (4000-4110 used).
--    asset_account_id / accum_depr_account_id / depr_expense_account_id
--    only apply to fixed assets — the new revenue/asset accounts use
--    NULL for those FK fields (acc_accounts FKs not enforced for them).
--
-- 3. Four settings INSERT IGNOREd:
--    accounting.samsara_daily_unit_cost = '0.00'
--    accounting.gps_net_revenue_account_id = <id of 4120>
--    accounting.gps_gross_revenue_account_id = <id of 4121>
--    accounting.samsara_recoverable_account_id = <id of 1055>
--    The account_id settings are populated via subquery using the
--    accounts created in step 2 — works whether they're brand-new or
--    pre-existing (idempotent re-run safe).
--
-- 4. K-22 catches surfaced + locked alongside this session:
--    - customers.gps_exempt: NOT a column (use leases.gps_opt_in for opt-in).
--    - customers GPS column placement uses billing_cycle anchor, not gps_exempt.

-- ── 1. customers.gps_revenue_presentation ──────────────────────────────────
ALTER TABLE `customers`
    ADD COLUMN `gps_revenue_presentation`
        ENUM('net','gross') COLLATE utf8mb4_unicode_ci
        NOT NULL DEFAULT 'net'
        AFTER `billing_cycle`;

-- ── 2. NEW GL accounts (idempotent via INSERT ... SELECT WHERE NOT EXISTS) ─
-- 4120 GPS Recharge Revenue — Net
INSERT INTO `acc_accounts`
    (`code`, `name`, `account_type`, `normal_balance`, `is_active`,
     `is_system`, `is_header`, `lead_schedule_code`, `notes`)
SELECT '4120', 'GPS Recharge Revenue — Net', 'revenue', 'credit', 1,
       0, 0, '100-Lead',
       'Margin-only GPS recharge revenue per ASPE 3400 net (agent) presentation. S-ACCT-GPS 2026-05-19.'
  FROM dual
 WHERE NOT EXISTS (SELECT 1 FROM `acc_accounts` WHERE `code` = '4120');

-- 4121 GPS Recharge Revenue — Gross
INSERT INTO `acc_accounts`
    (`code`, `name`, `account_type`, `normal_balance`, `is_active`,
     `is_system`, `is_header`, `lead_schedule_code`, `notes`)
SELECT '4121', 'GPS Recharge Revenue — Gross', 'revenue', 'credit', 1,
       0, 0, '100-Lead',
       'Full GPS recharge revenue per ASPE 3400 gross (principal) presentation. S-ACCT-GPS 2026-05-19.'
  FROM dual
 WHERE NOT EXISTS (SELECT 1 FROM `acc_accounts` WHERE `code` = '4121');

-- 1055 Samsara GPS Recoverable (asset — offsets the Samsara vendor bill cost
-- under NET presentation; in scope for AutoEntryBridge invoice JE only,
-- vendor-bill DR will hit this when Samsara invoicing is configured)
INSERT INTO `acc_accounts`
    (`code`, `name`, `account_type`, `normal_balance`, `is_active`,
     `is_system`, `is_header`, `lead_schedule_code`, `notes`)
SELECT '1055', 'Samsara GPS Recoverable', 'asset', 'debit', 1,
       0, 0, 'G-100',
       'Recoverable Samsara GPS cost under NET presentation. CR on invoice JE; DR on Samsara vendor bill (when configured). S-ACCT-GPS 2026-05-19.'
  FROM dual
 WHERE NOT EXISTS (SELECT 1 FROM `acc_accounts` WHERE `code` = '1055');

-- ── 3. Settings (idempotent) ───────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`) VALUES
('accounting.samsara_daily_unit_cost', '0.00', 'decimal', 'accounting',
 'Samsara Daily Unit Cost',
 'Cost per GPS unit per day (CAD). Used for NET presentation JE split. 0.00 = unknown — GPS revenue posts as gross until configured.', 0);

-- Account-id settings derived from acc_accounts at apply time. Idempotent:
-- INSERT IGNORE skips when key already exists (so re-runs don't clobber a
-- manually-edited mapping).
INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.gps_net_revenue_account_id', CAST(id AS CHAR), 'integer', 'accounting',
       'GPS Net Revenue Account',
       'GL account credited for the MARGIN portion of GPS billing when customer.gps_revenue_presentation = net.', 0
  FROM `acc_accounts` WHERE `code` = '4120' LIMIT 1;

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.gps_gross_revenue_account_id', CAST(id AS CHAR), 'integer', 'accounting',
       'GPS Gross Revenue Account',
       'GL account credited for the FULL GPS billing when customer.gps_revenue_presentation = gross (or when net split unavailable).', 0
  FROM `acc_accounts` WHERE `code` = '4121' LIMIT 1;

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.samsara_recoverable_account_id', CAST(id AS CHAR), 'integer', 'accounting',
       'Samsara Recoverable Account',
       'GL account credited for the COST portion of GPS billing under NET presentation. Offset by Samsara vendor bill DR.', 0
  FROM `acc_accounts` WHERE `code` = '1055' LIMIT 1;
