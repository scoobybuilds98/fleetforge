-- ============================================================
-- S-SOP-KNOWN-ISSUES — schema for the SOP chapter 12 fix backlog
-- 2026-09-24
--
-- The in-app SOP (S-SOP-MODULE) listed 27 known issues (I1–I27). This
-- migration carries the schema the ledger fixes need:
--
--   acc_journal_entries.source_type + 6 values
--     asset_acquisition / asset_betterment  (I2: an asset purchase or
--       betterment now posts its own entry)
--     bank_transfer                         (I5: CAD↔USD transfers post the
--       CAD value of each leg)
--     bank_opening_balance                  (I8: a bank account's opening
--       balance now posts DR bank / CR 3050)
--     bad_debt_recovery                     (I13: money received after a
--       write-off — DR bank / CR bad debt)
--     credit_note_refund                    (I18: a credit note paid back
--       in cash — DR 2060 / CR bank)
--   acc_tax_remittances.direction / tax_collected_cleared / itc_cleared
--     (I4: a GST/HST remittance clears 2030 AND 1050; refunds recordable)
--   payments.deposit_bank_account_id, acc_customer_deposits.bank_account_id
--     (I10: a customer payment or deposit posts to the bank it went into)
--   acc_bank_reconciliations.beginning_balance / cleared_balance
--     (I11: Difference = statement − (beginning + cleared items))
--   acc_bill_lines.equipment_unit_id
--     (I15: bill lines carry the trailer/truck, so the Per-Unit P&L has
--     direct costs)
--   credit_note_refunds (new table, I18)
--   acc_accounts 3050 Opening Balance Equity + its setting (I8)
--   accounting.revenue_account_map — adds the mileage keys (I1); existing
--     values are kept (JSON_MERGE_PATCH: the stored map wins)
-- ============================================================

ALTER TABLE `acc_journal_entries`
  MODIFY COLUMN `source_type` enum('invoice','payment','credit_note','ap_bill','ap_payment','bank_transaction','depreciation','asset_disposal','impairment','tax_remittance','fx_revaluation','manual','year_end','recurring','damage_recovery','damage_repair','damage_writeoff','lease_inception','lease_period','lease_termination','lease_ni_reclass','lease_residual_impairment','customer_deposit','asset_acquisition','asset_betterment','bank_transfer','bank_opening_balance','bad_debt_recovery','credit_note_refund') COLLATE utf8mb4_unicode_ci DEFAULT NULL;

ALTER TABLE `acc_tax_remittances`
  ADD COLUMN `direction` enum('payment','refund') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'payment'
    COMMENT 'S-SOP-KNOWN-ISSUES I4: refund = CRA paid FF (ITCs exceeded GST collected); amount stays positive'
    AFTER `amount`,
  ADD COLUMN `tax_collected_cleared` decimal(15,2) DEFAULT NULL
    COMMENT 'I4: amount debited to the tax payable account (2030/2040) by this remittance'
    AFTER `direction`,
  ADD COLUMN `itc_cleared` decimal(15,2) DEFAULT NULL
    COMMENT 'I4: GST/HST input tax credits credited out of 1050 by this remittance (0 for PST)'
    AFTER `tax_collected_cleared`;

ALTER TABLE `payments`
  ADD COLUMN `deposit_bank_account_id` int unsigned DEFAULT NULL
    COMMENT 'S-SOP-KNOWN-ISSUES I10: bank account the money went into; NULL = accounting.default_cash_account_id'
    AFTER `bank_name`,
  ADD KEY `idx_deposit_bank_account` (`deposit_bank_account_id`),
  ADD CONSTRAINT `fk_payments_deposit_bank_account` FOREIGN KEY (`deposit_bank_account_id`) REFERENCES `acc_bank_accounts` (`id`) ON DELETE SET NULL;

ALTER TABLE `acc_customer_deposits`
  ADD COLUMN `bank_account_id` int unsigned DEFAULT NULL
    COMMENT 'S-SOP-KNOWN-ISSUES I10: bank account the deposit went into; NULL = accounting.default_cash_account_id'
    AFTER `received_date`,
  ADD KEY `idx_bank_account` (`bank_account_id`),
  ADD CONSTRAINT `fk_customer_deposits_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `acc_bank_accounts` (`id`) ON DELETE SET NULL;

ALTER TABLE `acc_bank_reconciliations`
  ADD COLUMN `beginning_balance` decimal(15,2) DEFAULT NULL
    COMMENT 'S-SOP-KNOWN-ISSUES I11: previous completed reconciliation''s statement ending balance, else the account''s opening balance'
    AFTER `statement_ending_balance`,
  ADD COLUMN `cleared_balance` decimal(15,2) DEFAULT NULL
    COMMENT 'I11: beginning + cleared deposits − cleared withdrawals; Difference = statement − cleared'
    AFTER `beginning_balance`;

ALTER TABLE `acc_bill_lines`
  ADD COLUMN `equipment_unit_id` int unsigned DEFAULT NULL
    COMMENT 'S-SOP-KNOWN-ISSUES I15: trailer/truck this cost belongs to; stamped on the bill JE line for the Per-Unit P&L (NULL = the bill header unit)'
    AFTER `asset_id`,
  ADD KEY `idx_equipment_unit` (`equipment_unit_id`),
  ADD CONSTRAINT `fk_bill_lines_equipment_unit` FOREIGN KEY (`equipment_unit_id`) REFERENCES `equipment_units` (`id`) ON DELETE SET NULL;

CREATE TABLE `credit_note_refunds` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `credit_note_id` int unsigned NOT NULL,
  `customer_id` int unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL COMMENT 'In the credit note''s currency',
  `amount_cad` decimal(15,2) NOT NULL COMMENT 'CAD value posted to 2060 (credit note''s frozen rate)',
  `refund_date` date NOT NULL,
  `method` enum('cheque','eft','e_transfer','wire','credit_card','cash','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_id` int unsigned DEFAULT NULL COMMENT 'Paid from; NULL = accounting.default_cash_account_id',
  `journal_entry_id` int unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_credit_note` (`credit_note_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_bank_account` (`bank_account_id`),
  KEY `idx_journal_entry` (`journal_entry_id`),
  CONSTRAINT `fk_cn_refunds_credit_note` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cn_refunds_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cn_refunds_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `acc_bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cn_refunds_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `acc_journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cn_refunds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='S-SOP-KNOWN-ISSUES I18: customer credit (credit note) paid back in cash. Posts DR 2060 Customer Credits / CR bank.';

-- I8: equity account the bank opening-balance entries credit. The
-- accountant moves its balance to Retained Earnings once every opening
-- balance is in.
INSERT INTO `acc_accounts` (`code`, `name`, `description`, `account_type`, `account_subtype`, `parent_id`, `is_header`, `currency`, `normal_balance`, `is_system`, `is_active`, `sort_order`)
SELECT '3050', 'Opening Balance Equity',
       'Offset for opening balances entered at go-live (bank accounts). Move to Retained Earnings once every opening balance is in.',
       'equity', 'equity', (SELECT h.id FROM (SELECT id FROM `acc_accounts` WHERE `code` = '3000') h), 0, 'CAD', 'credit', 1, 1, 3050
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `acc_accounts` WHERE `code` = '3050');

INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`)
SELECT 'accounting.opening_balance_equity_account_id', CAST(a.id AS CHAR), 'integer', 'accounting',
       'Opening Balance Equity',
       'S-SOP-KNOWN-ISSUES I8: credited by a bank account''s opening-balance entry.'
  FROM `acc_accounts` a
 WHERE a.code = '3050'
   AND NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'accounting.opening_balance_equity_account_id');

-- I1: the revenue map gains the mileage line types the billing engine
-- actually writes (they fell back to 4110 Other Revenue). Keys already in
-- the stored map keep their value.
UPDATE `settings`
   SET `value` = JSON_MERGE_PATCH(
         '{"mileage":"4060","mileage_usage":"4060","mileage_estimate":"4060","mileage_drawdown_credit":"4060"}',
         `value`)
 WHERE `key` = 'accounting.revenue_account_map'
   AND JSON_VALID(`value`);
