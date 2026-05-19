-- S-ACCT-DMG — Damage Claims Subledger + AutoEntryBridge wiring (Phase C-11).
--
-- Spec ref: FLEETFORGE_ACCOUNTING_SPEC.md §23.11
-- Roadmap:  §11 row 19
--
-- 1. ALTER acc_journal_entries.source_type — APPEND 3 new values
--    'damage_recovery', 'damage_repair', 'damage_writeoff' so the
--    damage-claims subledger can drill down to JEs by source.
--    Append-at-end per D126 — never reorder existing ENUM values.
--
-- 2. Settings INSERT IGNORE — map the 3 GL accounts used by the new
--    bridge methods. Operator decisions (S-ACCT-DMG pre-flight):
--      - damage_recovery_revenue_account_id = #47 (4100 Damage Recovery
--        Revenue) — existing acct, exact name match. NOT 4140 (which
--        doesn't exist on disk).
--      - damage_repair_expense_account_id = #57 (6010 Maintenance &
--        Repairs — Labour) — existing acct; repair work on Mainland's
--        COA lives in operating_expense (6xxx), not cost_of_revenue (5xxx).
--        NOT 5130 (which doesn't exist).
--      - bad_debt_expense_account_id already exists from S028 → not touched.
--
-- 3. K-22 catches surfaced during pre-flight:
--    - damage_claims.status has 'invoiced' NOT 'billed_to_customer'
--      (prompt assumed billed_to_customer). Bridge fires on the
--      'invoiced' transition.
--    - acc_accounts code 4140 NOT on disk → use 4100.
--    - acc_accounts code 5130 NOT on disk → use 6010 (operating_expense).
--    - acc_bill_lines has NO damage_claim_id FK → repair-bridge wiring
--      via bills/approve.php deferred (logged + skipped, not added).

-- ── 1. Extend source_type ENUM (idempotent — runs cleanly when values
--       already present; MySQL coerces the duplicate-value MODIFY into a
--       no-op when the ENUM already contains the listed values) ─────────
ALTER TABLE `acc_journal_entries`
    MODIFY COLUMN `source_type` ENUM(
        'invoice','payment','credit_note','ap_bill','ap_payment',
        'bank_transaction','depreciation','asset_disposal','tax_remittance',
        'fx_revaluation','manual','year_end','recurring',
        'damage_recovery','damage_repair','damage_writeoff'
    ) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;

-- ── 2. Settings INSERT IGNORE — 2 new keys ────────────────────────────
-- Damage Recovery Revenue → existing #47 (code 4100)
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.damage_recovery_revenue_account_id', CAST(id AS CHAR),
       'integer', 'accounting',
       'Damage Recovery Revenue Account',
       'GL account credited when damage recovery is billed via the damage claims subledger (ASPE — operational P&L). S-ACCT-DMG 2026-05-19.',
       0
  FROM `acc_accounts` WHERE `code` = '4100' LIMIT 1;

-- Damage Repair Expense → existing #57 (code 6010)
INSERT IGNORE INTO `settings`
    (`key`, `value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
SELECT 'accounting.damage_repair_expense_account_id', CAST(id AS CHAR),
       'integer', 'accounting',
       'Damage Repair Expense Account',
       'GL account debited when a repair bill is linked to a damage claim. Mainland COA places repair under operating_expense (6010), not cost_of_revenue. S-ACCT-DMG 2026-05-19.',
       0
  FROM `acc_accounts` WHERE `code` = '6010' LIMIT 1;
