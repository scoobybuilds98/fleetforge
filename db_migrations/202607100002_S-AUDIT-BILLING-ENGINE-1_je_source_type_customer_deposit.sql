-- ============================================================================
-- S-AUDIT-BILLING-ENGINE-1 — add 'customer_deposit' to acc_journal_entries
-- source_type ENUM.
--
-- The three AR-deposit endpoints (deposits/create.php, apply.php, refund.php)
-- posted their JEs as source_type='manual' with NO source_id — untraceable and
-- in violation of the §16 source-stamping contract. They now stamp
-- 'customer_deposit' + the acc_customer_deposits.id. Existing 'manual' deposit
-- JEs are left as-is (identifiable via reference = deposit_number).
--
-- Idempotent: MODIFY to the same ENUM definition is a no-op re-run.
-- ============================================================================

ALTER TABLE `acc_journal_entries`
  MODIFY COLUMN `source_type` enum('invoice','payment','credit_note','ap_bill','ap_payment','bank_transaction','depreciation','asset_disposal','impairment','tax_remittance','fx_revaluation','manual','year_end','recurring','damage_recovery','damage_repair','damage_writeoff','lease_inception','lease_period','lease_termination','lease_ni_reclass','lease_residual_impairment','customer_deposit') COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT 'S-QBO-22: extended with impairment per D-QBO-22-2. NOT bridge-derived per spec §8.10 — FA-typed values flow through to QBO via standard JE push. S-AUDIT-BILLING-ENGINE-1: +customer_deposit (deposit endpoints stopped stamping ''manual'').';
