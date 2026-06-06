-- S-ACCT-LESSOR-4 — Direct Financing Lease Inception + Period Method
-- IDC Extension (Phase D-4).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.5
-- Roadmap:  §11 row 23
--
-- 1. INSERT IGNORE 1 new GL account:
--      1095  Deferred Initial Direct Costs — Lease     (asset/debit)
--    Placed in next-free slot after #86 (code 1090 NI Current). The
--    G-100 lead schedule code groups it with other current-asset
--    deferrals and the NI lease accounts for working-paper review.
--    Pre-flight verified absent on disk (no "Deferred IDC" / "Deferred
--    Charges" / "Deferred Direct Costs" account exists pre-migration).
--
-- 2. INSERT IGNORE 1 setting mapping the new account id:
--      accounting.lessor_deferred_idc_account_id → #1095's row id
--    AutoEntryBridge::onLeaseInception_DirectFinancing (NEW this
--    session) and the DF branch of onLeasePeriodPosting_Capital
--    (EXTENDED this session) both resolve this via requireAccountId().
--
-- 3. Locked decisions taking effect this session:
--      D-LESSOR-4-PERIOD-EXTENDED   (supersedes D-LESSOR-3-PERIOD-GENERIC)
--      D-LESSOR-4-IDC-DEFERRAL
--      D-LESSOR-4-IDC-AMORT-STRAIGHT-LINE

START TRANSACTION;

-- ── 1. INSERT GL account 1095 Deferred IDC ─────────────────────────────
INSERT IGNORE INTO `acc_accounts`
    (`code`, `name`, `description`, `account_type`, `normal_balance`,
     `currency`, `is_active`, `is_system`, `is_bank_account`,
     `is_fx_monetary`, `sort_order`, `lead_schedule_code`)
VALUES
    ('1095', 'Deferred Initial Direct Costs — Lease',
     'ASPE 3065 §24.5 direct-financing lease deferred IDC. Capitalized at inception (DR Deferred IDC vs sales-type CR Cash/AP expense); amortized straight-line over lease term as a yield adjustment to Finance Income each period. Balance returns to $0 at end of regular term (BPO row has $0 amortization per D-LESSOR-4-IDC-AMORT-STRAIGHT-LINE). S-ACCT-LESSOR-4 2026-05-19.',
     'asset', 'debit', 'CAD', 1, 1, 0, 0, 109, 'G-100');

-- ── 2. INSERT setting ──────────────────────────────────────────────────
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_deferred_idc_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Deferred Initial Direct Costs Account',
       'GL account debited at direct-financing lease inception for capitalized IDC. Amortized to Finance Income each period via D-LESSOR-4-PERIOD-EXTENDED. Resolved via requireAccountId() in both onLeaseInception_DirectFinancing and onLeasePeriodPosting_Capital (DF branch). S-ACCT-LESSOR-4 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '1095' LIMIT 1;

COMMIT;
