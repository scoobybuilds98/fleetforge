-- S-ACCT-LESSOR-3 — Sales-Type Lease JE Patterns (Phase D-3).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §24.4 + §24.6
-- Roadmap:  §11 row 22
--
-- 1. EXTEND acc_journal_entries.source_type ENUM with 3 lease values:
--    'lease_inception', 'lease_period', 'lease_termination'. Appended at
--    end per D126 — never reorder existing values. Idempotent: MySQL
--    treats the MODIFY as a no-op when the listed values already match.
--
-- 2. INSERT IGNORE 2 new GL accounts:
--      5200  COGS — Fleet (Lease-to-Own)            (cost_of_revenue/debit)
--      6280  Selling Expense — Lease Origination    (operating_expense/debit)
--    Both placed in next-free slots in their respective COA ranges.
--    On disk pre-migration: 5000 Cost of Revenue exists, no subaccount;
--    no 6280 anywhere. K-22 trap 13: acc_accounts uses `code`, not
--    `account_number` — direct INSERT via the `code` column.
--
-- 3. INSERT IGNORE 2 settings mapping the new account ids:
--      accounting.lessor_cogs_account_id            → #5200's row id
--      accounting.lessor_selling_expense_account_id → #6280's row id
--    AutoEntryBridge::onLeaseInception_SalesType resolves these via
--    requireAccountId() — never hardcodes the numbers.

START TRANSACTION;

-- ── 1. Extend source_type ENUM ─────────────────────────────────────────
-- Idempotent: re-running the migration is a no-op when the ENUM already
-- contains the listed values.
ALTER TABLE `acc_journal_entries`
    MODIFY COLUMN `source_type` ENUM(
        'invoice','payment','credit_note','ap_bill','ap_payment',
        'bank_transaction','depreciation','asset_disposal','tax_remittance',
        'fx_revaluation','manual','year_end','recurring',
        'damage_recovery','damage_repair','damage_writeoff',
        'lease_inception','lease_period','lease_termination'
    ) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

-- ── 2. INSERT GL accounts ──────────────────────────────────────────────
-- 5200 sits in next-free cost_of_revenue slot after 5000. K-22 trap 13:
-- `code` is the unique column on acc_accounts (NOT `account_number`).
INSERT IGNORE INTO `acc_accounts`
    (`code`, `name`, `description`, `account_type`, `normal_balance`,
     `currency`, `is_active`, `is_system`, `is_bank_account`,
     `is_fx_monetary`, `sort_order`, `lead_schedule_code`)
VALUES
    ('5200', 'COGS — Fleet (Lease-to-Own)',
     'ASPE 3065 sales-type lease cost of goods sold recognized at inception: carrying amount minus PV of unguaranteed residual. Direct-financing leases do NOT debit this account (no selling profit at inception).',
     'cost_of_revenue', 'debit', 'CAD', 1, 1, 0, 0, 520, '200-Lead'),
    ('6280', 'Selling Expense — Lease Origination',
     'ASPE 3065 sales-type lease initial direct costs expensed at inception (broker fees, origination fees, legal). Direct-financing leases defer IDC as a yield adjustment instead. S-ACCT-LESSOR-3 2026-05-19.',
     'operating_expense', 'debit', 'CAD', 1, 1, 0, 0, 628, '300-Lead');

-- ── 3. INSERT settings ────────────────────────────────────────────────
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_cogs_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — COGS Fleet (Lease-to-Own) Account',
       'GL account debited at sales-type lease inception for carrying amount minus PV of unguaranteed residual. Resolved via requireAccountId() in AutoEntryBridge::onLeaseInception_SalesType. S-ACCT-LESSOR-3 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '5200' LIMIT 1;

INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.lessor_selling_expense_account_id', CAST(`id` AS CHAR),
       'integer', 'accounting',
       'Lessor — Selling Expense (Lease Origination) Account',
       'GL account debited for initial direct costs at sales-type lease inception (D-LESSOR-3-IDC-TREATMENT — separate JE from main inception entry). Resolved via requireAccountId(). S-ACCT-LESSOR-3 2026-05-19.',
       0
FROM `acc_accounts` WHERE `code` = '6280' LIMIT 1;

COMMIT;
