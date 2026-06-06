-- ============================================================================
-- S-QBO-19 — Bill Payment Push (FF → QBO) (Phase QBO-8 / 2 of 2)
-- ============================================================================
--
-- Creates acc_qbo_bill_payment_map to track FF→QBO bill payment push state.
-- Pairs with S-QBO-18 BillPusher to close the AP-direction sync surface
-- (Phase QBO-8 COMPLETE after this session).
--
-- Schema mirrors acc_qbo_bill_map (S-QBO-18) shape with bill-payment-
-- specific deltas:
--   - ff_ap_payment_id (acc_ap_payments.id; NOT NULL — FF-origin only)
--   - qbo_bill_payment_id (Intuit BillPayment.Id; NULL until first push)
--   - qbo_total_amt (BillPayment.TotalAmt; for drift comparison)
--   - qbo_currency, qbo_exchange_rate (for FX comparison)
--   - qbo_bank_account_id (BankAccountRef snapshot — QBO bank account
--     the FF bank_account_id was mapped to via acc_qbo_account_map)
--   - qbo_pay_type (Check/CreditCard) snapshot for drift comparison
--   - No DocNumber column — BillPayment uses PaymentRefNum like Payment
--     does (S-QBO-14 D-QBO-14-6 21-char limit applies)
--   - push_status ENUM identical to acc_qbo_bill_map (includes typed
--     preflight sub-states for currency_mismatch + field_too_long)
--   - FK CASCADE on acc_ap_payments (when FF payment hard-deleted —
--     unlikely per soft-delete discipline but defensive)
--
-- Per D-QBO-19-1 (anticipated): bill payments are FF-origin only in v1
-- (mirrors D-QBO-18-6 bills). QBO-authored bill payments (rare;
-- accountant-direct-entry) handled via S-QBO-26 manual sync.
--
-- Per D-QBO-19-2: PayType mapping FF payment_method → QBO PayType:
--   FF 'check' / 'eft' / 'wire' → QBO 'Check'
--   FF 'credit_card' → QBO 'CreditCard'
--   FF 'cash' / 'other' → QBO 'Check' (Intuit doesn't expose Cash; Check
--     is operationally-equivalent for non-card non-wire payments)
--
-- @session  S-QBO-19
-- @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §8.9 (Bill Payment)
-- @decision D-QBO-19-1 (FF-origin only; mirrors D-QBO-18-6 bills),
--           D-QBO-19-2 (PayType mapping FF→QBO),
--           D-QBO-19-3 (BankAccountRef from acc_qbo_account_map via
--               ap_payment.bank_account_id; preflight gate enforces),
--           D-QBO-19-4 (LinkedTxn.TxnType='Bill' for each allocation),
--           D-QBO-19-5 (pushUpdate stubbed → S-QBO-19-UPDATE-FOLLOWUP
--               matching S-QBO-11/14/18 stub-then-implement pattern)

CREATE TABLE IF NOT EXISTS `acc_qbo_bill_payment_map` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ff_ap_payment_id`         INT UNSIGNED NOT NULL COMMENT 'NOT NULL: bill payments originate in FF only in S-QBO-19 v1 (D-QBO-19-1 mirrors D-QBO-18-6). QBO-authored bill payments handled via S-QBO-26 manual sync.',
    `qbo_bill_payment_id`      VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Intuit BillPayment.Id; NULL until first successful push',
    `qbo_sync_token`           VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO optimistic-lock token',
    `qbo_vendor_id`            VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO VendorRef.value snapshot — drift detection across vendor remapping',
    `qbo_bank_account_id`      VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO BankAccountRef.value snapshot (D-QBO-19-3 lookup via acc_qbo_account_map)',
    `qbo_pay_type`             VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO PayType snapshot (Check / CreditCard) — D-QBO-19-2 mapping',
    `qbo_total_amt`            DECIMAL(15,2) DEFAULT NULL COMMENT 'QBO TotalAmt snapshot — drift comparison baseline',
    `qbo_currency`             VARCHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO CurrencyRef.value (e.g. CAD/USD)',
    `qbo_exchange_rate`        DECIMAL(10,6) DEFAULT NULL COMMENT 'QBO ExchangeRate pinned at push time',
    `qbo_txn_date`             DATE DEFAULT NULL COMMENT 'QBO TxnDate snapshot',
    `qbo_doc_number`           VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'QBO PrivateNote or PaymentRefNum equivalent (Intuit varies by API version)',
    `ff_payment_snapshot_total` DECIMAL(15,2) DEFAULT NULL COMMENT 'FF amount snapshot at push time — drift baseline',
    `push_status`              ENUM('pending','pushed','voided','failed','skipped_voided','skipped_unmapped_void','skipped_by_mode','failed_preflight','failed_preflight_currency_mismatch','failed_preflight_field_too_long') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Mirrors acc_qbo_bill_map.push_status (S-QBO-18 + S-QBO-BILL-GOTCHAS-PAYDOWN) — typed sub-states for currency_mismatch + field_too_long applicable here too.',
    `push_error`               TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Last error for failed/failed_preflight states',
    `pushed_at`                DATETIME DEFAULT NULL COMMENT 'Most recent successful push timestamp',
    `last_synced_at`           DATETIME DEFAULT NULL COMMENT 'Most recent state mutation (push, gate fail, skip)',
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ff_ap_payment` (`ff_ap_payment_id`) COMMENT 'One mapping per FF ap_payment; enforces idempotency of pushCreate',
    UNIQUE KEY `uq_qbo_bill_payment` (`qbo_bill_payment_id`) COMMENT 'No two FF ap_payments share a QBO BillPayment.Id; NULL-multi-OK per InnoDB',
    KEY `idx_status` (`push_status`),
    KEY `idx_pushed_at` (`pushed_at`),
    CONSTRAINT `fk_qbo_bill_payment_map_ff` FOREIGN KEY (`ff_ap_payment_id`) REFERENCES `acc_ap_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase QBO-8 S-QBO-19: FF→QBO bill payment push state tracking. Mirrors acc_qbo_bill_map (S-QBO-18) shape with bill-payment-specific deltas (bank_account snapshot + pay_type + no doc_number column — BillPayment has no DocNumber in QBO API).';

-- Seed sync_mode setting for bill_payment if not already present
-- (S-QBO-3 seed at 202605202100_S-QBO-3.sql already includes
-- 'quickbooks.sync_mode.bill_payment' = 'queue'; ON DUPLICATE keeps it)
INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('quickbooks.sync_mode.bill_payment', 'queue', 'string', 'quickbooks', 0, 0, 'Per D-QBO-3 sync mode for bill payment push: sync (immediate) / queue (worker picks up) / qbo_to_ff (no push) / disabled (skip). Default queue.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
