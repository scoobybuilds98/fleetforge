-- ---------------------------------------------------------------------------
-- 014_acc_lead_schedules.sql
--
-- S-ACCT-WTB — seed acc_accounts.lead_schedule_code per spec §23.2
-- (CaseWare-aligned mapping). Idempotent: every UPDATE is keyed by the
-- unique `code` column; re-running is a no-op once values are in place.
-- Header accounts (is_header=1: codes 1000/1200/2000/2200/3000/4000/5000/
-- 6000/7000) are intentionally left NULL — they're grouping nodes that
-- can't be posted to, so they don't appear in the WTB.
--
-- Accounts not covered below remain NULL; the WTB API surfaces them under
-- a fallback bucket by account_type heuristic (G-100 for other assets,
-- BB-100 for other liabilities, etc.).
-- ---------------------------------------------------------------------------

-- ── A-100  Cash and equivalents ────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'A-100' WHERE `code` IN ('1010','1020');

-- ── B-100  AR Trade ────────────────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'B-100' WHERE `code` = '1030';

-- ── B-200  Allowance for doubtful accounts ────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'B-200' WHERE `code` = '1040';

-- ── CC-100 Sales tax (GST/HST/PST receivable + payable reconciliation) ────
-- Receivable side (ITCs) lives on the asset side; payable side on liability
-- side. Both share the same lead schedule because the GST34 / PST return
-- reconciles all 4 accounts together.
UPDATE `acc_accounts` SET `lead_schedule_code` = 'CC-100' WHERE `code` IN ('1050','1060','2030','2040');

-- ── D-100  Prepaid expenses ────────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'D-100' WHERE `code` IN ('1065','1070');

-- ── G-100  Other assets (security deposits held, etc.) ────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'G-100' WHERE `code` = '1080';

-- ── E-100  PP&E continuity (cost + accum dep for fleet, vehicles, office,
--            leaseholds — both cost AND accumulated lines share this lead) ─
UPDATE `acc_accounts` SET `lead_schedule_code` = 'E-100'
    WHERE `code` IN ('1210','1220','1230','1240','1250','1260','1270','1280');

-- ── AA-100 AP Trade ────────────────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'AA-100' WHERE `code` = '2010';

-- ── BB-100 Accrued liabilities + other current liabilities ────────────────
-- 2020 = Accrued; 2050 = Customer Deposits Security; 2060 = Customer Credits
-- Liability. The latter two are deposit/credit obligations to customers,
-- naturally grouped with accruals for CPA workpaper purposes.
UPDATE `acc_accounts` SET `lead_schedule_code` = 'BB-100' WHERE `code` IN ('2020','2050','2060');

-- ── DD-100 Income tax payable ──────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'DD-100' WHERE `code` = '2080';

-- ── EE-100 Long-term debt (current portion + LT portion + LOC) ────────────
-- 2070 (current portion of LTD), 2210 (equipment loans), 2220 (LOC) all
-- reconcile to the same loan amortization schedule.
UPDATE `acc_accounts` SET `lead_schedule_code` = 'EE-100' WHERE `code` IN ('2070','2210','2220');

-- ── LL-100 Equity ──────────────────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = 'LL-100' WHERE `code` IN ('3010','3020','3030','3040');

-- ── 100-Lead Revenue ───────────────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = '100-Lead' WHERE `code` LIKE '4%' AND `is_header` = 0;

-- ── 200-Lead COGS / direct costs ───────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = '200-Lead' WHERE `code` LIKE '5%' AND `is_header` = 0;

-- ── 300-Lead Operating expenses ────────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = '300-Lead' WHERE `code` LIKE '6%' AND `is_header` = 0;

-- ── 400-Lead Other income / expense ────────────────────────────────────────
UPDATE `acc_accounts` SET `lead_schedule_code` = '400-Lead' WHERE `code` LIKE '7%' AND `is_header` = 0;
